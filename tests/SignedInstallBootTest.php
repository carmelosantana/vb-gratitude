<?php

declare(strict_types=1);

/**
 * THE proof: a signed Gratitude artifact installs into the app, boots its server
 * code, and creates its schema — using nothing but plugin.spec.json-derived code.
 *
 * This is the exact regression the vb-native spike caught (uploaded server-code
 * plugins that never boot in a web request → routes 404). The plugin tree is
 * mounted read-only at env PLUGIN_SRC; the keypair comes from env PLUGIN_PRIV /
 * PLUGIN_PUB — never hardcoded, never committed.
 *
 * This exercises this plugin's real domain: shoutouts (table
 * vb_gratitude_shoutouts) and badge awards (table vb_gratitude_badge_awards);
 * the givenThisMonth metric widget. The plugin's real HTTP endpoints
 * (src/routes.php) are exercised end to end by HttpEndpointsTest.
 */

use App\Models\Plugin;
use App\Plugins\PluginLifecycle;
use App\Plugins\PluginManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/bootstrap.php';

afterEach(function () {
    File::deleteDirectory(storage_path('app/plugins/vb-gratitude'));
});

it('installs the signed vb-gratitude, boots it, and creates its schema', function () {
    // Guard: the real key material must be present (harness passes it via env).
    expect(getenv('PLUGIN_PRIV'))->not->toBeFalse();
    expect(is_dir(pluginSrc()))->toBeTrue();

    $user = pluginTestUser('rooftop_owner');
    $ctx = pluginBindTenant($user->id);

    // Signed install → refresh → explicit migrate (core-gap workaround) → boot.
    pluginInstallSignedAndBoot($ctx);

    // The installer persisted the first-party trust tier from the signature.
    expect(Plugin::where('slug', 'vb-gratitude')->value('trust'))->toBe('signed_first_party');

    // The plugin's server code actually executed (register() ran).
    expect(app(PluginManager::class)->serverCodeRan('vb-gratitude'))->toBeTrue();

    // Migrations ran: the plugin's tables exist.
    expect(Schema::hasTable('vb_gratitude_shoutouts'))->toBeTrue();
    expect(Schema::hasTable('vb_gratitude_badge_awards'))->toBeTrue();

    // This plugin ships enabledByDefault:false, so the signed install leaves it
    // deactivated and no role holds its permissions yet. Enabling it for the
    // tenant both registers the widget key AND applies the manifest's
    // permissionGrants — the rooftop_owner role then holds
    // vb-gratitude.shoutouts.read.rooftop, so no manual permission override is
    // needed. Both prerequisites for the widget endpoint (tenant-enabled → key in
    // registry; permission → not 403) are satisfied by enable() alone.
    app(PluginLifecycle::class)->enable('vb-gratitude');

    // A widget resolver runs (proves the two-part widget registration is wired
    // up end to end: manifest.json card + VbGratitudeServiceProvider::widgets() resolver).
    $this->actingAs($user)
        ->getJson('/api/v1/widgets/vb-gratitude.givenThisMonth')
        ->assertOk()
        ->assertJsonPath('data.payload.value', 0);

    // The plugin's real /api/v1/vb-gratitude endpoints (the regression the
    // vb-native spike caught: uploaded server-code plugins that never boot →
    // routes 404) are proven live end to end by HttpEndpointsTest.
});
