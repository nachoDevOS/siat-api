<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque codigos: CUIS y CUFD (el CAFC va por empresa, en la tabla cafcs).
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
            // Version de los servicios SOAP por la que se pidio: v2 (/v2) o
            // v3 (/sfd). El SIN comparte el CUIS entre las dos, pero el piloto
            // lo pide en ambas y el panel muestra de cual salio.
            $table->string('version', 2)->nullable();
            $table->dateTime('fecha_vigencia');
            // Cuando se cerraron las operaciones del sistema con este CUIS (es
            // la unica forma de que el SIN emita otro). Null = no se cerro.
            // Distingue en el panel "cerrado" de "vencido" o "reemplazado".
            $table->dateTime('cerrado_en')->nullable();

            $table->timestamps();

            // Se consulta "el CUIS vigente de este PV" muy seguido.
            $table->index(['punto_venta_id', 'fecha_vigencia']);
        });

        // CUFD: dura 24 horas. Su "codigo_control" entra al calculo del CUF,
        // por eso se guarda aparte del codigo.
        Schema::create('cufd', function (Blueprint $table) {
            $table->id();
            $table->foreignId('punto_venta_id')->constrained('puntos_venta')->cascadeOnDelete();
            // CUIS con el que el SIN lo emitio. Si se borra el CUIS, el CUFD
            // sobrevive sin el vinculo: sigue siendo el documento con el que se
            // calcularon CUF reales.
            $table->foreignId('cuis_id')->nullable()->constrained('cuis')->nullOnDelete();

            $table->string('codigo');
            // Igual que en el CUIS: v2 (/v2) o v3 (/sfd).
            $table->string('version', 2)->nullable();
            $table->string('codigo_control');
            $table->string('direccion')->nullable();
            $table->dateTime('fecha_vigencia');

            $table->timestamps();

            $table->index(['punto_venta_id', 'fecha_vigencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cufd');
        Schema::dropIfExists('cuis');
    }
};
