<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        Horizon::auth(function ($request) {
            $configured = (string) env('HORIZON_PLOI_TOKEN', '');
            if ($configured !== '') {
                $provided = $request->bearerToken()
                    ?? $request->header('X-Horizon-Token')
                    ?? $request->query('token');

                if (is_string($provided) && hash_equals($configured, $provided)) {
                    return true;
                }
            }

            return app()->environment('local') || Gate::check('viewHorizon', [$request->user()]);
        });

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');

        // Horizon::night();
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user) {
            return $user !== null;
        });
    }
}
