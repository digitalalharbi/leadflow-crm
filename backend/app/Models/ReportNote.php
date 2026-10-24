<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a person wrote about the period: the executive note, the marketing note, the sales note, a summary of the period.
 * Plain text only. Editing never overwrites: the old revision stays, marked not current, with who wrote it and when.
 */
class ReportNote extends Model
{
    use BelongsToTenant, HasUuid;

    public const KINDS = ['executive', 'marketing', 'sales', 'period'];

    protected $fillable = ['tenant_id', 'report_share_id', 'kind', 'body', 'revision', 'is_current', 'author_id'];

    protected function casts(): array
    {
        return ['is_current' => 'boolean', 'revision' => 'integer'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
