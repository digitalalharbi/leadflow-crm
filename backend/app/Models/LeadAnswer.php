<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadAnswer extends Model
{
    use BelongsToTenant;

    protected $fillable = ['lead_id', 'project_question_id', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ProjectQuestion::class, 'project_question_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
