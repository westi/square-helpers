#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/square_report_common.php';

exit(run_simple_season_report(
    $argv,
    'report_vip_bag_sales.php',
    'SQUARE_VIP_BAG_VARIATION_IDS',
    'vip_bag_sales',
    false,
    'VIP bag sales'
));
