<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use App\Models\Scopes\ProjectScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** The landing page and the form on it: what the visitor sees, what may be asked, where each answer lands. */
class LandingForm extends Model
{
    use BelongsToTenant, HasUuid, SoftDeletes;

    public const STATUSES = ['draft', 'published', 'archived'];

    protected static function booted(): void
    {
        static::addGlobalScope(new ProjectScope);
    }

    protected $fillable = [
        'project_id', 'name', 'status', 'headline', 'description', 'benefits', 'cta_label', 'logo_url', 'image_url',
        'thank_you_title', 'thank_you_message', 'consent_required', 'consent_text', 'privacy_url', 'fields', 'created_by',
    ];

    protected function casts(): array
    {
        return ['benefits' => 'array', 'fields' => 'array', 'consent_required' => 'boolean'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function links(): HasMany
    {
        return $this->hasMany(TrackingLink::class, 'landing_form_id');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published' && ! $this->trashed();
    }

    /** How many steps the form has: the highest step any field is on. */
    public function steps(): int
    {
        return max(1, (int) collect($this->fields ?? [])->max('step'));
    }
}
