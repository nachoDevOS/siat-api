<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emision masiva (tipo de emision 3 del SIN): mientras un punto de venta tiene
 * un lote ABIERTO, sus facturas se emiten con CUF de emision masiva y quedan
 * en estado MASIVA, validas e imprimibles pero sin enviar. Al cerrar el lote
 * viajan en paquetes de hasta 500 por recepcionMasivaFactura, una llamada por
 * paquete en vez de una por factura.
 *
 * Los paquetes reusan la tabla de contingencia, con tipo = MASIVA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lotes_masivos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('punto_venta_id')->constrained('puntos_venta')->cascadeOnDelete();
            // ABIERTO (emitiendo) o CERRADO (paquetes armados y en camino).
            $table->string('estado')->default('ABIERTO');
            $table->dateTime('abierto_en');
            $table->dateTime('cerrado_en')->nullable();
            $table->timestamps();

            $table->index(['punto_venta_id', 'estado']);
        });

        Schema::table('facturas', function (Blueprint $table) {
            $table->foreignId('lote_masivo_id')->nullable()->after('paquete_id')
                ->constrained('lotes_masivos')->nullOnDelete();
        });

        Schema::table('paquetes', function (Blueprint $table) {
            $table->foreignId('lote_masivo_id')->nullable()->after('tipo')
                ->constrained('lotes_masivos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('paquetes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lote_masivo_id');
        });

        Schema::table('facturas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lote_masivo_id');
        });

        Schema::dropIfExists('lotes_masivos');
    }
};
