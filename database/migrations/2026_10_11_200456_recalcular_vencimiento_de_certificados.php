<?php

use App\Models\Certificado;
use Illuminate\Database\Migrations\Migration;

/**
 * Corrige el vencimiento de los certificados ya cargados.
 *
 * Hasta el 2026-10-11 la fecha se leia del X509 en UTC: un certificado que
 * vence de madrugada (hora UTC) quedaba con un dia de mas en vence_el. Se
 * recalcula desde el propio .p12, ahora en hora de Bolivia. Un .p12 que no se
 * pueda abrir se deja como esta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Certificado::query()->each(function (Certificado $certificado): void {
            $vence = $certificado->vencimientoDelP12();

            if ($vence !== null && $certificado->vence_el?->toDateString() !== $vence->toDateString()) {
                $certificado->forceFill(['vence_el' => $vence->toDateString()])->saveQuietly();
            }
        });
    }

    public function down(): void
    {
        // Correccion de datos: no tiene vuelta atras util.
    }
};
