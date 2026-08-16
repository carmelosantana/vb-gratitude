<?php

declare(strict_types=1);

/**
 * GratitudeService::createShoutout — the core give-a-shoutout loop.
 *
 * These tests exercise the REAL host seams (no mocks): recipient existence via
 * StaffDirectory (seeded with a real StaffHubEmployee), points via the real core
 * GamificationService/ledger, tenant isolation via BelongsToTenant, and settings
 * via the host PluginSettings cascade.
 */

use App\Models\PluginNamespace;
use Illuminate\Support\Str;
use Vctrs\Plugins\Gamification\Models\GamificationLedger;
use Vctrs\Plugins\StaffHub\Models\StaffHubEmployee;
use Vctrs\Plugins\VbGratitude\GratitudeException;
use Vctrs\Plugins\VbGratitude\GratitudeService;
use Vctrs\Plugins\VbGratitude\Models\GratitudeShoutout;

require_once __DIR__.'/../bootstrap.php';

/**
 * Seed an active staff member (the only kind StaffDirectory::lookup resolves) and
 * return its id. Seeds via the StaffHub model like the host's own plugin tests —
 * the "never import StaffHub models" rule applies to the SERVICE, not test setup.
 */
function seedGratitudeRecipient(array $over = []): string
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

beforeEach(function () {
    pluginRunMigrations();
});

it('records a shoutout and awards points through the core gamification ledger', function () {
    $giver = pluginTestUser('rooftop_owner');
    pluginBindTenant($giver->id);
    $recipientId = seedGratitudeRecipient();

    $shoutout = app(GratitudeService::class)->createShoutout(
        'rooftop', PLUGIN_TEST_TENANT, $giver->id, $recipientId, 'Thanks for the save!', 'teamwork',
    );

    // Row persisted with the right fields + the BelongsToTenant stamp.
    $fresh = GratitudeShoutout::find($shoutout->id);
    expect($fresh)->not->toBeNull()
        ->and($fresh->giver_user_id)->toBe($giver->id)
        ->and($fresh->recipient_staff_id)->toBe($recipientId)
        ->and($fresh->message)->toBe('Thanks for the save!')
        ->and($fresh->category)->toBe('teamwork')
        ->and($fresh->tenant_id)->toBe(PLUGIN_TEST_TENANT)
        ->and($fresh->points_awarded)->toBe(5); // default pointsPerShoutout, from the award result

    // The award actually hit the core ledger — proof points were minted through
    // GamificationService, never a local points table.
    $ledger = GamificationLedger::query()->withoutGlobalScopes()
        ->where('tenant_id', PLUGIN_TEST_TENANT)
        ->where('user_id', $giver->id)
        ->where('event_key', 'gratitude.shoutout.given')
        ->where('source_ref', $shoutout->id)
        ->first();

    expect($ledger)->not->toBeNull()
        ->and((int) $ledger->points)->toBe(5)
        ->and($ledger->source_plugin)->toBe('vb-gratitude');
});

it('records the shoutout but awards zero once the daily allowance is spent', function () {
    $giver = pluginTestUser('rooftop_owner');
    pluginBindTenant($giver->id);
    $recipientId = seedGratitudeRecipient();
    $service = app(GratitudeService::class);

    // Default dailyShoutoutAllowance = 3: the first three each earn points.
    for ($i = 0; $i < 3; $i++) {
        $earned = $service->createShoutout('rooftop', PLUGIN_TEST_TENANT, $giver->id, $recipientId, "Nice work #{$i}");
        expect($earned->points_awarded)->toBe(5);
    }

    // The fourth is STILL recorded (giving is always encouraged) but earns 0 and
    // must NOT write another ledger row.
    $capped = $service->createShoutout('rooftop', PLUGIN_TEST_TENANT, $giver->id, $recipientId, 'One more thank-you');

    expect($capped->points_awarded)->toBe(0)
        ->and(GratitudeShoutout::count())->toBe(4);

    $ledgerCount = GamificationLedger::query()->withoutGlobalScopes()
        ->where('tenant_id', PLUGIN_TEST_TENANT)
        ->where('user_id', $giver->id)
        ->where('event_key', 'gratitude.shoutout.given')
        ->count();

    expect($ledgerCount)->toBe(3); // the cap prevented a fourth award
});

it('rejects an unknown recipient and writes no shoutout row', function () {
    $giver = pluginTestUser('rooftop_owner');
    pluginBindTenant($giver->id);

    $missing = (string) Str::uuid(); // no staff member with this id in the tenant

    expect(fn () => app(GratitudeService::class)->createShoutout(
        'rooftop', PLUGIN_TEST_TENANT, $giver->id, $missing, 'Hello?',
    ))->toThrow(GratitudeException::class);

    expect(GratitudeShoutout::count())->toBe(0);
});

it('keeps a shoutout isolated to the tenant it was created in', function () {
    $tenantA = PLUGIN_TEST_TENANT;
    $tenantB = (string) Str::uuid();

    $giver = pluginTestUser('rooftop_owner');
    pluginBindTenant($giver->id, $tenantA);
    $recipientId = seedGratitudeRecipient();

    $shoutout = app(GratitudeService::class)
        ->createShoutout('rooftop', $tenantA, $giver->id, $recipientId, 'Team A gratitude', 'teamwork');

    expect(GratitudeShoutout::count())->toBe(1);

    // Tenant B sees and counts nothing (BelongsToTenant global scope).
    pluginBindTenant($giver->id, $tenantB);
    expect(GratitudeShoutout::count())->toBe(0)
        ->and(GratitudeShoutout::find($shoutout->id))->toBeNull();

    // Back in tenant A the row is intact.
    pluginBindTenant($giver->id, $tenantA);
    expect(GratitudeShoutout::count())->toBe(1)
        ->and(GratitudeShoutout::find($shoutout->id))->not->toBeNull();
});

it('honours pointsPerShoutout and dailyShoutoutAllowance from the host settings mechanism', function () {
    $giver = pluginTestUser('rooftop_owner');
    $ctx = pluginBindTenant($giver->id);

    // Install so PluginSettings can discover this plugin's manifest — the cascade
    // only applies tenant overrides for keys the manifest declares.
    pluginInstallSignedAndBoot($ctx);

    // Tenant override: 7 points per shoutout, only ONE point-earning shoutout/day.
    PluginNamespace::create([
        'plugin_slug' => 'vb-gratitude',
        'namespace' => 'vb-gratitude:'.PLUGIN_TEST_TENANT,
        'tenant_type' => 'rooftop',
        'tenant_id' => PLUGIN_TEST_TENANT,
        'data_json' => ['settings' => ['pointsPerShoutout' => 7, 'dailyShoutoutAllowance' => 1]],
    ]);

    $recipientId = seedGratitudeRecipient();
    $service = app(GratitudeService::class);

    $first = $service->createShoutout('rooftop', PLUGIN_TEST_TENANT, $giver->id, $recipientId, 'Settings-driven thanks');
    expect($first->points_awarded)->toBe(7); // pointsPerShoutout override honoured

    $second = $service->createShoutout('rooftop', PLUGIN_TEST_TENANT, $giver->id, $recipientId, 'Second thanks');
    expect($second->points_awarded)->toBe(0); // dailyShoutoutAllowance=1 override honoured
});
