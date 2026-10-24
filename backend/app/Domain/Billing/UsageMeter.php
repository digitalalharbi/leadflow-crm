<?php

namespace App\Domain\Billing;

use App\Models\Lead;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How much of its subscription a company is using, counted from its own rows. Explicitly filtered by tenant_id and
 * free of the request's tenant scope, so the platform console reads the same numbers the company does.
 */
class UsageMeter
{
    public function __construct(private Tenant $tenant) {}

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    /** People holding a paid seat: active, inside the company. Outside viewers do not take one. */
    public function activeSeats(): int
    {
        return User::query()->where('tenant_id', $this->tenant->id)->where('is_active', true)
            ->whereNotIn('role', config('billing.seatless_roles'))->count();
    }

    /** Invitations not yet accepted hold their seat, so a full team cannot be overbooked by sending invites. */
    public function pendingSeats(): int
    {
        if (! Schema::hasTable('invitations')) {
            return 0;
        }

        return DB::table('invitations')->where('tenant_id', $this->tenant->id)
            ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())
            ->whereNotIn('role', config('billing.seatless_roles'))->count();
    }

    public function projects(): int
    {
        return Project::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->whereNull('deleted_at')->count();
    }

    public function leadsThisMonth(): int
    {
        return Lead::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', now()->startOfMonth())->count();
    }

    public function storageMb(): float
    {
        $bytes = (int) ProjectFile::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->sum('size_bytes');

        return round($bytes / 1048576, 1);
    }

    /**
     * Usage against limits, ready for a screen. `alert` is set at the configured share of a limit, `over` past it.
     *
     * @return array<string, array{used: int|float, limit: int|null, alert: bool, over: bool}>
     */
    public function summary(?Entitlements $entitlements = null): array
    {
        $entitlements ??= Entitlements::for($this->tenant);
        $ratio = (float) config('billing.usage_alert_ratio');

        $rows = [
            'seats' => [$this->activeSeats() + $this->pendingSeats(), $entitlements->seatLimit()],
            'projects' => [$this->projects(), $entitlements->limit('projects')],
            'leads_per_month' => [$this->leadsThisMonth(), $entitlements->limit('leads_per_month')],
            'storage_mb' => [$this->storageMb(), $entitlements->limit('storage_mb')],
        ];

        return collect($rows)->map(fn ($row) => [
            'used' => $row[0],
            'limit' => $row[1],
            'alert' => $row[1] !== null && $row[1] > 0 && $row[0] >= $row[1] * $ratio,
            'over' => $row[1] !== null && $row[0] > $row[1],
        ])->all();
    }
}
