<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Elimina el CAFC: queda fuera del alcance del sistema.
 *
 * CAFC es el "Codigo de Autorizacion de Factura COMPUTARIZADA", y este sistema
 * factura solo en modalidad ELECTRONICA EN LINEA (asi esta autorizado ante el
 * SIN por la solicitud R-1359). Dos hechos lo confirman:
 *
 *   1. El WSDL del piloto no expone una operacion 'cafc' en FacturacionCodigos
 *      (verificado el 2026-08-10): la solicitud fallaba con "Function not found".
 *   2. La contingencia en modalidad electronica no usa CAFC: se emite fuera de
 *      linea con el ultimo CUFD valido, bajo un evento significativo, y las
 *      facturas viajan despues en un paquete.
 *
 * La columna 'facturas.cafc_id' nunca se escribio: no hay dato que perder. El
 * elemento <cafc> del XSD del SIN SIGUE viajando en la cabecera, siempre con
 * xsi:nil, porque el esquema lo declara en la secuencia (ver ConstructorXml).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cafc_id');
        });

        Schema::dropIfExists('cafc');
    }

    public function down(): void
    {
        Schema::create('cafc', function (Blueprint $table) {
            $table->id();
            $table->foreignId('punto_venta_id')->constrained('puntos_venta')->cascadeOnDelete();

            $table->string('codigo');
            $table->unsignedInteger('cantidad_facturas');
            $table->unsignedInteger('facturas_usadas')->default(0);
            $table->dateTime('fecha_vigencia');

            $table->timestamps();

            $table->index(['punto_venta_id', 'fecha_vigencia']);
        });

        Schema::table('facturas', function (Blueprint $table) {
            $table->foreignId('cafc_id')->nullable()->after('cufd_id')
                ->constrained('cafc')->nullOnDelete();
        });
    }
};
