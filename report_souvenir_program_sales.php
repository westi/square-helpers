#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/square_report_common.php';

exit(run_simple_season_report(
    $argv,
    'report_souvenir_program_sales.php',
    'SQUARE_SOUVENIR_PROGRAM_VARIATION_IDS',
    'souvenir_program_sales',
    false,
    'Souvenir program sales'
));
