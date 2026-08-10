<?php

namespace App\Console\Commands;

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

        $cuis = PuntoVenta::query()
            ->whereHas('sucursal', fn ($q) => $q->where('empresa_id', $empresa->id))
            ->get()
            ->map(fn (PuntoVenta $pv) => $pv->cuisVigente())
            ->filter()
            ->first();

        if ($cuis === null) {
            $this->warn("La empresa {$empresa->nombre_comercial} no tiene CUIS vigente.");

            return self::SUCCESS;
        }

        $resumen = $sincronizador->sincronizarTodo($empresa, $cuis->codigo);

        foreach ($resumen as $tipo => $total) {
            $this->line("{$tipo}: {$total}");
        }

        return self::SUCCESS;
    }
}
