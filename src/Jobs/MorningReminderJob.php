<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbGratitude\Jobs;

use App\Plugins\Scheduling\PluginScheduledJob;

/**
 * Send a gentle morning gratitude prompt to opted-in users.
 *
 * Author logic goes in run(), NOT handle(). The base class's handle()
 * re-establishes tenant context via SystemContext::runAsTenant() before
 * calling run(); overriding handle() breaks per-tenant execution.
 */
class MorningReminderJob extends PluginScheduledJob
{
    protected function run(): void
    {
        // TODO: implement. $this->tenantType and $this->tenantId are bound.
    }
}
