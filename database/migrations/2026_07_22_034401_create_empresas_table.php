<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque clientes: la tabla raiz del sistema.
 * Cada fila de "empresas" es un cliente contribuyente del proveedor (SaaS).
 * Casi toda consulta del sistema filtra por empresa_id para aislar los datos
 * de un cliente de los de otro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();

            // Sandbox: copia en el piloto de una empresa de produccion, para que
            // el cliente pruebe su integracion sin tocar la real. Null = no es
            // copia de nadie.
            $table->foreignId('sandbox_de_id')->nullable()->constrained('empresas')->nullOnDelete();

            $table->string('nombre_comercial');
            $table->string('razon_social');
            $table->string('nit', 20);

            // Codigo de sistema que asigna el SIN a este contribuyente.
            $table->string('codigo_sistema')->nullable();

            // Se cifran con la clave de la app (cast 'encrypted' en el modelo)
            // porque son credenciales sensibles que jamas deben verse en claro.
            $table->text('token_delegado')->nullable();

            // 1 = Produccion, 2 = Piloto. Un mismo servidor mezcla ambos.
            $table->unsignedTinyInteger('codigo_ambiente')->default(2);
            // 1 = Electronica, 2 = Computarizada.
            $table->unsignedTinyInteger('codigo_modalidad')->default(1);
            // "Codigo Interno SIN" que el XSD V3 exige en la cabecera de la
            // factura (de 1 a 10 caracteres). El SIN en sus ejemplos manda "A".
            $table->string('codigo_interno', 10)->default('A');

            // Ciclo de vida ante el SIN, ver seccion 12.3 del documento maestro.
            $table->string('estado')->default('EN_REGISTRO');

            // URL a la que se notifican los cambios de estado de una factura.
            $table->string('webhook_url')->nullable();

            $table->timestamps();

            // Un mismo NIT puede existir una vez por ambiente (piloto y produccion).
            $table->unique(['nit', 'codigo_ambiente']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresas');
    }
};
