<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\EmailDelivery;
use App\Models\Lead;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use App\Support\Totp;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The first client's whole path on this server, through the real routes and the real database, inside one transaction that
 * is always rolled back. Nothing it writes is ever seen by anybody else, and it talks to nobody: mail is only written down,
 * jobs run here and now, nothing is broadcast, and what waits for a commit never runs. No HTTP edge is involved, and no
 * mail is delivered: this proves the application and its database, and says so.
 */
class ProductionSmoke extends Command
{
    protected $signature = 'mohtam:production-smoke';

    protected $description = "Walk the first client's path on this server, then roll all of it back";

    private string $step = 'start';

    public function handle(): int
    {
        config(['leadflow_mail.provider' => 'log', 'queue.default' => 'sync', 'broadcasting.default' => 'null', 'mail.default' => 'log']);

        $before = [Tenant::withoutGlobalScopes()->count(), User::withoutGlobalScopes()->count()];
        $rollback = new RuntimeException('rollback');

        try {
            DB::transaction(function () use ($rollback) {
                $this->walk();

                throw $rollback;
            });
        } catch (\Throwable $e) {
            if ($e !== $rollback) {
                $this->line("FIRST_CLIENT_SMOKE=FAIL at {$this->step}: ".Str::limit($e->getMessage(), 300));

                return self::FAILURE;
            }
        }

        if ($before !== [Tenant::withoutGlobalScopes()->count(), User::withoutGlobalScopes()->count()]) {
            $this->line('FIRST_CLIENT_SMOKE=FAIL: something outlived the rollback.');

            return self::FAILURE;
        }

        $this->line('FIRST_CLIENT_SMOKE=PASS (rolled back; nothing kept)');

        return self::SUCCESS;
    }

    private function walk(): void
    {
        $run = Str::lower(Str::random(10));
        $ownerEmail = "owner-{$run}@smoke.invalid";
        $repEmail = "rep-{$run}@smoke.invalid";
        $password = Str::password(24);

        $this->step('company and admin');
        $platform = User::create(['tenant_id' => null, 'name' => 'Smoke', 'email' => "platform-{$run}@smoke.invalid", 'password' => Str::password(32), 'role' => Role::SuperAdmin, 'is_active' => true]);
        $platform->forceFill(['mfa_secret' => Totp::generateSecret(), 'mfa_enabled_at' => now()])->save();
        $created = $this->api('POST', '/super-admin/tenants', ['company_name' => "Smoke {$run}", 'subdomain' => "smoke-{$run}", 'admin_name' => 'Smoke Owner', 'admin_email' => $ownerEmail, 'admin_password' => $password], $platform->createToken('platform', ['platform'], now()->addHour())->plainTextToken);
        $tenant = Tenant::withoutGlobalScopes()->where('uuid', $created['tenant']['id'])->firstOrFail();

        $this->step('login');
        $owner = $this->api('POST', '/auth/login', ['email' => $ownerEmail, 'password' => $password])['token'];

        $this->step('logout');
        $this->api('POST', '/auth/logout', [], $owner);

        $this->step('forgot and reset password');
        $this->api('POST', '/auth/password/forgot', ['email' => $ownerEmail]);
        $password = Str::password(24);
        $this->api('POST', '/auth/password/reset', ['token' => $this->tokenIn($ownerEmail, 'reset-password?token='), 'password' => $password, 'password_confirmation' => $password]);
        $owner = $this->api('POST', '/auth/login', ['email' => $ownerEmail, 'password' => $password])['token'];

        $this->step('email verification');
        DB::table('users')->where('email', $ownerEmail)->update(['email_verified_at' => null]);
        $this->api('POST', '/auth/email/verification', [], $owner);
        $html = (string) EmailDelivery::withoutGlobalScopes()->where('to_email', $ownerEmail)->latest('id')->value('html');
        preg_match('#verify-email\?token=([^"&]+)#', $html, $m) || throw new RuntimeException('No verification link in the email.');
        $this->api('POST', '/auth/email/verify', ['token' => html_entity_decode(urldecode($m[1])), 'password' => $password]);

        $this->step('project');
        $project = $this->api('POST', '/projects', ['name' => 'Smoke project'], $owner)['project']['id'];

        $this->step('invitation and team');
        $link = $this->api('POST', '/invitations', ['name' => 'Smoke Rep', 'email' => $repEmail, 'role' => 'sales', 'project_ids' => [$project], 'all_projects' => false], $owner)['link'] ?? throw new RuntimeException('No link was handed over.');
        $rep = $this->api('POST', '/public/invitations/'.basename((string) $link).'/accept', ['name' => 'Smoke Rep', 'password' => $password, 'password_confirmation' => $password])['token'];

        $this->step('second factor');
        $secret = $this->api('POST', '/auth/security/mfa/setup', [], $rep)['secret'];
        $this->api('POST', '/auth/security/mfa/confirm', ['code' => Totp::code($secret)], $rep);

        $this->step('lead source and landing page');
        $slug = "smoke-{$run}";
        $form = $this->api('POST', '/tracking/forms', [
            'name' => 'Smoke page', 'project_id' => $project, 'slug' => $slug, 'headline' => 'Smoke', 'consent_required' => false,
            'fields' => [['key' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true, 'mapping' => 'lead.full_name'], ['key' => 'mobile', 'type' => 'phone', 'label' => 'Mobile', 'required' => true, 'mapping' => 'lead.phone']],
            'page' => ['template' => 'real_estate_lead'],
        ], $owner)['form'];
        $this->api('POST', "/tracking/forms/{$form['id']}/publish", [], $owner);

        $this->step('new lead and attribution');
        $visit = $this->api('GET', "/public/landing/{$slug}?utm_source=smoke&utm_campaign=smoke-{$run}")['tracking'];
        $this->api('POST', "/public/landing/{$slug}/submit", ['submission_id' => (string) Str::uuid(), 'answers' => ['name' => 'Smoke Lead', 'mobile' => '0551234567'], 'visitor_id' => $visit['visitor_id'], 'session_id' => $visit['session_id'], 'utm_source' => 'smoke', 'utm_campaign' => "smoke-{$run}"]);
        $lead = app(TenantContext::class)->withTenant($tenant, fn () => Lead::where('phone_normalized', '+966551234567')->firstOrFail());

        $this->step('assignment');
        User::withoutGlobalScopes()->find($lead->assigned_to)?->email === $repEmail || throw new RuntimeException('The lead did not reach the salesperson.');

        $this->step('work now');
        ($this->api('GET', '/work/next', [], $rep)['lead']['id'] ?? null) === $lead->uuid || throw new RuntimeException('Work Now did not offer the lead.');

        $this->step('follow-up');
        $this->api('POST', '/follow-ups', ['lead_id' => $lead->uuid, 'due_at' => now()->addHour()->toIso8601String(), 'note' => 'Smoke', 'type' => 'call'], $rep);

        $this->step('appointment, visit and reservation');
        $appointment = $this->api('POST', '/appointments', ['lead_id' => $lead->uuid, 'scheduled_at' => now()->addDay()->toIso8601String()], $rep)['appointment']['id'];
        $this->api('POST', "/appointments/{$appointment}/outcome", ['outcome' => 'reservation'], $rep);
        $reservation = app(TenantContext::class)->withTenant($tenant, fn () => Reservation::where('lead_id', $lead->id)->firstOrFail());

        $this->step('sale and revenue');
        $this->api('POST', "/reservations/{$reservation->uuid}/convert", ['deal_value' => 750000], $rep);

        $this->step('report');
        $revenue = collect($this->api('GET', '/reports/campaign-quality', [], $owner)['rows'])->sum('revenue');
        (float) $revenue === 750000.0 || throw new RuntimeException('The sale is not in the report.');
        $this->api('POST', '/report-shares', ['name' => 'Smoke report', 'template' => 'executive', 'project_id' => $project], $owner);

        $this->step('second-factor sign-in');
        $this->api('POST', '/auth/login', ['email' => $repEmail, 'password' => $password])['mfa_required'] ?? throw new RuntimeException('The second factor was not asked for.');
    }

    private function step(string $name): void
    {
        if ($this->step !== 'start') {
            $this->line("ok  {$this->step}");
        }

        $this->step = $name;
    }

    /** The one-time token in the last email to this address. */
    private function tokenIn(string $email, string $path): string
    {
        $html = (string) EmailDelivery::withoutGlobalScopes()->where('to_email', $email)->latest('id')->value('html');
        preg_match('#/'.preg_quote($path, '#').'([A-Za-z0-9]{48})#', $html, $m) || throw new RuntimeException("No {$path} link in the email.");

        return $m[1];
    }

    /** One request through the application, as the outside world would send it. @return array<string, mixed> */
    private function api(string $method, string $path, array $body = [], ?string $token = null): array
    {
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => (string) config('tenancy.central_domain', 'localhost'), 'HTTP_USER_AGENT' => 'mohtam-smoke'];

        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        $kernel = app(Kernel::class);
        $request = Request::create('/api/v1'.$path, $method, [], [], [], $server, $body === [] ? null : json_encode($body));
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        app('auth')->forgetGuards();

        $json = json_decode((string) $response->getContent(), true);

        if ($response->getStatusCode() >= 300) {
            throw new RuntimeException($method.' '.strtok($path, '?').' answered '.$response->getStatusCode().': '.($json['message'] ?? ''));
        }

        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }
}
