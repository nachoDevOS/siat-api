<?php

namespace App\Jobs;

use App\Models\CasoPrueba;
use App\Models\Empresa;
use App\Services\Pruebas\EjecutorPruebas;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Ejecuta un caso de prueba del piloto en segundo plano, para que el panel no
 * quede bloqueado esperando la respuesta del SIAT.
 */
class EjecutarCasoPrueba implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $empresaId,
        public readonly int $casoId,
    ) {}

    public function handle(EjecutorPruebas $ejecutor): void
    {
        $empresa = Empresa::find($this->empresaId);
        $caso = CasoPrueba::find($this->casoId);

        if ($empresa === null || $caso === null) {
            return;
        }

        $ejecutor->ejecutarCaso($empresa, $caso);
    }
}
