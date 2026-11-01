<?php

namespace App\Domain\Privacy;

use App\Models\Lead;
use App\Models\Suppression;
use App\Services\Leads\PhoneNormalizer;
use App\Support\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The company's do-not-contact list, and the one place that decides what is on it.
 *
 * It works through the opt-out stamps every send path already honours (`whatsapp_opt_out_at`, `email_opt_out_at`): an
 * entry stamps the leads that carry that number or address now, and every lead created with it later. Taking an entry
 * off the list does not opt anyone back in — consent is given by the person, not restored by an administrator.
 */
class SuppressionList
{
    /** Short replies that mean "stop", in the languages leads write in. Whole message only, so "لا توقف" is not one. */
    private const STOP_WORDS = [
        'stop', 'unsubscribe', 'stop all', 'cancel', 'opt out', 'optout',
        'ايقاف', 'إيقاف', 'توقف', 'وقف', 'الغاء', 'إلغاء', 'الغاء الاشتراك', 'إلغاء الاشتراك', 'لا ترسل', 'لا ترسلوا', 'لا تراسلني',
    ];

    public function __construct(private PhoneNormalizer $phones, private TenantContext $context) {}

    public function isStopMessage(?string $body): bool
    {
        $text = mb_strtolower(trim((string) preg_replace('/[\s\p{P}]+/u', ' ', (string) $body)));

        return $text !== '' && mb_strlen($text) <= 30 && in_array($text, self::STOP_WORDS, true);
    }

    public function normalize(string $channel, string $value): ?string
    {
        return match ($channel) {
            'email' => filter_var($email = mb_strtolower(trim($value)), FILTER_VALIDATE_EMAIL) ? $email : null,
            'phone' => $this->phones->normalize($value),
            default => null,
        };
    }

    /** Adds an entry (idempotent) and stamps the company's leads that carry it. */
    public function add(int $tenantId, string $channel, string $value, string $reason, ?int $by = null, ?string $note = null): ?Suppression
    {
        $normalized = $this->normalize($channel, $value);

        if ($normalized === null) {
            return null;
        }

        try {
            $entry = Suppression::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenantId, 'channel' => $channel, 'value' => $normalized],
                ['reason' => $reason, 'note' => $note, 'created_by' => $by],
            );
        } catch (UniqueConstraintViolationException) {
            $entry = Suppression::withoutGlobalScopes()->where(['tenant_id' => $tenantId, 'channel' => $channel, 'value' => $normalized])->first();
        }

        $this->context->withoutScope(function () use ($tenantId, $channel, $normalized) {
            $leads = Lead::withoutGlobalScopes()->where('tenant_id', $tenantId);

            $channel === 'phone'
                ? $leads->where('phone_normalized', $normalized)->whereNull('whatsapp_opt_out_at')->update(['whatsapp_opt_out_at' => now()])
                : $leads->whereRaw('lower(email) = ?', [$normalized])->whereNull('email_opt_out_at')->update(['email_opt_out_at' => now()]);
        });

        return $entry;
    }

    /** Stamps a lead that is about to be created with a suppressed number or address. */
    public function applyTo(Lead $lead): void
    {
        if (! $lead->tenant_id) {
            return;
        }

        $rows = Suppression::withoutGlobalScopes()->where('tenant_id', $lead->tenant_id)
            ->where(function ($q) use ($lead) {
                $q->where(fn ($p) => $p->where('channel', 'phone')->where('value', $lead->phone_normalized ?? '__none__'))
                    ->orWhere(fn ($e) => $e->where('channel', 'email')->where('value', mb_strtolower((string) $lead->email) ?: '__none__'));
            })->pluck('channel');

        if ($rows->contains('phone')) {
            $lead->whatsapp_opt_out_at ??= now();
        }

        if ($rows->contains('email')) {
            $lead->email_opt_out_at ??= now();
        }
    }
}
