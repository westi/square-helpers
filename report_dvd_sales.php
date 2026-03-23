#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/square_report_common.php';

function parse_dvd_cli(): array
{
    $opts = getopt('', ['season::', 'location-id:', 'sandbox', 'stdout', 'debug', 'help']);
    if (isset($opts['help'])) {
        return ['help' => true];
    }
    return [
        'season' => $opts['season'] ?? null,
        'location_id' => $opts['location-id'] ?? null,
        'sandbox' => isset($opts['sandbox']),
        'stdout' => isset($opts['stdout']),
        'debug' => isset($opts['debug']),
        'help' => false,
    ];
}

function dvd_key_from_row(array $row): string
{
    $customerId = trim((string) ($row['customer_id'] ?? ''));
    if ($customerId !== '') {
        return 'cid:' . $customerId;
    }
    $name = trim(trim((string) ($row['first_name'] ?? '')) . ' ' . trim((string) ($row['last_name'] ?? '')));
    $email = trim((string) ($row['email'] ?? ''));
    $phone = trim((string) ($row['phone'] ?? ''));
    return 'id:' . strtolower($name . '|' . $email . '|' . $phone);
}

function dvd_order_quantities(array $orders, array $singleIds, array $bundleIds): array
{
    $singleSet = array_flip($singleIds);
    $bundleSet = array_flip($bundleIds);
    $out = [];
    foreach ($orders as $order) {
        $orderId = (string) ($order['id'] ?? '');
        if ($orderId === '') {
            continue;
        }
        $singleQty = 0;
        $bundleQty = 0;
        foreach (($order['line_items'] ?? []) as $item) {
            $catalogId = (string) ($item['catalog_object_id'] ?? '');
            $qty = (int) ($item['quantity'] ?? 0);
            if ($catalogId === '' || $qty <= 0) {
                continue;
            }
            if (isset($singleSet[$catalogId])) {
                $singleQty += $qty;
            } elseif (isset($bundleSet[$catalogId])) {
                $bundleQty += $qty;
            }
        }
        $out[$orderId] = [
            'dvd_single_qty' => $singleQty,
            'dvd_bundle_qty' => $bundleQty,
        ];
    }
    return $out;
}

function main(): int
{
    load_env();
    $cli = parse_dvd_cli();
    if ($cli['help']) {
        echo "Usage:\n";
        echo "  php report_dvd_sales.php [--season=YYYY] [--location-id=ID1,ID2] [--sandbox] [--stdout] [--debug]\n\n";
        echo "Environment:\n";
        echo "  SQUARE_ACCESS_TOKEN (required)\n";
        echo "  SQUARE_LOCATION_ID (required unless --location-id)\n";
        echo "  SQUARE_DVD_VARIATION_IDS (required, comma-separated)\n";
        echo "  SQUARE_DVD_BUNDLE_VARIATION_IDS (optional, comma-separated)\n";
        return 0;
    }

    set_debug_enabled((bool) $cli['debug']);

    $token = getenv('SQUARE_ACCESS_TOKEN');
    if ($token === false || $token === '') {
        fwrite(STDERR, "Error: SQUARE_ACCESS_TOKEN is required.\n");
        return 1;
    }

    $singleIds = parse_csv_ids((string) getenv('SQUARE_DVD_VARIATION_IDS'));
    $bundleIds = parse_csv_ids((string) getenv('SQUARE_DVD_BUNDLE_VARIATION_IDS'));
    $allDvdIds = array_values(array_unique(array_merge($singleIds, $bundleIds)));
    if (empty($allDvdIds)) {
        fwrite(STDERR, "Error: Set SQUARE_DVD_VARIATION_IDS and/or SQUARE_DVD_BUNDLE_VARIATION_IDS.\n");
        return 1;
    }

    try {
        $season = resolve_season_from_cli($cli['season']);
        $locationIds = parse_location_ids($cli['location_id']);
    } catch (Throwable $e) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        return 1;
    }

    $sandbox = $cli['sandbox'] || (getenv('SQUARE_SANDBOX') !== false && getenv('SQUARE_SANDBOX') !== '');
    $baseUrl = base_url_from_sandbox($sandbox);
    [$startAt, $endAt] = season_window_rfc3339($season);

    try {
        $orders = search_orders($baseUrl, $token, $locationIds, $startAt, $endAt, ['states' => ['COMPLETED']]);
        $orders = orders_containing_products($orders, $allDvdIds);

        $customerMap = [];
        $customerIds = collect_customer_ids($orders);
        if (!empty($customerIds)) {
            try {
                $customerMap = bulk_retrieve_customers($baseUrl, $token, $customerIds);
            } catch (Throwable $e) {
                fwrite(STDERR, "Warning: Could not fetch some customers: " . $e->getMessage() . "\n");
            }
        }

        $paymentInfoByOrder = build_payment_info_by_order($baseUrl, $token, $orders, $customerMap);
        $customerMap = hydrate_customers_from_payment_info($baseUrl, $token, $customerMap, $paymentInfoByOrder);
        $rows = build_rows($orders, $allDvdIds, $customerMap, $paymentInfoByOrder);
    } catch (Throwable $e) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        return 1;
    }

    $orderQty = dvd_order_quantities($orders, $singleIds, $bundleIds);
    $aggregated = [];
    foreach ($rows as $row) {
        $key = dvd_key_from_row($row);
        $q = $orderQty[$row['order_id'] ?? ''] ?? ['dvd_single_qty' => 0, 'dvd_bundle_qty' => 0];

        if (!isset($aggregated[$key])) {
            $aggregated[$key] = [
                'full_name' => trim(trim((string) ($row['first_name'] ?? '')) . ' ' . trim((string) ($row['last_name'] ?? ''))),
                'email' => trim((string) ($row['email'] ?? '')),
                'phone' => trim((string) ($row['phone'] ?? '')),
                'dvd_single_qty' => 0,
                'dvd_bundle_qty' => 0,
                'quantity' => 0,
                'address_line_1' => $row['address_line_1'] ?? '',
                'address_line_2' => $row['address_line_2'] ?? '',
                'locality' => $row['locality'] ?? '',
                'administrative_district_level_1' => $row['administrative_district_level_1'] ?? '',
                'postal_code' => $row['postal_code'] ?? '',
                'country' => $row['country'] ?? '',
                'address_source' => $row['address_source'] ?? 'none',
            ];
        } elseif (($aggregated[$key]['address_source'] ?? 'none') !== 'order_shipping' && (($row['address_source'] ?? 'none') === 'order_shipping')) {
            $aggregated[$key]['address_line_1'] = $row['address_line_1'] ?? '';
            $aggregated[$key]['address_line_2'] = $row['address_line_2'] ?? '';
            $aggregated[$key]['locality'] = $row['locality'] ?? '';
            $aggregated[$key]['administrative_district_level_1'] = $row['administrative_district_level_1'] ?? '';
            $aggregated[$key]['postal_code'] = $row['postal_code'] ?? '';
            $aggregated[$key]['country'] = $row['country'] ?? '';
            $aggregated[$key]['address_source'] = $row['address_source'] ?? 'none';
        }

        $aggregated[$key]['dvd_single_qty'] += (int) $q['dvd_single_qty'];
        $aggregated[$key]['dvd_bundle_qty'] += (int) $q['dvd_bundle_qty'];
        $aggregated[$key]['quantity'] += (int) $row['quantity'];
    }

    $rowsOut = array_values($aggregated);
    usort($rowsOut, static function (array $a, array $b): int {
        return strcmp(($a['full_name'] ?? '') . ($a['email'] ?? ''), ($b['full_name'] ?? '') . ($b['email'] ?? ''));
    });

    $columns = [
        'full_name',
        'email',
        'phone',
        'dvd_single_qty',
        'dvd_bundle_qty',
        'quantity',
        'address_line_1',
        'address_line_2',
        'locality',
        'administrative_district_level_1',
        'postal_code',
        'country',
        'address_source',
    ];

    if ($cli['stdout']) {
        output_csv_to_stream($rowsOut, $columns, fopen('php://output', 'w'));
        return 0;
    }

    $reportsDir = getcwd() . '/reports';
    try {
        ensure_reports_dir($reportsDir);
        $path = $reportsDir . '/dvd_sales_season_' . $season . '.csv';
        write_csv_file($path, $columns, $rowsOut);
    } catch (Throwable $e) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        return 1;
    }

    echo "Wrote " . count($rowsOut) . " row(s) to " . $path . "\n";
    return 0;
}

exit(main());
