<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Plugin migrations run on a privileged owner connection and may be re-run
// across installs, so every one MUST be idempotent.
//
// `posted_channel_id` was scaffolded for a "also post this shoutout to a team
// channel" feature that was never implemented: nothing in src/ ever wrote the
// column, and there is no sanctioned cross-plugin seam to write it with — the
// channels plugin binds only ChannelDirectory (vendor rooms), and
// ChannelSystemMessenger is neither container-bound nor listed in the host's
// docs/plugins/capabilities.md. A column no code path fills is a promise the
// plugin cannot keep, so it goes before first publish rather than shipping as
// a permanently-null field in the shoutout API response.
//
// The create migration no longer emits the column, so this is a no-op on a
// fresh install; it exists to clean up hosts that already ran 000001. Should
// the feature ever land through a properly sanctioned seam, it comes back as
// its own additive migration.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('vb_gratitude_shoutouts', 'posted_channel_id')) {
            Schema::table('vb_gratitude_shoutouts', function (Blueprint $table) {
                $table->dropColumn('posted_channel_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('vb_gratitude_shoutouts', 'posted_channel_id')) {
            Schema::table('vb_gratitude_shoutouts', function (Blueprint $table) {
                $table->uuid('posted_channel_id')->nullable();
            });
        }
    }
};
