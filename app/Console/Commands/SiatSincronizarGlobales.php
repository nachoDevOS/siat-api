<?php

namespace App\Console\Commands;

use App\Exceptions\SiatException;
use App\Models\Empresa;
use App\Models\PuntoVenta;
use App\Services\Catalogos\SincronizadorGlobal;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('siat:sincronizar-globales')]
#[Description('Sincroniza los catalogos parametricos globales del SIN (semanal)')]
class SiatSincronizarGlobales extends Command
{
    /**
     * Los catalogos globales son iguales para todos, asi que basta una
     * ejecucion con las credenciales de cualquier empresa activa (seccion 8.4).
     */
    public function handle(SincronizadorGlobal $sincronizador): int
    {
        // Se elige una empresa que tenga un CUIS vigente para autenticar.
        $empresa = Empresa::where('estado', Empresa::ESTADO_PRODUCCION)->first()
            ?? Empresa::first();

        if ($empresa === null) {
            $this->warn('No hay empresas para sincronizar catalogos globales.');

            return self::SUCCESS;
        }

        // Se busca el PUNTO DE VENTA, no el CUIS suelto: el SIN valida que el
        // CUIS corresponda a la sucursal y al punto de venta de la peticion, y
        // antes se mandaba el CUIS de uno con "punto de venta 0" de otro.
        $puntoVenta = PuntoVenta::with('sucursal.empresa')
            ->whereHas('sucursal', fn ($q) => $q->where('empresa_id', $empresa->id))
            ->get()
            ->first(fn (PuntoVenta $pv) => $pv->cuisVigente() !== null);

        if ($puntoVenta === null) {
            $this->warn("La empresa {$empresa->nombre_comercial} no tiene ningun punto de venta con CUIS vigente.");

            return self::FAILURE;
        }

        try {
            $resumen = $sincronizador->sincronizarTodo($puntoVenta);
        } catch (SiatException $e) {
            // Un rechazo del SIN ya no pasa por "sincronizado: 0 registros".
            $this->error("No se pudieron sincronizar los catalogos: {$e->getMessage()}");

            return self::FAILURE;
        }

        foreach ($resumen as $tipo => $total) {
            $this->line("{$tipo}: {$total}");
        }

        return self::SUCCESS;
    }
}
