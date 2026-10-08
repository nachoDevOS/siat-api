<?php

namespace App\Jobs;

use App\Models\CasoPrueba;
use App\Models\EjecucionPrueba;
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

    /**
     * Una prueba fallida no se reintenta sola: queda registrada como FALLIDA y
     * el operador decide. Reintentar a ciegas inflaria el conteo de errores.
     */
    public int $tries = 1;

    /**
     * Un paquete de la etapa VI arma y firma 500 facturas antes de enviarlas:
     * no entra en los 60 s por defecto del worker. Con un solo worker el
     * retry_after de la cola no lo duplica (nadie mas toma el job).
     */
    public int $timeout = 300;

    /**
     * @param  string|null  $despachadoEn  momento en que se encolo el lote. Si la
     *                                     prueba fallo DESPUES de eso, el resto del
     *                                     lote se descarta: 50 llamadas con el mismo
     *                                     error no aportan nada.
     */
    public function __construct(
        public readonly int $empresaId,
        public readonly int $casoId,
        public readonly ?string $despachadoEn = null,
    ) {}

    public function handle(EjecutorPruebas $ejecutor): void
    {
        $empresa = Empresa::find($this->empresaId);
        $caso = CasoPrueba::find($this->casoId);

        if ($empresa === null || $caso === null) {
            return;
        }

        // Ya llego a sus esperadas (por otro lote o a mano): no se pasa de largo.
        if ($caso->etapa !== null && $ejecutor->faltantes($empresa, $caso) === 0) {
            return;
        }

        if ($this->despachadoEn !== null && $this->falloEnEsteLote($empresa, $caso)) {
            return;
        }

        $ejecutor->ejecutarCaso($empresa, $caso);
    }

    private function falloEnEsteLote(Empresa $empresa, CasoPrueba $caso): bool
    {
        return EjecucionPrueba::where('empresa_id', $empresa->id)
            ->where('caso_id', $caso->id)
            ->where('estado', EjecucionPrueba::ESTADO_FALLIDO)
            ->where('ejecutado_en', '>=', $this->despachadoEn)
            ->exists();
    }
}
