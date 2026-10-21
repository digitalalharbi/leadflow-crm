<?php

namespace App\Notifications\Channels;

use App\Domain\Mail\EmailDeliveries;
use App\Domain\Mail\OutboundEmail;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Notifications\Notification;

/**
 * The email of a notification, through the product's own door.
 *
 * Laravel's `mail` channel would hand the message to the default mailer, out of sight of the queue,
 * the retries and the delivery log every other email of the product has. This renders the same
 * `toMail()` the notification already writes and queues it as a delivery instead — so an instant
 * alert is retried, logged and shown on the delivery screen exactly like a report. A notification
 * job carries no tenant, so the delivery is written in the recipient's.
 */
class ProviderMailChannel
{
    public function __construct(private EmailDeliveries $deliveries, private TenantContext $context) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || blank($notifiable->email) || ! method_exists($notification, 'toMail')) {
            return;
        }

        $message = $notification->toMail($notifiable);
        $html = is_string($message->view) ? view($message->view, $message->viewData)->render() : $message->render();
        $category = method_exists($notification, 'alertCategory') ? ($notification->alertCategory() ?? 'other') : 'other';
        $tenant = $notifiable->tenant;

        if ($tenant === null) {
            return;
        }

        $this->context->withTenant($tenant, fn () => $this->deliveries->queue(
            new OutboundEmail(
                $notifiable->email, $notifiable->name, (string) $message->subject, $html, null, '', '',
                // A notification job delivered twice is one email.
                $notification->id ? "alert:{$notification->id}:{$notifiable->id}" : '',
                ['kind' => 'alert', 'category' => $category],
            ),
            ['kind' => mb_substr("alert.{$category}", 0, 24), 'subject_type' => 'user', 'subject_id' => $notifiable->id],
        ));
    }
}
