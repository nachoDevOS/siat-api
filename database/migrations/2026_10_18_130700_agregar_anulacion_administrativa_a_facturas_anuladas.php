<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anulacion administrativa: la que el SIN aprueba por tramite, fuera del plazo
 * del servicio web (hasta el dia 9 del mes siguiente). No viaja por SOAP: el
 * SIN ya la anulo de su lado y aca solo se registra, con el numero de la
 * resolucion que la autorizo.
 *
 *   - tipo: SIAT (por servicio web, la de siempre) o ADMINISTRATIVA.
 *   - numero_resolucion: solo en la administrativa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas_anuladas', function (Blueprint $table) {
            $table->string('tipo')->default('SIAT')->after('factura_id');
            $table->string('numero_resolucion')->nullable()->after('tipo');
        });
    }

    public function down(): void
    {
        Schema::table('facturas_anuladas', function (Blueprint $table) {
            $table->dropColumn(['tipo', 'numero_resolucion']);
        });
    }
};
