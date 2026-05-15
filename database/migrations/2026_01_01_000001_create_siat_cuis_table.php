<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('siat_cuis', function (Blueprint $table) {
            $table->id();

            // Clave de contexto: un CUIS es válido para una sucursal + punto de venta específicos.
            // codigo_sucursal es el código SIAT de la sucursal (branches.codigo_sucursal en el sistema cliente).
            // codigo_punto_venta = 0 es el PV administrativo; es un valor válido, no null.
            $table->unsignedInteger('codigo_sucursal');
            $table->unsignedInteger('codigo_punto_venta');

            $table->string('codigo');
            $table->dateTime('fechaVigencia')->nullable(); // null = vigente indefinidamente
            $table->boolean('transaccion')->default(false);

            // status = 1 activo, 0 histórico. Solo puede haber un registro status=1
            // por par (codigo_sucursal, codigo_punto_venta).
            $table->tinyInteger('status')->default(1);

            $table->timestamps();

            $table->index(['codigo_sucursal', 'codigo_punto_venta', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siat_cuis');
    }
};
