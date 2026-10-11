<?php

namespace App\Jobs;

use App\Models\Empresa;
use App\Services\Catalogos\SincronizadorEmpresa;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sincroniza los catalogos por empresa (actividades, productos, leyendas).
 *
 * Lo despacha la tarea diaria 'sincronizar-catalogos-empresas'. Sin ella, en
 * produccion estos catalogos solo se llenaban desde el piloto: un producto que
 * el cliente homologara despues no existia aca y su factura se cortaba con
 * "no esta homologado". El panel tiene ademas un boton para correrlo a mano.
 */
class SincronizarCatalogosEmpresa implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [300, 900];

    public function __construct(public readonly int $empresaId) {}

    public function handle(SincronizadorEmpresa $sincronizador): void
    {
        $empresa = Empresa::find($this->empresaId);

        if ($empresa === null) {
            return;
        }

        // Sin CUIS vigente no hay con que autenticar la consulta: la empresa
        // todavia no llego a la etapa de codigos. No es una falla del job.
        $puntoVenta = $empresa->puntoVentaConCuisVigente();

        if ($puntoVenta === null) {
            return;
        }

        $sincronizador->sincronizarTodo($puntoVenta);
    }
}
