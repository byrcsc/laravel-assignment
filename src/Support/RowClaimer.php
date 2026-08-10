<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** @internal */
final class RowClaimer
{
    /**
     * @template ClaimedModel of Model
     *
     * @param  Builder<ClaimedModel>  $query
     * @return ClaimedModel|null
     */
    public function claim(Builder $query): ?Model
    {
        $driver = $query->getModel()->getConnection()->getDriverName();

        if ($driver !== 'sqlite') {
            return $query->lock('for update skip locked')->first();
        }

        $claimed = (clone $query)->update([
            'updated_at' => $query->getQuery()->raw('updated_at'),
        ]);

        if ($claimed === 0) {
            return null;
        }

        return $query->first();
    }
}
