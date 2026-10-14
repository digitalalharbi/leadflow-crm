<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A file a project sells with. */
class ProjectFile extends Model
{
    use BelongsToTenant, HasUuid;

    /**
     * What a file is for.
     *
     * Short on purpose. Every extra kind is a decision somebody has to make
     * while uploading, and a list long enough to need thinking about is a list
     * everybody picks "other" from.
     */
    public const KINDS = ['brochure', 'profile', 'price_list', 'investment_deck', 'financing_guide', 'other'];

    protected $fillable = [
        'project_id', 'uploaded_by', 'name', 'kind', 'language',
        'path', 'mime', 'size_bytes', 'is_active', 'sort',
    ];

    /** The path is where it sits on our disk; nobody outside needs to know. */
    protected $hidden = ['path'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'size_bytes' => 'integer',
            'sort' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(FileShare::class);
    }

    /**
     * Whether this file suits somebody who reads that language.
     *
     * A file with no language suits everybody — a price list is numbers, and
     * demanding a translation of it would leave a project with no price list
     * for half its customers.
     */
    public function suitsLanguage(?string $language): bool
    {
        return $this->language === null || $language === null || $this->language === $language;
    }
}
