<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The link between a project in مهتم and the repository it is built in.
 *
 * Deliberately thin. It knows which repository, which branches mean what, and
 * how far the last sweep got; everything about *what happened* in that
 * repository is recorded elsewhere, so that re-mapping a project does not throw
 * away its development history.
 */
class ProjectRepository extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = [
        'project_id', 'integration_id', 'provider',
        'repository_id', 'repository_full_name',
        'default_branch', 'develop_branch', 'production_branch',
        'sync_cursor', 'last_sync_at', 'last_webhook_at', 'discovery', 'last_error',
        'webhook_id', 'webhook_key', 'webhook_secret', 'webhook_status', 'signature_failures',
    ];

    protected function casts(): array
    {
        return [
            'repository_id' => 'integer',
            'webhook_id' => 'integer',
            // Needed back rather than compared: HMAC verification recomputes the
            // signature from it, which a hash could not do.
            'webhook_secret' => 'encrypted',
            'signature_failures' => 'integer',
            'discovery' => 'array',
            'sync_cursor' => 'datetime',
            'last_sync_at' => 'datetime',
            'last_webhook_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The connection whose credentials reach this repository. */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Integration::class, 'integration_id');
    }

    /**
     * Which of this repository's branches مهتم treats as which environment.
     *
     * Null means "not mapped", which is different from "the default branch":
     * a repository with no `develop` has no staging, and calling its production
     * branch staging would label every release wrongly.
     */
    public function environmentFor(?string $branch): ?string
    {
        if (blank($branch)) {
            return null;
        }

        return match ($branch) {
            $this->production_branch => 'production',
            $this->develop_branch => 'staging',
            default => null,
        };
    }

    /**
     * Where GitHub should post, and whether it can.
     *
     * The key is in the path and is not a secret — it only says which workspace
     * and repository a delivery claims to be about. The secret signs the body,
     * and nothing in a delivery is believed until it verifies.
     */
    public function webhookUrl(): ?string
    {
        return $this->webhook_key
            ? rtrim((string) config('app.url'), '/').'/api/v1/integrations/github/webhook/'.$this->webhook_key
            : null;
    }

    /**
     * Whether this repository can actually tell مهتم anything.
     *
     * Three states rather than a boolean, because "we were not allowed to create
     * the webhook" and "there is no webhook" need different things from the
     * person reading the screen: one needs a wider token or a manual hook, the
     * other needs somebody to press a button.
     */
    public function readiness(): array
    {
        $reasons = [];

        /*
         * Loaded explicitly rather than through the relation: readiness is
         * asked for in a list, where lazy loading is disabled outside
         * production and would throw rather than answer.
         */
        $connection = $this->relationLoaded('connection')
            ? $this->connection
            : Integration::withoutGlobalScopes()->find($this->integration_id);

        if (blank($connection?->credentials['access_token'] ?? null)) {
            $reasons[] = 'not_connected';
        }

        if ($this->webhook_status !== 'ok') {
            $reasons[] = 'webhook_'.$this->webhook_status;
        }

        if (blank($this->production_branch)) {
            $reasons[] = 'no_production_branch';
        }

        if (($this->discovery['unavailable']['workflows'] ?? null) !== null) {
            $reasons[] = 'workflows_unreadable';
        }

        $state = match (true) {
            in_array('not_connected', $reasons, true) => 'broken',
            $reasons === [] => 'ready',
            default => 'partial',
        };

        return ['state' => $state, 'reasons' => $reasons];
    }

    public function webUrl(): string
    {
        return 'https://github.com/'.$this->repository_full_name;
    }
}
