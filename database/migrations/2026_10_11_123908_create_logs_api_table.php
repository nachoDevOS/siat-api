<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de cada llamada a la API REST: que token, de que empresa, a que
 * ruta, con que resultado y desde que IP.
 *
 * Es la contraparte de logs_siat del otro lado del sistema: logs_siat dice que
 * le mandamos al SIN; esta tabla dice que nos pidieron los sistemas de venta.
 * Sirve para soporte ("mi factura no salio") y para detectar un token filtrado
 * (llamadas desde una IP rara). No guarda el cuerpo: lleva datos del comprador
 * y ya queda en la factura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logs_api', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            // Sin FK: un token revocado se borra y su historial debe quedar.
            $table->unsignedBigInteger('token_id')->nullable();
            $table->string('metodo', 10);
            $table->string('ruta');
            $table->unsignedSmallInteger('estado_http');
            $table->string('ip', 45)->nullable();
            $table->unsignedInteger('duracion_ms')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['empresa_id', 'created_at']);
            $table->index('token_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logs_api');
    }
};
