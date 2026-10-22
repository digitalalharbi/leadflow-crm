<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * What one campaign cost on one day.
 *
 * Money is stored in minor units and never as a float: 15.33 SAR is 1533. A
 * cost-per-lead that is out by a hundredth of a riyal is not a rounding error
 * anybody notices, but a budget total that disagrees with the ad account by
 * eleven halalas is one somebody will spend an afternoon on.
 */
class CampaignSpend extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'platform', 'campaign_name', 'spend_date',
        'amount_minor', 'currency', 'source', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'spend_date' => 'date',
            'amount_minor' => 'integer',
        ];
    }

    /** For display and for arithmetic a human will check by hand. */
    public function amount(): float
    {
        return $this->amount_minor / 100;
    }
}
