<?php

declare(strict_types=1);

/**
 * THE proof that vb_gratitude_shoutouts is fail-closed tenant-isolated.
 *
 * A shoutout written under tenant A must be INVISIBLE to tenant B and VISIBLE
 * to tenant A. Isolation comes exclusively from App\Support\Concerns\BelongsToTenant
 * (the TenantScope global scope + the `creating` tenant-stamp hook, both bundled
 * via App\Plugins\PluginModel) plus the migration's fail-closed Postgres RLS — this
 * test never hand-adds a where('tenant_id', …). Switching the bound TenantContext
 * is the whole mechanism under test.
 */

use App\Support\TenantContext;
use Illuminate\Support\Str;
use Vctrs\Plugins\VbGratitude\Models\GratitudeShoutout;

require_once __DIR__.'/bootstrap.php';

beforeEach(function () {
    pluginRunMigrations();
});

it('hides one tenant\'s shoutouts from another tenant', function () {
    $tenantA = PLUGIN_TEST_TENANT;
    $tenantB = (string) Str::uuid();

    // ── Tenant A writes a shoutout ───────────────────────────────────────────
    pluginBindTenant('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', $tenantA);

    $shoutout = GratitudeShoutout::create([
        'giver_user_id' => (string) Str::uuid(),
        'recipient_staff_id' => (string) Str::uuid(),
        'message' => 'Thanks for covering my shift!',
        'category' => 'teamwork',
        'points_awarded' => 25,
    ]);

    // BelongsToTenant stamped the row with tenant A (never hand-set).
    expect($shoutout->tenant_id)->toBe($tenantA)
        ->and($shoutout->tenant_type)->toBe('rooftop');

    // Tenant A sees exactly its own row (guards against a vacuous pass).
    expect(GratitudeShoutout::count())->toBe(1)
        ->and(GratitudeShoutout::find($shoutout->id))->not->toBeNull();

    // ── Tenant B must see nothing ────────────────────────────────────────────
    pluginBindTenant('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', $tenantB);

    expect(GratitudeShoutout::count())->toBe(0)
        ->and(GratitudeShoutout::find($shoutout->id))->toBeNull()
        ->and(GratitudeShoutout::pluck('id')->all())->not->toContain($shoutout->id);

    // ── Back to tenant A: the row is still there ─────────────────────────────
    pluginBindTenant('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', $tenantA);

    expect(GratitudeShoutout::count())->toBe(1)
        ->and(GratitudeShoutout::find($shoutout->id))->not->toBeNull();
});

it('isolates badge awards across tenants too', function () {
    $tenantA = PLUGIN_TEST_TENANT;
    $tenantB = (string) Str::uuid();

    pluginBindTenant('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', $tenantA);

    $award = \Vctrs\Plugins\VbGratitude\Models\GratitudeBadgeAward::create([
        'user_id' => (string) Str::uuid(),
        'badge_key' => 'first_shoutout',
        'earned_at' => now(),
    ]);

    expect($award->tenant_id)->toBe($tenantA);
    expect(\Vctrs\Plugins\VbGratitude\Models\GratitudeBadgeAward::count())->toBe(1);

    pluginBindTenant('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', $tenantB);

    expect(\Vctrs\Plugins\VbGratitude\Models\GratitudeBadgeAward::count())->toBe(0)
        ->and(\Vctrs\Plugins\VbGratitude\Models\GratitudeBadgeAward::find($award->id))->toBeNull();
});
