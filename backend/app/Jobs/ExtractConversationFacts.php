<?php

namespace App\Jobs;

use App\Domain\Conversation\ConversationIntelligence;
use App\Models\Lead;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Reads a lead's conversation after the customer writes — off the request that
 * received the message, and never able to break it.
 */
class ExtractConversationFacts implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $leadId) {}

    public function handle(ConversationIntelligence $conversation, TenantContext $tenants): void
    {
        $lead = Lead::withoutGlobalScopes()->find($this->leadId);

        if (! $lead || $lead->trashed()) {
            return;
        }

        try {
            $tenants->withTenant($lead->tenant, fn () => $conversation->analyse($lead));
        } catch (\Throwable $e) {
            // The conversation is read to help; failing to read it is logged and is nothing else.
            Log::warning('conversation_extraction_failed', ['lead' => $this->leadId, 'error' => $e->getMessage()]);
        }
    }
}
