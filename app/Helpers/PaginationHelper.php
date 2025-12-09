<?php

namespace App\Helpers;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

class PaginationHelper
{
    /**
     * Get validated pagination parameters
     *
     * @param Request $request
     * @param int $defaultPerPage Default items per page
     * @param int $maxPerPage Maximum items per page
     * @return array ['per_page' => int, 'page' => int]
     */
    public static function getPaginationParams(Request $request, int $defaultPerPage = 20, int $maxPerPage = 100): array
    {
        $validated = $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:' . $maxPerPage,
            'page' => 'sometimes|integer|min:1',
        ]);

        return [
            'per_page' => $validated['per_page'] ?? $defaultPerPage,
            'page' => $validated['page'] ?? 1,
        ];
    }

    /**
     * Paginate a query builder with validated parameters
     *
     * @param Builder $query
     * @param Request $request
     * @param int $defaultPerPage
     * @param int $maxPerPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public static function paginate(Builder $query, Request $request, int $defaultPerPage = 20, int $maxPerPage = 100)
    {
        $params = self::getPaginationParams($request, $defaultPerPage, $maxPerPage);
        return $query->paginate($params['per_page'], ['*'], 'page', $params['page']);
    }
}

