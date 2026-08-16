<?php

declare(strict_types=1);

/**
 * GratitudeService badge evaluation — milestone badges awarded from the
 * BADGE-EVALUATION HOOK in createShoutout.
 *
 * These exercise the REAL host seams (no mocks): shoutouts persist through the
 * live service, badges are computed from this tenant's own vb_gratitude_shoutouts
 * rows, tenant isolation rides BelongsToTenant, and the final case drives a real
 * HTTP request through GET /badges (Task 4's endpoint).
 *
 * Covers both giving-based badges (total / distinct recipients) AND the
 * received-based `appreciated` badge, which resolves the giver's own staff id
 * through the sanctioned PII-free StaffDirectory::staffIdForUser seam.
 *
 * Seed + boot helpers (seedGratitudeRecipient, seedGratitudeStaff, bootGratitudeAs)
 * are reused from GratitudeServiceTest / HttpEndpointsTest.
 */

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Vctrs\Plugins\VbGratitude\GratitudeService;
use Vctrs\Plugins\VbGratitude\Models\GratitudeBadgeAward;
use Vctrs\Plugins\VbGratitude\Models\GratitudeShoutout;

require_once __DIR__.'/../bootstrap.php';

beforeEach(function () {
    pluginRunMigrations();
});

afterEach(function () {
    // Only the HTTP case installs to disk; harmless for the unit cases.
    File::deleteDirectory(storage_path('app/plugins/vb-gratitude'));
});

/** True when the giver already holds $badgeKey in the current tenant. */
function giverHasBadge(string $userId, string $badgeKey): bool
{
    return GratitudeBadgeAward::query()
        ->where('user_id', $userId)
        ->where('badge_key', $badgeKey)
        ->exists();
}

/**
 * Persist one shoutout RECEIVED by $recipientStaffId (from an arbitrary giver),
 * directly, so the giver's own received count can be built up exactly.
 * BelongsToTenant stamps the currently-bound tenant.
 */
function seedReceivedShoutout(string $recipientStaffId): void
{
    $s = new GratitudeShoutout;
    $s->giver_user_id = (string) Str::uuid();
    $s->recipient_staff_id = $recipientStaffId;
    $s->message = 'received';
    $s->points_awarded = 0;
    $s->save();
}

it('awards first_thanks on the giver\'s very first shoutout', function () {
    $giver = pluginTestUser('rooftop_owner');
    pluginBindTenant($giver->id);
    $recipientId = seedGratitudeRecipient();

    app(GratitudeService::class)->createShoutout(
        'rooftop', PLUGIN_TEST_TENANT, $giver->id, $recipientId, 'Thanks for the save!',
    );

    $awards = GratitudeBadgeAward::query()->where('user_id', $giver->id)->get();

    expect($awards)->toHaveCount(1);
    expect($awards->first()->badge_key)->toBe('first_thanks')
        ->and($awards->first()->user_id)->toBe($giver->id)
        ->and($awards->first()->tenant_id)->toBe(PLUGIN_TEST_TENANT)
        ->and($awards->first()->earned_at)->not->toBeNull();
});

it('awards grateful_regular at 10 shoutouts and gratitude_champion at 50', function () {
    $giver = pluginTestUser('rooftop_owner');
    pluginBindTenant($giver->id);
    $recipientId = seedGratitudeRecipient();
    $service = app(GratitudeService::class);

    // 9 shoutouts: below the grateful_regular threshold.
    for ($i = 1; $i <= 9; $i++) {
        $service->createShoutout('rooftop', PLUGIN_TEST_TENANT, $giver->id, $recipientId, "thanks {$i}");
    }
    expect(giverHasBadge($giver->id, 'grateful_regular'))->toBeFalse();

    // 10th crosses grateful_regular; champion (50) still far off.
    $service->createShoutout('rooftop', PLUGIN_TEST_TENANT, $giver->id, $recipientId, 'thanks 10');
    expect(giverHasBadge($giver->id, 'grateful_regular'))->toBeTrue()
        ->and(giverHasBadge($giver->id, 'gratitude_champion'))->toBeFalse();

    // On to 50 → gratitude_champion.
    for ($i = 11; $i <= 50; $i++) {
        $service->createShoutout('rooftop', PLUGIN_TEST_TENANT, $giver->id, $recipientId, "thanks {$i}");
    }
    expect(giverHasBadge($giver->id, 'gratitude_champion'))->toBeTrue();
});

it('awards team_connector once the giver tags 5 DISTINCT teammates', function () {
    $giver = pluginTestUser('rooftop_owner');
    pluginBindTenant($giver->id);
    $service = app(GratitudeService::class);

    // 4 distinct teammates: below the distinct-recipient threshold.
    for ($i = 1; $i <= 4; $i++) {
        $rid = seedGratitudeRecipient(['personal_email' => "teammate{$i}@home.example"]);
        $service->createShoutout('rooftop', PLUGIN_TEST_TENANT, $giver->id, $rid, "thanks {$i}");
    }
    expect(giverHasBadge($giver->id, 'team_connector'))->toBeFalse();

    // The 5th DISTINCT teammate earns it.
    $fifth = seedGratitudeRecipient(['personal_email' => 'teammate5@home.example']);
    $service->createShoutout('rooftop', PLUGIN_TEST_TENANT, $giver->id, $fifth, 'thanks 5');
    expect(giverHasBadge($giver->id, 'team_connector'))->toBeTrue();
});

it('does NOT award team_connector when the SAME teammate is tagged five times', function () {
    $giver = pluginTestUser('rooftop_owner');
    pluginBindTenant($giver->id);
    $recipientId = seedGratitudeRecipient();
    $service = app(GratitudeService::class);

    // Five shoutouts, one recipient → distinct recipients stays at 1.
    for ($i = 1; $i <= 5; $i++) {
        $service->createShoutout('rooftop', PLUGIN_TEST_TENANT, $giver->id, $recipientId, "thanks {$i}");
    }

    expect(giverHasBadge($giver->id, 'team_connector'))->toBeFalse()
        // ...but the shoutouts DID land: first_thanks proves the path ran.
        ->and(giverHasBadge($giver->id, 'first_thanks'))->toBeTrue();
});

it('never awards a badge twice when its threshold is re-crossed', function () {
    $giver = pluginTestUser('rooftop_owner');
    pluginBindTenant($giver->id);
    $recipientId = seedGratitudeRecipient();
    $service = app(GratitudeService::class);

    // Three shoutouts each re-satisfy first_thanks (threshold 1) — still one row.
    $service->createShoutout('rooftop', PLUGIN_TEST_TENANT, $giver->id, $recipientId, 'first');
    $service->createShoutout('rooftop', PLUGIN_TEST_TENANT, $giver->id, $recipientId, 'second');
    $service->createShoutout('rooftop', PLUGIN_TEST_TENANT, $giver->id, $recipientId, 'third');

    $firstThanks = GratitudeBadgeAward::query()
        ->where('user_id', $giver->id)
        ->where('badge_key', 'first_thanks')
        ->get();

    expect($firstThanks)->toHaveCount(1);
});

it('scopes badge awards to the tenant the shoutouts were given in', function () {
    $tenantA = PLUGIN_TEST_TENANT;
    $tenantB = (string) Str::uuid();

    $giver = pluginTestUser('rooftop_owner');
    pluginBindTenant($giver->id, $tenantA);
    $recipientId = seedGratitudeRecipient();

    app(GratitudeService::class)
        ->createShoutout('rooftop', $tenantA, $giver->id, $recipientId, 'Team A gratitude');
    expect(giverHasBadge($giver->id, 'first_thanks'))->toBeTrue();

    // Tenant B: the SAME giver has no awards (BelongsToTenant global scope).
    pluginBindTenant($giver->id, $tenantB);
    expect(GratitudeBadgeAward::query()->where('user_id', $giver->id)->count())->toBe(0);

    // Back in tenant A the award is intact.
    pluginBindTenant($giver->id, $tenantA);
    expect(giverHasBadge($giver->id, 'first_thanks'))->toBeTrue();
});

it('awards appreciated when a giver who has RECEIVED >= 10 next gives a shoutout', function () {
    $giver = pluginTestUser('rooftop_owner');
    pluginBindTenant($giver->id);

    // The giver IS a staff member — seed their staff↔user mapping so
    // StaffDirectory::staffIdForUser resolves their received recipient id.
    $giverStaffId = seedGratitudeRecipient([
        'user_id' => $giver->id,
        'personal_email' => 'giver-staff@home.example',
    ]);
    // Someone else for the giver to shout AT (their giving, not their receiving).
    $recipientId = seedGratitudeRecipient(['personal_email' => 'recipient@home.example']);

    // 10 shoutouts received by the giver — crosses the appreciated threshold.
    for ($i = 1; $i <= 10; $i++) {
        seedReceivedShoutout($giverStaffId);
    }
    // Not yet awarded — evaluation only runs when the giver GIVES.
    expect(giverHasBadge($giver->id, 'appreciated'))->toBeFalse();

    // The giver's next shoutout fires the hook, which evaluates their received count.
    app(GratitudeService::class)->createShoutout(
        'rooftop', PLUGIN_TEST_TENANT, $giver->id, $recipientId, 'thanks back!',
    );

    expect(giverHasBadge($giver->id, 'appreciated'))->toBeTrue();
});

it('does NOT award appreciated when the giver has RECEIVED fewer than 10', function () {
    $giver = pluginTestUser('rooftop_owner');
    pluginBindTenant($giver->id);

    $giverStaffId = seedGratitudeRecipient([
        'user_id' => $giver->id,
        'personal_email' => 'giver-staff@home.example',
    ]);
    $recipientId = seedGratitudeRecipient(['personal_email' => 'recipient@home.example']);

    // Only 9 received — one short of the threshold.
    for ($i = 1; $i <= 9; $i++) {
        seedReceivedShoutout($giverStaffId);
    }

    app(GratitudeService::class)->createShoutout(
        'rooftop', PLUGIN_TEST_TENANT, $giver->id, $recipientId, 'thanks back!',
    );

    expect(giverHasBadge($giver->id, 'appreciated'))->toBeFalse()
        // ...but the giving path DID run: first_thanks proves the hook fired.
        ->and(giverHasBadge($giver->id, 'first_thanks'))->toBeTrue();
});

it('never awards appreciated twice when the giver keeps giving after crossing 10 received', function () {
    $giver = pluginTestUser('rooftop_owner');
    pluginBindTenant($giver->id);

    $giverStaffId = seedGratitudeRecipient([
        'user_id' => $giver->id,
        'personal_email' => 'giver-staff@home.example',
    ]);
    $recipientId = seedGratitudeRecipient(['personal_email' => 'recipient@home.example']);

    for ($i = 1; $i <= 10; $i++) {
        seedReceivedShoutout($giverStaffId);
    }
    $service = app(GratitudeService::class);

    // Three gives, each re-satisfying the appreciated threshold — still one row.
    $service->createShoutout('rooftop', PLUGIN_TEST_TENANT, $giver->id, $recipientId, 'one');
    $service->createShoutout('rooftop', PLUGIN_TEST_TENANT, $giver->id, $recipientId, 'two');
    $service->createShoutout('rooftop', PLUGIN_TEST_TENANT, $giver->id, $recipientId, 'three');

    $appreciated = GratitudeBadgeAward::query()
        ->where('user_id', $giver->id)
        ->where('badge_key', 'appreciated')
        ->get();

    expect($appreciated)->toHaveCount(1);
});

it('does NOT award appreciated to a giver with no staff mapping (seam resolves null)', function () {
    $giver = pluginTestUser('rooftop_owner');
    pluginBindTenant($giver->id);

    // No staff record mapped to the giver → staffIdForUser is null → skip.
    $recipientId = seedGratitudeRecipient(['personal_email' => 'recipient@home.example']);

    // Even with 12 shoutouts to OTHER staff present, the giver maps to none.
    $otherStaffId = seedGratitudeRecipient(['personal_email' => 'other@home.example']);
    for ($i = 1; $i <= 12; $i++) {
        seedReceivedShoutout($otherStaffId);
    }

    app(GratitudeService::class)->createShoutout(
        'rooftop', PLUGIN_TEST_TENANT, $giver->id, $recipientId, 'thanks!',
    );

    expect(giverHasBadge($giver->id, 'appreciated'))->toBeFalse();
});

it('surfaces an earned badge through GET /badges', function () {
    $user = bootGratitudeAs([
        '+vb-gratitude.shoutouts.create.rooftop',
        '+vb-gratitude.badges.read.rooftop',
    ]);
    $recipient = seedGratitudeStaff();

    // A real POST fires the createShoutout hook, which awards first_thanks.
    $this->actingAs($user)
        ->postJson('/api/v1/vb-gratitude/shoutouts', [
            'recipient_staff_id' => $recipient->id,
            'message' => 'Thanks for the assist!',
        ])
        ->assertStatus(201);

    $this->actingAs($user)
        ->getJson('/api/v1/vb-gratitude/badges')
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.badges.0.badge_key', 'first_thanks');
});
