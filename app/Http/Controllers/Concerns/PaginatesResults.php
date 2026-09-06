<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Backwards-compatible pagination for list endpoints that used to return the
 * whole table.
 *
 * Two rules make it non-breaking:
 *   - `per_page` is optional and each endpoint passes the default that keeps its
 *     current screen whole, so existing callers see no truncation;
 *   - an out-of-range `per_page` is clamped instead of rejected, so no caller
 *     starts getting a 422 it never got before.
 *
 * The ceiling is what makes the endpoint safe: nobody can ask for the full base
 * in a single page once the data grows.
 */
trait PaginatesResults
{
    /** Hard ceiling: no caller gets more rows than this in one page. */
    public const MAX_PER_PAGE = 200;

    protected function resolvePerPage(Request $request, int $default): int
    {
        $requested = (int) $request->input('per_page', $default);

        if ($requested < 1) {
            return $default;
        }

        return min($requested, self::MAX_PER_PAGE);
    }
}
