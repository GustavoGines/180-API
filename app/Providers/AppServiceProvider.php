<?php

namespace App\Providers;

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

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

        Gate::define('admin', function (User $user) {
            return $user->role === 'admin';
        });
    }
}
