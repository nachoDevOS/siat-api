<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sandbox de un cliente: una copia de la empresa en el ambiente PILOTO, con su
 * propio token, para que el sistema de ventas pruebe la API emitiendo contra
 * el SIAT piloto (sin validez fiscal) mientras la empresa real factura en
 * produccion.
 *
 * Es otra fila de empresas (el UNIQUE(nit, codigo_ambiente) ya lo permite);
 * sandbox_de_id la ata a la empresa de produccion de la que salio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->foreignId('sandbox_de_id')->nullable()->after('id')
                ->constrained('empresas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sandbox_de_id');
        });
    }
};
