<?php

namespace App\Http\Resources;

use App\Domain\Access\AccessResolver;
use App\Domain\Access\Permissions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role?->value,
            'phone' => $this->phone,
            'avatar_url' => $this->avatar_url,
            'is_active' => $this->is_active,
            // Null once the date has passed, so the interface never has to work
            // out whether "away until last Tuesday" still means anything.
            'away_until' => $this->away_until?->isFuture() ? $this->away_until->toIso8601String() : null,
            // Empty means every city; one means an equal share.
            'covers_cities' => $this->covers_cities ?? [],
            'assignment_weight' => (int) ($this->assignment_weight ?? 1),
            'handles' => $this->handles ?? [],
            // The projects a project-scoped role was given; empty for everybody else.
            'partner' => $this->whenLoaded('partner', fn () => $this->partner ? ['id' => $this->partner->uuid, 'name' => $this->partner->name] : null, null),
            'project_ids' => $this->whenLoaded('projects', fn () => $this->projects->pluck('uuid')->all(), []),
            'permissions' => $this->when($request->user()?->role?->managesTenant() === true, fn () => Permissions::forUser($this->resource)),
            'access_role' => AccessResolver::customRole($this->resource)?->uuid,
            // Empty means every language, the same way empty coverage means
            // every city. Distinct from `locale`, which is this person's own
            // choice of interface language and says nothing about who they can
            // sell to.
            'speaks' => $this->speaks ?? [],
            'locale' => $this->locale,
            'email_verified' => $this->email_verified_at !== null,
            'google_linked' => $this->google_id !== null,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            // When they last used any session — Sanctum stamps it on every request, so this costs nothing extra.
            'last_active_at' => isset($this->tokens_max_last_used_at) ? Carbon::parse($this->tokens_max_last_used_at)->toIso8601String() : null,
            'all_projects' => (bool) ($this->all_projects ?? true),
            'created_at' => $this->created_at?->toIso8601String(),
            'leads_count' => $this->when(isset($this->leads_count), fn () => (int) $this->leads_count),
        ];
    }
}
