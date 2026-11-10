<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Reemplazar índices únicos para respetar SoftDeletes en PostgreSQL
            DB::statement('DROP INDEX IF EXISTS clients_phone_norm_unique;');
            DB::statement("CREATE UNIQUE INDEX clients_phone_norm_unique ON clients (regexp_replace(phone, '[^0-9]+', '', 'g')) WHERE phone IS NOT NULL AND deleted_at IS NULL;");

            DB::statement('DROP INDEX IF EXISTS clients_email_ci_unique;');
            DB::statement('CREATE UNIQUE INDEX clients_email_ci_unique ON clients (LOWER(email)) WHERE email IS NOT NULL AND deleted_at IS NULL;');

            DB::statement('DROP INDEX IF EXISTS users_email_ci_unique;');
            DB::statement('CREATE UNIQUE INDEX users_email_ci_unique ON users (LOWER(email)) WHERE deleted_at IS NULL;');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS clients_phone_norm_unique;');
            DB::statement("CREATE UNIQUE INDEX clients_phone_norm_unique ON clients (regexp_replace(phone, '[^0-9]+', '', 'g')) WHERE phone IS NOT NULL;");

            DB::statement('DROP INDEX IF EXISTS clients_email_ci_unique;');
            DB::statement('CREATE UNIQUE INDEX clients_email_ci_unique ON clients (LOWER(email)) WHERE email IS NOT NULL;');

            DB::statement('DROP INDEX IF EXISTS users_email_ci_unique;');
            DB::statement('CREATE UNIQUE INDEX users_email_ci_unique ON users (LOWER(email));');
        }
    }
};
