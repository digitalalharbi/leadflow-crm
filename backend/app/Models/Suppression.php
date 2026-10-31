<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** One address or number this company must not message automatically, and why. */
class Suppression extends Model
{
    use BelongsToTenant, HasUuid;

    public const CHANNELS = ['email', 'phone'];

    /** opt_out: they asked · bounced: the address does not exist · manual: staff added it · complaint: marked as spam. */
    public const REASONS = ['opt_out', 'bounced', 'manual', 'complaint'];

    protected $fillable = ['tenant_id', 'channel', 'value', 'reason', 'note', 'created_by'];
}
