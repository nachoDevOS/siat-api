<?php

namespace App\Console\Commands;

use App\Models\PuntoVenta;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('siat:revisar-codigos')]
#[Description('Revisa la vigencia del CUIS de cada punto de venta (diario)')]
class SiatRevisarCodigos extends Command
{
    /**
     * Chequeo diario (seccion 8.6): alerta si un punto de venta esta por quedar
     * sin CUIS vigente. Sin CUIS no se puede pedir el CUFD, y sin CUFD no se
     * emite: es el codigo que hay que vigilar con anticipacion.
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
            }
        });

        $this->info("Alertas: {$alertas}");

        return self::SUCCESS;
    }
}
