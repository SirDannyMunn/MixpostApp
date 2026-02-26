<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Laravel\Passport\Passport;
// Passport v13 requires setting which views to render for authorization.

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        \App\Models\Organization::class => \App\Policies\OrganizationPolicy::class,
        \App\Models\Bookmark::class => \App\Policies\BookmarkPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // Policies are auto-registered from $policies
        Gate::policy(\App\Models\Template::class, \App\Policies\TemplatePolicy::class);
        Gate::policy(\App\Models\MediaPack::class, \App\Policies\MediaPackPolicy::class);
        Gate::policy(\App\Models\MediaImage::class, \App\Policies\MediaImagePolicy::class);
        Gate::policy(\App\Models\Project::class, \App\Policies\ProjectPolicy::class);
        Gate::policy(\App\Models\SocialAccount::class, \App\Policies\SocialAccountPolicy::class);
        Gate::policy(\App\Models\Account::class, \App\Policies\AccountPolicy::class);
        Gate::policy(\App\Models\ScheduledPost::class, \App\Policies\ScheduledPostPolicy::class);

        // Configure Passport expiry (routes are auto-registered in v13+)
        Passport::tokensExpireIn(now()->addHours(1));
        Passport::refreshTokensExpireIn(now()->addDays(30));

        // Register the authorization view using our app's blade view
        Passport::authorizationView('passport.authorize');
    }
}
