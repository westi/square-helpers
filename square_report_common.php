<?php

declare(strict_types=1);

const SQUARE_API_VERSION = '2026-01-22';
const SQUARE_DEBUG_ENABLED_DEFAULT = false;

function set_debug_enabled(bool $enabled): void
{
    $GLOBALS['square_debug_enabled'] = $enabled;
}

function debug_enabled(): bool
{
    return (bool) ($GLOBALS['square_debug_enabled'] ?? SQUARE_DEBUG_ENABLED_DEFAULT);
}

function redact_sensitive_value(string $key, $value)
{
    $keyLower = strtolower($key);
    $secretKeys = [
        'authorization',
        'access_token',
        'token',
        'card',
        'card_details',
        'card_number',
        'cvv',
        'email',
        'email_address',
        'phone',
        'phone_number',
        'address',
        'buyer_email_address',
    ];
    foreach ($secretKeys as $secretKey) {
        if (strpos($keyLower, $secretKey) !== false) {
            return '[REDACTED]';
        }
    }

    if (!is_array($value)) {
        return $value;
    }

    $out = [];
    foreach ($value as $k => $v) {
        $out[$k] = redact_sensitive_value((string) $k, $v);
    }
    return $out;
}

function redact_sensitive_array(array $payload): array
{
    $out = [];
    foreach ($payload as $k => $v) {
        $out[$k] = redact_sensitive_value((string) $k, $v);
    }
    return $out;
}

function debug_log(string $event, array $data = []): void
{
    if (!debug_enabled()) {
        return;
    }
    $line = [
        'ts' => gmdate('c'),
        'event' => $event,
        'data' => $data,
    ];
    fwrite(STDERR, '[square-debug] ' . json_encode($line, JSON_UNESCAPED_SLASHES) . "\n");
}

function load_env(?string $path = null): void
{
    $path = $path ?? (getcwd() . '/.env');
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $value = trim(substr($line, $eq + 1));
        if ($key !== '') {
            putenv("$key=$value");
        }
    }
}

function square_request(string $baseUrl, string $accessToken, string $method, string $path, ?array $body = null): array
{
    $url = rtrim($baseUrl, '/') . $path;
    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
        'Square-Version: ' . SQUARE_API_VERSION,
    ];

    $maxTries = 3;
    $try = 0;
    $lastException = null;

    while ($try < $maxTries) {
        $try++;
        $startedAt = microtime(true);
        debug_log('square_request_start', [
            'method' => $method,
            'path' => $path,
            'attempt' => $try,
            'body' => $body !== null ? redact_sensitive_array($body) : null,
        ]);

        $opts = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
            ],
        ];

        if ($body !== null && $method === 'POST') {
            $opts['http']['content'] = json_encode($body);
        }

        $context = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($response === false) {
            $err = error_get_last();
            debug_log('square_request_transport_error', [
                'method' => $method,
                'path' => $path,
                'attempt' => $try,
                'elapsed_ms' => $elapsedMs,
                'error' => $err['message'] ?? 'unknown error',
            ]);
            throw new RuntimeException('Square API request failed: ' . ($err['message'] ?? 'unknown error'));
        }

        $responseHeaders = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : ($http_response_header ?? []);

        $code = 0;
        if (!empty($responseHeaders[0]) && preg_match('/HTTP\/\d\.\d\s+(\d{3})/', $responseHeaders[0], $m)) {
            $code = (int) $m[1];
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            debug_log('square_request_json_error', [
                'method' => $method,
                'path' => $path,
                'attempt' => $try,
                'status_code' => $code,
                'elapsed_ms' => $elapsedMs,
                'json_error' => json_last_error_msg(),
                'raw_response_preview' => substr($response, 0, 500),
            ]);
            throw new RuntimeException('Square API invalid JSON: ' . json_last_error_msg());
        }

        debug_log('square_request_response', [
            'method' => $method,
            'path' => $path,
            'attempt' => $try,
            'status_code' => $code,
            'elapsed_ms' => $elapsedMs,
            'response' => redact_sensitive_array($data),
        ]);

        if ($code === 429 && $try < $maxTries) {
            $lastException = new RuntimeException('Square API rate limited (429)');
            usleep(500000 * $try);
            continue;
        }

        if ($code >= 400) {
            $msg = $data['errors'][0]['detail'] ?? $data['errors'][0]['code'] ?? (string) $code;
            throw new RuntimeException('Square API error: ' . $msg);
        }

        if (!empty($data['errors'])) {
            $msg = $data['errors'][0]['detail'] ?? $data['errors'][0]['code'] ?? 'Unknown error';
            throw new RuntimeException('Square API error: ' . $msg);
        }

        return $data;
    }

    throw $lastException ?? new RuntimeException('Square API request failed');
}

function search_orders(
    string $baseUrl,
    string $accessToken,
    array $locationIds,
    string $startAt,
    string $endAt,
    ?array $stateFilter = null
): array {
    $query = [
        'filter' => [
            'date_time_filter' => [
                'created_at' => [
                    'start_at' => $startAt,
                    'end_at' => $endAt,
                ],
            ],
        ],
        'sort' => [
            'sort_field' => 'CREATED_AT',
            'sort_order' => 'ASC',
        ],
    ];

    if ($stateFilter !== null) {
        $query['filter']['state_filter'] = $stateFilter;
    }

    $all = [];
    $cursor = null;
    do {
        $body = [
            'location_ids' => $locationIds,
            'query' => $query,
            'limit' => 500,
        ];
        if ($cursor !== null && $cursor !== '') {
            $body['cursor'] = $cursor;
        }
        $result = square_request($baseUrl, $accessToken, 'POST', '/v2/orders/search', $body);
        foreach (($result['orders'] ?? []) as $order) {
            $all[] = $order;
        }
        $cursor = $result['cursor'] ?? null;
    } while ($cursor !== null && $cursor !== '');

    return $all;
}

function orders_containing_products(array $orders, array $variationIds): array
{
    if (empty($variationIds)) {
        debug_log('orders_filter_products_skipped', [
            'orders_in' => count($orders),
            'orders_out' => count($orders),
            'reason' => 'no_variation_ids',
        ]);
        return $orders;
    }
    $allowed = array_flip($variationIds);
    $matched = [];
    foreach ($orders as $order) {
        $orderId = (string) ($order['id'] ?? '');
        $itemDebug = [];
        $isMatch = false;
        foreach (($order['line_items'] ?? []) as $item) {
            $catalogId = $item['catalog_object_id'] ?? null;
            $itemDebug[] = [
                'name' => (string) ($item['name'] ?? ''),
                'catalog_object_id' => $catalogId,
                'quantity' => (string) ($item['quantity'] ?? ''),
                'matched_target_variation' => $catalogId !== null && isset($allowed[$catalogId]),
            ];
            if ($catalogId !== null && isset($allowed[$catalogId])) {
                $isMatch = true;
            }
        }
        if ($isMatch) {
            $matched[] = $order;
            debug_log('orders_filter_products_order_matched', [
                'order_id' => $orderId,
                'items' => $itemDebug,
            ]);
        } else {
            debug_log('orders_filter_products_order_filtered_out', [
                'order_id' => $orderId,
                'items' => $itemDebug,
                'target_variation_ids' => $variationIds,
            ]);
        }
    }
    debug_log('orders_filter_products_result', [
        'orders_in' => count($orders),
        'orders_out' => count($matched),
        'variation_ids_count' => count($variationIds),
        'variation_ids' => $variationIds,
    ]);
    return $matched;
}

function get_recipient_from_order(array $order): ?array
{
    foreach ($order['fulfillments'] ?? [] as $f) {
        $type = $f['type'] ?? '';
        $recipient = null;
        if ($type === 'SHIPMENT') {
            $recipient = $f['shipment_details']['recipient'] ?? null;
        } elseif ($type === 'PICKUP') {
            $recipient = $f['pickup_details']['recipient'] ?? null;
        } elseif ($type === 'DELIVERY') {
            $recipient = $f['delivery_details']['recipient'] ?? null;
        }
        if ($recipient !== null) {
            return $recipient;
        }
    }
    return null;
}

function get_shipment_address(array $order): ?array
{
    foreach (($order['fulfillments'] ?? []) as $f) {
        if (($f['type'] ?? '') === 'SHIPMENT') {
            $recipient = $f['shipment_details']['recipient'] ?? null;
            if ($recipient !== null && !empty($recipient['address'])) {
                return $recipient['address'];
            }
        }
    }
    $recipient = get_recipient_from_order($order);
    if ($recipient !== null && !empty($recipient['address'])) {
        return $recipient['address'];
    }
    return null;
}

function get_matching_line_items(array $order, array $variationIds): array
{
    $out = [];
    $lineItems = $order['line_items'] ?? [];
    if (empty($variationIds)) {
        foreach ($lineItems as $item) {
            $catalogId = $item['catalog_object_id'] ?? null;
            $out[] = [
                'name' => $item['name'] ?? $catalogId ?? '',
                'catalog_object_id' => $catalogId ?? '',
                'quantity' => (int) ($item['quantity'] ?? 0),
            ];
        }
        return $out;
    }
    $allowed = array_flip($variationIds);
    foreach ($lineItems as $item) {
        $catalogId = $item['catalog_object_id'] ?? null;
        if ($catalogId !== null && isset($allowed[$catalogId])) {
            $out[] = [
                'name' => $item['name'] ?? $catalogId,
                'catalog_object_id' => $catalogId,
                'quantity' => (int) ($item['quantity'] ?? 0),
            ];
        }
    }
    return $out;
}

function retrieve_payment(string $baseUrl, string $accessToken, string $paymentId): ?array
{
    if ($paymentId === '') {
        return null;
    }
    $data = square_request($baseUrl, $accessToken, 'GET', '/v2/payments/' . $paymentId);
    return $data['payment'] ?? null;
}

function get_payment(string $baseUrl, string $accessToken, string $paymentId): ?array
{
    try {
        return retrieve_payment($baseUrl, $accessToken, $paymentId);
    } catch (Throwable $e) {
        return null;
    }
}

function get_payment_ids_from_order(array $order): array
{
    $ids = [];
    foreach (($order['tenders'] ?? []) as $tender) {
        $pid = $tender['payment_id'] ?? null;
        if ($pid !== null && $pid !== '') {
            $ids[] = $pid;
        }
    }
    return $ids;
}

function money_amount(array $money): int
{
    return (int) ($money['amount'] ?? 0);
}

function completed_payment_net_amount(array $payment): int
{
    if (($payment['status'] ?? '') !== 'COMPLETED') {
        return 0;
    }
    $amount = money_amount($payment['amount_money'] ?? []);
    $refunded = money_amount($payment['refunded_money'] ?? []);
    return max(0, $amount - $refunded);
}

function open_order_is_paid(string $baseUrl, string $accessToken, array $order): bool
{
    $totalAmount = money_amount($order['total_money'] ?? []);
    $netDueAmount = money_amount($order['net_amount_due_money'] ?? []);
    if ($netDueAmount > 0) {
        return false;
    }
    if ($totalAmount <= 0) {
        return true;
    }

    $paidAmount = 0;
    foreach (get_payment_ids_from_order($order) as $paymentId) {
        $payment = retrieve_payment($baseUrl, $accessToken, $paymentId);
        if ($payment !== null) {
            $paidAmount += completed_payment_net_amount($payment);
        }
    }
    return $paidAmount >= $totalAmount;
}

function filter_reportable_paid_orders(string $baseUrl, string $accessToken, array $orders): array
{
    $out = [];
    $filtered = [];
    foreach ($orders as $order) {
        $state = (string) ($order['state'] ?? '');
        if ($state === 'COMPLETED') {
            $out[] = $order;
            continue;
        }
        if ($state === 'OPEN' && open_order_is_paid($baseUrl, $accessToken, $order)) {
            $out[] = $order;
            continue;
        }
        $filtered[] = [
            'order_id' => $order['id'] ?? '',
            'state' => $state,
            'net_due' => $order['net_amount_due_money']['amount'] ?? null,
        ];
    }
    debug_log('orders_paid_filter_result', [
        'orders_in' => count($orders),
        'orders_out' => count($out),
        'filtered_out' => $filtered,
    ]);
    return $out;
}

function bulk_retrieve_customers(string $baseUrl, string $accessToken, array $customerIds): array
{
    $customerIds = array_values(array_unique(array_filter($customerIds)));
    if (empty($customerIds)) {
        return [];
    }
    $map = [];
    foreach (array_chunk($customerIds, 100) as $chunk) {
        $result = square_request($baseUrl, $accessToken, 'POST', '/v2/customers/bulk-retrieve', ['customer_ids' => $chunk]);
        foreach (($result['responses'] ?? []) as $id => $resp) {
            if (isset($resp['customer'])) {
                $map[$id] = $resp['customer'];
            }
        }
    }
    return $map;
}

function format_address(?array $addr): array
{
    if ($addr === null) {
        return [
            'address_line_1' => '',
            'address_line_2' => '',
            'locality' => '',
            'administrative_district_level_1' => '',
            'postal_code' => '',
            'country' => '',
        ];
    }
    return [
        'address_line_1' => $addr['address_line_1'] ?? '',
        'address_line_2' => $addr['address_line_2'] ?? '',
        'locality' => $addr['locality'] ?? '',
        'administrative_district_level_1' => $addr['administrative_district_level_1'] ?? '',
        'postal_code' => $addr['postal_code'] ?? '',
        'country' => $addr['country'] ?? '',
    ];
}

function build_rows(array $orders, array $variationIds, array $customerMap, array $paymentInfoByOrder = []): array
{
    $rows = [];
    foreach ($orders as $order) {
        $orderId = $order['id'] ?? '';
        $paymentInfo = $paymentInfoByOrder[$orderId] ?? [];
        $customerId = $order['customer_id'] ?? $paymentInfo['customer_id'] ?? '';

        $address = get_shipment_address($order);
        $addressSource = 'none';
        if ($address !== null) {
            $addressSource = 'order_shipping';
        } elseif ($customerId !== '') {
            $customer = $customerMap[$customerId] ?? null;
            $address = $customer['address'] ?? null;
            if ($address !== null) {
                $addressSource = 'customer_profile';
            }
        }
        $addr = format_address($address);

        $customer = $customerMap[$customerId] ?? null;
        $firstName = $customer['given_name'] ?? '';
        $lastName = $customer['family_name'] ?? '';
        $email = $customer['email_address'] ?? '';
        $phone = $customer['phone_number'] ?? '';

        if ($address !== null) {
            $firstName = $address['first_name'] ?? $firstName;
            $lastName = $address['last_name'] ?? $lastName;
        }

        $recipient = get_recipient_from_order($order);
        if ($recipient !== null) {
            $dn = $recipient['display_name'] ?? '';
            if ($dn !== '' && $firstName === '' && $lastName === '') {
                $parts = explode(' ', $dn, 2);
                $firstName = $parts[0] ?? '';
                $lastName = $parts[1] ?? '';
            }
            if (($recipient['email_address'] ?? '') !== '') {
                $email = $recipient['email_address'];
            }
            if (($recipient['phone_number'] ?? '') !== '') {
                $phone = $recipient['phone_number'];
            }
        }
        if ($email === '' && ($paymentInfo['buyer_email_address'] ?? '') !== '') {
            $email = $paymentInfo['buyer_email_address'];
        }

        $lineItems = get_matching_line_items($order, $variationIds);
        $productParts = [];
        $totalQty = 0;
        foreach ($lineItems as $item) {
            $productParts[] = $item['name'] . ' (' . $item['quantity'] . ')';
            $totalQty += $item['quantity'];
        }

        $rows[] = array_merge([
            'order_id' => $orderId,
            'order_date' => $order['created_at'] ?? '',
            'customer_id' => $customerId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone,
            'product_name_or_id' => implode('; ', $productParts),
            'quantity' => (string) $totalQty,
            'address_source' => $addressSource,
            'has_address' => $addressSource === 'none' ? 'N' : 'Y',
        ], $addr);
    }
    return $rows;
}

function output_csv(array $rows): void
{
    $cols = [
        'order_id', 'order_date', 'customer_id', 'first_name', 'last_name', 'email', 'phone',
        'address_line_1', 'address_line_2', 'locality', 'administrative_district_level_1', 'postal_code', 'country',
        'product_name_or_id', 'quantity', 'has_address', 'address_source',
    ];
    output_csv_to_stream($rows, $cols, fopen('php://output', 'w'));
}

function output_csv_to_stream(array $rows, array $cols, $stream): void
{
    fputcsv($stream, $cols, ',', '"', '\\');
    foreach ($rows as $row) {
        $line = [];
        foreach ($cols as $c) {
            $line[] = $row[$c] ?? '';
        }
        fputcsv($stream, $line, ',', '"', '\\');
    }
}

function output_json(array $rows): void
{
    echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

function order_url(string $base, string $orderId): string
{
    $base = rtrim($base, '/');
    return $base . '/' . $orderId;
}

function order_links_cell(array $orderIds, string $orderUrlBase): string
{
    $parts = [];
    foreach ($orderIds as $oid) {
        $oid = trim((string) $oid);
        if ($oid !== '') {
            $parts[] = order_url($orderUrlBase, $oid);
        }
    }
    return implode('; ', $parts);
}

function parse_and_merge_item_summaries(array $productNameOrIdStrings): array
{
    $merged = [];
    foreach ($productNameOrIdStrings as $str) {
        $str = trim((string) $str);
        if ($str === '') {
            continue;
        }
        $parts = array_map('trim', explode(';', $str));
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(.+)\s*\((\d+)\)\s*$/', $part, $m)) {
                $name = trim($m[1]);
                $qty = (int) $m[2];
                $merged[$name] = ($merged[$name] ?? 0) + $qty;
            } else {
                $merged[$part] = ($merged[$part] ?? 0) + 1;
            }
        }
    }
    return $merged;
}

function purchaser_key(array $row): string
{
    $customerId = $row['customer_id'] ?? '';
    if ($customerId !== '') {
        return 'c:' . $customerId;
    }
    $first = trim($row['first_name'] ?? '') . '|' . trim($row['last_name'] ?? '');
    $addr = trim($row['address_line_1'] ?? '') . '|' . trim($row['locality'] ?? '') . '|' . trim($row['postal_code'] ?? '');
    return 'n:' . $first . '|' . $addr;
}

function format_address_multiline(array $row): string
{
    $a1 = trim($row['address_line_1'] ?? '');
    $a2 = trim($row['address_line_2'] ?? '');
    $locality = trim($row['locality'] ?? '');
    $state = trim($row['administrative_district_level_1'] ?? '');
    $postal = trim($row['postal_code'] ?? '');
    $country = trim($row['country'] ?? '');
    if ($a1 === '' && $locality === '') {
        return '(No address on file)';
    }
    $lines = [];
    if ($a1 !== '') {
        $lines[] = $a1;
    }
    if ($a2 !== '') {
        $lines[] = $a2;
    }
    $cityLine = $locality;
    if ($state !== '' || $postal !== '') {
        $cityLine .= ($cityLine !== '' ? ', ' : '') . trim($state . ' ' . $postal);
    }
    if ($cityLine !== '') {
        $lines[] = $cityLine;
    }
    if ($country !== '') {
        $lines[] = $country;
    }
    return implode("\n", $lines);
}

function output_report(array $rows, string $orderUrlBase): void
{
    $groups = [];
    foreach ($rows as $row) {
        $key = purchaser_key($row);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
                'email' => trim($row['email'] ?? ''),
                'phone' => trim($row['phone'] ?? ''),
                'address' => format_address_multiline($row),
                'order_ids' => [],
                'product_strings' => [],
            ];
        }
        $orderId = $row['order_id'] ?? '';
        if ($orderId !== '' && !in_array($orderId, $groups[$key]['order_ids'], true)) {
            $groups[$key]['order_ids'][] = $orderId;
        }
        $ps = trim($row['product_name_or_id'] ?? '');
        if ($ps !== '') {
            $groups[$key]['product_strings'][] = $ps;
        }
    }

    foreach ($groups as $group) {
        echo "--- Purchaser ---\n";
        if ($group['name'] !== '') {
            echo $group['name'] . "\n";
        }
        if ($group['email'] !== '') {
            echo $group['email'] . "\n";
        }
        if ($group['phone'] !== '') {
            echo $group['phone'] . "\n";
        }
        echo "\n";
        echo $group['address'] . "\n";
        echo "\n";
        $items = parse_and_merge_item_summaries($group['product_strings']);
        echo "Items purchased:\n";
        if (empty($items)) {
            echo "  (No items)\n";
        } else {
            foreach ($items as $name => $qty) {
                echo "  - " . $name . ": " . $qty . "\n";
            }
        }
        echo "\nOrder links:\n";
        foreach ($group['order_ids'] as $oid) {
            echo "  " . order_url($orderUrlBase, $oid) . "\n";
        }
        echo "\n---\n\n";
    }
}

function parse_csv_ids(?string $raw): array
{
    if ($raw === null || trim($raw) === '') {
        return [];
    }
    $parts = array_map('trim', explode(',', $raw));
    $parts = array_map(static function (string $value): string {
        $value = trim($value);
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        return trim($value);
    }, $parts);
    return array_values(array_filter($parts));
}

function dates_to_rfc3339(string $start, string $end): array
{
    $start = trim($start);
    $end = trim($end);
    if (strlen($start) === 10) {
        $start .= 'T00:00:00Z';
    }
    if (strlen($end) === 10) {
        $end .= 'T23:59:59Z';
    }
    return [$start, $end];
}

function auto_season_end_year(?DateTimeImmutable $now = null): int
{
    $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $year = (int) $now->format('Y');
    $month = (int) $now->format('n');
    if ($month >= 12 || $month <= 3) {
        return $month >= 12 ? $year + 1 : $year;
    }
    return $year + 1;
}

/** @param $throughEndOfJuly false: through Mar 31 (Cast/Souvenir/VIP); true: through Jul 31 (DVD, variation-ID suggest). */
function season_window_rfc3339(int $seasonEndYear, bool $throughEndOfJuly = false): array
{
    $startYear = $seasonEndYear - 1;
    $start = sprintf('%04d-12-01T00:00:00Z', $startYear);
    $end = $throughEndOfJuly
        ? sprintf('%04d-07-31T23:59:59Z', $seasonEndYear)
        : sprintf('%04d-03-31T23:59:59Z', $seasonEndYear);
    return [$start, $end];
}

function ensure_reports_dir(string $dir): void
{
    if (is_dir($dir)) {
        return;
    }
    if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create reports directory: ' . $dir);
    }
}

function base_url_from_sandbox(bool $sandbox): string
{
    return $sandbox ? 'https://connect.squareupsandbox.com' : 'https://connect.squareup.com';
}

function collect_customer_ids(array $orders): array
{
    $customerIds = [];
    foreach ($orders as $order) {
        $cid = $order['customer_id'] ?? '';
        if ($cid !== '') {
            $customerIds[] = $cid;
        }
        foreach (($order['tenders'] ?? []) as $tender) {
            $tid = $tender['customer_id'] ?? '';
            if ($tid !== '') {
                $customerIds[] = $tid;
            }
        }
    }
    return $customerIds;
}

function build_payment_info_by_order(string $baseUrl, string $accessToken, array $orders, array $customerMap): array
{
    $paymentInfoByOrder = [];
    foreach ($orders as $order) {
        $orderId = $order['id'] ?? '';
        $customerId = $order['customer_id'] ?? '';
        $hasCustomer = $customerId !== '' && isset($customerMap[$customerId]);
        $hasRecipient = get_recipient_from_order($order) !== null;
        if ($hasCustomer || $hasRecipient) {
            continue;
        }
        $paymentIds = get_payment_ids_from_order($order);
        if (empty($paymentIds)) {
            continue;
        }
        $payment = get_payment($baseUrl, $accessToken, $paymentIds[0]);
        if ($payment === null) {
            continue;
        }
        $info = [];
        $payCid = $payment['customer_id'] ?? '';
        if ($payCid !== '') {
            $info['customer_id'] = $payCid;
        }
        $buyerEmail = $payment['buyer_email_address'] ?? '';
        if ($buyerEmail !== '') {
            $info['buyer_email_address'] = $buyerEmail;
        }
        if (!empty($info)) {
            $paymentInfoByOrder[$orderId] = $info;
        }
    }
    return $paymentInfoByOrder;
}

function hydrate_customers_from_payment_info(string $baseUrl, string $accessToken, array $customerMap, array $paymentInfoByOrder): array
{
    $paymentCustomerIds = [];
    foreach ($paymentInfoByOrder as $info) {
        $cid = $info['customer_id'] ?? '';
        if ($cid !== '' && !isset($customerMap[$cid])) {
            $paymentCustomerIds[] = $cid;
        }
    }
    if (empty($paymentCustomerIds)) {
        return $customerMap;
    }
    try {
        $extra = bulk_retrieve_customers($baseUrl, $accessToken, $paymentCustomerIds);
        return $customerMap + $extra;
    } catch (Throwable $e) {
        return $customerMap;
    }
}

function fetch_orders_with_people(
    string $baseUrl,
    string $accessToken,
    array $locationIds,
    string $startAt,
    string $endAt,
    array $variationIds,
    ?array $stateFilter,
    bool $requirePaidOrders = false
): array {
    $orders = search_orders($baseUrl, $accessToken, $locationIds, $startAt, $endAt, $stateFilter);
    debug_log('orders_search_result', [
        'orders_count' => count($orders),
        'start_at' => $startAt,
        'end_at' => $endAt,
        'state_filter' => $stateFilter,
    ]);
    $orders = orders_containing_products($orders, $variationIds);
    if ($requirePaidOrders) {
        $orders = filter_reportable_paid_orders($baseUrl, $accessToken, $orders);
    }

    $customerMap = [];
    $customerIds = collect_customer_ids($orders);
    if (!empty($customerIds)) {
        try {
            $customerMap = bulk_retrieve_customers($baseUrl, $accessToken, $customerIds);
        } catch (Throwable $e) {
            fwrite(STDERR, "Warning: Could not fetch some customers: " . $e->getMessage() . "\n");
        }
    }

    $paymentInfoByOrder = build_payment_info_by_order($baseUrl, $accessToken, $orders, $customerMap);
    $customerMap = hydrate_customers_from_payment_info($baseUrl, $accessToken, $customerMap, $paymentInfoByOrder);

    $rows = build_rows($orders, $variationIds, $customerMap, $paymentInfoByOrder);
    debug_log('rows_built', [
        'rows_count' => count($rows),
    ]);
    return $rows;
}

function aggregate_rows_by_customer(array $rows, bool $includeAddress): array
{
    $out = [];
    foreach ($rows as $row) {
        $name = trim(trim($row['first_name'] ?? '') . ' ' . trim($row['last_name'] ?? ''));
        $email = trim((string) ($row['email'] ?? ''));
        $phone = trim((string) ($row['phone'] ?? ''));
        $customerId = trim((string) ($row['customer_id'] ?? ''));
        $quantity = (int) ($row['quantity'] ?? 0);
        $key = $customerId !== '' ? 'cid:' . $customerId : 'id:' . strtolower($name . '|' . $email . '|' . $phone);

        if (!isset($out[$key])) {
            $out[$key] = [
                'first_name' => trim($row['first_name'] ?? ''),
                'last_name' => trim($row['last_name'] ?? ''),
                'full_name' => $name,
                'email' => $email,
                'phone' => $phone,
                'quantity' => 0,
                'order_ids' => [],
            ];
            if ($includeAddress) {
                $out[$key]['address_line_1'] = $row['address_line_1'] ?? '';
                $out[$key]['address_line_2'] = $row['address_line_2'] ?? '';
                $out[$key]['locality'] = $row['locality'] ?? '';
                $out[$key]['administrative_district_level_1'] = $row['administrative_district_level_1'] ?? '';
                $out[$key]['postal_code'] = $row['postal_code'] ?? '';
                $out[$key]['country'] = $row['country'] ?? '';
                $out[$key]['address_source'] = $row['address_source'] ?? 'none';
            }
        } elseif ($includeAddress && (($out[$key]['address_source'] ?? 'none') !== 'order_shipping') && (($row['address_source'] ?? 'none') === 'order_shipping')) {
            $out[$key]['address_line_1'] = $row['address_line_1'] ?? '';
            $out[$key]['address_line_2'] = $row['address_line_2'] ?? '';
            $out[$key]['locality'] = $row['locality'] ?? '';
            $out[$key]['administrative_district_level_1'] = $row['administrative_district_level_1'] ?? '';
            $out[$key]['postal_code'] = $row['postal_code'] ?? '';
            $out[$key]['country'] = $row['country'] ?? '';
            $out[$key]['address_source'] = $row['address_source'] ?? 'none';
        }

        $out[$key]['quantity'] += $quantity;
        $orderId = trim((string) ($row['order_id'] ?? ''));
        if ($orderId !== '' && !in_array($orderId, $out[$key]['order_ids'], true)) {
            $out[$key]['order_ids'][] = $orderId;
        }
    }
    $aggregated = array_values($out);
    debug_log('rows_aggregated_by_customer', [
        'rows_in' => count($rows),
        'rows_out' => count($aggregated),
        'include_address' => $includeAddress,
    ]);
    return $aggregated;
}

function write_csv_file(string $path, array $columns, array $rows): void
{
    $fp = fopen($path, 'w');
    if ($fp === false) {
        throw new RuntimeException('Unable to write CSV file: ' . $path);
    }
    output_csv_to_stream($rows, $columns, $fp);
    fclose($fp);
}

function parse_location_ids(?string $override): array
{
    $locationId = $override ?? getenv('SQUARE_LOCATION_ID');
    if ($locationId === false || $locationId === '') {
        throw new RuntimeException('SQUARE_LOCATION_ID or --location-id is required.');
    }
    $locationIds = parse_csv_ids((string) $locationId);
    if (empty($locationIds)) {
        throw new RuntimeException('At least one location ID is required.');
    }
    if (count($locationIds) > 10) {
        throw new RuntimeException('Maximum 10 location IDs.');
    }
    return $locationIds;
}

function resolve_season_from_cli(?string $seasonRaw): int
{
    if ($seasonRaw === null || trim($seasonRaw) === '') {
        return auto_season_end_year();
    }
    if (!preg_match('/^\d{4}$/', trim($seasonRaw))) {
        throw new RuntimeException('--season must be a 4-digit year (ending year).');
    }
    return (int) trim($seasonRaw);
}

function run_simple_season_report(
    array $argv,
    string $scriptName,
    string $productEnvVar,
    string $outputSlug,
    bool $includeAddress,
    string $reportLabel
): int {
    load_env();
    $opts = getopt('', ['season::', 'location-id:', 'sandbox', 'stdout', 'debug', 'help']);
    if (isset($opts['help'])) {
        echo "Usage:\n";
        echo "  php " . $scriptName . " [--season=YYYY] [--location-id=ID1,ID2] [--sandbox] [--stdout] [--debug]\n\n";
        echo "Report:\n";
        echo "  " . $reportLabel . " (Dec 1 to Mar 31 show season, named by ending year)\n\n";
        echo "Environment:\n";
        echo "  SQUARE_ACCESS_TOKEN (required)\n";
        echo "  SQUARE_LOCATION_ID (required unless --location-id)\n";
        echo "  " . $productEnvVar . " (required, comma-separated variation IDs)\n";
        echo "  SQUARE_ORDER_URL_BASE (optional; prefix for order links in CSV, default production or sandbox dashboard)\n";
        echo "\n";
        echo "Debug:\n";
        echo "  --debug  Print redacted Square API request/response logs to STDERR\n";
        return 0;
    }
    set_debug_enabled(isset($opts['debug']));

    $token = getenv('SQUARE_ACCESS_TOKEN');
    if ($token === false || $token === '') {
        fwrite(STDERR, "Error: SQUARE_ACCESS_TOKEN is required.\n");
        return 1;
    }
    $productIds = parse_csv_ids((string) getenv($productEnvVar));
    if (empty($productIds)) {
        fwrite(STDERR, "Error: " . $productEnvVar . " is required and must list variation IDs.\n");
        return 1;
    }

    try {
        $season = resolve_season_from_cli($opts['season'] ?? null);
        $locationIds = parse_location_ids($opts['location-id'] ?? null);
    } catch (Throwable $e) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        return 1;
    }

    $sandbox = isset($opts['sandbox']) || (getenv('SQUARE_SANDBOX') !== false && getenv('SQUARE_SANDBOX') !== '');
    $baseUrl = base_url_from_sandbox($sandbox);
    [$startAt, $endAt] = season_window_rfc3339($season);

    try {
        $rows = fetch_orders_with_people(
            $baseUrl,
            $token,
            $locationIds,
            $startAt,
            $endAt,
            $productIds,
            ['states' => ['COMPLETED']]
        );
    } catch (Throwable $e) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        return 1;
    }

    $rows = aggregate_rows_by_customer($rows, $includeAddress);
    if ($includeAddress) {
        usort($rows, static function (array $a, array $b): int {
            return strcmp(($a['full_name'] ?? '') . ($a['email'] ?? ''), ($b['full_name'] ?? '') . ($b['email'] ?? ''));
        });
    } else {
        usort($rows, static function (array $a, array $b): int {
            $cmp = strcmp($a['last_name'] ?? '', $b['last_name'] ?? '');
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp($a['first_name'] ?? '', $b['first_name'] ?? '');
        });
    }

    $orderUrlBase = getenv('SQUARE_ORDER_URL_BASE');
    if ($orderUrlBase === false || $orderUrlBase === '') {
        $orderUrlBase = $sandbox
            ? 'https://squareupsandbox.com/dashboard/orders/overview'
            : 'https://app.squareup.com/dashboard/orders/overview';
    }
    if (!$includeAddress) {
        foreach ($rows as $i => $row) {
            $ids = $row['order_ids'] ?? [];
            $rows[$i]['order_links'] = order_links_cell(is_array($ids) ? $ids : [], $orderUrlBase);
            unset($rows[$i]['order_ids']);
        }
    }

    $columns = $includeAddress
        ? ['full_name', 'email', 'phone', 'quantity']
        : ['first_name', 'last_name', 'quantity', 'order_links'];
    if ($includeAddress) {
        $columns = array_merge($columns, [
            'address_line_1',
            'address_line_2',
            'locality',
            'administrative_district_level_1',
            'postal_code',
            'country',
            'address_source',
        ]);
    }

    if (isset($opts['stdout'])) {
        output_csv_to_stream($rows, $columns, fopen('php://output', 'w'));
        return 0;
    }

    $reportsDir = getcwd() . '/reports';
    try {
        ensure_reports_dir($reportsDir);
    } catch (Throwable $e) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        return 1;
    }

    $path = $reportsDir . '/' . $outputSlug . '_season_' . $season . '.csv';
    try {
        write_csv_file($path, $columns, $rows);
    } catch (Throwable $e) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        return 1;
    }

    echo "Wrote " . count($rows) . " row(s) to " . $path . "\n";
    return 0;
}

