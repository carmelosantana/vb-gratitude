<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Plugin migrations run on a privileged owner connection and may be re-run
// across installs, so every one MUST be idempotent.
//
// A giver earns each badge AT MOST ONCE per tenant. GratitudeService checks
// before inserting, but that check-then-insert races under concurrency; this
// unique index is the DB backstop that makes the loser of a race fail closed
// (a QueryException the service catches and ignores) rather than write a
// duplicate award row.
return new class extends Migration
{
    public function up(): void
    {
        // Postgres `IF NOT EXISTS` keeps this idempotent across re-installs.
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS vb_gratitude_badge_awards_unique_award
                ON public.vb_gratitude_badge_awards (tenant_type, tenant_id, user_id, badge_key);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP INDEX IF EXISTS vb_gratitude_badge_awards_unique_award;');
    }
};
