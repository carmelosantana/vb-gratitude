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
     * Single source of truth for the milestone badges.
     *
     * `kind` decides which of the giver's counts the threshold is measured
     * against:
     *   - 'total'                → total shoutouts the giver has recorded;
     *   - 'distinct_recipients'  → distinct recipient_staff_id the giver has tagged;
     *   - 'received'             → shoutouts the giver has RECEIVED (total, this
     *                              tenant), resolved through the sanctioned PII-free
     *                              StaffDirectory::staffIdForUser seam. Skipped when
     *                              that seam is unavailable or the giver is unmapped.
     *
     * The 'total'/'distinct_recipients' badges are computable purely from THIS
     * tenant's own vb_gratitude_shoutouts rows — no cross-plugin seam, no PII. The
     * 'received' badge (`appreciated`) additionally needs the giver's own staff id,
     * which comes ONLY from StaffDirectory (never a StaffHub model).
     *
     * Add a row here to add a badge; nothing else in the evaluator changes.
     *
     * @var array<string, array{kind: 'total'|'distinct_recipients'|'received', threshold: int}>
     */
    private const BADGES = [
        'first_thanks' => ['kind' => 'total', 'threshold' => 1],
        'grateful_regular' => ['kind' => 'total', 'threshold' => 10],
        'team_connector' => ['kind' => 'distinct_recipients', 'threshold' => 5],
        'gratitude_champion' => ['kind' => 'total', 'threshold' => 50],
        // TIMING: because badges are evaluated only on the GIVER's shoutout-create,
        // a user earns `appreciated` the next time they GIVE a shoutout after
        // crossing 10 received — acceptable, since evaluation runs on create.
        'appreciated' => ['kind' => 'received', 'threshold' => 10],
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
        // evaluate the giver's milestone badges and award any newly earned one.
        // Kept off the persisted-shoutout return path — a badge insert never blocks
        // or alters the shoutout the caller just made.
        $this->evaluateBadges($tenantType, $tenantId, $giverUserId);

        return $shoutout;
    }

    /**
     * Evaluate the giver's milestone badges and award any newly earned.
     *
     * The giving-based counts ('total'/'distinct_recipients') are computed entirely
     * from this tenant's own shoutouts (BelongsToTenant scopes every query), so they
     * need no cross-plugin seam and touch no PII. The received-based `appreciated`
     * badge additionally resolves the giver's OWN staff id through the sanctioned
     * StaffDirectory::staffIdForUser seam and counts shoutouts addressed to it.
     *
     * At most one COUNT per `kind` runs (memoized), regardless of how many badges
     * share a kind, so the core shoutout path stays cheap. When the received count
     * is unavailable (host predates the seam, or the giver has no staff mapping)
     * received-based badges are simply skipped — never a crash.
     */
    private function evaluateBadges(string $tenantType, string $tenantId, string $giverUserId): void
    {
        $totalGiven = null;
        $distinctRecipients = null;

        // Resolved lazily on first 'received' badge; null = unavailable → skip.
        $receivedResolved = false;
        $receivedCount = null;

        foreach (self::BADGES as $badgeKey => $def) {
            if ($def['kind'] === 'received') {
                if (! $receivedResolved) {
                    $receivedCount = $this->receivedCountForGiver($tenantType, $tenantId, $giverUserId);
                    $receivedResolved = true;
                }
                if ($receivedCount === null) {
                    continue; // seam unavailable or unmapped giver → skip
                }
                $count = $receivedCount;
            } else {
                $count = match ($def['kind']) {
                    'total' => $totalGiven ??= GratitudeShoutout::query()
                        ->where('giver_user_id', $giverUserId)
                        ->count(),
                    'distinct_recipients' => $distinctRecipients ??= GratitudeShoutout::query()
                        ->where('giver_user_id', $giverUserId)
                        ->distinct()
                        ->count('recipient_staff_id'),
                };
            }

            if ($count >= $def['threshold']) {
                $this->awardBadge($giverUserId, $badgeKey);
            }
        }
    }

    /**
     * The giver's OWN received-shoutout count (total, this tenant), or null when it
     * can't be determined without PII: the host predates StaffDirectory::staffIdForUser,
     * or the giver has no active staff record in this tenant.
     *
     * Staff resolution is ONLY through StaffDirectory — never a StaffHub model,
     * never a hand-join. BelongsToTenant scopes the shoutout count to this tenant.
     */
    private function receivedCountForGiver(string $tenantType, string $tenantId, string $giverUserId): ?int
    {
        // DEFENSIVE GUARD for hosts that predate the seam: degrade to null rather
        // than fataling on a BadMethodCall.
        if (! method_exists($this->staff, 'staffIdForUser')) {
            return null;
        }

        $staffId = $this->staff->staffIdForUser($tenantType, $tenantId, $giverUserId);
        if ($staffId === null) {
            return null; // unmapped giver
        }

        return GratitudeShoutout::query()
            ->where('recipient_staff_id', $staffId)
            ->count();
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
