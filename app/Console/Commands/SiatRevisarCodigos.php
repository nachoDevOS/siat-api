<?php

namespace App\Console\Commands;

use App\Exceptions\SiatException;
use App\Models\PuntoVenta;
use App\Services\Siat\GestorCodigos;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('siat:revisar-codigos')]
#[Description('Pide un CUIS nuevo a los puntos de venta que quedaron sin uno vigente (diario)')]
class SiatRevisarCodigos extends Command
{
    /**
     * Chequeo diario (seccion 8.6): a todo punto de venta que quedo sin CUIS
     * vigente se le pide uno nuevo. Sin CUIS no se puede pedir el CUFD, y sin
     * CUFD no se emite.
     *
     * Antes solo avisaba y alguien tenia que pedirlo a mano desde el panel.
     * No se puede renovar ANTES de que venza: con uno vigente el SIN devuelve
     * el mismo (codigo 980). Lo que no se consigue queda como alerta.
     */
    public function handle(GestorCodigos $gestor): int
    {
        $renovados = 0;
        $alertas = 0;

        PuntoVenta::where('activo', true)->with('sucursal.empresa')->chunkById(100, function ($puntos) use ($gestor, &$renovados, &$alertas) {
            foreach ($puntos as $punto) {
                if ($punto->cuisVigente() !== null) {
                    continue;
                }

                $etiqueta = "{$punto->sucursal->empresa->nombre_comercial} / PV {$punto->codigo_punto_venta}";

                // Un punto de venta que el SIN no conoce, o una empresa sin
                // token, no puede pedir codigos: queda solo como alerta.
                if (! $punto->existeEnElSiat() || blank($punto->sucursal->empresa->token_delegado)) {
                    $this->alertar("Sin CUIS vigente (no se puede pedir: falta token o registro en el SIN): {$etiqueta}");
                    $alertas++;

                    continue;
                }

                try {
                    // Con CUIS nuevo, tambien un CUFD atado a el.
                    $gestor->renovarCuis($punto);
                    $this->line("CUIS renovado: {$etiqueta}");
                    $renovados++;
                } catch (SiatException $e) {
                    $this->alertar("Sin CUIS vigente y el SIN no entrego uno: {$etiqueta} ({$e->getMessage()})");
                    $alertas++;
                }
            }
        });

        $this->info("CUIS renovados: {$renovados}. Alertas: {$alertas}");

        return self::SUCCESS;
    }

    /**
     * A la consola y al log: lo programado corre sin consola y lo que solo se
     * imprime se pierde.
     */
    private function alertar(string $mensaje): void
    {
        $this->warn($mensaje);
        Log::warning($mensaje);
    }
}
