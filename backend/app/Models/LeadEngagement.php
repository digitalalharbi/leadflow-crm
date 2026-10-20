<?php

namespace App\Models;

use App\Domain\Leads\InterestEngine;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something the customer did.
 *
 * The lead timeline records what the team did; this records what came back,
 * which is the half that says whether any of it worked.
 */
class LeadEngagement extends Model
{
    protected static function booted(): void
    {
        // Whatever this is, it changes how interested the lead is.
        static::saved(fn (self $m) => true && InterestEngine::touch($m->lead_id, 'engagement'));
    }

    use BelongsToTenant;

    public const FILE_OPENED = 'file_opened';

    public const EMAIL_OPENED = 'email_opened';

    public const LINK_CLICKED = 'link_clicked';

    public const WHATSAPP_REPLIED = 'whatsapp_replied';

    /**
     * How far back an engagement still counts for scoring.
     *
     * A brochure opened twice last March is history; the same two opens this
     * morning is a phone call. Thirty days is long enough to cover a slow
     * buying decision and short enough that a score means "now".
     */
    public const RECENT_DAYS = 30;

    protected $fillable = ['lead_id', 'type', 'meta', 'happened_at'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'happened_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
