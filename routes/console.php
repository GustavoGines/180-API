<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Usamos los strings de las firmas directamente para evitar instanciar los comandos (y sus dependencias como Firebase) al iniciar la app
Schedule::command('app:send-tomorrow-notifications')
    ->everyFiveMinutes()
    ->appendOutputTo(storage_path('logs/laravel.log'));

Schedule::command('app:send-today-notifications')
    ->dailyAt('08:00') // <-- Se ejecuta todos los días a las 8:00 AM
    ->appendOutputTo(storage_path('logs/laravel.log'));

Schedule::command('sanctum:prune-expired')->daily();
