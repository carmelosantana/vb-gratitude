<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbGratitude\Http\Controllers;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * HTTP surface for the "shoutouts" API resource.
 *
 * Routes are NOT auto-registered by the generator — wire them up by hand
 * in src/routes.php, each with a `can:` gate (see the docblock there).
 *
 * Every handler MUST return the canonical ApiResponse envelope
 * {traceId, data, status} via App\Support\ApiResponse::success()/error(),
 * so the vendored axios client kit (ui/plugin-ui/client.ts) can unwrap it.
 */
class ShoutoutsController
{
    public function index(): JsonResponse
    {
        // TODO: implement. Return ApiResponse::success() with real data.
        return ApiResponse::success([]);
    }
}
