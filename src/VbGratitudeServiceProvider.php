<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbGratitude;

use App\Plugins\Contracts\PluginModule;
use App\Plugins\Contracts\ProvidesScheduledTasks;
use App\Plugins\PluginManifest;
use App\Plugins\Scheduling\PluginScheduledJob;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Route;
use Vctrs\Plugins\StaffHub\StaffDirectory;
use Vctrs\Plugins\VbGratitude\Models\GratitudeShoutout;

/**
 * Gratitude — plugin module contract.
 *
 * Constructed by the host as new self(PluginManifest $manifest, string $dir).
 *
 * WIDGET CONTRACT: every key returned by widgets() must also appear in
 * manifest.json widgets[], and vice versa. The manifest puts the CARD on the
 * dashboard; this method supplies the RESOLVER. Declare one without the other
 * and the card renders, then 404s. Both are generated from plugin.spec.json, so
 * do not hand-edit one without the other.
 *
 * Resolvers return ['type' => 'metric'|'list'|'chart', 'payload' => [...]].
 * The host renders nothing else, and 'chart' is donut-only.
 */
class VbGratitudeServiceProvider implements PluginModule, ProvidesScheduledTasks
{
    public function __construct(
        private readonly PluginManifest $manifest,
        private readonly string $dir,
    ) {}

    public function manifest(): PluginManifest
    {
        return $this->manifest;
    }

    public function register(): void
    {
        Route::group([], $this->dir.'/src/routes.php');
    }

    public function navItems(): array
    {
        return $this->manifest->nav;
    }

    public function widgets(): array
    {
        return [
            // How many shoutouts the CURRENT actor has GIVEN in the current calendar
            // month. Context is resolved AT CALL TIME from the request TenantContext
            // (the resolver runs inside the widget request), never at registration.
            // BelongsToTenant on GratitudeShoutout scopes the count to the active
            // tenant, so there is never a hand-written tenant_id filter here.
            'vb-gratitude.givenThisMonth' => [
                'vb-gratitude.shoutouts.read.rooftop',
                function (): array {
                    $count = GratitudeShoutout::query()
                        ->where('giver_user_id', app(TenantContext::class)->userId())
                        ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                        ->count();

                    return [
                        'type' => 'metric',
                        'payload' => [
                            'label' => 'Shoutouts you\'ve given this month',
                            'value' => $count,
                        ],
                    ];
                },
            ],
            // How many shoutouts the CURRENT actor has RECEIVED this month. Shoutouts
            // are addressed to a STAFF id (recipient_staff_id), so counting received
            // requires mapping the current USER to their staff-employee id — done
            // through the sanctioned PII-free StaffDirectory::staffIdForUser seam via
            // the currentUserStaffId() choke-point below (never a StaffHub model).
            //
            // When that mapping yields null (an unmapped user, or a host that predates
            // the seam) the resolver still returns a clean metric of 0 — it never
            // errors. BelongsToTenant scopes the count to the active tenant, and the
            // explicit month whereBetween mirrors givenThisMonth.
            'vb-gratitude.receivedThisMonth' => [
                'vb-gratitude.shoutouts.read.rooftop',
                function (): array {
                    $recipientStaffId = $this->currentUserStaffId();

                    $count = $recipientStaffId === null
                        ? 0
                        : GratitudeShoutout::query()
                            ->where('recipient_staff_id', $recipientStaffId)
                            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                            ->count();

                    return [
                        'type' => 'metric',
                        'payload' => [
                            'label' => 'Shoutouts you\'ve received this month',
                            'value' => $count,
                        ],
                    ];
                },
            ],
            // The tenant's most recent shoutouts (newest first, small limit). Recipient
            // names are enriched ONLY through StaffDirectory::lookup(includePii:false)
            // — safe fields, no PII, graceful null on a miss — never a StaffHub model.
            'vb-gratitude.recentShoutouts' => [
                'vb-gratitude.shoutouts.read.rooftop',
                function (): array {
                    $ctx = app(TenantContext::class);
                    $staff = app(StaffDirectory::class);

                    $rows = GratitudeShoutout::query()
                        ->orderByDesc('created_at')
                        ->limit(5)
                        ->get()
                        ->map(function (GratitudeShoutout $s) use ($ctx, $staff): array {
                            $recipient = $staff->lookup(
                                $ctx->activeTenantType(),
                                $ctx->activeTenantId(),
                                $s->recipient_staff_id,
                                false,
                            );

                            return [
                                'message' => $s->message,
                                'recipient_name' => $recipient['display_name'] ?? null,
                                'created_at' => $s->created_at?->toIso8601String(),
                            ];
                        })
                        ->all();

                    return [
                        'type' => 'list',
                        'payload' => [
                            'rows' => $rows,
                        ],
                    ];
                },
            ],
        ];
    }

    /**
     * Map the current actor to their active staff-employee id in-tenant.
     *
     * Resolved ONLY through the sanctioned PII-free StaffDirectory::staffIdForUser
     * seam — never a StaffHub model, never a hand-join. Returns null when the user
     * has no active staff record in this tenant (unmapped user).
     *
     * DEFENSIVE GUARD: the seam is a recent host addition, so on an older host
     * that predates it we degrade to null (best-effort 0) rather than fataling on
     * a BadMethodCall. Kept as the single choke-point so both received-based read
     * paths share one mapping.
     */
    private function currentUserStaffId(): ?string
    {
        if (! method_exists(StaffDirectory::class, 'staffIdForUser')) {
            return null; // host predates the staffIdForUser seam
        }

        $ctx = app(TenantContext::class);

        return app(StaffDirectory::class)->staffIdForUser(
            $ctx->activeTenantType(),
            $ctx->activeTenantId(),
            $ctx->userId(),
        );
    }

    public function permissions(): array
    {
        return [];
    }

    /**
     * @return array<int, array{key: string, cron: string, perTenant: bool, job: class-string<PluginScheduledJob>}>
     */
    public function scheduledTasks(): array
    {
        return [
            [
                'key' => 'vb-gratitude/morning-reminder',
                'cron' => '0 9 * * *',
                'perTenant' => true,
                'job' => Jobs\MorningReminderJob::class,
            ],
        ];
    }
}
