<?php

namespace App\Console\Commands;

use App\Domain\Leads\InterestEngine;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Interest decays: a lead nobody has heard from in two weeks is colder today than yesterday and no
 * event says so. This re-reads the open leads whose number is stale, hour by hour, a bounded batch
 * at a time — events keep the rest fresh as they happen.
 */
class RefreshInterest extends Command
{
    protected $signature = 'leadflow:refresh-interest {--limit=1500 : leads per workspace per run}';

    protected $description = 'Re-read how interested open leads are, where the number has gone stale';

    public function handle(InterestEngine $engine, TenantContext $context): int
    {
        $changed = 0;
        $seen = 0;

        $tenants = $context->withoutScope(fn () => Tenant::where('is_active', true)->get());

        foreach ($tenants as $tenant) {
            try {
                $context->withTenant($tenant, function () use ($engine, &$changed, &$seen) {
                    Lead::query()->reorder()
                        ->whereNotIn('status', [LeadStatus::Won->value, LeadStatus::Lost->value])
                        ->where(fn ($q) => $q->whereNull('interest_at')->orWhere('interest_at', '<', now()->subHours(6)))
                        ->orderBy('interest_at')->limit((int) $this->option('limit'))->get()
                        ->each(function (Lead $lead) use ($engine, &$changed, &$seen) {
                            $seen++;
                            $changed += $engine->refresh($lead, 'schedule') ? 1 : 0;
                        });
                });
            } catch (\Throwable $e) {
                $this->error("Tenant {$tenant->slug}: {$e->getMessage()}");
                report($e);
            }
        }

        $this->info("Interest: {$seen} lead(s) re-read, {$changed} changed level.");

        return self::SUCCESS;
    }
}
