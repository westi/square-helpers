#!/usr/bin/env php
<?php

/**
 * List purchasers of specific Square products within a time range.
 * Outputs CSV (or JSON) with purchaser and mailing address for shipping.
 *
 * Usage:
 *   php list_product_purchasers.php --start=2025-01-01 --end=2025-01-31 [--products=VAR_ID1,VAR_ID2] [--output=json] [--sandbox]
 *
 * Requires: SQUARE_ACCESS_TOKEN, SQUARE_LOCATION_ID (env or .env)
 */

declare(strict_types=1);

const SQUARE_API_VERSION = '2026-01-22';

// --- Env / .env ------------------------------------------------------------

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

// --- HTTP ------------------------------------------------------------------

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
        $opts = [
            'http' => [
                'method'  => $method,
                'header'  => implode("\r\n", $headers),
                'ignore_errors' => true,
            ],
        ];

        if ($body !== null && $method === 'POST') {
            $opts['http']['content'] = json_encode($body);
        }

        $context = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $err = error_get_last();
            throw new RuntimeException('Square API request failed: ' . ($err['message'] ?? 'unknown error'));
        }

        $code = 0;
        if (!empty($http_response_header[0]) && preg_match('/HTTP\/\d\.\d\s+(\d{3})/', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Square API invalid JSON: ' . json_last_error_msg());
        }

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

// --- Orders -----------------------------------------------------------------

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
                    'end_at'   => $endAt,
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
        $orders = $result['orders'] ?? [];
        foreach ($orders as $order) {
            $all[] = $order;
        }
        $cursor = $result['cursor'] ?? null;
    } while ($cursor !== null && $cursor !== '');

    return $all;
}

function orders_containing_products(array $orders, array $variationIds): array
{
    if (empty($variationIds)) {
        return $orders;
    }
    $variationIds = array_flip($variationIds);
    $matched = [];
    foreach ($orders as $order) {
        $lineItems = $order['line_items'] ?? [];
        foreach ($lineItems as $item) {
            $catalogId = $item['catalog_object_id'] ?? null;
            if ($catalogId !== null && isset($variationIds[$catalogId])) {
                $matched[] = $order;
                break;
            }
        }
    }
    return $matched;
}

function get_shipment_address(array $order): ?array
{
    $fulfillments = $order['fulfillments'] ?? [];
    foreach ($fulfillments as $f) {
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

/** Return the first recipient from any fulfillment (SHIPMENT, PICKUP, DELIVERY) for contact info. */
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
    $variationIds = array_flip($variationIds);
    foreach ($lineItems as $item) {
        $catalogId = $item['catalog_object_id'] ?? null;
        if ($catalogId !== null && isset($variationIds[$catalogId])) {
            $out[] = [
                'name' => $item['name'] ?? $catalogId,
                'catalog_object_id' => $catalogId,
                'quantity' => (int) ($item['quantity'] ?? 0),
            ];
        }
    }
    return $out;
}

// --- Payments ---------------------------------------------------------------

function get_payment(string $baseUrl, string $accessToken, string $paymentId): ?array
{
    if ($paymentId === '') {
        return null;
    }
    try {
        $data = square_request($baseUrl, $accessToken, 'GET', '/v2/payments/' . $paymentId);
        return $data['payment'] ?? null;
    } catch (Throwable $e) {
        return null;
    }
}

/** @return string[] */
function get_payment_ids_from_order(array $order): array
{
    $ids = [];
    foreach ($order['tenders'] ?? [] as $tender) {
        $pid = $tender['payment_id'] ?? null;
        if ($pid !== null && $pid !== '') {
            $ids[] = $pid;
        }
    }
    return $ids;
}

// --- Customers --------------------------------------------------------------

function bulk_retrieve_customers(string $baseUrl, string $accessToken, array $customerIds): array
{
    $customerIds = array_unique(array_filter($customerIds));
    if (empty($customerIds)) {
        return [];
    }
    $customerIds = array_values($customerIds);
    $chunks = array_chunk($customerIds, 100);
    $map = [];
    foreach ($chunks as $chunk) {
        $body = ['customer_ids' => $chunk];
        $result = square_request($baseUrl, $accessToken, 'POST', '/v2/customers/bulk-retrieve', $body);
        $responses = $result['responses'] ?? [];
        foreach ($responses as $id => $resp) {
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

// --- Rows -------------------------------------------------------------------

/**
 * @param array<string, array{customer_id?: string, buyer_email_address?: string}> $paymentInfoByOrder
 */
function build_rows(array $orders, array $variationIds, array $customerMap, array $paymentInfoByOrder = []): array
{
    $rows = [];
    foreach ($orders as $order) {
        $orderId = $order['id'] ?? '';
        $orderDate = $order['created_at'] ?? '';
        $paymentInfo = $paymentInfoByOrder[$orderId] ?? [];
        $customerId = $order['customer_id'] ?? $paymentInfo['customer_id'] ?? '';

        $address = get_shipment_address($order);
        if ($address === null && $customerId !== '') {
            $customer = $customerMap[$customerId] ?? null;
            $address = $customer['address'] ?? null;
        }

        $addr = format_address($address);
        $hasAddress = ($address !== null && trim(($address['address_line_1'] ?? '') . ($address['locality'] ?? '')) !== '') ? 'Y' : 'N';

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
        $productNameOrId = implode('; ', $productParts);
        $quantity = (string) $totalQty;

        $rows[] = array_merge([
            'order_id' => $orderId,
            'order_date' => $orderDate,
            'customer_id' => $customerId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone,
            'product_name_or_id' => $productNameOrId,
            'quantity' => $quantity,
            'has_address' => $hasAddress,
        ], $addr);
    }
    return $rows;
}

// --- Output ------------------------------------------------------------------

function output_csv(array $rows): void
{
    $cols = [
        'order_id', 'order_date', 'customer_id', 'first_name', 'last_name', 'email', 'phone',
        'address_line_1', 'address_line_2', 'locality', 'administrative_district_level_1', 'postal_code', 'country',
        'product_name_or_id', 'quantity', 'has_address',
    ];
    $fp = fopen('php://output', 'w');
    fputcsv($fp, $cols, ',', '"', '\\');
    foreach ($rows as $row) {
        $line = [];
        foreach ($cols as $c) {
            $line[] = $row[$c] ?? '';
        }
        fputcsv($fp, $line, ',', '"', '\\');
    }
    fclose($fp);
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

/** @return array<string, int> Map of item name => total quantity */
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

// --- CLI --------------------------------------------------------------------

function parse_cli(): array
{
    $short = '';
    $long = ['start:', 'end:', 'products:', 'location-id:', 'output::', 'sandbox', 'state:', 'help'];
    $opts = getopt($short, $long);
    if (isset($opts['help'])) {
        return ['help' => true];
    }
    return [
        'start' => $opts['start'] ?? null,
        'end' => $opts['end'] ?? null,
        'products' => $opts['products'] ?? null,
        'location_id' => $opts['location-id'] ?? null,
        'output' => $opts['output'] ?? 'csv',
        'sandbox' => isset($opts['sandbox']),
        'state' => $opts['state'] ?? null,
    ];
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

function main(): int
{
    load_env();

    $cli = parse_cli();
    if (!empty($cli['help'])) {
        echo <<<'HELP'
List purchasers of specific Square products within a time range.

Usage:
  php list_product_purchasers.php --start=YYYY-MM-DD --end=YYYY-MM-DD [--products=VAR_ID1,VAR_ID2] [options]

Required:
  --start=DATE       Start date (YYYY-MM-DD or RFC 3339)
  --end=DATE         End date (YYYY-MM-DD or RFC 3339)

Optional:
  --products=IDS     Comma-separated catalog item variation IDs; when omitted, all orders in the date range are included
  --location-id=ID   Override SQUARE_LOCATION_ID (comma-separated for multiple, max 10)
  --output=csv|json|report  Output format (default: csv)
  --sandbox          Use Square sandbox
  --state=COMPLETED  Filter by order state (default: all; use COMPLETED for completed only)
  --help             Show this help

Environment (or .env):
  SQUARE_ACCESS_TOKEN   Required. Square API access token.
  SQUARE_LOCATION_ID    Required if not using --location-id. One or more location IDs, comma-separated.
  SQUARE_SANDBOX=1      Use sandbox (or pass --sandbox).
  SQUARE_ORDER_URL_BASE  Base URL for order links in report (e.g. https://app.squareup.com/dashboard/orders/overview).

HELP;
        return 0;
    }

    $accessToken = getenv('SQUARE_ACCESS_TOKEN');
    if ($accessToken === false || $accessToken === '') {
        fwrite(STDERR, "Error: SQUARE_ACCESS_TOKEN is required.\n");
        return 1;
    }

    $locationId = $cli['location_id'] ?? getenv('SQUARE_LOCATION_ID');
    if ($locationId === false || $locationId === '') {
        fwrite(STDERR, "Error: SQUARE_LOCATION_ID or --location-id is required.\n");
        return 1;
    }
    $locationIds = array_map('trim', explode(',', (string) $locationId));
    $locationIds = array_values(array_filter($locationIds));
    if (empty($locationIds)) {
        fwrite(STDERR, "Error: At least one location ID is required.\n");
        return 1;
    }
    if (count($locationIds) > 10) {
        fwrite(STDERR, "Error: Maximum 10 location IDs.\n");
        return 1;
    }

    if ($cli['start'] === null || $cli['end'] === null) {
        fwrite(STDERR, "Error: --start and --end are required.\n");
        return 1;
    }

    $variationIds = array_values(array_filter(array_map('trim', explode(',', (string) ($cli['products'] ?? '')))));

    $sandbox = $cli['sandbox'] || (getenv('SQUARE_SANDBOX') !== false && getenv('SQUARE_SANDBOX') !== '');
    $baseUrl = $sandbox ? 'https://connect.squareupsandbox.com' : 'https://connect.squareup.com';

    try {
        [$startAt, $endAt] = dates_to_rfc3339($cli['start'], $cli['end']);
    } catch (Throwable $e) {
        fwrite(STDERR, "Error: Invalid date format.\n");
        return 1;
    }

    $stateFilter = null;
    if ($cli['state'] !== null && $cli['state'] !== '') {
        $stateFilter = ['states' => [strtoupper($cli['state'])]];
    }

    try {
        $orders = search_orders($baseUrl, $accessToken, $locationIds, $startAt, $endAt, $stateFilter);
    } catch (Throwable $e) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        return 1;
    }

    $orders = orders_containing_products($orders, $variationIds);
    $customerIds = [];
    foreach ($orders as $order) {
        $cid = $order['customer_id'] ?? '';
        if ($cid !== '') {
            $customerIds[] = $cid;
        }
        foreach ($order['tenders'] ?? [] as $tender) {
            $tid = $tender['customer_id'] ?? '';
            if ($tid !== '') {
                $customerIds[] = $tid;
            }
        }
    }

    $customerMap = [];
    if (!empty($customerIds)) {
        try {
            $customerMap = bulk_retrieve_customers($baseUrl, $accessToken, $customerIds);
        } catch (Throwable $e) {
            fwrite(STDERR, "Warning: Could not fetch some customers: " . $e->getMessage() . "\n");
        }
    }

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

    $paymentCustomerIds = [];
    foreach ($paymentInfoByOrder as $info) {
        $cid = $info['customer_id'] ?? '';
        if ($cid !== '' && !isset($customerMap[$cid])) {
            $paymentCustomerIds[] = $cid;
        }
    }
    if (!empty($paymentCustomerIds)) {
        try {
            $extra = bulk_retrieve_customers($baseUrl, $accessToken, $paymentCustomerIds);
            $customerMap = $customerMap + $extra;
        } catch (Throwable $e) {
            // non-fatal
        }
    }

    $rows = build_rows($orders, $variationIds, $customerMap, $paymentInfoByOrder);

    $output = $cli['output'] ?? 'csv';
    if ($output === 'json') {
        output_json($rows);
    } elseif ($output === 'report') {
        $orderUrlBase = getenv('SQUARE_ORDER_URL_BASE');
        if ($orderUrlBase === false || $orderUrlBase === '') {
            $orderUrlBase = $sandbox
                ? 'https://squareupsandbox.com/dashboard/orders/overview'
                : 'https://app.squareup.com/dashboard/orders/overview';
        }
        output_report($rows, $orderUrlBase);
    } else {
        output_csv($rows);
    }

    return 0;
}

exit(main());
