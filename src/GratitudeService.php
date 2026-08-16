<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbGratitude;

use App\Plugins\PluginSettings;
use Vctrs\Plugins\Gamification\GamificationService;
use Vctrs\Plugins\StaffHub\StaffDirectory;
use Vctrs\Plugins\VbGratitude\Models\GratitudeShoutout;

/**
 * The heart of vb-gratitude: record a teammate shoutout and award points for
 * giving it.
 *
 * Three hard seams, never bypassed:
 *   - recipient existence is checked ONLY through StaffDirectory (PII-free) —
 *     this service never touches a StaffHub model;
 *   - points are minted ONLY through the core GamificationService ledger —
 *     there is no local points table;
 *   - tenant isolation is carried ONLY by BelongsToTenant on GratitudeShoutout
 *     (via PluginModel) — no hand-written tenant_id filters here.
 */
class GratitudeService
{
    /** Plugin slug — the key the host uses for settings + gamification source. */
    private const SLUG = 'vb-gratitude';

    /** Gamification event key for a given shoutout (auto-creates a rule on first award). */
    private const EVENT_KEY = 'gratitude.shoutout.given';

    /** Manifest defaults, mirrored here as the fallback when a setting is unset. */
    private const DEFAULT_POINTS_PER_SHOUTOUT = 5;

    private const DEFAULT_DAILY_ALLOWANCE = 3;

    public function __construct(
        private readonly StaffDirectory $staff,
        private readonly GamificationService $gamification,
        private readonly PluginSettings $settings,
    ) {}

    /**
     * Record a shoutout from $giverUserId to $recipientStaffId and (while under
     * the giver's daily point-earning allowance) award points for it.
     *
     * The shoutout is ALWAYS recorded — giving is always encouraged. Points are
     * only earned up to `dailyShoutoutAllowance` per giver per day; beyond that the
     * row is still written but with points_awarded = 0 and no ledger entry, which
     * rewards generosity while capping point-farming.
     *
     * @throws GratitudeException when the recipient is not an active teammate in
     *                            this tenant (no row is written in that case).
     */
    public function createShoutout(
        string $tenantType,
        string $tenantId,
        string $giverUserId,
        string $recipientStaffId,
        string $message,
        ?string $category = null,
    ): GratitudeShoutout {
        // 1. Validate the recipient exists in THIS tenant. Existence only — the
        //    service never needs personal fields, so lookup runs without PII.
        $recipient = $this->staff->lookup($tenantType, $tenantId, $recipientStaffId, false);
        if ($recipient === null) {
            throw GratitudeException::unknownRecipient($recipientStaffId);
        }

        // 2. Resolve this plugin's admin settings through the host mechanism,
        //    falling back to the manifest defaults when a key is unset.
        $settings = $this->settings->resolve(self::SLUG);
        $pointsPerShoutout = (int) ($settings['pointsPerShoutout'] ?? self::DEFAULT_POINTS_PER_SHOUTOUT);
        $dailyAllowance = (int) ($settings['dailyShoutoutAllowance'] ?? self::DEFAULT_DAILY_ALLOWANCE);

        // 3. Daily allowance: how many point-earning shoutouts has this giver
        //    already banked today? BelongsToTenant scopes the count to the current
        //    tenant, so a giver's allowance is independent per tenant.
        $earnedToday = GratitudeShoutout::query()
            ->where('giver_user_id', $giverUserId)
            ->where('points_awarded', '>', 0)
            ->whereDate('created_at', now()->toDateString())
            ->count();
        $underDailyCap = $earnedToday < $dailyAllowance;

        // 4. Persist. BelongsToTenant stamps tenant_type/tenant_id on create;
        //    points_awarded starts at 0 and a successful award below overwrites it.
        $shoutout = new GratitudeShoutout;
        $shoutout->giver_user_id = $giverUserId;
        $shoutout->recipient_staff_id = $recipientStaffId;
        $shoutout->message = $message;
        $shoutout->category = $category;
        $shoutout->points_awarded = 0;
        $shoutout->save();

        // 5. Award points — ONLY while under the daily cap, ONLY via the core
        //    gamification ledger. The award idiom mirrors the reference call-sites
        //    (TaskController/TrainingController): positional
        //    (userId, tenantType, tenantId, eventKey, sourcePlugin, sourceRef, reason, defaultPoints).
        if ($underDailyCap) {
            $result = $this->gamification->award(
                $giverUserId,
                $tenantType,
                $tenantId,
                self::EVENT_KEY,
                self::SLUG,
                (string) $shoutout->id,
                'Gave a gratitude shoutout',
                $pointsPerShoutout,
            );

            $shoutout->points_awarded = (int) ($result['pointsAwarded'] ?? 0);
            $shoutout->save();
        }

        // BADGE-EVALUATION HOOK (later task): evaluate & award gratitude badges
        // (e.g. first_shoutout, top_helper milestones) here, AFTER the shoutout +
        // point award have landed. Intentionally NOT implemented in this task.

        return $shoutout;
    }
}
