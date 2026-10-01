<?php

namespace App\Models;

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadMessage extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'lead_id', 'user_id', 'direction', 'channel', 'provider',
        'provider_message_id', 'message_type', 'template_name', 'body',
        'status', 'sent_at', 'delivered_at', 'read_at', 'failed_at',
        'failure_code', 'failure_reason', 'provider_payload',
    ];

    /** The raw provider envelope repeats the body and carries routing metadata. */
    protected $hidden = ['provider_payload'];

    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'channel' => MessageChannel::class,
            'status' => MessageStatus::class,
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
            'provider_payload' => 'array',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** Who sent it. Null for inbound messages and for anything sent by a job. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeInbound(Builder $query): Builder
    {
        return $query->where('direction', MessageDirection::Inbound);
    }

    public function scopeOutbound(Builder $query): Builder
    {
        return $query->where('direction', MessageDirection::Outbound);
    }

    /**
     * Move the delivery status forward, never backwards.
     *
     * Meta's callbacks are not ordered: a "sent" can arrive after a "read" when
     * one delivery is retried. Taking the latest callback at face value would
     * show a message the customer has already read as merely sent.
     */
    public function advanceStatus(MessageStatus $status, ?\DateTimeInterface $at = null): bool
    {
        $at ??= now();

        if ($this->status->isTerminal() || $status->rank() <= $this->status->rank()) {
            // Still record the timestamp if this is the first time we hear it.
            return $this->recordTimestamp($status, $at);
        }

        $this->status = $status;
        $this->recordTimestamp($status, $at);

        return $this->save();
    }

    private function recordTimestamp(MessageStatus $status, \DateTimeInterface $at): bool
    {
        $column = match ($status) {
            MessageStatus::Sent => 'sent_at',
            MessageStatus::Delivered => 'delivered_at',
            MessageStatus::Read => 'read_at',
            MessageStatus::Failed => 'failed_at',
            default => null,
        };

        if ($column === null || $this->{$column} !== null) {
            return $this->isDirty() ? $this->save() : false;
        }

        $this->{$column} = $at;

        return $this->save();
    }
}
