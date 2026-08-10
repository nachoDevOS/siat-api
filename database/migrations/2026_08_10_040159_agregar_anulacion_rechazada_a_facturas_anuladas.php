<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La anulacion se marca en local apenas la pide el cliente, pero el SIN puede
 * rechazarla (fuera de plazo, factura inexistente, motivo invalido). Antes eso
 * no se registraba en ningun lado: la factura quedaba ANULADA de este lado y
 * vigente del lado del SIN, sin rastro de la divergencia.
 *
 * Se guarda el motivo del rechazo para que el operador lo vea en el panel y el
 * job pueda devolver la factura a su estado anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas_anuladas', function (Blueprint $table) {
            $table->string('estado')->default('PENDIENTE')->after('codigo_recepcion');
            $table->text('motivo_rechazo')->nullable()->after('estado');
            // Estado en el que estaba la factura antes de marcarla ANULADA, para
            // poder devolverla ahi si el SIN rechaza la anulacion.
            $table->string('estado_anterior')->nullable()->after('motivo_rechazo');
        });
    }

    public function down(): void
    {
        Schema::table('facturas_anuladas', function (Blueprint $table) {
            $table->dropColumn(['estado', 'motivo_rechazo', 'estado_anterior']);
        });
    }
};
