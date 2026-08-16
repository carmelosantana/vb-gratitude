<?php

declare(strict_types=1);

/**
 * HTTP layer for vb-gratitude: the four session-authed /api/v1/vb-gratitude
 * endpoints (POST/GET shoutouts, GET badges, GET teammates).
 *
 * These are full host-integrated feature tests: the plugin is signed, installed,
 * booted (so its routes are live), enabled for the tenant, and the acting user is
 * granted the manifest permissions — the exact arrangement SignedInstallBootTest
 * proves. Each test then drives a real HTTP request through the `session-api`
 * middleware and asserts the canonical ApiResponse envelope.
 */

use App\Plugins\PluginLifecycle;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Vctrs\Plugins\StaffHub\Models\StaffHubEmployee;
use Vctrs\Plugins\VbGratitude\Models\GratitudeBadgeAward;
use Vctrs\Plugins\VbGratitude\Models\GratitudeShoutout;

require_once __DIR__.'/../bootstrap.php';

afterEach(function () {
    File::deleteDirectory(storage_path('app/plugins/vb-gratitude'));
});

/**
 * Seed an active staff member (the only kind StaffDirectory resolves) and return
 * its id. Seeds via the StaffHub model like the host's own plugin tests — the
 * "never import StaffHub models" rule is about the plugin's runtime code, not test
 * setup.
 */
function seedGratitudeStaff(array $over = []): StaffHubEmployee
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
    ], $over));
}

/**
 * Install + boot the plugin (routes live), enable it for the tenant, and return a
 * user whose membership carries the given permission overrides. Mirrors the
 * SignedInstallBootTest arrangement.
 *
 * @param  list<string>  $grants  e.g. ['+vb-gratitude.shoutouts.create.rooftop']
 */
function bootGratitudeAs(array $grants): App\Models\User
{
    expect(getenv('PLUGIN_PRIV'))->not->toBeFalse();

    $user = pluginTestUser('rooftop_owner', $grants);
    $ctx = pluginBindTenant($user->id);

    pluginInstallSignedAndBoot($ctx);
    app(PluginLifecycle::class)->enable('vb-gratitude');

    return $user;
}

it('creates a shoutout for a valid recipient and awards points (201 envelope)', function () {
    $user = bootGratitudeAs([
        '+vb-gratitude.shoutouts.create.rooftop',
        '+vb-gratitude.shoutouts.read.rooftop',
    ]);
    $recipient = seedGratitudeStaff();

    $this->actingAs($user)
        ->postJson('/api/v1/vb-gratitude/shoutouts', [
            'recipient_staff_id' => $recipient->id,
            'message' => 'Thanks for covering my shift!',
            'category' => 'teamwork',
        ])
        ->assertStatus(201)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.shoutout.recipient_staff_id', $recipient->id)
        ->assertJsonPath('data.shoutout.message', 'Thanks for covering my shift!')
        ->assertJsonPath('data.shoutout.points_awarded', 5); // default pointsPerShoutout

    expect(GratitudeShoutout::withoutGlobalScopes()
        ->where('recipient_staff_id', $recipient->id)
        ->where('giver_user_id', $user->id)
        ->count())->toBe(1);
});

it('maps an unknown recipient to a 422 and writes no row (never a 500)', function () {
    $user = bootGratitudeAs(['+vb-gratitude.shoutouts.create.rooftop']);
    $missing = (string) Str::uuid(); // no staff member with this id in the tenant

    $this->actingAs($user)
        ->postJson('/api/v1/vb-gratitude/shoutouts', [
            'recipient_staff_id' => $missing,
            'message' => 'Hello?',
        ])
        ->assertStatus(422)
        ->assertJsonPath('status', 'error');

    expect(GratitudeShoutout::withoutGlobalScopes()->count())->toBe(0);
});

it('rejects a giver without the create permission with a 403', function () {
    // Read-only grant: enough to reach the route, NOT enough to pass the create gate.
    $user = bootGratitudeAs(['+vb-gratitude.shoutouts.read.rooftop']);
    $recipient = seedGratitudeStaff();

    $this->actingAs($user)
        ->postJson('/api/v1/vb-gratitude/shoutouts', [
            'recipient_staff_id' => $recipient->id,
            'message' => 'Should never land',
        ])
        ->assertStatus(403);

    expect(GratitudeShoutout::withoutGlobalScopes()->count())->toBe(0);
});

it('returns the tenant feed newest-first with recipient names enriched', function () {
    $user = bootGratitudeAs([
        '+vb-gratitude.shoutouts.create.rooftop',
        '+vb-gratitude.shoutouts.read.rooftop',
    ]);
    $recipient = seedGratitudeStaff(['display_name' => 'Alex Helper']);

    // Two shoutouts; force the first to be older so newest-first is deterministic.
    $older = GratitudeShoutout::create([
        'giver_user_id' => $user->id,
        'recipient_staff_id' => $recipient->id,
        'message' => 'Older thanks',
    ]);
    $older->forceFill(['created_at' => now()->subHour()])->saveQuietly();

    GratitudeShoutout::create([
        'giver_user_id' => $user->id,
        'recipient_staff_id' => $recipient->id,
        'message' => 'Newer thanks',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/vb-gratitude/shoutouts')
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.shoutouts.0.message', 'Newer thanks')
        ->assertJsonPath('data.shoutouts.0.recipient_name', 'Alex Helper')
        ->assertJsonPath('data.shoutouts.1.message', 'Older thanks')
        ->assertJsonPath('data.shoutouts.1.recipient_name', 'Alex Helper');
});

it('blocks the shoutout feed without read permission (403)', function () {
    // Create grant reaches the route but the index is read-gated.
    $user = bootGratitudeAs(['+vb-gratitude.shoutouts.create.rooftop']);

    $this->actingAs($user)
        ->getJson('/api/v1/vb-gratitude/shoutouts')
        ->assertStatus(403);
});

it('returns the badges envelope (empty is acceptable)', function () {
    $user = bootGratitudeAs(['+vb-gratitude.badges.read.rooftop']);

    $this->actingAs($user)
        ->getJson('/api/v1/vb-gratitude/badges')
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.badges', []);
});

it('lists the acting user\'s earned badges newest-first', function () {
    $user = bootGratitudeAs(['+vb-gratitude.badges.read.rooftop']);

    GratitudeBadgeAward::create([
        'user_id' => $user->id,
        'badge_key' => 'first_shoutout',
        'earned_at' => now()->subDay(),
    ]);
    GratitudeBadgeAward::create([
        'user_id' => $user->id,
        'badge_key' => 'top_helper',
        'earned_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/vb-gratitude/badges')
        ->assertOk()
        ->assertJsonPath('data.badges.0.badge_key', 'top_helper')
        ->assertJsonPath('data.badges.1.badge_key', 'first_shoutout');
});

it('blocks the badges list without badges-read permission (403)', function () {
    // Shoutout-read grant reaches the route but badges is badges-read-gated.
    $user = bootGratitudeAs(['+vb-gratitude.shoutouts.read.rooftop']);

    $this->actingAs($user)
        ->getJson('/api/v1/vb-gratitude/badges')
        ->assertStatus(403);
});

it('lists assignable teammates for the picker via StaffDirectory', function () {
    $user = bootGratitudeAs(['+vb-gratitude.shoutouts.create.rooftop']);
    seedGratitudeStaff(['display_name' => 'Pat Picker']);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/vb-gratitude/teammates')
        ->assertOk()
        ->assertJsonPath('status', 'success');

    $names = collect($response->json('data.teammates'))->pluck('display_name');
    expect($names)->toContain('Pat Picker');

    // SAFE_FIELDS only — no work_email leaks through the picker.
    $first = $response->json('data.teammates.0');
    expect($first)->not->toHaveKey('work_email');
});

it('blocks the teammate picker without create permission (403)', function () {
    // Read grant reaches nothing here — teammates is create-gated.
    $user = bootGratitudeAs(['+vb-gratitude.shoutouts.read.rooftop']);

    $this->actingAs($user)
        ->getJson('/api/v1/vb-gratitude/teammates')
        ->assertStatus(403);
});
