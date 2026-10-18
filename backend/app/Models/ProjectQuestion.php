<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectQuestion extends Model
{
    use BelongsToTenant, HasUuid;

    public const TYPES = ['select', 'boolean', 'number', 'text'];

    protected $fillable = [
        'project_id', 'key', 'label', 'type', 'options', 'rules', 'is_required', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'rules' => 'array',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(LeadAnswer::class);
    }
}
