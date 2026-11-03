<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use App\Models\Scopes\CompanyScope;
use App\Models\Scopes\ProjectScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** The landing page and the form on it: what the visitor sees, what may be asked, where each answer lands. */
class LandingForm extends Model
{
    use BelongsToTenant, HasUuid, SoftDeletes;

    public const STATUSES = ['draft', 'published', 'paused', 'archived'];

    protected static function booted(): void
    {
        static::addGlobalScope(new ProjectScope);
        static::addGlobalScope(new CompanyScope);
    }

    protected $fillable = [
        'project_id', 'name', 'status', 'headline', 'description', 'benefits', 'cta_label', 'logo_url', 'image_url',
        'thank_you_title', 'thank_you_message', 'consent_required', 'consent_text', 'privacy_url', 'fields', 'layout', 'design', 'settings',
        'published_version_id', 'published_at', 'created_by', 'slug', 'page',
    ];

    protected function casts(): array
    {
        return ['benefits' => 'array', 'fields' => 'array', 'layout' => 'array', 'design' => 'array', 'settings' => 'array', 'consent_required' => 'boolean', 'published_at' => 'datetime', 'page' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function links(): HasMany
    {
        return $this->hasMany(TrackingLink::class, 'landing_form_id');
    }

    /** The link the page's own address runs on, so it is tracked like every other way in. */
    public function defaultLink(): BelongsTo
    {
        return $this->belongsTo(TrackingLink::class, 'default_link_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(LandingFormVersion::class, 'landing_form_id');
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(LandingFormVersion::class, 'published_version_id');
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
