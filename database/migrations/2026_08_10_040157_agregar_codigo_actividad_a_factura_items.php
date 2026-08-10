<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El XSD del SIN exige una <actividadEconomica> por cada detalle de la factura.
 * Hasta ahora el campo viajaba siempre vacio porque no habia de donde sacarlo.
 *
 * Se guarda en el item y no se resuelve al armar el XML por la misma razon por
 * la que los datos del comprador estan desnormalizados: la factura es un
 * documento congelado. Si manana el SIN reasigna un producto a otra actividad,
 * la factura ya emitida debe seguir mostrando la actividad con la que se emitio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factura_items', function (Blueprint $table) {
            $table->string('codigo_actividad')->nullable()->after('codigo_producto_sin');
        });
    }

    public function down(): void
    {
        Schema::table('factura_items', function (Blueprint $table) {
            $table->dropColumn('codigo_actividad');
        });
    }
};
