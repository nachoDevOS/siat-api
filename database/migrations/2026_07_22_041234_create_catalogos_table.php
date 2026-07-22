<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogos GLOBALES (una copia para todos) y catalogos POR EMPRESA.
 *
 * Los globales van todos en una sola tabla "catalogos" con la columna "tipo"
 * porque comparten la misma forma (codigo + descripcion) y asi se sincronizan
 * y consultan mas facil. Los por-empresa llevan empresa_id porque su contenido
 * depende del NIT que consulta.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Una sola tabla para TODAS las parametricas globales del SIN.
        Schema::create('catalogos', function (Blueprint $table) {
            $table->id();
            // Ej: 'unidades_medida', 'tipos_metodo_pago', 'motivos_anulacion'.
            $table->string('tipo');
            $table->string('codigo_clasificador');
            $table->string('descripcion');

            $table->timestamps();

            // Se consulta siempre "dame todos los codigos de este tipo".
            $table->unique(['tipo', 'codigo_clasificador']);
            $table->index('tipo');
        });

        // Actividades economicas registradas de ESE NIT.
        Schema::create('actividades_economicas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('codigo_actividad');
            $table->string('descripcion');
            $table->string('tipo_actividad')->nullable();

            $table->timestamps();

            $table->unique(['empresa_id', 'codigo_actividad']);
        });

        // Productos-servicios homologados por el SIN segun las actividades.
        Schema::create('productos_servicios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('codigo_actividad');
            $table->string('codigo_producto');
            $table->string('descripcion');

            $table->timestamps();

            $table->unique(['empresa_id', 'codigo_actividad', 'codigo_producto']);
        });

        // Leyendas de factura, que dependen de la actividad economica.
        Schema::create('leyendas_factura', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('codigo_actividad');
            $table->text('descripcion_leyenda');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leyendas_factura');
        Schema::dropIfExists('productos_servicios');
        Schema::dropIfExists('actividades_economicas');
        Schema::dropIfExists('catalogos');
    }
};
