<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada aviso enviado (o por enviar) a un destino de webhook. Es el historial
 * que el cliente consulta para depurar su receptor y desde el que pide un
 * reenvio. Guarda el cuerpo exacto: un reenvio manda los mismos bytes, con la
 * misma firma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_entregas', function (Blueprint $table) {
            $table->id();
            // Id publico de la entrega: viaja en X-Siat-Entrega para que el
            // receptor deduplique.
            $table->uuid('uuid')->unique();
            $table->foreignId('webhook_endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $table->string('evento');
            $table->longText('cuerpo');
            // PENDIENTE (en cola o reintentando), ENTREGADA, FALLIDA (agoto los reintentos).
            $table->string('estado')->default('PENDIENTE');
            $table->unsignedSmallInteger('intentos')->default(0);
            $table->unsignedSmallInteger('codigo_http')->nullable();
            $table->text('error')->nullable();
            $table->dateTime('entregada_en')->nullable();
            $table->timestamps();

            $table->index(['webhook_endpoint_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_entregas');
    }
};
