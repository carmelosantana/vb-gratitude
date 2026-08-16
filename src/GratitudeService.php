<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbGratitude;

use App\Plugins\PluginSettings;
use Illuminate\Database\QueryException;
use Vctrs\Plugins\Gamification\GamificationService;
use Vctrs\Plugins\StaffHub\StaffDirectory;
use Vctrs\Plugins\VbGratitude\Models\GratitudeBadgeAward;
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

    /**
     * Single source of truth for the GIVING-based milestone badges.
     *
     * Every badge here is computable purely from THIS tenant's own
     * vb_gratitude_shoutouts rows by the giver — no cross-plugin seam, no PII.
     * `kind` decides which of the giver's counts the threshold is measured
     * against:
     *   - 'total'                → total shoutouts the giver has recorded;
     *   - 'distinct_recipients'  → distinct recipient_staff_id the giver has tagged.
     *
     * Add a row here to add a badge; nothing else in the evaluator changes.
     *
     * NOTE: the received-based `appreciated` badge is intentionally absent. It
     * needs a user→staff mapping the sanctioned StaffDirectory seam does not
     * expose; it awaits a core StaffDirectory::staffIdForUser() seam.
     *
     * @var array<string, array{kind: 'total'|'distinct_recipients', threshold: int}>
     */
    private const GIVING_BADGES = [
        'first_thanks' => ['kind' => 'total', 'threshold' => 1],
        'grateful_regular' => ['kind' => 'total', 'threshold' => 10],
        'team_connector' => ['kind' => 'distinct_recipients', 'threshold' => 5],
        'gratitude_champion' => ['kind' => 'total', 'threshold' => 50],
    ];

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

        // BADGE-EVALUATION HOOK: the shoutout + any point award have landed, so
        // evaluate the giver's giving-milestone badges and award any newly earned
        // one. Kept off the persisted-shoutout return path — a badge insert never
        // blocks or alters the shoutout the caller just made.
        $this->evaluateGivingBadges($giverUserId);

        return $shoutout;
    }

    /**
     * Evaluate the giver's GIVING-milestone badges and award any newly earned.
     *
     * Computed entirely from this tenant's own shoutouts (BelongsToTenant scopes
     * every query), so it needs no cross-plugin seam and touches no PII. At most
     * one COUNT per `kind` runs (memoized), regardless of how many badges share a
     * kind, so the core shoutout path stays cheap.
     *
     * Received-based badges (e.g. `appreciated`) are deliberately NOT evaluated
     * here — they await a core StaffDirectory::staffIdForUser() seam.
     */
    private function evaluateGivingBadges(string $giverUserId): void
    {
        $totalGiven = null;
        $distinctRecipients = null;

        foreach (self::GIVING_BADGES as $badgeKey => $def) {
            $count = match ($def['kind']) {
                'total' => $totalGiven ??= GratitudeShoutout::query()
                    ->where('giver_user_id', $giverUserId)
                    ->count(),
                'distinct_recipients' => $distinctRecipients ??= GratitudeShoutout::query()
                    ->where('giver_user_id', $giverUserId)
                    ->distinct()
                    ->count('recipient_staff_id'),
            };

            if ($count >= $def['threshold']) {
                $this->awardBadge($giverUserId, $badgeKey);
            }
        }
    }

    /**
     * Award $badgeKey to $userId once, idempotently.
     *
     * Each user earns each badge at most once (unique per user+badge in this
     * tenant). The exists() check skips the common re-crossing case; the
     * try/catch swallows the losing side of a concurrent double-award, which the
     * table's unique index rejects — so a duplicate is ignored, never a crash.
     * BelongsToTenant stamps tenant_type/tenant_id on the new row.
     */
    private function awardBadge(string $userId, string $badgeKey): void
    {
        $alreadyEarned = GratitudeBadgeAward::query()
            ->where('user_id', $userId)
            ->where('badge_key', $badgeKey)
            ->exists();

        if ($alreadyEarned) {
            return;
        }

        try {
            $award = new GratitudeBadgeAward;
            $award->user_id = $userId;
            $award->badge_key = $badgeKey;
            $award->earned_at = now();
            $award->save();
        } catch (QueryException) {
            // A racing insert already awarded this badge (unique index violation).
            // Idempotent by design: the badge exists, so swallow the duplicate.
        }
    }
}
