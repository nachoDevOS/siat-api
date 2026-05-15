<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_packages', function (Blueprint $table) {
            $table->id();

            // Tipo de evento significativo (ej: 4=Venta sin internet, 5=Corte de energía...).
            $table->unsignedInteger('codigoEventoSignificativo');
            $table->string('descripcion')->nullable();

            // Rango de tiempo de la contingencia que cubre este paquete.
            $table->string('fechaHoraInicioEvento');
            $table->string('fechaHoraFinEvento');

            // Código de recepción del evento significativo devuelto por el SIN.
            // Es el ID que el SIN asigna al evento; se usa como codigoEvento al enviar el paquete.
            $table->string('codigoRecepcionEventoSignificativo')->nullable();

            // Contexto SIAT: sucursal y PV que originaron las facturas del paquete.
            $table->unsignedInteger('codigo_sucursal');
            $table->unsignedInteger('codigo_punto_venta');

            // Estado final del paquete devuelto por el SIN tras validar.
            $table->string('codigoEstado')->nullable();
            $table->string('codigoDescripcion')->nullable();
            $table->string('codigoRecepcion')->nullable();
            $table->boolean('transaccion')->default(false);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_packages');
    }
};
