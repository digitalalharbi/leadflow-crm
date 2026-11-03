<?php

namespace App\Models;

use App\Casts\EncryptedBody;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One email the product sent on its own account, and how it went. */
class EmailDelivery extends Model
{
    use BelongsToTenant, HasUuid;

    public const QUEUED = 'queued';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    /** Written down by the log provider: not delivered. */
    public const LOGGED = 'logged';

    public const FAILED = 'failed';

    protected $fillable = [
        'kind', 'to_email', 'to_name', 'subject', 'html', 'text', 'provider', 'status', 'attempts', 'max_attempts', 'provider_message_id', 'last_error',
        'subject_type', 'subject_id', 'idempotency_key', 'created_by', 'next_attempt_at', 'sent_at', 'failed_at',
    ];

    protected $hidden = ['html', 'text'];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'max_attempts' => 'integer', 'html' => EncryptedBody::class, 'text' => EncryptedBody::class, 'next_attempt_at' => 'datetime', 'sent_at' => 'datetime', 'failed_at' => 'datetime'];
    }

    public function attemptsLog(): HasMany
    {
        return $this->hasMany(EmailDeliveryAttempt::class)->orderBy('attempt');
    }
}
