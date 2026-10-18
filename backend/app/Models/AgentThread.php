<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentThread extends Model
{
    use BelongsToTenant, HasUuid;

    protected $fillable = ['agent', 'routine', 'project_id', 'started_by', 'title'];

    public function messages(): HasMany
    {
        return $this->hasMany(AgentMessage::class)->orderBy('id');
    }

    public function say(string $role, ?string $agent, string $content, array $data = []): AgentMessage
    {
        return $this->messages()->create(['role' => $role, 'agent' => $agent, 'content' => $content, 'data' => $data ?: null]);
    }
}
