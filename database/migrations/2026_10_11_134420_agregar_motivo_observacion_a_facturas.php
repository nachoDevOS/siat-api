<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Por que el SIN observo (rechazo) la factura, tal como lo dijo: "[1001] HASH
 * INVALIDO | ...".
 *
 * Antes el motivo solo quedaba en el log del servidor: el sistema de ventas
 * recibia "OBSERVADA" por webhook sin saber que corregir, y el proveedor tenia
 * que ir a buscarlo a los logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->text('motivo_observacion')->nullable()->after('codigo_estado_siat');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropColumn('motivo_observacion');
        });
    }
};
