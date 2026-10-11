<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * <codigoExcepcion> del XML: 1 autoriza al SIN a registrar una factura cuyo NIT
 * de comprador no valida (tipo de documento 5); 0 es el caso normal.
 *
 * Hasta ahora viajaba siempre con xsi:nil y una venta a un NIT invalido no
 * tenia salida: el SIN la rechazaba. Se guarda en la factura porque es parte
 * del documento firmado y debe poder reconstruirse igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->unsignedTinyInteger('codigo_excepcion')->default(0)->after('numero_tarjeta');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropColumn('codigo_excepcion');
        });
    }
};
