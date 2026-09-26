<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn("users", "rol")) {
            Schema::table("users", function (Blueprint $table) {
                $table->string("rol")->default("cronometrista");
            });
        }
        if (!Schema::hasColumn("fecha_categorias", "penal_estirada_seg")) {
            Schema::table("fecha_categorias", function (Blueprint $table) {
                $table->integer("penal_estirada_seg")->default(60);
            });
        }
        if (!Schema::hasColumn("tramos", "estiradas")) {
            Schema::table("tramos", function (Blueprint $table) {
                $table->integer("estiradas")->default(0);
            });
        }
        if (!Schema::hasColumn("dispositivos_cronometro", "ultimo_reset_reason")) {
            Schema::table("dispositivos_cronometro", function (Blueprint $table) {
                $table->smallInteger("ultimo_reset_reason")->default(0);
            });
        }
    }

    public function down(): void {}
};
