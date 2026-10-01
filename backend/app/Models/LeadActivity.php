<?php

namespace App\Models;

use App\Enums\ActivityType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadActivity extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = ['lead_id', 'user_id', 'type', 'description', 'meta'];

    protected function casts(): array
    {
        return ['type' => ActivityType::class, 'meta' => 'array'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
