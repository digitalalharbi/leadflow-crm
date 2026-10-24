<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/**
 * "Which projects": none (all of them), one, or several. The engines were written for one; a portfolio report is several,
 * and this keeps every engine asking the question the same way.
 */
final class ProjectIds
{
    /** @return list<int> */
    public static function list(int|array|null $ids): array
    {
        if ($ids === null) {
            return [];
        }

        return array_values(array_unique(array_map('intval', (array) $ids)));
    }

    /** @param  Builder|EloquentBuilder<*>  $query */
    public static function apply($query, string $column, int|array|null $ids)
    {
        $list = self::list($ids);

        return $list === [] ? $query : $query->whereIn($column, $list);
    }
}
