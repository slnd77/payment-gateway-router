<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('subscriptions:sync')->hourly();

if (config('demo.enabled')) {
    Schedule::command('demo:reset')->cron(config('demo.reset_cron'))->withoutOverlapping();
}
