<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One structured fact a conversation yielded. Kind and value come from a closed list; see Conversation\Vocabulary. */
class LeadFact extends Model
{
    use BelongsToTenant;

    protected $fillable = ['lead_id', 'kind', 'value', 'detail', 'confidence', 'source', 'message_id', 'extracted_at'];

    protected function casts(): array
    {
        return ['detail' => 'array', 'confidence' => 'integer', 'extracted_at' => 'datetime'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
