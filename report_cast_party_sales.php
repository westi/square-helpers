#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/square_report_common.php';

exit(run_simple_season_report(
    $argv,
    'report_cast_party_sales.php',
    'SQUARE_CAST_PARTY_VARIATION_IDS',
    'cast_party_sales',
    false,
    'Cast Party ticket sales'
));
