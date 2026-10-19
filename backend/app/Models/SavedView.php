<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** A set of lead filters somebody wants one press away. Personal: it is the creator's alone. */
class SavedView extends Model
{
    use BelongsToTenant, HasUuid;

    /** The only filters a saved view may carry — the ones the leads list understands. */
    public const FILTERS = [
        'search', 'status', 'temperature', 'classification', 'platform', 'project_id', 'assigned_to',
        'overdue', 'priority', 'wrong_fit', 'unassigned', 'awaiting_contact', 'replied',
        'campaign_name', 'follow_up', 'view', 'qualification_status', 'sort', 'direction',
    ];

    protected $fillable = ['user_id', 'name', 'filters', 'sort_order'];

    protected function casts(): array
    {
        return ['filters' => 'array'];
    }
}
