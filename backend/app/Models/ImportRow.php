<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRow extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['import_id', 'lead_id', 'row_number', 'status', 'data', 'error'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }
}
