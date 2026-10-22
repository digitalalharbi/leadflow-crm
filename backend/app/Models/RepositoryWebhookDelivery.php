<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One delivery from GitHub, accepted or not.
 *
 * Carries a summary rather than the payload: a `push` body holds every commit
 * message and every author's email address, and none of it is needed once the
 * reducer has run.
 */
class RepositoryWebhookDelivery extends Model
{
    use BelongsToTenant;

    public const RECEIVED = 'received';

    public const PROCESSED = 'processed';

    public const IGNORED = 'ignored';

    public const DUPLICATE = 'duplicate';

    public const REJECTED = 'rejected';

    public const FAILED = 'failed';

    protected $fillable = [
        'project_repository_id', 'delivery_id', 'event', 'action',
        'signature_valid', 'status', 'error', 'summary', 'received_at', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'summary' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
