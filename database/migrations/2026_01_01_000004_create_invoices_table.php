<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            // Referencia al sistema cliente (ID de venta en el sistema que consume la API).
            $table->string('external_id')->nullable()->index();

            // FK al paquete de contingencia (nullable = emitida en línea o pendiente de paquete).
            $table->unsignedBigInteger('invoice_package_id')->nullable()->index();

            // Numeración correlativa (nroFactura en el XML SIAT).
            $table->string('nroFactura')->nullable();

            // CUF: Código Único de Factura. Generado localmente; identificador definitivo ante el SIN.
            $table->string('cuf')->nullable()->index();

            // Contexto SIAT con el que se emitió. Necesario para anular/revertir/verificar después.
            $table->unsignedInteger('codigo_sucursal');
            $table->unsignedInteger('codigo_punto_venta');

            $table->string('fechaEmision');
            $table->unsignedTinyInteger('codigoMetodoPago')->default(1); // 1=Efectivo, 2=QR/Tarjeta
            $table->decimal('montoTotal', 12, 2)->default(0);
            $table->decimal('montoTotalSujetoIva', 12, 2)->default(0);
            $table->decimal('descuentoAdicional', 12, 2)->default(0);

            // XML completo guardado para reenvío en paquetes de contingencia.
            $table->longText('xml')->nullable();

            // Estado devuelto por el SIN: 908=VALIDADA, 902=PENDIENTE DE ENVIO.
            $table->string('codigoEstado')->nullable();
            $table->string('codigoDescripcion')->nullable();
            $table->string('codigoRecepcion')->nullable();
            $table->boolean('transaccion')->default(false);

            // reversed=true: anulación ya revertida. El SIN solo permite revertir una vez.
            $table->boolean('reversed')->default(false);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
