<?php

namespace App\Models;

use App\Domain\Conversions\ConversionCredentials;
use App\Domain\Conversions\ExporterRegistry;
use App\Domain\Tracking\Destinations\DestinationKind;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A place events can go: its public identifier, its encrypted credential, and what it may be told. */
class TrackingDestination extends Model
{
    use BelongsToTenant, HasUuid;

    protected $fillable = [
        'project_id', 'kind', 'name', 'is_enabled', 'config', 'credentials', 'events', 'send_value', 'hash_identifiers', 'consent_mode',
        'last_event_at', 'last_error', 'created_by',
    ];

    /** The credential is never serialised: it is read by the code that sends, and by nothing that answers a request. */
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean', 'config' => 'array', 'credentials' => 'encrypted:array', 'events' => 'array',
            'send_value' => 'boolean', 'hash_identifiers' => 'boolean', 'last_event_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(DestinationDelivery::class, 'destination_id');
    }

    public function destinationKind(): DestinationKind
    {
        return DestinationKind::from($this->kind);
    }

    public function readiness(): string
    {
        return $this->destinationKind()->readiness((array) $this->config, (array) $this->credentials);
    }

    /** The events it may be told about: its own list, or the default one. @return list<string> */
    public function allowedEvents(): array
    {
        return $this->events ?: DestinationKind::DEFAULT_EVENTS;
    }

    /** Whether the offline-conversion pipeline for this platform has what it needs — the later stages are sent there. */
    public function offlineConversions(): ?string
    {
        $platform = $this->destinationKind()->conversionPlatform();

        if ($platform === null || ! app(ExporterRegistry::class)->has($platform)) {
            return null;
        }

        $credentials = ConversionCredentials::for($platform);

        return app(ExporterRegistry::class)->get($platform)->isConfigured($credentials) ? 'ready' : 'awaiting_credentials';
    }
}
