<?php

namespace App\Providers;

use App\Models\Company;
use App\Models\DeadlineExtensionRequest;
use App\Models\Task;
use App\Models\User;
use App\Policies\CompanyPolicy;
use App\Policies\DeadlineExtensionRequestPolicy;
use App\Policies\TaskPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
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
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(DeadlineExtensionRequest::class, DeadlineExtensionRequestPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);

        View::composer('components.navbar', function ($view): void {
            $user = Auth::user();
            $notifications = $user?->notifications()->latest()->limit(5)->get() ?? collect();

            $view->with('notifications', $notifications)
                ->with('unreadNotificationCount', $user?->unreadNotifications()->count() ?? 0);
        });
    }
}
