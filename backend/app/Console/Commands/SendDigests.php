<?php

namespace App\Console\Commands;

use App\Domain\Digest\DigestComposer;
use App\Domain\Mail\DigestEmail;
use App\Domain\Mail\EmailDeliveries;
use App\Domain\Mail\OutboundEmail;
use App\Domain\Notifications\AlertGate;
use App\Domain\Notifications\NotificationPrefs;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The email summaries: sales every morning, marketing and the executive one every Sunday morning
 * — eight o'clock where the workspace is, once each, to the people who chose them.
 *
 * Run hourly; a workspace is only sent to in the hour that is 08:00 for it, and a person is only
 * ever sent a given summary once a day (an alert mark), so a second run in the same hour, or a
 * manual one, cannot double up. Alerts somebody set to "daily" or "weekly" ride along in the
 * summary of that rhythm — or, with no summary chosen, arrive alone.
 *
 * Each summary is queued as a delivery (App\Domain\Mail): the same door, retries and delivery log
 * as every other email the product sends, and the same honesty when the provider only logs.
 */
class SendDigests extends Command
{
    protected $signature = 'leadflow:send-digests
        {--tenant= : only this tenant slug}
        {--kind= : daily or weekly (default: whichever is due)}
        {--now : send regardless of the hour, for a manual run}';

    protected $description = 'Email the daily and weekly summaries to the people who chose them';

    private const SEND_AT_HOUR = 8;

    /** Sunday: the week the market works starts there. */
    private const WEEK_STARTS = CarbonImmutable::SUNDAY;

    private EmailDeliveries $deliveries;

    public function handle(DigestComposer $composer, TenantContext $context, EmailDeliveries $deliveries): int
    {
        $this->deliveries = $deliveries;
        $sent = 0;

        $tenants = $context->withoutScope(fn () => Tenant::with('settings')->where('is_active', true)
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))->get());

        foreach ($tenants as $tenant) {
            try {
                $local = CarbonImmutable::now((string) ($tenant->settings?->timezone ?: 'Asia/Riyadh'));
                $force = (bool) $this->option('now');
                $morning = $local->hour === self::SEND_AT_HOUR;

                $rhythms = array_values(array_filter([
                    ($force || $morning) && in_array($this->option('kind'), [null, 'daily'], true) ? 'daily' : null,
                    ($force || ($morning && $local->dayOfWeek === self::WEEK_STARTS)) && in_array($this->option('kind'), [null, 'weekly'], true) ? 'weekly' : null,
                ]));

                if ($rhythms === []) {
                    continue;
                }

                $context->withTenant($tenant, function () use ($tenant, $rhythms, $composer, $local, &$sent) {
                    User::query()->where('tenant_id', $tenant->id)->where('is_active', true)->whereNotNull('email')->get()
                        ->each(function (User $user) use ($tenant, $rhythms, $composer, $local, &$sent) {
                            foreach ($rhythms as $rhythm) {
                                $sent += $this->sendTo($user, $tenant, $rhythm, $composer, $local);
                            }
                        });
                });
            } catch (\Throwable $e) {
                $this->error("Tenant {$tenant->slug}: {$e->getMessage()}");
                report($e);
            }
        }

        $this->info("Digests: {$sent} sent.");

        return self::SUCCESS;
    }

    private function sendTo(User $user, Tenant $tenant, string $rhythm, DigestComposer $composer, CarbonImmutable $local): int
    {
        $since = $rhythm === 'daily' ? CarbonImmutable::now()->subDay() : CarbonImmutable::now()->subWeek();
        $rollup = $composer->rollup($user, $rhythm, $since);
        $digests = $rhythm === 'daily' ? ['daily_sales'] : ['weekly_marketing', 'weekly_executive'];
        $wanted = array_values(array_filter($digests, fn (string $d) => NotificationPrefs::wantsDigest($user, $d)));
        $count = 0;

        foreach ($wanted as $index => $kind) {
            if (! AlertGate::once("digest:{$kind}:{$user->id}:{$local->toDateString()}")) {
                continue;
            }

            // The roll-up rides on the last summary of the rhythm — one email for it, not one each.
            $carry = $index === count($wanted) - 1 ? $rollup : [];
            $this->mail($user, $tenant, $kind, $composer->compose($kind, $user), $carry, $local);
            $count++;
        }

        // Alerts chosen for later, and no summary to carry them: they arrive alone.
        if ($wanted === [] && $rollup !== [] && AlertGate::once("digest:alerts_{$rhythm}:{$user->id}:{$local->toDateString()}")) {
            $this->mail($user, $tenant, "alerts_{$rhythm}", ['kind' => "alerts_{$rhythm}", 'title' => $rhythm === 'daily' ? 'تنبيهات اليوم' : 'تنبيهات الأسبوع', 'period' => $local->toDateString(), 'stats' => [], 'lists' => []], $rollup, $local);
            $count++;
        }

        return $count;
    }

    /**
     * One summary to one person, queued. The idempotency key is the same one the alert mark uses, so
     * even a delivery asked for twice is one email.
     *
     * @param  array<string,mixed>  $digest
     * @param  list<array{title:string,body:string}>  $rollup
     */
    private function mail(User $user, Tenant $tenant, string $kind, array $digest, array $rollup, CarbonImmutable $local): void
    {
        $mail = DigestEmail::compose($digest, $rollup, $tenant->name);

        $this->deliveries->queue(
            new OutboundEmail($user->email, $user->name, $mail['subject'], $mail['html'], null, '', '', "digest:{$kind}:{$user->id}:{$local->toDateString()}", ['kind' => 'digest', 'digest' => $kind]),
            ['kind' => mb_substr("digest.{$kind}", 0, 24), 'subject_type' => 'user', 'subject_id' => $user->id],
        );
    }
}
