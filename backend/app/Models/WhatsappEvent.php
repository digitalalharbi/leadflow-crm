<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The webhook ledger: what we have already processed, and what arrived that we
 * could not place. Written on every delivery, read to decide whether a delivery
 * is a repeat.
 */
class WhatsappEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    /**
     * A delivery we refuse before identifying the tenant — a bad signature, an
     * unknown phone number id — still gets a row. That is the only record that
     * someone is probing the endpoint.
     */
    public bool $tenantOptional = true;

    public const TYPE_MESSAGE = 'message';

    public const TYPE_STATUS = 'status';

    public const TYPE_UNMATCHED = 'unmatched';

    public const TYPE_REJECTED = 'rejected';

    /*
     * tenant_id is deliberately absent: BelongsToTenant stamps it, and leaving
     * it fillable would let any future caller write a row into another
     * workspace's ledger.
     */
    protected $fillable = [
        'integration_id', 'event_id', 'event_type',
        'phone_number_id', 'from_phone', 'outcome', 'detail', 'payload',
    ];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'occurrences' => 'integer',
        ];
    }

    /**
     * Record a delivery we refused, as a tally rather than a row per request.
     *
     * A rejection is written before anyone is authenticated — that is the point
     * of it — so one row per attempt is a disk-exhaustion primitive that needs
     * no login. The upsert collapses repeats onto a counter, which also makes
     * the row more useful: "4,812 probes against this number" says more than
     * 4,812 identical rows.
     */
    public static function recordRejection(
        ?int $tenantId,
        ?int $integrationId,
        ?string $phoneNumberId,
        string $outcome,
    ): void {
        /*
         * Written as raw SQL because the conflict target has to repeat the
         * partial index's predicate as well as its expressions, and the query
         * builder's upsert cannot express a `WHERE` on a conflict target.
         */
        DB::statement(<<<'SQL'
            INSERT INTO whatsapp_events
                (tenant_id, integration_id, event_type, event_id, phone_number_id, outcome, occurrences, created_at, last_seen_at)
            VALUES (?, ?, 'rejected', ?, ?, ?, 1, now(), now())
            ON CONFLICT (coalesce(tenant_id, 0), coalesce(phone_number_id, ''), coalesce(outcome, ''))
                WHERE event_type = 'rejected'
            DO UPDATE SET
                occurrences = whatsapp_events.occurrences + 1,
                last_seen_at = now()
        SQL, [
            $tenantId,
            $integrationId,
            $outcome.':'.($phoneNumberId ?: 'none'),
            $phoneNumberId,
            $outcome,
        ]);
    }

    /** Append-only: a row describes a delivery that already happened. */
    public function setUpdatedAt($value)
    {
        return $this;
    }
}
