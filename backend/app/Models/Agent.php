<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    use BelongsToTenant;

    protected $fillable = ['key', 'name', 'role', 'model', 'tools', 'is_active'];

    protected function casts(): array
    {
        return ['tools' => 'array', 'is_active' => 'boolean'];
    }
}
