<?php

namespace App\Providers;

use App\Models\FollowUp;
use App\Models\Import;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\FollowUpPolicy;
use App\Policies\ImportPolicy;
use App\Policies\IntegrationPolicy;
use App\Policies\LeadPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\TenantSettingsPolicy;
use App\Policies\UserPolicy;
use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        // Mass-assignment protection stays on; we also fail loudly in dev when a
        // relation is accessed without eager loading, so N+1s never reach staging.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Gate::policy(Lead::class, LeadPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(FollowUp::class, FollowUpPolicy::class);
        Gate::policy(Integration::class, IntegrationPolicy::class);
        Gate::policy(Import::class, ImportPolicy::class);
        Gate::policy(Tenant::class, TenantSettingsPolicy::class);

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        // Credential stuffing guard: slow, and keyed on email + IP.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        RateLimiter::for('webhook', fn (Request $request) => Limit::perMinute(
            config('integrations.webhooks.rate_limit_per_minute')
        )->by($request->route('key').'|'.$request->ip()));
    }
}
