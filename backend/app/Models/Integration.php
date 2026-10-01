<?php

namespace App\Models;

use App\Enums\IntegrationProvider;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Integration extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    public const CONNECTED = 'connected';
    public const NOT_CONNECTED = 'not_connected';
    public const CONFIGURATION_REQUIRED = 'configuration_required';
    public const ERROR = 'error';

    protected $fillable = [
        'provider', 'name', 'status', 'credentials', 'config',
        'field_mapping', 'defaults', 'is_active', 'last_synced_at',
        'last_error', 'leads_received',
    ];

    /** Credentials are encrypted at rest and never serialised to the client. */
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'provider' => IntegrationProvider::class,
            'credentials' => 'encrypted:array',
            'config' => 'array',
            'field_mapping' => 'array',
            'defaults' => 'array',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function webhookEndpoints(): HasMany
    {
        return $this->hasMany(WebhookEndpoint::class);
    }

    /** Which credential keys are set, without ever revealing the values. */
    public function configuredCredentialKeys(): array
    {
        return array_keys(array_filter($this->credentials ?? [], fn ($v) => filled($v)));
    }
}
