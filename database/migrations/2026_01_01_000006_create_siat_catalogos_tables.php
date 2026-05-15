<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas de catálogos/paramétricas del SIAT.
 * Se sincronizan desde el endpoint FacturacionSincronizacion del SIN.
 * No se modifican manualmente; solo se leen durante la generación de facturas.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Actividades económicas (código CAEB). Se usa en cada <detalle> del XML.
        Schema::create('siat_actividades', function (Blueprint $table) {
            $table->id();
            $table->string('codigoCaeb')->index();
            $table->string('descripcion')->nullable();
            $table->timestamps();
        });

        // Leyendas obligatorias. Se elige una aleatoria para la <cabecera> de cada factura.
        Schema::create('siat_leyendas', function (Blueprint $table) {
            $table->id();
            $table->string('codigoActividad')->nullable();
            $table->text('descripcionLeyenda');
            $table->timestamps();
        });

        // Motivos de anulación. El operador debe elegir uno al anular una factura.
        Schema::create('siat_motivos_anulacion', function (Blueprint $table) {
            $table->id();
            $table->string('codigoClasificador')->index();
            $table->string('descripcion');
            $table->timestamps();
        });

        // Tipos de eventos significativos para contingencia (ej: "Venta sin internet").
        Schema::create('siat_eventos_significativos', function (Blueprint $table) {
            $table->id();
            $table->string('codigoClasificador')->index();
            $table->string('descripcion');
            $table->timestamps();
        });

        // Tipos de documento de identidad (CI, NIT, Pasaporte, etc.).
        Schema::create('siat_tipo_documentos', function (Blueprint $table) {
            $table->id();
            $table->string('codigoClasificador')->index();
            $table->string('descripcion');
            $table->timestamps();
        });

        // Métodos de pago aceptados por el SIN.
        Schema::create('siat_metodo_pagos', function (Blueprint $table) {
            $table->id();
            $table->string('codigoClasificador')->index();
            $table->string('descripcion');
            $table->timestamps();
        });

        // Tipos de factura (Con crédito fiscal, Sin crédito fiscal, etc.).
        Schema::create('siat_tipo_facturas', function (Blueprint $table) {
            $table->id();
            $table->string('codigoClasificador')->index();
            $table->string('descripcion');
            $table->timestamps();
        });

        // Unidades de medida (unidadMedida en <detalle>).
        Schema::create('siat_unidad_medidas', function (Blueprint $table) {
            $table->id();
            $table->string('codigoClasificador')->index();
            $table->string('descripcion');
            $table->timestamps();
        });

        // Países de origen de productos.
        Schema::create('siat_pais_origens', function (Blueprint $table) {
            $table->id();
            $table->string('codigoClasificador')->index();
            $table->string('descripcion');
            $table->timestamps();
        });

        // Tipos de punto de venta.
        Schema::create('siat_tipo_punto_ventas', function (Blueprint $table) {
            $table->id();
            $table->string('codigoClasificador')->index();
            $table->string('descripcion');
            $table->timestamps();
        });

        // Tipos de moneda.
        Schema::create('siat_tipo_monedas', function (Blueprint $table) {
            $table->id();
            $table->string('codigoClasificador')->index();
            $table->string('descripcion');
            $table->timestamps();
        });

        // Tipos de emisión (En línea, Fuera de línea, etc.).
        Schema::create('siat_tipo_emisions', function (Blueprint $table) {
            $table->id();
            $table->string('codigoClasificador')->index();
            $table->string('descripcion');
            $table->timestamps();
        });

        // Actividades por documento-sector.
        Schema::create('siat_actividad_documento_sectors', function (Blueprint $table) {
            $table->id();
            $table->string('codigoDocumentoSector')->index();
            $table->string('codigoActividad')->nullable();
            $table->string('descripcion')->nullable();
            $table->timestamps();
        });

        // Productos y servicios del catálogo SIN (codigoProductoSin en <detalle>).
        Schema::create('siat_productos', function (Blueprint $table) {
            $table->id();
            $table->string('codigoProducto')->index();
            $table->string('descripcion')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siat_productos');
        Schema::dropIfExists('siat_actividad_documento_sectors');
        Schema::dropIfExists('siat_tipo_emisions');
        Schema::dropIfExists('siat_tipo_monedas');
        Schema::dropIfExists('siat_tipo_punto_ventas');
        Schema::dropIfExists('siat_pais_origens');
        Schema::dropIfExists('siat_unidad_medidas');
        Schema::dropIfExists('siat_tipo_facturas');
        Schema::dropIfExists('siat_metodo_pagos');
        Schema::dropIfExists('siat_tipo_documentos');
        Schema::dropIfExists('siat_eventos_significativos');
        Schema::dropIfExists('siat_motivos_anulacion');
        Schema::dropIfExists('siat_leyendas');
        Schema::dropIfExists('siat_actividades');
    }
};
