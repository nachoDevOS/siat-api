<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula cada factura de contingencia con el paquete que la transporta.
 *
 * Antes el paquete se armaba filtrando por punto_venta_id + estado, asi que una
 * factura que entraba a contingencia entre "armar" y "enviar" se marcaba como
 * ENVIADA sin haber viajado nunca. Con paquete_id el conjunto queda congelado
 * en el momento de armarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->foreignId('paquete_id')->nullable()->after('cafc_id')
                ->constrained('paquetes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paquete_id');
        });
    }
};
