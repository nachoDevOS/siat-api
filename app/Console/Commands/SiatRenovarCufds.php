<?php

namespace App\Console\Commands;

use App\Jobs\RenovarCufd;
use App\Models\PuntoVenta;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('siat:renovar-cufds')]
#[Description('Renueva los CUFD proximos a vencer (capa preventiva, cada hora)')]
class SiatRenovarCufds extends Command
{
    /**
     * Capa preventiva de la estrategia de dos capas (seccion 8.2): renueva los
     * CUFD que vencen dentro de las proximas 2 horas para que la primera venta
     * del dia no tenga que esperar.
     */
    public function handle(): int
    {
        $limite = now()->addHours(2);
        $renovados = 0;

        PuntoVenta::where('activo', true)->chunkById(100, function ($puntos) use ($limite, &$renovados) {
            foreach ($puntos as $punto) {
                $vigente = $punto->cufdVigente();

                // Sin CUFD, o el vigente vence pronto: se despacha la renovacion.
                if ($vigente === null || $vigente->fecha_vigencia->lessThan($limite)) {
                    RenovarCufd::dispatch($punto->id);
                    $renovados++;
                }
            }
        });

        $this->info("CUFD despachados a renovar: {$renovados}");

        return self::SUCCESS;
    }
}
