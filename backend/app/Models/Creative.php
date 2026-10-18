<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Creative extends Model
{
    use BelongsToTenant, HasUuid;

    protected $fillable = [
        'platform', 'external_id', 'name', 'format', 'headline', 'body',
        'call_to_action', 'media_url', 'thumbnail_url', 'raw',
    ];

    protected function casts(): array
    {
        return ['raw' => 'array'];
    }
}
