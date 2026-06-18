#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/square_report_common.php';

function parse_cli_options(): array
{
    $opts = getopt('', ['season::', 'location-id:', 'sandbox', 'debug', 'json', 'help']);
    if (isset($opts['help'])) {
        return ['help' => true];
    }
    return [
        'season' => $opts['season'] ?? null,
        'location_id' => $opts['location-id'] ?? null,
        'sandbox' => isset($opts['sandbox']),
        'debug' => isset($opts['debug']),
        'json' => isset($opts['json']),
        'help' => false,
    ];
}

function print_help(): void
{
    echo <<<'HELP'
Suggest report variation IDs by scanning sold line items in the show season.

Usage:
  php suggest_report_variation_ids.php [--season=YYYY] [--location-id=ID1,ID2] [--sandbox] [--debug] [--json]

Options:
  --season=YYYY      Season ending year (Dec previous year through Jul 31 this year; matches DVD window)
  --location-id=IDS  Override SQUARE_LOCATION_ID (comma-separated)
  --sandbox          Use Square sandbox API
  --debug            Print Square API debug logs to STDERR
  --json             Print machine-readable JSON output
  --help             Show this help

Environment:
  SQUARE_ACCESS_TOKEN   Required
  SQUARE_LOCATION_ID    Required unless --location-id

What it does:
  - Loads COMPLETED orders plus OPEN orders that Square reports as paid
  - Extracts line item catalog_object_id values actually seen in orders
  - Groups top IDs by keyword heuristics for:
      CAST PARTY, SOUVENIR PROGRAM, VIP BAG, DVD, SUMMER REUNION TICKET
  - Prints suggested .env lines

HELP;
}

function item_keyword_group(string $name): ?string
{
    $n = strtolower(trim($name));
    if ($n === '') {
        return null;
    }
    if (strpos($n, 'cast') !== false && strpos($n, 'party') !== false) {
        return 'cast_party';
    }
    if (strpos($n, 'souvenir') !== false || strpos($n, 'program') !== false || strpos($n, 'programme') !== false) {
        return 'souvenir_program';
    }
    if (strpos($n, 'vip') !== false && strpos($n, 'bag') !== false) {
        return 'vip_bag';
    }
    if (strpos($n, 'dvd') !== false) {
        return 'dvd';
    }
    if (strpos($n, 'summer') !== false && strpos($n, 'reunion') !== false && strpos($n, 'ticket') !== false) {
        return 'summer_reunion_ticket';
    }
    return null;
}

function main(): int
{
    load_env();
    $cli = parse_cli_options();
    if ($cli['help']) {
        print_help();
        return 0;
    }

    set_debug_enabled((bool) $cli['debug']);

    $accessToken = getenv('SQUARE_ACCESS_TOKEN');
    if ($accessToken === false || $accessToken === '') {
        fwrite(STDERR, "Error: SQUARE_ACCESS_TOKEN is required.\n");
        return 1;
    }

    try {
        $seasonEndYear = resolve_season_from_cli($cli['season']);
        $locationIds = parse_location_ids($cli['location_id']);
    } catch (Throwable $e) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        return 1;
    }

    $sandbox = (bool) $cli['sandbox'] || (getenv('SQUARE_SANDBOX') !== false && getenv('SQUARE_SANDBOX') !== '');
    $baseUrl = base_url_from_sandbox($sandbox);
    [$startAt, $endAt] = season_window_rfc3339($seasonEndYear, true);
    $orderStates = ['OPEN', 'COMPLETED'];

    try {
        $orders = search_orders(
            $baseUrl,
            $accessToken,
            $locationIds,
            $startAt,
            $endAt,
            ['states' => $orderStates]
        );
        $orders = filter_reportable_paid_orders($baseUrl, $accessToken, $orders);
    } catch (Throwable $e) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        return 1;
    }

    $byVariation = [];
    foreach ($orders as $order) {
        foreach (($order['line_items'] ?? []) as $item) {
            $catalogId = trim((string) ($item['catalog_object_id'] ?? ''));
            if ($catalogId === '') {
                continue;
            }
            if (!isset($byVariation[$catalogId])) {
                $byVariation[$catalogId] = [
                    'catalog_object_id' => $catalogId,
                    'names' => [],
                    'quantity' => 0,
                    'orders' => [],
                ];
            }
            $name = trim((string) ($item['name'] ?? ''));
            if ($name !== '') {
                $byVariation[$catalogId]['names'][$name] = true;
            }
            $byVariation[$catalogId]['quantity'] += (int) ($item['quantity'] ?? 0);
            $orderId = (string) ($order['id'] ?? '');
            if ($orderId !== '') {
                $byVariation[$catalogId]['orders'][$orderId] = true;
            }
        }
    }

    $rows = [];
    foreach ($byVariation as $id => $entry) {
        $names = array_keys($entry['names']);
        sort($names);
        $rows[] = [
            'catalog_object_id' => $id,
            'names' => $names,
            'quantity' => $entry['quantity'],
            'order_count' => count($entry['orders']),
        ];
    }
    usort($rows, static function (array $a, array $b): int {
        return $b['quantity'] <=> $a['quantity'];
    });

    $groups = [
        'cast_party' => [],
        'souvenir_program' => [],
        'vip_bag' => [],
        'dvd' => [],
        'summer_reunion_ticket' => [],
    ];
    foreach ($rows as $row) {
        $allNames = $row['names'];
        $group = null;
        foreach ($allNames as $name) {
            $group = item_keyword_group($name);
            if ($group !== null) {
                break;
            }
        }
        if ($group !== null) {
            $groups[$group][] = $row;
        }
    }

    $suggestions = [
        'SQUARE_CAST_PARTY_VARIATION_IDS' => implode(',', array_map(static fn(array $r): string => $r['catalog_object_id'], $groups['cast_party'])),
        'SQUARE_SOUVENIR_PROGRAM_VARIATION_IDS' => implode(',', array_map(static fn(array $r): string => $r['catalog_object_id'], $groups['souvenir_program'])),
        'SQUARE_VIP_BAG_VARIATION_IDS' => implode(',', array_map(static fn(array $r): string => $r['catalog_object_id'], $groups['vip_bag'])),
        'SQUARE_DVD_VARIATION_IDS' => implode(',', array_map(static fn(array $r): string => $r['catalog_object_id'], $groups['dvd'])),
        'SQUARE_SUMMER_REUNION_TICKET_VARIATION_IDS' => implode(',', array_map(static fn(array $r): string => $r['catalog_object_id'], $groups['summer_reunion_ticket'])),
    ];

    if ($cli['json']) {
        echo json_encode([
            'season_end_year' => $seasonEndYear,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'order_states' => $orderStates,
            'total_orders' => count($orders),
            'distinct_variations' => count($rows),
            'variations' => $rows,
            'suggestions' => $suggestions,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        return 0;
    }

    echo "Season window: " . $startAt . " .. " . $endAt . "\n";
    echo "Order states scanned: " . implode(', ', $orderStates) . "\n";
    echo "Orders scanned: " . count($orders) . "\n";
    echo "Distinct sold variation IDs: " . count($rows) . "\n\n";

    echo "Top sold variation IDs:\n";
    $top = array_slice($rows, 0, 30);
    foreach ($top as $row) {
        $displayName = $row['names'][0] ?? '(no name)';
        $extra = count($row['names']) > 1 ? ' (+' . (count($row['names']) - 1) . ' alt names)' : '';
        echo "  - " . $row['catalog_object_id'] . " | qty=" . $row['quantity'] . " | orders=" . $row['order_count'] . " | " . $displayName . $extra . "\n";
    }
    echo "\n";

    echo "Suggested .env configuration:\n";
    foreach ($suggestions as $key => $value) {
        echo $key . "=" . $value . "\n";
    }
    echo "\n";

    echo "Review notes:\n";
    echo "  - Suggestions are keyword-based from line item names.\n";
    echo "  - If any value is empty, no matching items were found by heuristic.\n";
    echo "  - Use --json for full detail, then copy exact IDs into .env.\n";

    return 0;
}

exit(main());

