<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de los jobs de la cola: una fila por job, que se actualiza en cada
 * cambio de estado (en cola, procesando, exitoso, reintentando, fallido).
 *
 * Laravel con cola en base de datos solo guarda lo pendiente (jobs, se borra
 * al terminar) y lo fallido (failed_jobs): los exitosos no dejaban rastro y
 * nada se veia sin entrar a la terminal. Se llena sola con los eventos de la
 * cola (App\Services\Trabajos\RegistroTrabajos) y se purga con
 * siat:purgar-logs (exitosos a los 7 dias, el resto a los 30).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trabajos', function (Blueprint $table) {
            $table->id();
            // El uuid del payload: el mismo durante reintentos y en failed_jobs.
            $table->uuid('uuid')->unique();
            $table->string('clase');
            $table->string('cola', 50)->default('default');
            $table->string('estado', 20);
            $table->unsignedSmallInteger('intentos')->default(0);
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            // Lo que el job afecta: factura, paquete, punto_venta o caso_prueba.
            $table->string('relacionado_tipo', 30)->nullable();
            $table->unsignedBigInteger('relacionado_id')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duracion_ms')->nullable();
            $table->timestamp('encolado_en')->nullable();
            $table->timestamp('iniciado_en')->nullable();
            $table->timestamp('terminado_en')->nullable();
            $table->timestamps();

            $table->index(['estado', 'id']);
            $table->index(['empresa_id', 'id']);
            $table->index(['cola', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trabajos');
    }
};
