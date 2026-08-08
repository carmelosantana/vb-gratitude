<?php

declare(strict_types=1);

/**
 * Pest bootstrap for vb-gratitude.
 *
 * The plugin lives outside the host repo, so it has no Composer autoload entry.
 * scripts/test-in-app.sh rsyncs this directory into a throwaway host worktree at
 * tests/Feature/Plugins/VbGratitude/ (a path Pest already scans, so these tests
 * inherit the host TestCase and DatabaseTransactions) and mounts the plugin
 * read-only at PLUGIN_SRC. This file:
 *
 *   - registers a PSR-4 autoloader mapping Vctrs\Plugins\VbGratitude\ → PLUGIN_SRC/src
 *     (for unit / job tests that reference plugin classes without installing it);
 *   - provides pluginRunMigrations() to create the plugin tables directly
 *     (unit tests);
 *   - provides pluginBindTenant() to construct and bind a real TenantContext;
 *   - provides pluginZip() + pluginInstallSignedAndBoot() — the proven
 *     signed-install → refresh → explicit-migrate → bootProviders sequence
 *     used by feature tests.
 *
 * PLUGIN_TEST_TENANT and pluginTestUser() are NOT defined here: they live in
 * the host's own tests/Pest.php and are inherited for free once this plugin's
 * tests are rsynced into tests/Feature/Plugins/VbGratitude/. Redefining them
 * here would shadow (or collide with) the host's copy.
 *
 * Every function is guarded so requiring this file from multiple test files is
 * safe.
 */

use App\Plugins\ArtifactSigning;
use App\Plugins\PluginInstaller;
use App\Plugins\PluginManager;
use App\Plugins\PluginMigrator;
use App\Support\TenantContext;
use Illuminate\Support\Facades\File;

if (! function_exists('pluginSrc')) {
    /** Absolute path to the read-only plugin mount. */
    function pluginSrc(): string
    {
        $src = getenv('PLUGIN_SRC');

        if ($src === false || $src === '') {
            throw new RuntimeException('PLUGIN_SRC is not set; run tests via scripts/test-in-app.sh');
        }

        return rtrim($src, '/');
    }
}

// ── PSR-4 autoloader for the mounted plugin src (idempotent) ────────────────────
if (! defined('VbGratitude_BOOTSTRAP_AUTOLOAD')) {
    define('VbGratitude_BOOTSTRAP_AUTOLOAD', true);

    spl_autoload_register(static function (string $class): void {
        $prefix = 'Vctrs\Plugins\VbGratitude\\';

        if (! str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $file = pluginSrc().'/src/'.str_replace('\\', '/', $relative).'.php';

        if (is_file($file)) {
            require_once $file;
        }
    });
}

if (! function_exists('pluginRunMigrations')) {
    /**
     * Run this plugin's migration files directly (idempotent — each migration
     * short-circuits when its table already exists). Used by unit/job tests
     * that exercise plugin services against real tables without a full
     * install.
     */
    function pluginRunMigrations(): void
    {
        $dir = pluginSrc().'/database/migrations';
        foreach (glob($dir.'/*.php') ?: [] as $path) {
            $migration = require $path; // returns the anonymous Migration instance
            $migration->up();
        }
    }
}

if (! function_exists('pluginBindTenant')) {
    /**
     * Construct a real TenantContext for the given user in PLUGIN_TEST_TENANT,
     * bind that instance into the container, and return it.
     */
    function pluginBindTenant(string $userId, string $tenantId = PLUGIN_TEST_TENANT, string $type = 'rooftop'): TenantContext
    {
        // Empty traceId (matching the in-plugin test convention): the AuditObserver
        // then mints a fresh uuid per write, so multiple writes to the same
        // resource in one test don't collide on the audit_events unique key.
        $ctx = new TenantContext($userId, $type, $tenantId, '');
        app()->instance(TenantContext::class, $ctx);

        return $ctx;
    }
}

if (! function_exists('pluginZip')) {
    /**
     * Recursively zip the mounted plugin source (manifest + src/ + database/ +
     * dist/) and return the temp ZIP path. Mirrors the shipping artifact the
     * installer expects. The path filter MUST agree with scripts/build-zip.sh
     * and the harness zip regex.
     */
    function pluginZip(?string $srcDir = null): string
    {
        $srcDir = rtrim($srcDir ?? pluginSrc(), '/');
        $zipPath = tempnam(sys_get_temp_dir(), 'plugin-zip-').'.zip';

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($it as $file) {
            $rel = ltrim(str_replace($srcDir, '', $file->getPathname()), '/');
            if (! preg_match('#^(manifest\.json|src/|database/|dist/)#', $rel)) {
                continue; // ship only what install + runtime need
            }
            if ($file->isDir()) {
                $zip->addEmptyDir($rel);
            } else {
                $zip->addFile($file->getPathname(), $rel);
            }
        }
        $zip->close();

        return $zipPath;
    }
}

if (! function_exists('pluginInstallSignedAndBoot')) {
    /**
     * Install the plugin from a freshly-signed ZIP (real VCTRS key from env),
     * then boot it so its routes/widgets/tables are live.
     *
     * CORE GAP (documented, not patched): PluginInstaller::installFromZip() runs
     * plugin migrations BEFORE PluginManager::refresh(), and PluginManager is a
     * boot-discovered singleton, so the uploaded plugin's migrations are silently
     * skipped at install time. We therefore refresh() the manager (so it
     * rediscovers the freshly-installed dir), then run the plugin migrations
     * explicitly via PluginMigrator, then bootProviders() to execute the plugin's
     * register() (routes) — this proves the plugin code itself is correct.
     */
    function pluginInstallSignedAndBoot(TenantContext $ctx): void
    {
        $priv = getenv('PLUGIN_PRIV');
        $pub = getenv('PLUGIN_PUB');

        config()->set('plugins.registry_pubkey', $pub);
        config()->set('plugins.require_signature', true);

        // Each install extracts the plugin to storage/app/plugins/<slug> — a
        // NON-transactional on-disk write that survives the test's DB rollback.
        // Remove any leftover dir and force a rescan so every test installs clean.
        File::deleteDirectory(storage_path('app/plugins/vb-gratitude'));
        app(PluginManager::class)->refresh();

        $zip = pluginZip();
        $sig = ArtifactSigning::signBytes((string) file_get_contents($zip), (string) $priv);

        try {
            app(PluginInstaller::class)->installFromZip($zip, $ctx, $sig, null);
        } finally {
            @unlink($zip);
        }

        $mgr = app(PluginManager::class);
        $mgr->refresh();
        app(PluginMigrator::class)->migrate('vb-gratitude');
        $mgr->bootProviders();
    }
}
