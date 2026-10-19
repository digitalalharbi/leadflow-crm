<?php

namespace App\Jobs;

use App\Domain\Actions\WhatsappApprovals;
use App\Models\ActionProposal;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Sends an agent's proposal to the approvers' WhatsApp. Never retried: a second send is a second link. */
class NotifyProposalApprovers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $proposalId) {}

    public function handle(WhatsappApprovals $approvals, TenantContext $context): void
    {
        $tenantId = $context->withoutScope(fn () => ActionProposal::query()->whereKey($this->proposalId)->value('tenant_id'));
        $tenant = $tenantId ? $context->withoutScope(fn () => Tenant::find($tenantId)) : null;

        if ($tenant) {
            $context->withTenant($tenant, fn () => $approvals->notify(ActionProposal::query()->findOrFail($this->proposalId)));
        }
    }
}
