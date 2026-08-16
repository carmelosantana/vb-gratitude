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
 * the givenThisMonth metric widget. If your own spec adds a real endpoint to
 * src/routes.php, uncomment and adapt the HTTP assertion below.
 */

use App\Models\Membership;
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

    // This plugin ships enabledByDefault:false and its manifest declares no
    // permissionGrants, so the signed install leaves it deactivated and the
    // rooftop_owner role has none of its permissions. Both are prerequisites for
    // the widget endpoint (tenant-enabled → key in registry; permission → not
    // 403), so the test explicitly activates the plugin for the tenant and grants
    // the widget's read permission before asserting the resolver.
    app(PluginLifecycle::class)->enable('vb-gratitude');
    Membership::where('user_id', $user->id)
        ->update(['permission_overrides_json' => ['+vb-gratitude.shoutouts.read.rooftop']]);

    // A widget resolver runs (proves the two-part widget registration is wired
    // up end to end: manifest.json card + VbGratitudeServiceProvider::widgets() resolver).
    $this->actingAs($user)
        ->getJson('/api/v1/widgets/vb-gratitude.givenThisMonth')
        ->assertOk()
        ->assertJsonPath('data.payload.value', 0);

    // TODO: once src/routes.php declares a real endpoint (see its commented
    // example), prove it resolves (200 + {status:success}) instead of the 404
    // the vb-native spike caught:
    // $this->actingAs($user)
    //     ->getJson('/api/v1/vb-gratitude/overview')
    //     ->assertOk()
    //     ->assertJsonPath('status', 'success');
});
