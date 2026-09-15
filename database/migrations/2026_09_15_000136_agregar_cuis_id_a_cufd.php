<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ata cada CUFD al CUIS con el que se lo pidio.
 *
 * El SIN exige el CUIS vigente para emitir un CUFD, asi que la relacion existe
 * en los hechos, pero no se guardaba: la tabla solo sabia a que punto de venta
 * pertenecia. Sin el vinculo no se puede responder "que CUFD salieron de este
 * CUIS", que es lo que hace falta para auditar una factura vieja —su CUF
 * depende del codigo_control de un CUFD, y ese CUFD de un CUIS concreto—.
 *
 * Es nullable porque los CUFD ya guardados no tienen forma de saberlo: cuando
 * se emitieron, el dato no se registraba. Se deja en null en vez de adivinar
 * por fecha, que daria un vinculo inventado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cufd', function (Blueprint $table) {
            $table->foreignId('cuis_id')
                ->nullable()
                ->after('punto_venta_id')
                // Si se borra el CUIS, el CUFD sobrevive sin el vinculo: sigue
                // siendo el documento con el que se calcularon CUF reales.
                ->constrained('cuis')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cufd', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cuis_id');
        });
    }
};
