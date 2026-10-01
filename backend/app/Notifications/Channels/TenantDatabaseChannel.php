<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/** Stamps tenant_id on database notifications so they can be scoped and purged per tenant. */
class TenantDatabaseChannel extends DatabaseChannel
{
    protected function buildPayload($notifiable, Notification $notification): array
    {
        return array_merge(parent::buildPayload($notifiable, $notification), [
            'tenant_id' => $notifiable->tenant_id ?? null,
        ]);
    }
}
