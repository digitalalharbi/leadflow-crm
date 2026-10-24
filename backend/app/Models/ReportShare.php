<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/** A live report behind a link: what it shows, who may open it, until when, and how often it was opened. */
class ReportShare extends Model
{
    use BelongsToTenant, HasUuid;

    public const STATES = ['draft', 'live', 'archived'];

    public const RANGES = ['today', 'yesterday', 'last_7', 'last_14', 'last_30', 'this_month', 'previous_month', 'quarter', 'custom'];

    public const MODELS = ['first', 'last', 'linear', 'position'];

    protected $fillable = [
        'name', 'state', 'client_name', 'template', 'sections', 'layout', 'compare', 'compare_mode', 'compare_from', 'compare_to',
        'refresh_seconds', 'days', 'period', 'period_from', 'period_to', 'attribution_model', 'currency',
        'filters', 'branding', 'options', 'project_id', 'partner_id', 'created_by', 'revision', 'published_at', 'archived_at',
    ];

    // The old link columns are no longer written or read: a link lives in `short_links`.
    protected $hidden = ['token_hash', 'token_enc', 'password_hash'];

    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'layout' => 'array',
            'compare' => 'boolean',
            'refresh_seconds' => 'integer',
            'days' => 'integer',
            'period_from' => 'date',
            'period_to' => 'date',
            'compare_from' => 'date',
            'compare_to' => 'date',
            'filters' => 'array',
            'branding' => 'array',
            'options' => 'array',
            'revision' => 'integer',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The deliveries this report makes on its own. */
    public function schedules(): HasMany
    {
        return $this->hasMany(ReportSchedule::class);
    }

    /** @return list<int> the projects the report covers: its project list, or the one project it started with. Empty = every project. */
    public function projectIds(): array
    {
        $ids = DB::table('report_share_projects')->where('report_share_id', $this->id)->pluck('project_id')->map(fn ($i) => (int) $i)->all();

        return $ids !== [] ? $ids : ($this->project_id ? [(int) $this->project_id] : []);
    }

    /** @return list<array{id:string,name:string}> */
    public function projectNames(): array
    {
        $ids = $this->projectIds();

        return $ids === [] ? [] : Project::withoutGlobalScopes()->whereIn('id', $ids)->orderBy('name')->get(['uuid', 'name'])->map(fn ($p) => ['id' => $p->uuid, 'name' => $p->name])->all();
    }

    /** Replaces the project list. The first project is also kept as `project_id`, which older code still reads. */
    public function syncProjects(array $projectIds): void
    {
        $projectIds = array_values(array_unique(array_map('intval', $projectIds)));

        DB::table('report_share_projects')->where('report_share_id', $this->id)->delete();

        foreach ($projectIds as $id) {
            DB::table('report_share_projects')->insert(['tenant_id' => $this->tenant_id, 'report_share_id' => $this->id, 'project_id' => $id]);
        }

        $this->forceFill(['project_id' => $projectIds[0] ?? null])->saveQuietly();
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ReportNote::class)->where('is_current', true)->orderBy('id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ReportRevision::class)->orderByDesc('revision');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ReportActivity::class)->orderByDesc('id');
    }

    /** Every address this report has been given: one per client, each with its own password, expiry and count. */
    public function links(): HasMany
    {
        return $this->hasMany(ShortLink::class, 'resource_id')->where('type', 'report')->oldest('id');
    }

    /** The report's own link: the first one that is still good, otherwise the first one it ever had. */
    public function primaryLink(): ?ShortLink
    {
        $links = $this->relationLoaded('links') ? $this->links : $this->links()->get();

        return $links->first(fn (ShortLink $l) => ! $l->isRevoked()) ?? $links->first();
    }

    public function url(): string
    {
        return $this->primaryLink()?->url() ?? '';
    }

    public function token(): ?string
    {
        return $this->primaryLink()?->token();
    }

    /** active · expired · revoked — of the report's own link. A report with no link has nothing open. */
    public function status(): string
    {
        return $this->primaryLink()?->status() ?? 'revoked';
    }

    public function hasPassword(): bool
    {
        return (bool) $this->primaryLink()?->hasPassword();
    }

    /** The report's state in the library, apart from its links: draft · live · archived. */
    public function isLive(): bool
    {
        return ($this->state ?? 'live') === 'live';
    }
}
