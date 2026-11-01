<?php

namespace App\Providers;

use App\Domain\Access\AccessResolver;
use App\Domain\Automation\Thresholds;
use App\Domain\Billing\BillingProvider;
use App\Domain\Billing\ManualBillingProvider;
use App\Domain\Calls\CallProviderRegistry;
use App\Domain\Calls\Providers\ManualCallProvider;
use App\Domain\Calls\Providers\TwilioCallProvider;
use App\Domain\Git\GitHub\GitHubClient;
use App\Domain\Git\GitHub\GitHubProvider;
use App\Domain\Git\GitProviderRegistry;
use App\Events\Contracts\LeadDomainEvent;
use App\Http\Controllers\Api\V1\WhatsappWebhookController;
use App\Listeners\BroadcastLeadChange;
use App\Listeners\DispatchOutboundWebhooks;
use App\Listeners\RecordConversionOutcome;
use App\Listeners\RecordMarketingEvents;
use App\Listeners\RunAutomationRules;
use App\Models\ApiKey;
use App\Models\AutomationRule;
use App\Models\Company;
use App\Models\FollowUp;
use App\Models\Import;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Partner;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TrackingLink;
use App\Models\User;
use App\Policies\AutomationRulePolicy;
use App\Policies\CompanyPolicy;
use App\Policies\FollowUpPolicy;
use App\Policies\ImportPolicy;
use App\Policies\IntegrationPolicy;
use App\Policies\LeadPolicy;
use App\Policies\MessageTemplatePolicy;
use App\Policies\PartnerPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\TenantSettingsPolicy;
use App\Policies\TrackingLinkPolicy;
use App\Policies\UserPolicy;
use App\Support\CompanyContext;
use App\Support\ProjectContext;
use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(CompanyContext::class);
        $this->app->singleton(ProjectContext::class);

        // Manual until a payment provider is configured; the subscription model does not change when one is.
        $this->app->bind(BillingProvider::class, fn () => match (config('billing.provider')) {
            default => new ManualBillingProvider,
        });

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
        // A queue worker lives for days: judge each job by the lines as they are now.
        // And start each job from no company at all: a job names its own tenant (withTenant), it never inherits the
        // previous job's — nor a permission resolved for somebody whose role has since changed.
        // (A `sync` job runs inside the request that dispatched it and keeps that request's company.)
        Queue::before(function (JobProcessing $event) {
            Thresholds::flush();
            AccessResolver::flush();

            if ($event->connectionName !== 'sync') {
                app(TenantContext::class)->clear();
                app(ProjectContext::class)->clear();
            }
        });

        /*
         * One listener for every lead event. Registered on the *interface*:
         * Laravel resolves listeners through interfaces but not through parent
         * classes, so binding to the abstract base would catch nothing and do
         * it silently — the hardest kind of bug to notice in an automation
         * product. This way a new event type is wired up by implementing it.
         */
        Event::listen(LeadDomainEvent::class, RunAutomationRules::class);
        Event::listen(LeadDomainEvent::class, RecordConversionOutcome::class);
        Event::listen(LeadDomainEvent::class, RecordMarketingEvents::class);
        Event::listen(LeadDomainEvent::class, BroadcastLeadChange::class);
        Event::listen(LeadDomainEvent::class, DispatchOutboundWebhooks::class);

        // Mass-assignment protection stays on; we also fail loudly in dev when a
        // relation is accessed without eager loading, so N+1s never reach staging.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        /*
         * The specialised roles are decided by the permission matrix, once, at the route
         * (EnforcePermissions) — by the time a policy is asked, the request has already been
         * granted its area and verb. Their policies are therefore not asked to guess at roles
         * they were never written for: without this a sales manager who is granted `leads.edit`
         * would be refused by a policy that only knows "admin, manager or the lead's owner".
         */
        Gate::before(fn ($user) => $user instanceof User && $user->usesMatrix() ? true : null);

        Gate::policy(AutomationRule::class, AutomationRulePolicy::class);
        Gate::policy(Lead::class, LeadPolicy::class);
        Gate::policy(Partner::class, PartnerPolicy::class);
        Gate::policy(TrackingLink::class, TrackingLinkPolicy::class);
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
        /*
         * The public landing page: each kind of request has an allowance of its own, per visitor (address) and per page. A numeric
         * `throttle:N,M` shares one counter between every route it is put on, so a page's views and a form's step reports would
         * spend the same few submits — and the customer the button was for would be told to slow down.
         */
        RateLimiter::for('landing-view', fn (Request $request) => Limit::perMinute(120)->by('lv:'.$request->ip().':'.$request->route('token')));
        RateLimiter::for('landing-beacon', fn (Request $request) => Limit::perMinute(240)->by('lb:'.$request->ip().':'.$request->route('token')));
        RateLimiter::for('landing-submit', fn (Request $request) => Limit::perMinute(30)->by('ls:'.$request->ip().':'.$request->route('token')));
        RateLimiter::for('landing-asset', fn (Request $request) => Limit::perMinute(240)->by('la:'.$request->ip()));

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(config('integrations.login.per_email_per_minute'))
                ->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(config('integrations.login.per_ip_per_minute'))
                ->by($request->ip()),
        ]);

        // The public API, per key (by its hash, so the limiter's store never holds a usable key), else per address.
        /*
         * Per key, at the key's own rate when the company set one (a nightly sync and a live widget do not need the
         * same ceiling), else 120 a minute. The rate is remembered for a minute so the limiter never costs a query
         * per call; an unknown token is limited by address, at the default.
         */
        RateLimiter::for('api-key', function (Request $request) {
            $token = $request->bearerToken();
            $hash = $token ? hash('sha256', $token) : null;

            /*
             * A token that is not a key gets no bucket of its own — otherwise a stream of random tokens would each
             * start fresh and never meet a limit — and is not cached, so it cannot fill the cache either. It is held
             * to the address's bucket at the default rate, which also bounds the lookups it costs.
             */
            $rate = $hash === null ? null : Cache::get('api-key-rate:'.$hash);

            if ($hash !== null && $rate === null) {
                $found = ApiKey::withoutGlobalScopes()->where('key_hash', $hash)->first(['rate_limit_per_minute']);

                if ($found !== null) {
                    $rate = (int) ($found->rate_limit_per_minute ?: ApiKey::DEFAULT_RATE);
                    Cache::put('api-key-rate:'.$hash, $rate, 60);
                }
            }

            return $rate === null
                ? Limit::perMinute(ApiKey::DEFAULT_RATE)->by('ip:'.$request->ip())
                : Limit::perMinute((int) $rate)->by('key:'.$hash);
        });

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
