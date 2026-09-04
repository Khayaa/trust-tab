<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * MoMo delivers a callback once with no retry, so a dropped one would leave a
 * payment pending forever. Overlap protection matters because a slow MoMo would
 * otherwise stack runs that all ask about the same payments.
 */
Schedule::command('payments:reconcile-pending')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer();
