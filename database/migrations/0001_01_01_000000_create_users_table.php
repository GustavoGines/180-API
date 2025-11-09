<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('role', 20)->default('staff');   // admin | staff
            $table->string('email', 150);                   // unicidad via índice en LOWER(email)
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestampsTz();                         // created_at / updated_at con TZ
        });

        // ✅ Constraint de rol (PostgreSQL)
        DB::statement("ALTER TABLE users
            ADD CONSTRAINT users_role_chk
            CHECK (role IN ('admin','staff'))");

        // ✅ Unicidad case-insensitive en email
        DB::statement('CREATE UNIQUE INDEX users_email_ci_unique ON users (LOWER(email))');

        // ✅ Índice útil por rol y por creado (dashboards / listados)
        DB::statement('CREATE INDEX users_role_idx ON users (role)');
        DB::statement('CREATE INDEX users_created_idx ON users (created_at)');

        // Password resets (si pensás usarlos por correo)
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 150)->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        // (Opcional) sessions — si tu app es solo API con tokens, NO la necesitás.
        // Podés comentar este bloque para no crearla.
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        // Borrar índices/constraints antes de dropear tabla (no estrictamente necesario)
        DB::statement('DROP INDEX IF EXISTS users_email_ci_unique');
        DB::statement('DROP INDEX IF EXISTS users_role_idx');
        DB::statement('DROP INDEX IF EXISTS users_created_idx');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_chk');

        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
