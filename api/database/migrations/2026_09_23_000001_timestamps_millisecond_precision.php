<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Comandos: created_at, entregado_at, updated_at
        DB::statement('ALTER TABLE comandos_cronometro ALTER COLUMN created_at TYPE timestamp(3)');
        DB::statement('ALTER TABLE comandos_cronometro ALTER COLUMN updated_at TYPE timestamp(3)');
        DB::statement('ALTER TABLE comandos_cronometro ALTER COLUMN entregado_at TYPE timestamp(3)');

        // Eventos: timestamp_servidor, created_at
        DB::statement('ALTER TABLE eventos_cronometro ALTER COLUMN timestamp_servidor TYPE timestamp(3)');
        DB::statement('ALTER TABLE eventos_cronometro ALTER COLUMN created_at TYPE timestamp(3)');
        DB::statement('ALTER TABLE eventos_cronometro ALTER COLUMN updated_at TYPE timestamp(3)');

        // Estado cronometraje: largada_at
        DB::statement('ALTER TABLE estado_cronometraje ALTER COLUMN largada_at TYPE timestamp(3)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE comandos_cronometro ALTER COLUMN created_at TYPE timestamp(0)');
        DB::statement('ALTER TABLE comandos_cronometro ALTER COLUMN updated_at TYPE timestamp(0)');
        DB::statement('ALTER TABLE comandos_cronometro ALTER COLUMN entregado_at TYPE timestamp(0)');

        DB::statement('ALTER TABLE eventos_cronometro ALTER COLUMN timestamp_servidor TYPE timestamp(0)');
        DB::statement('ALTER TABLE eventos_cronometro ALTER COLUMN created_at TYPE timestamp(0)');
        DB::statement('ALTER TABLE eventos_cronometro ALTER COLUMN updated_at TYPE timestamp(0)');

        DB::statement('ALTER TABLE estado_cronometraje ALTER COLUMN largada_at TYPE timestamp(0)');
    }
};
