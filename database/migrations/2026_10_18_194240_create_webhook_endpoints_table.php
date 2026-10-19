<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Destinos de webhook que el cliente registra por la API: varios por empresa,
 * cada uno suscrito a los eventos que le interesan y con su propio secreto de
 * firma. Conviven con empresas.webhook_url (el webhook unico de siempre, que
 * sigue recibiendo los eventos de factura).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('url');
            // Lista de suscripciones: '*', 'grupo.*' (factura.*, siat.*) o un evento exacto.
            $table->json('eventos');
            $table->string('descripcion')->nullable();
            // Cifrado: con el se firma cada entrega.
            $table->text('secreto');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
