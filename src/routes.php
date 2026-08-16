<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vctrs\Plugins\VbGratitude\Http\Controllers\BadgesController;
use Vctrs\Plugins\VbGratitude\Http\Controllers\ShoutoutsController;

// Session-authed browser surface for the extracted vb-gratitude plugin.
//
// The ESM UI (dist/entry.js) fetches these as the logged-in user via the
// `session-api` group (auth:sanctum + tenant). Every handler returns the
// canonical ApiResponse envelope {traceId, data, status} so the vendored axios
// client kit can unwrap it. Every route carries a `can:` gate — permissions are
// declared in manifest.json and follow vb-gratitude.<resource>.<action>.<scope>.
Route::middleware(['web', 'session-api'])
    ->prefix('api/v1/vb-gratitude')
    ->name('vb-gratitude.api.')
    ->group(function () {
        // Give a shoutout to a teammate.
        Route::post('/shoutouts', [ShoutoutsController::class, 'store'])
            ->middleware('can:vb-gratitude.shoutouts.create.rooftop')
            ->name('shoutouts.store');

        // The tenant's recent shoutout feed (recipient names enriched, PII-free).
        Route::get('/shoutouts', [ShoutoutsController::class, 'index'])
            ->middleware('can:vb-gratitude.shoutouts.read.rooftop')
            ->name('shoutouts.index');

        // The acting user's earned badge awards.
        Route::get('/badges', [BadgesController::class, 'index'])
            ->middleware('can:vb-gratitude.badges.read.rooftop')
            ->name('badges.index');

        // Tag-a-teammate picker (needs create rights to tag someone).
        Route::get('/teammates', [ShoutoutsController::class, 'teammates'])
            ->middleware('can:vb-gratitude.shoutouts.create.rooftop')
            ->name('teammates.index');
    });
