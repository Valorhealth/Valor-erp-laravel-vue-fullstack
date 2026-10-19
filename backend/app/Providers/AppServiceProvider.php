<?php

namespace App\Providers;

use App\Services\Audit\AuditLogger;
use App\Services\Notifications\InAppNotificationPublisher;
use App\Services\Notifications\WorkflowEmailNotifier;
use App\Services\Notifications\WorkflowMessageSender;
use App\Services\Notifications\WorkflowRecipientResolver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(WorkflowEmailNotifier::class, fn ($app) => new WorkflowEmailNotifier(
            $app->make(AuditLogger::class),
            $app->make(WorkflowRecipientResolver::class),
            $app->make(WorkflowMessageSender::class),
            $app->make(InAppNotificationPublisher::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
