<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('tasks:generate-recurring-occurrences')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->appendOutputTo(storage_path('logs/recurring-task-generation.log'));

Schedule::command('daily-accountability:process')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->appendOutputTo(storage_path('logs/daily-accountability.log'));
