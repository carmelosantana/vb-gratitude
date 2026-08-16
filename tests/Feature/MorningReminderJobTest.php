<?php

declare(strict_types=1);

/**
 * MorningReminderJob — the gentle daily gratitude prompt.
 *
 * Exercises the REAL host seams (no mocks): the admin gate via the host
 * PluginSettings cascade, user enumeration via the host memberships/users
 * tables (seeded through pluginTestUser), the per-user opt-out via the host
 * HostStore KV, and delivery via App\Events\FeedEventRequested (Event::fake).
 *
 * The job's per-tenant handle() re-binds a system TenantContext for the tenant
 * it is handed, so tests invoke it exactly as the host scheduler does:
 * (new MorningReminderJob('rooftop', $tenant))->handle().
 */

use App\Events\FeedEventRequested;
use App\Models\Membership;
use App\Models\User;
use App\Plugins\HostStore;
use App\Plugins\PluginSettings;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Vctrs\Plugins\VbGratitude\Jobs\MorningReminderJob;

require_once __DIR__.'/../bootstrap.php';

/**
 * Record a user's morningReminderOptIn choice through the host KV seam. Writes
 * under the given tenant's context (HostStore scopes by the active tenant).
 */
function gratitudeSetOptIn(string $userId, bool $optIn, string $tenantId = PLUGIN_TEST_TENANT): void
{
    $prior = app()->bound(TenantContext::class) ? app(TenantContext::class) : null;
    pluginBindTenant($userId, $tenantId);
    app(HostStore::class)->put('vb-gratitude', 'user-preferences', $userId, ['morningReminderOptIn' => $optIn]);
    if ($prior instanceof TenantContext) {
        app()->instance(TenantContext::class, $prior);
    }
}

beforeEach(function () {
    pluginRunMigrations();
});

it('emits nothing when reminderEnabled is off', function () {
    Event::fake([FeedEventRequested::class]);

    $owner = pluginTestUser('rooftop_owner');
    $ctx = pluginBindTenant($owner->id);

    // Install so PluginSettings discovers the manifest — the cascade only applies
    // a tenant override for keys the manifest declares.
    pluginInstallSignedAndBoot($ctx);
    app(PluginSettings::class)->setOverride('vb-gratitude', 'rooftop', PLUGIN_TEST_TENANT, [
        'reminderEnabled' => false,
    ]);

    pluginTestUser('rooftop_admin'); // an otherwise-eligible member

    (new MorningReminderJob('rooftop', PLUGIN_TEST_TENANT))->handle();

    Event::assertNotDispatched(FeedEventRequested::class);
});

it('emits one prompt per opted-in user, targeting each of them', function () {
    Event::fake([FeedEventRequested::class]);

    // reminderEnabled defaults to true (no override needed).
    $a = pluginTestUser('rooftop_owner');
    $b = pluginTestUser('rooftop_admin');
    $c = pluginTestUser('employee');

    (new MorningReminderJob('rooftop', PLUGIN_TEST_TENANT))->handle();

    // Exactly one prompt per member, all warm/pressure-free.
    Event::assertDispatchedTimes(FeedEventRequested::class, 3);

    foreach ([$a, $b, $c] as $u) {
        Event::assertDispatched(
            FeedEventRequested::class,
            fn ($e) => $e->sourceType === 'user'
                && $e->sourceId === $u->id
                && $e->tenantType === 'rooftop'
                && $e->tenantId === PLUGIN_TEST_TENANT
                && $e->actorType === 'system'
                && $e->actorId === TenantContext::SYSTEM_ACTOR
                && $e->pluginNamespace === 'vb-gratitude'
                && $e->eventType === 'vb-gratitude.morning_reminder'
                && $e->summary === 'Who made your day? Share a gratitude shoutout.'
                && ($e->detailPayload['recipientUserId'] ?? null) === $u->id
        );
    }
});

it('skips a user who opted out via the morningReminderOptIn preference', function () {
    Event::fake([FeedEventRequested::class]);

    $stayIn = pluginTestUser('rooftop_owner');
    $optedOut = pluginTestUser('rooftop_admin');

    gratitudeSetOptIn($optedOut->id, false);

    (new MorningReminderJob('rooftop', PLUGIN_TEST_TENANT))->handle();

    // Only the opted-in user is nudged; the opt-out receives nothing.
    Event::assertDispatchedTimes(FeedEventRequested::class, 1);
    Event::assertDispatched(
        FeedEventRequested::class,
        fn ($e) => $e->sourceId === $stayIn->id
    );
    Event::assertNotDispatched(
        FeedEventRequested::class,
        fn ($e) => $e->sourceId === $optedOut->id
    );
});

it('targets only THIS tenant\'s users (tenant isolation)', function () {
    Event::fake([FeedEventRequested::class]);

    $mine = pluginTestUser('rooftop_owner'); // in PLUGIN_TEST_TENANT

    // A user who belongs ONLY to a different tenant.
    $otherTenant = (string) Str::uuid();
    $stranger = User::create([
        'email' => uniqid('other', true).'@x.co',
        'display_name' => 'Other Tenant User',
        'status' => 'active',
    ]);
    Membership::create([
        'user_id' => $stranger->id,
        'tenant_type' => 'rooftop',
        'tenant_id' => $otherTenant,
        'role_key' => 'rooftop_owner',
        'status' => 'active',
    ]);

    (new MorningReminderJob('rooftop', PLUGIN_TEST_TENANT))->handle();

    Event::assertDispatchedTimes(FeedEventRequested::class, 1);
    Event::assertDispatched(FeedEventRequested::class, fn ($e) => $e->sourceId === $mine->id);
    Event::assertNotDispatched(FeedEventRequested::class, fn ($e) => $e->sourceId === $stranger->id);
});
