<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CAFC por empresa: vuelve el codigo que la migracion 2026_09_14 habia sacado.
 *
 * Se quito creyendo que la modalidad electronica no lo usa, pero el SIN lo
 * entrega tambien para electronica en linea: lo exigen los eventos
 * significativos 5, 6 y 7 (falla de software, de hardware, corte de energia),
 * en los que se factura a mano con la numeracion del CAFC. Verificado en el
 * sistema que factura en produccion (ventas) y con el CAFC del piloto que el
 * SIN emitio el 2026-10-14.
 *
 * Va por EMPRESA y no por punto de venta ni global: el SIN lo emite para un NIT
 * y la factura manual lleva el nitEmisor del cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cafcs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();

            $table->string('codigo', 50);
            // Rango de numeros de factura que el SIN autorizo con este CAFC.
            $table->unsignedInteger('numero_inicio');
            $table->unsignedInteger('numero_fin');
            // Ultimo numero ya usado; null = todavia ninguno.
            $table->unsignedInteger('ultimo_numero')->nullable();
            // Uno solo activo por empresa: cargar otro desactiva el anterior.
            $table->boolean('activo')->default(true);

            $table->timestamps();

            $table->unique(['empresa_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cafcs');
    }
};
