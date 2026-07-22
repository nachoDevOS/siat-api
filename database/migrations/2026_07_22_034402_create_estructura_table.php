<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estructura de cada empresa: certificados, sucursales y puntos de venta.
 * Se agrupan en una sola migracion porque forman la jerarquia fisica del
 * contribuyente y siempre se crean/eliminan juntas.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Certificado digital .p12 por empresa. El archivo y la passphrase se
        // cifran en el modelo; aca solo definimos las columnas.
        Schema::create('certificados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();

            // Contenido binario del .p12 cifrado (se guarda como texto base64 cifrado).
            $table->longText('contenido_p12');
            $table->text('passphrase');

            $table->string('emitido_por')->nullable();
            $table->date('vence_el')->nullable();

            // Solo un certificado activo por empresa a la vez; el resto queda
            // como historial para no perder trazabilidad.
            $table->boolean('activo')->default(true);

            $table->timestamps();
        });

        // Sucursales: el contribuyente las registra en su Oficina Virtual.
        // El sistema NO las crea en el SIAT, solo las copia para referenciarlas.
        Schema::create('sucursales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();

            // 0 = casa matriz. El SIN usa un entero, no un id interno.
            $table->unsignedInteger('codigo_sucursal');
            $table->string('nombre');
            $table->string('municipio')->nullable();
            $table->string('direccion')->nullable();
            $table->string('telefono')->nullable();

            $table->timestamps();

            $table->unique(['empresa_id', 'codigo_sucursal']);
        });

        // Puntos de venta: estos SI los crea el sistema en el SIAT.
        // "siguiente_factura" es el correlativo local; se reserva con bloqueo
        // de fila al emitir para que dos cajas no tomen el mismo numero.
        Schema::create('puntos_venta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnDelete();

            $table->unsignedInteger('codigo_punto_venta');
            $table->string('nombre');
            $table->unsignedTinyInteger('tipo_punto_venta')->default(1);

            $table->unsignedBigInteger('siguiente_factura')->default(1);
            $table->boolean('activo')->default(true);

            $table->timestamps();

            $table->unique(['sucursal_id', 'codigo_punto_venta']);
        });
    }

    public function down(): void
    {
        // Orden inverso por las llaves foraneas.
        Schema::dropIfExists('puntos_venta');
        Schema::dropIfExists('sucursales');
        Schema::dropIfExists('certificados');
    }
};
