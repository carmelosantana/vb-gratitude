<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbGratitude\Http\Controllers;

use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vctrs\Plugins\VbGratitude\Models\GratitudeBadgeAward;

/**
 * HTTP surface for the "badges" API resource.
 *
 * Routes are wired by hand in src/routes.php, each behind a `can:` gate.
 *
 * Every handler returns the canonical ApiResponse envelope {traceId, data, status}
 * via App\Support\ApiResponse::success()/error(), so the vendored axios client kit
 * (ui/plugin-ui/client.ts) can unwrap it.
 */
class BadgesController
{
    /**
     * GET /api/v1/vb-gratitude/badges — the acting user's earned badge awards,
     * newest-first. Badge EVALUATION is a later task; this endpoint just lists
     * whatever awards exist (an empty list is expected for now).
     *
     * BelongsToTenant scopes the awards to the current tenant; the user filter
     * narrows them to the caller.
     */
    public function index(Request $request): JsonResponse
    {
        $uid = app(TenantContext::class)->userId();

        $badges = GratitudeBadgeAward::query()
            ->where('user_id', $uid)
            ->orderByDesc('earned_at')
            ->get(['badge_key', 'earned_at']);

        return ApiResponse::success(['badges' => $badges]);
    }
}
