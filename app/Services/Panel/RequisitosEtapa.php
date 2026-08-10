<?php

namespace App\Services\Panel;

use App\Models\CasoPrueba;
use App\Models\EjecucionPrueba;
use App\Models\Empresa;
use App\Models\PuntoVenta;
use Illuminate\Support\Carbon;

/**
 * Responde una sola pregunta: que le falta a este cliente para pasar a la
 * siguiente etapa ante el SIN.
 *
 * Vive en un unico lugar porque la misma respuesta la necesitan el listado
 * (para el stepper), la ficha (para el checklist) y el alta guiada (para saber
 * que accion habilitar). Si cada pantalla lo calculara por su cuenta, el panel
 * terminaria contradiciendose a si mismo.
 */
class RequisitosEtapa
{
    /**
     * Dias antes del vencimiento a partir de los cuales el certificado se
     * marca en ambar. Coincide con el aviso de 'siat:avisar-certificados'.
     */
    private const DIAS_AVISO_CERTIFICADO = 30;

    /**
     * Estado completo de la empresa para el panel.
     *
     * @return array{
     *     etapa: string,
     *     siguiente: ?string,
     *     requisitos: list<array{titulo: string, cumplido: bool, detalle: string}>,
     *     completos: bool,
     *     cumplidos: int,
     *     total: int,
     * }
     */
    public function para(Empresa $empresa): array
    {
        $requisitos = $this->requisitosDe($empresa);
        $cumplidos = count(array_filter($requisitos, fn (array $r): bool => $r['cumplido']));

        return [
            'etapa' => $empresa->estado,
            'siguiente' => $this->siguienteEtapa($empresa),
            'requisitos' => $requisitos,
            'completos' => $requisitos !== [] && $cumplidos === count($requisitos),
            'cumplidos' => $cumplidos,
            'total' => count($requisitos),
        ];
    }

    /**
     * Etapa a la que puede avanzar. null si ya esta en PRODUCCION.
     */
    public function siguienteEtapa(Empresa $empresa): ?string
    {
        // Una empresa observada no avanza: vuelve a pruebas a corregir.
        if ($empresa->estado === Empresa::ESTADO_OBSERVADO) {
            return Empresa::ESTADO_EN_PRUEBAS;
        }

        $posicion = array_search($empresa->estado, EstadosVisuales::CICLO_EMPRESA, true);

        if ($posicion === false) {
            return Empresa::ESTADO_EN_REGISTRO;
        }

        return EstadosVisuales::CICLO_EMPRESA[$posicion + 1] ?? null;
    }

    /**
     * Requisitos de la etapa actual. Cada etapa pide lo suyo: no tiene sentido
     * exigir el piloto completo a quien todavia no cargo el certificado.
     *
     * @return list<array{titulo: string, cumplido: bool, detalle: string}>
     */
    private function requisitosDe(Empresa $empresa): array
    {
        return match ($empresa->estado) {
            Empresa::ESTADO_EN_REGISTRO => $this->requisitosDeRegistro($empresa),
            Empresa::ESTADO_EN_PRUEBAS, Empresa::ESTADO_OBSERVADO => $this->requisitosDePruebas($empresa),
            Empresa::ESTADO_PILOTO_APROBADO => $this->requisitosDeProduccion($empresa),
            default => [],
        };
    }

    /**
     * Lo que hace falta para poder siquiera empezar el piloto. Todo esto sale
     * de tramites del contribuyente en la Oficina Virtual del SIN.
     *
     * @return list<array{titulo: string, cumplido: bool, detalle: string}>
     */
    private function requisitosDeRegistro(Empresa $empresa): array
    {
        $certificado = $empresa->certificados->firstWhere('activo', true);
        $sucursales = $empresa->sucursales;
        $puntosVenta = $sucursales->flatMap->puntosVenta;

        return [
            [
                'titulo' => 'Token delegado cargado',
                'cumplido' => filled($empresa->token_delegado),
                'detalle' => 'Se genera en la Oficina Virtual del SIN y autentica cada llamada SOAP.',
            ],
            [
                'titulo' => 'Codigo de sistema asignado',
                'cumplido' => filled($empresa->codigo_sistema),
                'detalle' => 'Lo entrega el SIN al aprobar la solicitud del contribuyente.',
            ],
            [
                'titulo' => 'Certificado .p12 activo',
                'cumplido' => $certificado !== null,
                'detalle' => 'Sin certificado no se puede firmar el XML de la factura.',
            ],
            [
                'titulo' => 'Al menos una sucursal',
                'cumplido' => $sucursales->isNotEmpty(),
                'detalle' => 'La casa matriz es la sucursal 0. Se copia de la Oficina Virtual.',
            ],
            [
                'titulo' => 'Al menos un punto de venta',
                'cumplido' => $puntosVenta->isNotEmpty(),
                'detalle' => 'Los puntos de venta si los registra este sistema en el SIAT.',
            ],
        ];
    }

    /**
     * Lo que hace falta para dar el piloto por aprobado.
     *
     * @return list<array{titulo: string, cumplido: bool, detalle: string}>
     */
    private function requisitosDePruebas(Empresa $empresa): array
    {
        $progreso = $this->progresoPiloto($empresa);

        return [
            [
                'titulo' => 'CUIS vigente en algun punto de venta',
                'cumplido' => $this->tieneCodigoVigente($empresa, 'cuis'),
                'detalle' => 'Identifica el punto de venta ante el SIN. Dura cerca de un ano.',
            ],
            [
                'titulo' => 'CUFD vigente en algun punto de venta',
                'cumplido' => $this->tieneCodigoVigente($empresa, 'cufd'),
                'detalle' => 'Dura 24 horas y su codigo de control entra al calculo del CUF.',
            ],
            [
                'titulo' => "Casos del piloto superados ({$progreso['exitosos']}/{$progreso['total']})",
                'cumplido' => $progreso['total'] > 0 && $progreso['exitosos'] === $progreso['total'],
                'detalle' => 'Los casos obligatorios de la fase piloto deben quedar en EXITOSO.',
            ],
        ];
    }

    /**
     * Del piloto aprobado a produccion el paso es un tramite ante el SIN; de
     * este lado solo se comprueba que el cliente siga en condiciones de emitir.
     *
     * @return list<array{titulo: string, cumplido: bool, detalle: string}>
     */
    private function requisitosDeProduccion(Empresa $empresa): array
    {
        $certificado = $empresa->certificados->firstWhere('activo', true);

        return [
            [
                'titulo' => 'Certificado vigente',
                'cumplido' => $certificado !== null
                    && ($certificado->vence_el === null || $certificado->vence_el->isFuture()),
                'detalle' => 'Un certificado vencido invalida la firma de toda factura nueva.',
            ],
            [
                'titulo' => 'Ambiente de produccion configurado',
                'cumplido' => $empresa->codigo_ambiente === 1,
                'detalle' => 'El ambiente 1 es produccion; el 2 sigue apuntando al piloto.',
            ],
            [
                'titulo' => 'Webhook de notificacion configurado',
                'cumplido' => filled($empresa->webhook_url),
                'detalle' => 'Opcional pero recomendado: avisa al cliente cuando el SIN valida u observa.',
            ],
        ];
    }

    /**
     * Progreso del piloto: cuantos casos obligatorios estan en EXITOSO.
     *
     * @return array{exitosos: int, total: int, porcentaje: int}
     */
    public function progresoPiloto(Empresa $empresa): array
    {
        $casos = CasoPrueba::where('fase', CasoPrueba::FASE_PILOTO)->get();

        // Solo cuenta la ULTIMA ejecucion de cada caso: un caso que fallo y
        // despues paso, paso.
        $ultimas = EjecucionPrueba::where('empresa_id', $empresa->id)
            ->get()
            ->groupBy('caso_id')
            ->map(fn ($grupo) => $grupo->sortByDesc('ejecutado_en')->first());

        $exitosos = $casos->filter(
            fn (CasoPrueba $caso): bool => ($ultimas[$caso->id] ?? null)?->estado === EjecucionPrueba::ESTADO_EXITOSO,
        )->count();

        $total = $casos->count();

        return [
            'exitosos' => $exitosos,
            'total' => $total,
            'porcentaje' => $total === 0 ? 0 : (int) round($exitosos / $total * 100),
        ];
    }

    /**
     * Semaforo de vigencia de una fecha: verde si falta tiempo, ambar si esta
     * por vencer, rojo si ya vencio o no existe.
     *
     * @return array{color: string, texto: string}
     */
    public static function vigencia(?Carbon $vence, float $horasDeAviso): array
    {
        if ($vence === null) {
            return ['color' => 'rojo', 'texto' => 'sin datos'];
        }

        if ($vence->isPast()) {
            return ['color' => 'rojo', 'texto' => 'vencido '.$vence->diffForHumans()];
        }

        // Ambar cuando el vencimiento cae dentro de la ventana de aviso.
        $color = now()->addHours($horasDeAviso)->gte($vence) ? 'ambar' : 'verde';

        return ['color' => $color, 'texto' => 'vence '.$vence->diffForHumans()];
    }

    /**
     * Semaforo del certificado activo, que avisa 30 dias antes de vencer.
     *
     * @return array{color: string, texto: string}
     */
    public function semaforoCertificado(Empresa $empresa): array
    {
        $certificado = $empresa->certificados->firstWhere('activo', true);

        if ($certificado === null) {
            return ['color' => 'rojo', 'texto' => 'sin certificado'];
        }

        if ($certificado->vence_el === null) {
            return ['color' => 'ambar', 'texto' => 'cargado, sin fecha de vencimiento'];
        }

        return self::vigencia($certificado->vence_el, self::DIAS_AVISO_CERTIFICADO * 24);
    }

    /**
     * Hay algun punto de venta de la empresa con ese codigo vigente.
     */
    private function tieneCodigoVigente(Empresa $empresa, string $codigo): bool
    {
        return PuntoVenta::query()
            ->whereHas('sucursal', fn ($q) => $q->where('empresa_id', $empresa->id))
            ->whereHas($codigo === 'cuis' ? 'cuis' : 'cufds', fn ($q) => $q->where('fecha_vigencia', '>', now()))
            ->exists();
    }
}
