<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque codigos: CUIS, CUFD y CAFC.
 * Son historial: cada solicitud al SIN crea una fila nueva y jamas se
 * sobreescribe la anterior. Para saber cual esta vigente se busca la mas
 * reciente cuya fecha_vigencia aun no paso.
 */
return new class extends Migration
{
    public function up(): void
    {
        // CUIS: identifica al punto de venta ante el SIN. Dura ~1 anio.
        Schema::create('cuis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('punto_venta_id')->constrained('puntos_venta')->cascadeOnDelete();

            $table->string('codigo');
            $table->dateTime('fecha_vigencia');

            $table->timestamps();

            // Se consulta "el CUIS vigente de este PV" muy seguido.
            $table->index(['punto_venta_id', 'fecha_vigencia']);
        });

        // CUFD: dura 24 horas. Su "codigo_control" entra al calculo del CUF,
        // por eso se guarda aparte del codigo.
        Schema::create('cufd', function (Blueprint $table) {
            $table->id();
            $table->foreignId('punto_venta_id')->constrained('puntos_venta')->cascadeOnDelete();

            $table->string('codigo');
            $table->string('codigo_control');
            $table->string('direccion')->nullable();
            $table->dateTime('fecha_vigencia');

            $table->timestamps();

            $table->index(['punto_venta_id', 'fecha_vigencia']);
        });

        // CAFC: reserva para emitir en contingencia (sin internet o SIAT caido).
        // Trae un rango de facturas; "facturas_usadas" lleva el consumo.
        Schema::create('cafc', function (Blueprint $table) {
            $table->id();
            $table->foreignId('punto_venta_id')->constrained('puntos_venta')->cascadeOnDelete();

            $table->string('codigo');
            $table->unsignedInteger('cantidad_facturas');
            $table->unsignedInteger('facturas_usadas')->default(0);
            $table->dateTime('fecha_vigencia');

            $table->timestamps();

            $table->index(['punto_venta_id', 'fecha_vigencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cafc');
        Schema::dropIfExists('cufd');
        Schema::dropIfExists('cuis');
    }
};
