<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque pruebas: los casos viven en base de datos (no en codigo) para poder
 * editarlos cuando el SIN cambie el manual sin tocar la aplicacion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('casos_prueba', function (Blueprint $table) {
            $table->id();
            // Fase 1 = pruebas del sistema (tu NIT), Fase 3 = piloto por cliente.
            $table->unsignedTinyInteger('fase');
            $table->unsignedInteger('orden');
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            // Tipo de operacion que ejecuta el caso (ej: 'cuis', 'recepcionFactura').
            $table->string('tipo');
            $table->json('payload_ejemplo')->nullable();
            $table->boolean('obligatorio')->default(true);

            $table->timestamps();

            $table->index(['fase', 'orden']);
        });

        Schema::create('ejecuciones_prueba', function (Blueprint $table) {
            $table->id();
            // Nullable porque la fase 1 se corre con tu propio NIT, sin empresa cliente.
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('caso_id')->constrained('casos_prueba')->cascadeOnDelete();

            $table->string('estado')->default('PENDIENTE');
            $table->json('respuesta')->nullable();
            $table->unsignedInteger('duracion_ms')->nullable();
            $table->unsignedInteger('intento')->default(1);
            $table->dateTime('ejecutado_en')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ejecuciones_prueba');
        Schema::dropIfExists('casos_prueba');
    }
};
