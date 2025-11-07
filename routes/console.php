<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Console\Commands\SendTomorrowOrderNotifications;
use App\Console\Commands\SendTodayOrderNotifications;
use Illuminate\Support\Facades\Schedule; 

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(SendTomorrowOrderNotifications::class) 
    ->everyFiveMinutes()
    ->appendOutputTo(storage_path('logs/laravel.log'));

Schedule::command(SendTodayOrderNotifications::class)
    ->dailyAt('08:00') // <-- Se ejecuta todos los días a las 8:00 AM
    ->appendOutputTo(storage_path('logs/laravel.log'));


Schedule::command('sanctum:prune-expired')->daily();