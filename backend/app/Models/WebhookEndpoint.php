<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class WebhookEndpoint extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'integration_id', 'key', 'secret_hash', 'label',
        'is_active', 'last_received_at', 'received_count',
    ];

    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_received_at' => 'datetime'];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /** Returns [endpoint, plaintextSecret]. The secret is shown exactly once. */
    public static function issue(?int $integrationId = null, ?string $label = null): array
    {
        $secret = Str::random(48);

        $endpoint = static::create([
            'integration_id' => $integrationId,
            'key' => Str::lower(Str::random(32)),
            'secret_hash' => Hash::make($secret),
            'label' => $label,
        ]);

        return [$endpoint, $secret];
    }

    public function verifySecret(string $secret): bool
    {
        return Hash::check($secret, $this->secret_hash);
    }
}
