<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AgentMessage extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['agent_thread_id', 'role', 'agent', 'content', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
