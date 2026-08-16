<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbGratitude;

use App\Plugins\Contracts\PluginModule;
use App\Plugins\Contracts\ProvidesScheduledTasks;
use App\Plugins\PluginManifest;
use App\Plugins\Scheduling\PluginScheduledJob;
use Illuminate\Support\Facades\Route;

/**
 * Gratitude — plugin module contract.
 *
 * Constructed by the host as new self(PluginManifest $manifest, string $dir).
 *
 * WIDGET CONTRACT: every key returned by widgets() must also appear in
 * manifest.json widgets[], and vice versa. The manifest puts the CARD on the
 * dashboard; this method supplies the RESOLVER. Declare one without the other
 * and the card renders, then 404s. Both are generated from plugin.spec.json, so
 * do not hand-edit one without the other.
 *
 * Resolvers return ['type' => 'metric'|'list'|'chart', 'payload' => [...]].
 * The host renders nothing else, and 'chart' is donut-only.
 */
class VbGratitudeServiceProvider implements PluginModule, ProvidesScheduledTasks
{
    public function __construct(
        private readonly PluginManifest $manifest,
        private readonly string $dir,
    ) {}

    public function manifest(): PluginManifest
    {
        return $this->manifest;
    }

    public function register(): void
    {
        Route::group([], $this->dir.'/src/routes.php');
    }

    public function navItems(): array
    {
        return $this->manifest->nav;
    }

    public function widgets(): array
    {
        return [
            'vb-gratitude.givenThisMonth' => [
            'vb-gratitude.shoutouts.read.rooftop',
            fn (): array => [
                'type' => 'metric',
                'payload' => [
                    'label' => 'Shoutouts you\'ve given this month',
                        'value' => 0,
                ],
            ],
        ],
            'vb-gratitude.receivedThisMonth' => [
            'vb-gratitude.shoutouts.read.rooftop',
            fn (): array => [
                'type' => 'metric',
                'payload' => [
                    'label' => 'Shoutouts you\'ve received this month',
                        'value' => 0,
                ],
            ],
        ],
            'vb-gratitude.recentShoutouts' => [
            'vb-gratitude.shoutouts.read.rooftop',
            fn (): array => [
                'type' => 'list',
                'payload' => [
                    'rows' => [],
                ],
            ],
        ],
        ];
    }

    public function permissions(): array
    {
        return [];
    }

    /**
     * @return array<int, array{key: string, cron: string, perTenant: bool, job: class-string<PluginScheduledJob>}>
     */
    public function scheduledTasks(): array
    {
        return [
            [
                'key' => 'vb-gratitude/morning-reminder',
                'cron' => '0 9 * * *',
                'perTenant' => true,
                'job' => Jobs\MorningReminderJob::class,
            ],
        ];
    }
}
