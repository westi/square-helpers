#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/square_report_common.php';

function parse_cli(): array
{
    $short = '';
    $long = ['start:', 'end:', 'products:', 'location-id:', 'output::', 'sandbox', 'state:', 'debug', 'help'];
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
        'debug' => isset($opts['debug']),
    ];
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
  --debug            Print redacted Square API request/response logs to STDERR
  --help             Show this help

Environment (or .env):
  SQUARE_ACCESS_TOKEN   Required. Square API access token.
  SQUARE_LOCATION_ID    Required if not using --location-id. One or more location IDs, comma-separated.
  SQUARE_SANDBOX=1      Use sandbox (or pass --sandbox).
  SQUARE_ORDER_URL_BASE  Base URL for order links in report (e.g. https://app.squareup.com/dashboard/orders/overview).

HELP;
        return 0;
    }

    set_debug_enabled((bool) ($cli['debug'] ?? false));

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

    $variationIds = parse_csv_ids((string) ($cli['products'] ?? ''));

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
        $rows = fetch_orders_with_people(
            $baseUrl,
            $accessToken,
            $locationIds,
            $startAt,
            $endAt,
            $variationIds,
            $stateFilter
        );
    } catch (Throwable $e) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        return 1;
    }

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
