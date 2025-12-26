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
        // DB::statement("SET TIME ZONE 'America/Argentina/Buenos_Aires'");

        // DB::statement("SET TIME ZONE 'America/Argentina/Buenos_Aires'");

        $dateSerializer = function ($c) {
            return $c->setTimezone(config('app.timezone', 'America/Argentina/Buenos_Aires'))
                ->format('Y-m-d\TH:i:sP');
        };

        Carbon::serializeUsing($dateSerializer);
        CarbonImmutable::serializeUsing($dateSerializer);

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000'); // <-- Ponemos un valor por defecto para pruebas locales

            return $frontendUrl.'?email='.$notifiable->getEmailForPasswordReset().'&token='.$token;
        });

        Gate::define('admin', function (User $user) {
            return $user->role === 'admin';
        });

        Gate::define('manage-orders', function (User $user) {
            // Permite la acción si el rol del usuario es 'admin' o 'staff'
            return in_array($user->role, ['admin', 'staff']);
        });

    }
}
