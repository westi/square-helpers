#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/square_report_common.php';

exit(run_simple_season_report(
    $argv,
    'report_summer_reunion_ticket_doorlist.php',
    'SQUARE_SUMMER_REUNION_TICKET_VARIATION_IDS',
    'summer_reunion_ticket_doorlist',
    false,
    'Summer Reunion ticket doorlist',
    true,
    ['OPEN', 'COMPLETED'],
    true,
    true,
    true
));
