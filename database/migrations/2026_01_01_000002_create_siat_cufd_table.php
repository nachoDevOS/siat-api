<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('siat_cufd', function (Blueprint $table) {
            $table->id();

            // Mismo contexto que siat_cuis: el CUFD pertenece a un sucursal/PV específico.
            $table->unsignedInteger('codigo_sucursal');
            $table->unsignedInteger('codigo_punto_venta');

            $table->string('codigo');
            // codigoControl se concatena al final del hex del CUF (últimos 8 caracteres del CUF).
            $table->string('codigoControl')->default('');
            // direccion es la URL del portal de verificación del SIN, se usa para el QR de la factura.
            $table->string('direccion')->nullable();
            $table->dateTime('fechaVigencia')->nullable();
            $table->boolean('transaccion')->default(false);
            $table->tinyInteger('status')->default(1);

            $table->timestamps();

            $table->index(['codigo_sucursal', 'codigo_punto_venta', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siat_cufd');
    }
};
