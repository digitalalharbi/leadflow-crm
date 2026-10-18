<?php

namespace App\Providers;

use App\Domain\Calls\CallProviderRegistry;
use App\Domain\Calls\Providers\ManualCallProvider;
use App\Domain\Calls\Providers\TwilioCallProvider;
use App\Domain\Git\GitHub\GitHubClient;
use App\Domain\Git\GitHub\GitHubProvider;
use App\Domain\Git\GitProviderRegistry;
use App\Events\Contracts\LeadDomainEvent;
use App\Http\Controllers\Api\V1\WhatsappWebhookController;
use App\Listeners\BroadcastLeadChange;
use App\Listeners\RecordConversionOutcome;
use App\Listeners\RunAutomationRules;
use App\Models\AutomationRule;
use App\Models\Company;
use App\Models\FollowUp;
use App\Models\Import;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\AutomationRulePolicy;
use App\Policies\CompanyPolicy;
use App\Policies\FollowUpPolicy;
use App\Policies\ImportPolicy;
use App\Policies\IntegrationPolicy;
use App\Policies\LeadPolicy;
use App\Policies\MessageTemplatePolicy;
use App\Policies\ProjectPolicy;
use App\Policies\TenantSettingsPolicy;
use App\Policies\UserPolicy;
use App\Support\CompanyContext;
use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(CompanyContext::class);

        /*
         * One client, one registry, for every code host. Bound here rather than
         * resolved where they are used so that the base URL comes from config —
         * which is what lets the tests point GitHub at a fake without any class
         * knowing it is being tested.
         */
        $this->app->singleton(GitHubClient::class, fn () => new GitHubClient(
            (string) config('services.github.api_url', 'https://api.github.com'),
        ));

        $this->app->singleton(CallProviderRegistry::class, function ($app) {
            $registry = new CallProviderRegistry;
            // The one that places no calls, and the fallback for every other.
            $registry->register($app->make(ManualCallProvider::class));
            // A real one, which is used only for a workspace that has given it
            // credentials — see CallProviderRegistry::forTenant.
            $registry->register($app->make(TwilioCallProvider::class));

            return $registry;
        });

        $this->app->singleton(GitProviderRegistry::class, function ($app) {
            $registry = new GitProviderRegistry;
            $registry->register($app->make(GitHubProvider::class));

            return $registry;
        });
    }

    public function boot(): void
    {
        /*
         * One listener for every lead event. Registered on the *interface*:
         * Laravel resolves listeners through interfaces but not through parent
         * classes, so binding to the abstract base would catch nothing and do
         * it silently — the hardest kind of bug to notice in an automation
         * product. This way a new event type is wired up by implementing it.
         */
        Event::listen(LeadDomainEvent::class, RunAutomationRules::class);
        Event::listen(LeadDomainEvent::class, RecordConversionOutcome::class);
        Event::listen(LeadDomainEvent::class, BroadcastLeadChange::class);

        // Mass-assignment protection stays on; we also fail loudly in dev when a
        // relation is accessed without eager loading, so N+1s never reach staging.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Gate::policy(AutomationRule::class, AutomationRulePolicy::class);
        Gate::policy(Lead::class, LeadPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(FollowUp::class, FollowUpPolicy::class);
        Gate::policy(Integration::class, IntegrationPolicy::class);
        Gate::policy(Import::class, ImportPolicy::class);
        Gate::policy(MessageTemplate::class, MessageTemplatePolicy::class);
        Gate::policy(Tenant::class, TenantSettingsPolicy::class);

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(
            config('integrations.api.per_minute')
        )->by($request->user()?->id ?: $request->ip()));

        // Credential stuffing guard: slow, and keyed on email + IP.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(config('integrations.login.per_email_per_minute'))
                ->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(config('integrations.login.per_ip_per_minute'))
                ->by($request->ip()),
        ]);

        RateLimiter::for('webhook', fn (Request $request) => Limit::perMinute(
            config('integrations.webhooks.rate_limit_per_minute')
        )->by($request->route('key').'|'.$request->ip()));

        /*
         * WhatsApp callbacks need their own limiter, keyed on the phone number
         * the delivery arrived on rather than on a route parameter this route
         * does not have — `route('key')` was null here, which collapsed every
         * tenant into one bucket per source IP. Meta delivers from a narrow
         * address range, so one busy workspace would have starved all the
         * others and Meta would eventually disable the callback.
         *
         * The outer per-IP limit is far looser, and exists only to bound an
         * unauthenticated flood from a single source.
         */
        RateLimiter::for('whatsapp-webhook', fn (Request $request) => [
            Limit::perMinute(config('integrations.whatsapp.per_number_per_minute'))
                ->by('wa:'.WhatsappWebhookController::phoneNumberIdOf($request)),
            Limit::perMinute(config('integrations.whatsapp.per_ip_per_minute'))
                ->by('wa-ip:'.$request->ip()),
        ]);
    }
}
