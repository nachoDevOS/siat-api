<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque facturacion: facturas, sus items y el registro de anulaciones.
 * Es el corazon del sistema; todo lo demas existe para poder llenar estas filas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facturas', function (Blueprint $table) {
            $table->id();

            // Aislamiento multi-cliente + trazabilidad del punto de venta y codigos.
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('punto_venta_id')->constrained('puntos_venta')->cascadeOnDelete();
            $table->foreignId('cufd_id')->nullable()->constrained('cufd')->nullOnDelete();
            $table->foreignId('cafc_id')->nullable()->constrained('cafc')->nullOnDelete();

            // El CUF se calcula localmente antes de enviar. Se indexa porque la
            // API consulta la factura por su CUF.
            $table->string('cuf')->nullable();
            $table->unsignedBigInteger('numero_factura');
            $table->dateTime('fecha_emision');

            // Datos del comprador (denormalizados: la factura es un documento fijo).
            $table->unsignedTinyInteger('comprador_tipo_documento');
            $table->string('comprador_numero_documento');
            $table->string('comprador_complemento')->nullable();
            $table->string('comprador_razon_social');
            $table->string('comprador_email')->nullable();

            // Pago y moneda.
            $table->unsignedTinyInteger('metodo_pago');
            $table->string('numero_tarjeta')->nullable();
            $table->unsignedTinyInteger('moneda')->default(1);
            $table->decimal('tipo_cambio', 12, 5)->default(1);

            // Montos (2 decimales como exige el SIN).
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('descuento_global', 14, 2)->default(0);
            $table->decimal('gift_card', 14, 2)->default(0);
            $table->decimal('anticipo', 14, 2)->default(0);
            $table->decimal('monto_total', 14, 2)->default(0);
            $table->decimal('monto_total_moneda', 14, 2)->default(0);
            $table->decimal('monto_total_sujeto_iva', 14, 2)->default(0);

            $table->string('leyenda')->nullable();
            $table->string('usuario')->nullable();
            $table->unsignedTinyInteger('codigo_documento_sector')->default(1);
            $table->unsignedTinyInteger('tipo_emision')->default(1);

            // Estado interno + codigos que devuelve el SIAT tras el envio.
            $table->string('estado')->default('PENDIENTE');
            $table->string('codigo_recepcion')->nullable();
            $table->string('codigo_estado_siat')->nullable();

            // El XML firmado es el documento legal; se guarda completo.
            $table->longText('xml_firmado')->nullable();
            $table->string('ruta_pdf')->nullable();

            // Referencia del sistema de ventas del cliente (idempotencia de negocio).
            $table->string('referencia_externa')->nullable();

            $table->dateTime('enviada_en')->nullable();
            $table->dateTime('validada_en')->nullable();
            $table->timestamps();

            $table->index('cuf');
            // Una misma venta del cliente (referencia_externa) genera una sola factura.
            $table->unique(['empresa_id', 'referencia_externa']);
        });

        Schema::create('factura_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factura_id')->constrained('facturas')->cascadeOnDelete();

            $table->unsignedBigInteger('codigo_producto_sin');
            $table->string('codigo_interno')->nullable();
            $table->string('descripcion');
            $table->decimal('cantidad', 14, 4);
            $table->unsignedInteger('unidad_medida');
            $table->decimal('precio_unitario', 14, 2);
            $table->decimal('descuento', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2);
            $table->string('numero_serie')->nullable();
            $table->string('numero_imei')->nullable();

            $table->timestamps();
        });

        Schema::create('facturas_anuladas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factura_id')->constrained('facturas')->cascadeOnDelete();
            $table->unsignedTinyInteger('motivo');
            $table->string('codigo_recepcion')->nullable();
            $table->dateTime('anulada_en');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facturas_anuladas');
        Schema::dropIfExists('factura_items');
        Schema::dropIfExists('facturas');
    }
};
