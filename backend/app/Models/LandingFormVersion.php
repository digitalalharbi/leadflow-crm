<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A form as it was the moment somebody published it: frozen, so a running campaign and an old submission can always be explained. */
class LandingFormVersion extends Model
{
    use BelongsToTenant, HasUuid;

    protected $fillable = ['landing_form_id', 'version', 'variant', 'snapshot', 'checksum', 'published_by', 'published_at'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'published_at' => 'datetime'];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(LandingForm::class, 'landing_form_id')->withTrashed();
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
