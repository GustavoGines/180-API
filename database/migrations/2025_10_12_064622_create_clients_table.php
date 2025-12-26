<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 40)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('ig_handle')->nullable();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('name');
        });

        // Unicidad por teléfono NORMALIZADO (solo dígitos), permitiendo NULL
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                CREATE UNIQUE INDEX clients_phone_norm_unique
                ON clients (regexp_replace(phone, '[^0-9]+', '', 'g'))
                WHERE phone IS NOT NULL
            ");
        }

        // Unicidad case-insensitive de email, permitiendo NULL
        DB::statement('
            CREATE UNIQUE INDEX clients_email_ci_unique
            ON clients ((lower(email)))
            WHERE email IS NOT NULL
        ');
    }

    public function down(): void
    {
        // Los índices se borran automáticamente al dropear la tabla,
        // pero si querés ser explícito:
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS clients_phone_norm_unique');
        }
        DB::statement('DROP INDEX IF EXISTS clients_email_ci_unique');

        Schema::dropIfExists('clients');
    }
};
