<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Plugin migrations run on a privileged owner connection and may be re-run
// across installs, so every one MUST be idempotent.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vb_gratitude_shoutouts')) {
            Schema::create('vb_gratitude_shoutouts', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('tenant_type');
                $table->uuid('tenant_id')->index();
                $table->uuid('giver_user_id')->index();
                $table->uuid('recipient_staff_id')->index();
                $table->text('message');
                $table->string('category')->nullable();
                $table->integer('points_awarded')->nullable();
                $table->uuid('posted_channel_id')->nullable();
                $table->timestamps();
            });
        }

        $this->applyRls();
    }

    /**
     * Reproduce the platform's fail-closed tenant RLS. The predicate is copied
     * byte-for-byte from core's enforce_real_rls sweep.
     *
     * This is NOT optional boilerplate. VCTRbase runs Postgres RLS fail-closed:
     * the host grants app_user full DML on every table it finds, so a tenant
     * table carrying tenant_type/tenant_id but no ENABLE + policy + FORCE lets
     * one tenant read another tenant's rows. Core's enforce_real_rls sweep never
     * travels with an extracted plugin, so a clean external install is never
     * swept — the migration has to carry the policy itself.
     *
     * Applied UNCONDITIONALLY (not behind the hasTable guard) so it lands on
     * every substrate — a host that already owns the table still gets FORCE RLS
     * reasserted.
     */
    private function applyRls(): void
    {
        $t = 'vb_gratitude_shoutouts';
        $predicate = <<<'SQL'
            current_setting('app.bypass_rls', true) = '1'
            OR ( (tenant_type)::text = current_setting('app.tenant_type', true)
                 AND (tenant_id)::text = NULLIF(current_setting('app.tenant_id', true), '') )
        SQL;
        DB::unprepared("ALTER TABLE public.{$t} ENABLE ROW LEVEL SECURITY;");
        DB::unprepared("DROP POLICY IF EXISTS {$t}_tenant_isolation ON public.{$t};");
        DB::unprepared("CREATE POLICY {$t}_tenant_isolation ON public.{$t} USING ({$predicate});");
        DB::unprepared("ALTER TABLE public.{$t} FORCE ROW LEVEL SECURITY;");
        DB::unprepared(<<<SQL
            DO \$\$ BEGIN
              IF EXISTS (SELECT FROM pg_roles WHERE rolname = 'app_user') THEN
                EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON public.{$t} TO app_user';
                EXECUTE 'GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO app_user';
              END IF;
            END \$\$;
        SQL);
    }

    public function down(): void
    {
        $t = 'vb_gratitude_shoutouts';
        DB::unprepared("ALTER TABLE public.{$t} NO FORCE ROW LEVEL SECURITY;");
        DB::unprepared("DROP POLICY IF EXISTS {$t}_tenant_isolation ON public.{$t};");
        Schema::dropIfExists('vb_gratitude_shoutouts');
    }
};
