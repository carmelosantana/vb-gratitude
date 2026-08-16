<?php

declare(strict_types=1);

/**
 * The three dashboard widget resolvers — givenThisMonth, receivedThisMonth,
 * recentShoutouts — exercised end to end over the real host widget endpoint
 * (/api/v1/widgets/<key>).
 *
 * Each test reuses the proven install→enable→grant arrangement from
 * SignedInstallBootTest so the two-part widget registration (manifest card +
 * VbGratitudeServiceProvider::widgets() resolver) is wired for real; the
 * resolver then resolves the acting user + tenant from the request TenantContext
 * exactly as it will in production.
 */

use App\Models\Membership;
use App\Plugins\PluginLifecycle;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Vctrs\Plugins\StaffHub\Models\StaffHubEmployee;
use Vctrs\Plugins\VbGratitude\Models\GratitudeShoutout;

require_once __DIR__.'/../bootstrap.php';

afterEach(function () {
    File::deleteDirectory(storage_path('app/plugins/vb-gratitude'));
});

/**
 * Install + boot the signed plugin, activate it for the tenant, and grant the
 * acting user the widgets' read permission. Returns the acting user.
 */
function widgetsBootAndGrant(): App\Models\User
{
    $user = pluginTestUser('rooftop_owner');
    $ctx = pluginBindTenant($user->id);

    pluginInstallSignedAndBoot($ctx);
    app(PluginLifecycle::class)->enable('vb-gratitude');
    Membership::where('user_id', $user->id)
        ->update(['permission_overrides_json' => ['+vb-gratitude.shoutouts.read.rooftop']]);

    return $user;
}

/** Seed an active staff recipient in the test tenant and return its id. */
function widgetsSeedRecipient(array $over = []): string
{
    return StaffHubEmployee::withoutTenantScope()->create(array_merge([
        'tenant_type' => 'rooftop',
        'tenant_id' => PLUGIN_TEST_TENANT,
        'display_name' => 'Jordan Recipient',
        'first_name' => 'Jordan',
        'last_name' => 'Recipient',
        'personal_email' => 'jordan@home.example',
        'status' => 'active',
        'is_active' => true,
    ], $over))->id;
}

/**
 * Persist a shoutout row directly (bypassing the daily-allowance service) with a
 * chosen giver + created_at, so month/giver boundaries can be exercised exactly.
 * BelongsToTenant stamps the active tenant; created_at set before save is kept.
 */
function widgetsSeedShoutout(string $giverUserId, string $recipientStaffId, string $message, ?Carbon\CarbonInterface $createdAt = null): GratitudeShoutout
{
    $s = new GratitudeShoutout;
    $s->giver_user_id = $giverUserId;
    $s->recipient_staff_id = $recipientStaffId;
    $s->message = $message;
    $s->points_awarded = 0;
    if ($createdAt !== null) {
        $s->created_at = $createdAt;
        $s->updated_at = $createdAt;
    }
    $s->save();

    return $s;
}

it('counts only the current user\'s this-month shoutouts in givenThisMonth', function () {
    $user = widgetsBootAndGrant();
    $recipientId = widgetsSeedRecipient();
    $otherUser = (string) Str::uuid();

    // Three by the acting user THIS month — the only rows that must count.
    widgetsSeedShoutout($user->id, $recipientId, 'A', now());
    widgetsSeedShoutout($user->id, $recipientId, 'B', now());
    widgetsSeedShoutout($user->id, $recipientId, 'C', now());

    // Must NOT count: another user this month, and the acting user last month.
    widgetsSeedShoutout($otherUser, $recipientId, 'other user', now());
    widgetsSeedShoutout($user->id, $recipientId, 'last month', now()->startOfMonth()->subDay());

    $this->actingAs($user)
        ->getJson('/api/v1/widgets/vb-gratitude.givenThisMonth')
        ->assertOk()
        ->assertJsonPath('data.type', 'metric')
        ->assertJsonPath('data.payload.label', 'Shoutouts you\'ve given this month')
        ->assertJsonPath('data.payload.value', 3);
});

it('returns recent shoutouts newest-first, limited, with recipient_name enriched', function () {
    $user = widgetsBootAndGrant();
    $recipientId = widgetsSeedRecipient(['display_name' => 'Alex Helper']);

    // Six rows with distinct, increasing created_at so newest-first is deterministic.
    $base = now()->subMinutes(10);
    $labels = ['oldest', 'r2', 'r3', 'r4', 'r5', 'newest'];
    foreach ($labels as $i => $label) {
        widgetsSeedShoutout($user->id, $recipientId, $label, $base->copy()->addMinutes($i));
    }

    $res = $this->actingAs($user)
        ->getJson('/api/v1/widgets/vb-gratitude.recentShoutouts')
        ->assertOk()
        ->assertJsonPath('data.type', 'list');

    $rows = $res->json('data.payload.rows');

    // Limited to 5, newest first (so 'oldest' is dropped, 'newest' leads).
    expect($rows)->toHaveCount(5)
        ->and($rows[0]['message'])->toBe('newest')
        ->and($rows[4]['message'])->toBe('r2')
        ->and($rows[0]['recipient_name'])->toBe('Alex Helper') // enriched via StaffDirectory, no PII
        ->and($rows[0])->toHaveKey('created_at');
});

it('enriches recipient_name to null when the staff record cannot be resolved', function () {
    $user = widgetsBootAndGrant();
    $unknownStaffId = (string) Str::uuid(); // no staff row → graceful null

    widgetsSeedShoutout($user->id, $unknownStaffId, 'to a ghost', now());

    $rows = $this->actingAs($user)
        ->getJson('/api/v1/widgets/vb-gratitude.recentShoutouts')
        ->assertOk()
        ->json('data.payload.rows');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['message'])->toBe('to a ghost')
        ->and($rows[0]['recipient_name'])->toBeNull();
});

it('counts the acting user\'s this-month RECEIVED shoutouts via the staffIdForUser seam', function () {
    $user = widgetsBootAndGrant();

    // The acting user IS a staff member — seed their staff↔user mapping so
    // StaffDirectory::staffIdForUser resolves their recipient id (PII-free seam).
    $myStaffId = widgetsSeedRecipient([
        'user_id' => $user->id,
        'display_name' => 'Acting Owner',
        'personal_email' => 'owner-staff@home.example',
    ]);
    // A second, unrelated staff member — shoutouts to them must NOT count.
    $otherStaffId = widgetsSeedRecipient(['personal_email' => 'other@home.example']);

    $someGiver = (string) Str::uuid();

    // Two received by me THIS month — the only rows that count.
    widgetsSeedShoutout($someGiver, $myStaffId, 'received A', now());
    widgetsSeedShoutout($someGiver, $myStaffId, 'received B', now());

    // Must NOT count: to me but last month, and to someone else this month.
    widgetsSeedShoutout($someGiver, $myStaffId, 'to me last month', now()->startOfMonth()->subDay());
    widgetsSeedShoutout($someGiver, $otherStaffId, 'to other this month', now());

    $this->actingAs($user)
        ->getJson('/api/v1/widgets/vb-gratitude.receivedThisMonth')
        ->assertOk()
        ->assertJsonPath('data.type', 'metric')
        ->assertJsonPath('data.payload.label', 'Shoutouts you\'ve received this month')
        ->assertJsonPath('data.payload.value', 2);
});

it('resolves receivedThisMonth to a metric of 0 without erroring for an unmapped user', function () {
    // The acting user maps to no staff record, so currentUserStaffId() is null and
    // the resolver must still return a clean metric of 0 — never an error — even
    // with received shoutouts (to OTHER staff) present in the tenant.
    $user = widgetsBootAndGrant();
    $recipientId = widgetsSeedRecipient(); // some other staff, NOT mapped to the actor

    widgetsSeedShoutout((string) Str::uuid(), $recipientId, 'received one', now());

    $this->actingAs($user)
        ->getJson('/api/v1/widgets/vb-gratitude.receivedThisMonth')
        ->assertOk()
        ->assertJsonPath('data.type', 'metric')
        ->assertJsonPath('data.payload.label', 'Shoutouts you\'ve received this month')
        ->assertJsonPath('data.payload.value', 0);
});

it('still resolves a widget for a user WITH the read permission (two-part registration wired)', function () {
    $user = widgetsBootAndGrant();

    // No rows: proves the resolver itself resolves through the endpoint (200
    // envelope), confirming manifest card + provider resolver stay wired.
    $this->actingAs($user)
        ->getJson('/api/v1/widgets/vb-gratitude.givenThisMonth')
        ->assertOk()
        ->assertJsonPath('data.payload.value', 0);
});
