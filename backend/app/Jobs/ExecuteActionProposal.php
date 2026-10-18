<?php

namespace App\Jobs;

use App\Domain\Actions\ActionGateway;
use App\Models\ActionProposal;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Runs one approved proposal. Safe to retry: the gateway executes each proposal at most once. */
class ExecuteActionProposal implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $proposalId, public ?int $userId = null) {}

    public function handle(ActionGateway $gateway, TenantContext $context): void
    {
        $tenantId = $context->withoutScope(fn () => ActionProposal::query()->whereKey($this->proposalId)->value('tenant_id'));
        $tenant = $tenantId ? $context->withoutScope(fn () => Tenant::find($tenantId)) : null;

        if ($tenant === null) {
            return;
        }

        $context->withTenant($tenant, fn () => $gateway->execute($this->proposalId, $this->userId));
    }
}
