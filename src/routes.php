<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Session-authed browser surface for the extracted vb-gratitude plugin.
//
// The ESM UI (dist/entry.js) fetches these as the logged-in user via the
// `session-api` group (auth:sanctum + tenant). Every handler must return the
// canonical ApiResponse envelope {traceId, data, status} so the vendored axios
// client kit can unwrap it.
Route::middleware(['web', 'session-api'])
    ->prefix('api/v1/vb-gratitude')
    ->name('vb-gratitude.api.')
    ->group(function () {
        // Every route MUST carry a can: gate. Permissions are declared in
        // manifest.json and follow vb-gratitude.<resource>.<action>.<scope>.
        //
        // Example:
        // Route::get('/overview', [OverviewController::class, 'index'])
        //     ->middleware('can:vb-gratitude.records.read.rooftop')
        //     ->name('overview');
    });
