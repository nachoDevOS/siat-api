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
            // Nota de credito-debito (sector 24): la factura que ajusta. Restrict:
            // una factura con notas no se puede borrar.
            $table->foreignId('factura_origen_id')->nullable()->constrained('facturas')->restrictOnDelete();
            // paquete_id y lote_masivo_id se agregan en las migraciones que crean
            // esas tablas, que vienen despues.

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
            // codigoExcepcion del SIN: 1 = se emite aunque el NIT no sea valido.
            $table->unsignedTinyInteger('codigo_excepcion')->default(0);
            $table->unsignedTinyInteger('moneda')->default(1);
            $table->decimal('tipo_cambio', 12, 5)->default(1);

            // Montos (2 decimales como exige el SIN).
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('descuento_global', 14, 2)->default(0);
            $table->decimal('gift_card', 14, 2)->default(0);
            // IVA transparente: total sin IVA y el IVA sumado encima.
            $table->decimal('total_precio_neto', 14, 2)->default(0);
            $table->decimal('iva', 14, 2)->default(0);
            $table->decimal('monto_total', 14, 2)->default(0);
            $table->decimal('monto_total_moneda', 14, 2)->default(0);
            $table->decimal('monto_total_sujeto_iva', 14, 2)->default(0);
            // Solo notas: 13% de lo devuelto, el credito fiscal que se revierte.
            $table->decimal('monto_efectivo_credito_debito', 14, 2)->nullable();

            $table->string('leyenda')->nullable();
            $table->string('usuario')->nullable();
            $table->unsignedTinyInteger('codigo_documento_sector')->default(1);
            $table->unsignedTinyInteger('tipo_emision')->default(1);

            // Estado interno + codigos que devuelve el SIAT tras el envio.
            $table->string('estado')->default('PENDIENTE');
            $table->string('codigo_recepcion')->nullable();
            $table->string('codigo_estado_siat')->nullable();
            // Por que el SIN observo la factura, si la observo.
            $table->text('motivo_observacion')->nullable();

            // El XML firmado es el documento legal; se guarda completo.
            $table->longText('xml_firmado')->nullable();
            $table->string('ruta_pdf')->nullable();

            // Referencia del sistema de ventas del cliente (idempotencia de negocio).
            $table->string('referencia_externa')->nullable();

            $table->dateTime('enviada_en')->nullable();
            $table->dateTime('validada_en')->nullable();
            $table->timestamps();

            // Unico: dos facturas con el mismo CUF es un error que hay que frenar.
            $table->unique('cuf');
            // Las colas buscan facturas por estado en orden de llegada.
            $table->index(['estado', 'id'], 'facturas_estado_id_index');
            // Una misma venta del cliente (referencia_externa) genera una sola factura.
            $table->unique(['empresa_id', 'referencia_externa']);
        });

        Schema::create('factura_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factura_id')->constrained('facturas')->cascadeOnDelete();

            $table->unsignedBigInteger('codigo_producto_sin');
            $table->string('codigo_actividad')->nullable();
            $table->string('codigo_interno')->nullable();
            $table->string('descripcion');
            $table->decimal('cantidad', 14, 4);
            $table->unsignedInteger('unidad_medida');
            $table->decimal('precio_unitario', 14, 2);
            $table->decimal('descuento', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2);
            $table->string('numero_serie')->nullable();
            $table->string('numero_imei')->nullable();
            // Solo notas: 1 = detalle original, 2 = devuelto, y el item que ajusta.
            $table->unsignedTinyInteger('codigo_detalle_transaccion')->nullable();
            $table->foreignId('item_origen_id')->nullable()->constrained('factura_items')->restrictOnDelete();

            $table->timestamps();
        });

        Schema::create('facturas_anuladas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factura_id')->constrained('facturas')->cascadeOnDelete();
            // SIAT (se anula en el SIN) o ADMINISTRATIVA (por resolucion, sin
            // llamada al SIN).
            $table->string('tipo')->default('SIAT');
            $table->string('numero_resolucion')->nullable();
            $table->unsignedTinyInteger('motivo');
            $table->string('codigo_recepcion')->nullable();
            $table->string('estado')->default('PENDIENTE');
            $table->text('motivo_rechazo')->nullable();
            // Estado en el que estaba la factura antes de marcarla ANULADA, para
            // poder devolverla ahi si el SIN rechaza la anulacion.
            $table->string('estado_anterior')->nullable();
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
