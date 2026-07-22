<?php

namespace App\Console\Commands;

use App\Models\PuntoVenta;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('siat:revisar-codigos')]
#[Description('Revisa vigencia de CUIS y disponibilidad de CAFC por punto de venta (diario)')]
class SiatRevisarCodigos extends Command
{
    /**
     * Chequeo diario (seccion 8.6): alerta si un punto de venta esta por quedar
     * sin CUIS vigente o sin CAFC de reserva para contingencia.
     */
    public function handle(): int
    {
        $alertas = 0;

        PuntoVenta::where('activo', true)->with('sucursal.empresa')->chunkById(100, function ($puntos) use (&$alertas) {
            foreach ($puntos as $punto) {
                $etiqueta = "{$punto->sucursal->empresa->nombre_comercial} / PV {$punto->codigo_punto_venta}";

                if ($punto->cuisVigente() === null) {
                    $this->warn("Sin CUIS vigente: {$etiqueta}");
                    $alertas++;
                }

                // CAFC de reserva disponible (vigente y con folios).
                $cafc = $punto->cafcs()->where('fecha_vigencia', '>', now())
                    ->whereColumn('facturas_usadas', '<', 'cantidad_facturas')
                    ->exists();

                if (! $cafc) {
                    $this->warn("Sin CAFC de reserva: {$etiqueta}");
                    $alertas++;
                }
            }
        });

        $this->info("Alertas: {$alertas}");

        return self::SUCCESS;
    }
}
