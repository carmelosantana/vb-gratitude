<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbGratitude\Jobs;

use App\Events\FeedEventRequested;
use App\Plugins\HostStore;
use App\Plugins\PluginSettings;
use App\Plugins\Scheduling\PluginScheduledJob;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Send a gentle morning gratitude prompt to opted-in users.
 *
 * The host dispatches one instance per enabled tenant each morning (cron
 * `0 9 * * *`, perTenant). handle() (base class) re-establishes the tenant
 * context via SystemContext::runAsTenant() before calling run(); author logic
 * therefore lives in run() and never overrides handle().
 *
 * Seams used (all sanctioned host mechanisms — see docs/plugins/capabilities.md):
 *   - admin gate `reminderEnabled` ....... App\Plugins\PluginSettings::resolve()
 *   - enumerate this tenant's users ...... host `memberships` JOIN `users`
 *   - per-user `morningReminderOptIn` .... App\Plugins\HostStore (per-tenant KV)
 *   - deliver the nudge .................. App\Events\FeedEventRequested
 *
 * The manifest declares `morningReminderOptIn` under settings.userPreferences,
 * but the host has NO per-user preference store or read seam (PluginSettings
 * cascades admin/group/tenant settings only). The plugin's own per-tenant KV
 * store (HostStore) is the cleanest sanctioned home for the opt-out: an entry
 * under namespace `user-preferences` keyed by user id records a user's choice;
 * the default (no entry) is opted-in.
 */
class MorningReminderJob extends PluginScheduledJob
{
    private const SLUG = 'vb-gratitude';

    /** HostStore namespace holding per-user reminder preferences (key = user id). */
    private const PREF_NAMESPACE = 'user-preferences';

    /** Preference flag stored in each entry's value; absent entry ⇒ default true. */
    private const PREF_KEY = 'morningReminderOptIn';

    /** Warm, short nudge — no streak, no pressure. */
    private const PROMPT = 'Who made your day? Share a gratitude shoutout.';

    /** Cap the per-tenant fan-out so one tenant can never emit unboundedly. */
    private const MAX_RECIPIENTS = 1000;

    protected function run(): void
    {
        // 1. Admin gate — tenant may have turned the daily prompt off.
        if (! (app(PluginSettings::class)->resolve(self::SLUG)['reminderEnabled'] ?? true)) {
            return;
        }

        // 2. Enumerate THIS tenant's active users via the host membership table.
        $userIds = DB::table('memberships')
            ->join('users', 'users.id', '=', 'memberships.user_id')
            ->where('memberships.tenant_type', $this->tenantType)
            ->where('memberships.tenant_id', $this->tenantId)
            ->where('memberships.status', 'active')
            ->where('users.status', 'active')
            ->orderBy('memberships.created_at')
            ->limit(self::MAX_RECIPIENTS)
            ->pluck('memberships.user_id')
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return;
        }

        // 3. Read opt-out preferences in one shot (default = opted in).
        $optOut = [];
        foreach (app(HostStore::class)->list(self::SLUG, self::PREF_NAMESPACE, self::MAX_RECIPIENTS) as $entry) {
            $value = is_array($entry->value_json) ? $entry->value_json : [];
            if (($value[self::PREF_KEY] ?? true) === false) {
                $optOut[$entry->key] = true;
            }
        }

        // 4. Deliver one gentle prompt per opted-in user.
        foreach ($userIds as $userId) {
            if (isset($optOut[$userId])) {
                continue;
            }

            event(new FeedEventRequested(
                tenantType: $this->tenantType,
                tenantId: $this->tenantId,
                actorType: 'system',
                actorId: TenantContext::SYSTEM_ACTOR,
                sourceType: 'user',
                sourceId: (string) $userId,
                pluginNamespace: self::SLUG,
                eventType: 'vb-gratitude.morning_reminder',
                summary: self::PROMPT,
                detailPayload: ['recipientUserId' => (string) $userId],
            ));
        }
    }
}
