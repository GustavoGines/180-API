<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DB::statement("SET TIME ZONE 'America/Argentina/Buenos_Aires'");

        Carbon::serializeUsing(function (Carbon $c) {
            return $c->setTimezone(config('app.timezone', 'America/Argentina/Buenos_Aires'))
                     ->format('Y-m-d\TH:i:sP'); // ej: 2025-10-12T19:45:48-03:00
        });
    
        CarbonImmutable::serializeUsing(function (CarbonImmutable $c) {
            return $c->setTimezone(config('app.timezone', 'America/Argentina/Buenos_Aires'))
                     ->format('Y-m-d\TH:i:sP');
        });

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });
    }
}
