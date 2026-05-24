<?php

namespace App\Providers;

use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

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
        if ($this->app->environment('production')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        // So `php artisan serve`'s inner `php -S` process inherits upload limits (see scripts/serve-mobile.sh + dev-php-ini.d).
        if (! in_array('PHP_INI_SCAN_DIR', ServeCommand::$passthroughVariables, true)) {
            ServeCommand::$passthroughVariables[] = 'PHP_INI_SCAN_DIR';
        }

        // Multipart venue uploads: allow Sanctum token in a form field when Authorization is stripped (common on mobile).
        Sanctum::$accessTokenRetrievalCallback = function (Request $request): ?string {
            $header = $request->bearerToken();
            if (is_string($header) && $header !== '') {
                return $header;
            }

            $body = $request->input('sanctum_plain_token');
            if (! is_string($body)) {
                return null;
            }

            $trimmed = trim($body);

            return $trimmed !== '' ? $trimmed : null;
        };
    }
}
