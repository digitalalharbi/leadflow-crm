<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A platform's lead form, tied to a project and to which answer goes where. From then on its leads arrive in that project. */
class LeadFormMapping extends Model
{
    use BelongsToTenant, HasUuid;

    public const PROVIDERS = ['meta', 'tiktok', 'snapchat', 'google_ads', 'linkedin'];

    protected $fillable = ['project_id', 'provider', 'account_ref', 'form_ref', 'form_name', 'field_mapping', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['field_mapping' => 'array', 'is_active' => 'boolean'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
