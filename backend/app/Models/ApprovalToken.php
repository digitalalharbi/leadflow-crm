<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalToken extends Model
{
    use BelongsToTenant;

    protected $fillable = ['action_proposal_id', 'user_id', 'token_hash', 'channel', 'delivery', 'delivery_error', 'expires_at', 'used_at', 'used_decision'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(ActionProposal::class, 'action_proposal_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
