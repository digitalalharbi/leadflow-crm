<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Mail\EmailDeliveries;
use App\Domain\Mail\MailerRegistry;
use App\Http\Controllers\Controller;
use App\Models\EmailDelivery;
use App\Models\ReportShare;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/**
 * The delivery status of the product's own email: which provider is in force and whether it is
 * really able to send, the counts by status, the recent deliveries, the failure log of each, and a
 * way to try a failed one again. For the people who send reports.
 */
class EmailDeliveryController extends Controller
{
    public function __construct(private MailerRegistry $registry, private EmailDeliveries $deliveries, private AuditLogger $audit) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', ReportShare::class);

        $current = $this->registry->current();
        $deliveries = EmailDelivery::query()->with('attemptsLog')->latest('id')->limit(50)->get();

        return ApiResponse::success([
            'provider' => ['key' => $current->key(), 'configured' => $current->isConfigured(), 'delivers' => $current->delivers(), 'requires' => $current->requires()],
            'providers' => array_map(fn ($p) => ['key' => $p->key(), 'configured' => $p->isConfigured(), 'delivers' => $p->delivers(), 'requires' => $p->requires()], $this->registry->all()),
            'counts' => EmailDelivery::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'deliveries' => $deliveries->map(fn (EmailDelivery $d) => $this->present($d))->all(),
        ]);
    }

    public function retry(Request $request, string $uuid)
    {
        $this->authorize('create', ReportShare::class);

        $delivery = EmailDelivery::where('uuid', $uuid)->firstOrFail();
        abort_unless($delivery->status === EmailDelivery::FAILED, 422, 'Only a failed email can be tried again.');

        $this->deliveries->retry($delivery);
        $this->audit->log('email_delivery.retried', $request->user(), $delivery, ['to' => $delivery->to_email]);

        return ApiResponse::success(['delivery' => $this->present($delivery->fresh('attemptsLog'))]);
    }

    /** @return array<string,mixed> */
    private function present(EmailDelivery $d): array
    {
        return [
            'id' => $d->uuid, 'kind' => $d->kind, 'to' => $d->to_email, 'subject' => $d->subject, 'provider' => $d->provider, 'status' => $d->status,
            'attempts' => $d->attempts, 'max_attempts' => $d->max_attempts, 'error' => $d->last_error,
            'created_at' => $d->created_at?->toIso8601String(), 'sent_at' => $d->sent_at?->toIso8601String(), 'next_attempt_at' => $d->next_attempt_at?->toIso8601String(),
            'log' => $d->relationLoaded('attemptsLog') ? $d->attemptsLog->map(fn ($a) => ['attempt' => $a->attempt, 'ok' => $a->ok, 'retryable' => $a->retryable, 'error' => $a->error, 'at' => $a->attempted_at?->toIso8601String()])->all() : [],
        ];
    }
}
