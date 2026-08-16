<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbGratitude\Http\Controllers;

use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vctrs\Plugins\StaffHub\StaffDirectory;
use Vctrs\Plugins\VbGratitude\GratitudeException;
use Vctrs\Plugins\VbGratitude\GratitudeService;
use Vctrs\Plugins\VbGratitude\Models\GratitudeShoutout;

/**
 * HTTP surface for the "shoutouts" API resource.
 *
 * Routes are wired by hand in src/routes.php, each behind a `can:` gate.
 *
 * Every handler returns the canonical ApiResponse envelope {traceId, data, status}
 * via App\Support\ApiResponse::success()/error(), so the vendored axios client kit
 * (ui/plugin-ui/client.ts) can unwrap it.
 */
class ShoutoutsController
{
    /** Newest-first feed cap. */
    private const FEED_LIMIT = 50;

    /**
     * POST /api/v1/vb-gratitude/shoutouts — record a shoutout for a teammate.
     *
     * Tenant + acting user come from the host TenantContext (built by the
     * `session-api`/`tenant` middleware). A GratitudeException (unknown recipient)
     * is mapped to a 422 in the envelope — a bad-caller-input signal, never a 500.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recipient_staff_id' => 'required|uuid',
            'message' => 'required|string|max:2000',
            'category' => 'nullable|string|max:100',
        ]);

        $ctx = app(TenantContext::class);

        try {
            $shoutout = app(GratitudeService::class)->createShoutout(
                $ctx->activeTenantType(),
                $ctx->activeTenantId(),
                $ctx->userId(),
                $data['recipient_staff_id'],
                $data['message'],
                $data['category'] ?? null,
            );
        } catch (GratitudeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(['shoutout' => $shoutout], 201);
    }

    /**
     * GET /api/v1/vb-gratitude/shoutouts — the tenant's recent shoutouts,
     * newest-first. BelongsToTenant scopes the query to the current tenant.
     *
     * Each row is enriched with the recipient's display name via the PII-free
     * StaffDirectory (names are SAFE_FIELDS — no PII gate, includePii stays false).
     * A recipient that no longer resolves degrades to a null name rather than
     * failing the feed.
     */
    public function index(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        $staff = app(StaffDirectory::class);

        $shoutouts = GratitudeShoutout::query()
            ->orderByDesc('created_at')
            ->limit(self::FEED_LIMIT)
            ->get();

        $rows = $shoutouts->map(function (GratitudeShoutout $shoutout) use ($ctx, $staff): array {
            $recipient = $staff->lookup(
                $ctx->activeTenantType(),
                $ctx->activeTenantId(),
                $shoutout->recipient_staff_id,
                false,
            );

            return [
                ...$shoutout->toArray(),
                'recipient_name' => $recipient['display_name'] ?? null,
            ];
        })->all();

        return ApiResponse::success(['shoutouts' => $rows]);
    }

    /**
     * GET /api/v1/vb-gratitude/teammates — the tag-a-teammate picker.
     *
     * Gated on create rights (you need them to tag someone). Returns
     * StaffDirectory::listAssignable, which projects SAFE_FIELDS only (names, no
     * work_email) — no PII gate needed.
     */
    public function teammates(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);

        $teammates = app(StaffDirectory::class)->listAssignable(
            $ctx->activeTenantType(),
            $ctx->activeTenantId(),
            null,
            $request->query('search'),
        );

        return ApiResponse::success(['teammates' => $teammates]);
    }
}
