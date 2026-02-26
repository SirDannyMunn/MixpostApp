<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\DevAutoAuth;
use App\Models\Folder;
use App\Observers\FolderObserver;
use App\Services\Ai\Research\ResearchExecutor;
use App\Services\Ai\Research\Sources\SocialWatcherResearchGateway;
use LaundryOS\TalkingHead\Contracts\ImageProvider;
use LaundryOS\TalkingHead\Services\HostAppImageProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ImageProvider::class, HostAppImageProvider::class);
        
        // Register ResearchExecutor with canonical gateway
        $this->app->when(ResearchExecutor::class)
            ->needs(SocialWatcherResearchGateway::class)
            ->give(function ($app) {
                return $app->make(SocialWatcherResearchGateway::class);
            });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (App::environment('local')) {
            // Ensure DevAutoAuth is applied automatically to the API group in local
            $router = app('router');
            // $router->pushMiddlewareToGroup('api', DevAutoAuth::class);
        }

        Folder::observe(FolderObserver::class);
    }
}
