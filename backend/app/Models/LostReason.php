<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LostReason extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    protected $fillable = ['key', 'label', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Seeded for every new tenant; admins can add/edit afterwards. */
    public const DEFAULTS = [
        ['key' => 'not_interested', 'label' => 'غير مهتم'],
        ['key' => 'no_response', 'label' => 'لا يوجد رد'],
        ['key' => 'budget', 'label' => 'الميزانية غير مناسبة'],
        ['key' => 'financing', 'label' => 'تعذر التمويل'],
        ['key' => 'location', 'label' => 'الموقع غير مناسب'],
        ['key' => 'price', 'label' => 'السعر مرتفع'],
        ['key' => 'duplicate', 'label' => 'عميل مكرر'],
        ['key' => 'wrong_number', 'label' => 'رقم خاطئ'],
        ['key' => 'bought_elsewhere', 'label' => 'اشترى من جهة أخرى'],
        ['key' => 'other', 'label' => 'سبب آخر'],
    ];
}
