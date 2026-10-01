<?php

namespace App\Policies;

use App\Models\MessageTemplate;
use App\Models\User;

/**
 * Saved messages are the words a company says to its customers, so changing
 * them is a management decision — but every rep needs to read them.
 *
 * Previously these borrowed IntegrationPolicy, which is admin-only. That
 * excluded sales managers by accident, and tied the question "who may rewrite
 * what we say to buyers?" to the unrelated question of who may hold an API key.
 */
class MessageTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->seesAllLeads();
    }

    public function update(User $user, MessageTemplate $template): bool
    {
        return $user->belongsToTenant($template->tenant_id) && $user->seesAllLeads();
    }

    public function delete(User $user, MessageTemplate $template): bool
    {
        return $this->update($user, $template);
    }

    /** Deactivated messages are an editing detail, not something reps browse. */
    public function viewInactive(User $user): bool
    {
        return $user->seesAllLeads();
    }
}
