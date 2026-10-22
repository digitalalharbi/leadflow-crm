<?php

namespace App\Notifications\Channels;

use App\Domain\Notifications\StaffWhatsapp;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Notifications\Notification;

/**
 * The WhatsApp message of a notification, to a member of staff who asked for it.
 *
 * Same content as the email and the tray: a title, a sentence and a link. A notification job
 * carries no tenant, so the decision and the log are written in the recipient's own workspace.
 */
class StaffWhatsappChannel
{
    public function __construct(private StaffWhatsapp $alerts, private TenantContext $context) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! method_exists($notification, 'alertCategory') || ! method_exists($notification, 'alertContent')) {
            return;
        }

        $category = $notification->alertCategory();
        $tenant = $notifiable->tenant;

        if ($category === null || $tenant === null) {
            return;
        }

        $content = $notification->alertContent();
        $base = rtrim((string) config('integrations.app_url'), '/');
        $link = $content['lead_id'] ? "{$base}/leads/{$content['lead_id']}" : $base;
        $text = "*{$content['title']}*\n{$content['body']}\n{$link}";
        // About a lead: once per lead and category. About the workspace: once per day.
        $dedup = $content['lead_id'] ? "{$category}:{$content['lead_id']}" : "{$category}:".now()->toDateString().':'.substr(sha1($content['title']), 0, 10);

        $this->context->withTenant($tenant, fn () => $this->alerts->queue($notifiable, $category, $content['title'], $text, $dedup));
    }
}
