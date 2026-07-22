<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque operacion: eventos significativos, paquetes de contingencia y la
 * auditoria de toda peticion SOAP (logs_siat).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Evento significativo: se registra cuando el SIAT se cae, para poder
        // emitir en contingencia y luego cerrar y mandar el paquete.
        Schema::create('eventos_significativos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('punto_venta_id')->constrained('puntos_venta')->cascadeOnDelete();

            $table->unsignedInteger('codigo_evento');
            $table->string('descripcion')->nullable();
            // CUFD vigente al momento del evento (lo pide el SIN al registrarlo).
            $table->string('cufd_evento')->nullable();
            $table->dateTime('fecha_inicio');
            $table->dateTime('fecha_fin')->nullable();
            $table->string('codigo_recepcion')->nullable();
            $table->string('estado')->default('ABIERTO');

            $table->timestamps();
        });

        // Paquete: agrupa las facturas de contingencia para enviarlas juntas.
        Schema::create('paquetes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('punto_venta_id')->constrained('puntos_venta')->cascadeOnDelete();
            $table->foreignId('evento_id')->nullable()->constrained('eventos_significativos')->nullOnDelete();

            $table->unsignedInteger('cantidad_facturas')->default(0);
            $table->string('codigo_recepcion')->nullable();
            $table->string('estado')->default('PENDIENTE');
            $table->dateTime('enviado_en')->nullable();

            $table->timestamps();
        });

        // Auditoria: cada llamada SOAP guarda su XML enviado y recibido. Es la
        // unica forma de depurar de verdad una respuesta del SIN.
        Schema::create('logs_siat', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();

            $table->string('servicio');
            $table->string('operacion');
            $table->longText('xml_enviado')->nullable();
            $table->longText('xml_recibido')->nullable();
            $table->unsignedInteger('duracion_ms')->nullable();
            $table->boolean('exitoso')->default(false);
            $table->text('mensaje_error')->nullable();

            // Solo created_at: un log no se actualiza, se agrega.
            $table->timestamp('created_at')->nullable();

            $table->index(['empresa_id', 'servicio', 'operacion']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logs_siat');
        Schema::dropIfExists('paquetes');
        Schema::dropIfExists('eventos_significativos');
    }
};
