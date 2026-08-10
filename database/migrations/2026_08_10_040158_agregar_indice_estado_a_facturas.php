<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las dos tareas programadas mas frecuentes barren facturas por estado
 * (PENDIENTE cada 5 min, ENVIADA/RECIBIDA cada 15 min) y la tabla no tenia
 * ningun indice por esa columna: cada corrida escaneaba la tabla entera.
 *
 * El indice va por (estado, id) porque las dos consultas ordenan por id para
 * tomar el lote mas viejo primero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->index(['estado', 'id'], 'facturas_estado_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropIndex('facturas_estado_id_index');
        });
    }
};
