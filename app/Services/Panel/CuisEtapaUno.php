<?php

namespace App\Services\Panel;

use App\Models\CasoPrueba;
use App\Models\EjecucionPrueba;
use App\Models\Empresa;

/**
 * Estado de las pruebas de CUIS de la etapa I del piloto, por punto de venta
 * y version: si ya cuentan, con que CUIS, y si no por que fallo la ultima.
 *
 * Lo pintan la ficha (resumen tras dar de alta la sucursal, con "Reintentar")
 * y la pantalla de codigos del punto de venta. Las dos tienen que decir lo
 * mismo, por eso se calcula en un solo lugar.
 */
class CuisEtapaUno
{
    /**
     * Filas agrupadas por punto de venta, en el orden del portal (PV 1, PV 0)
     * y con V2 antes que V3. Vacio fuera del piloto o sin pruebas cargadas.
     *
     * @return array<int, array{codigo_sucursal: int, codigo_punto_venta: int, pruebas: list<array{caso: CasoPrueba, version: string, contada: bool, cuis: ?string, error: ?string, intentada: bool}>}>
     */
    public function porPuntoVenta(Empresa $empresa): array
    {
        if (! $empresa->esSandbox()) {
            return [];
        }

        $casos = CasoPrueba::where('fase', CasoPrueba::FASE_PILOTO)
            ->where('etapa', 1)
            ->where('tipo', 'solicitudCuis')
            ->get();

        // La ultima ejecucion de cada caso, y la ultima exitosa: una prueba
        // contada sigue contada aunque despues se haya repetido y fallado.
        $ejecuciones = EjecucionPrueba::where('empresa_id', $empresa->id)
            ->whereIn('caso_id', $casos->pluck('id'))
            ->latest('id')
            ->get()
            ->groupBy('caso_id');

        $grupos = [];

        foreach ($casos as $caso) {
            $codigoSucursal = (int) ($caso->payload_ejemplo['codigoSucursal'] ?? 0);
            $codigoPuntoVenta = (int) ($caso->payload_ejemplo['codigoPuntoVenta'] ?? 0);
            $deEste = $ejecuciones->get($caso->id, collect());
            $exitosa = $deEste->firstWhere('estado', EjecucionPrueba::ESTADO_EXITOSO);

            $grupos[$codigoPuntoVenta] ??= ['codigo_sucursal' => $codigoSucursal, 'codigo_punto_venta' => $codigoPuntoVenta, 'pruebas' => []];
            $grupos[$codigoPuntoVenta]['pruebas'][] = [
                'caso' => $caso,
                'version' => strtoupper((string) ($caso->payload_ejemplo['version'] ?? 'v3')),
                'contada' => $exitosa !== null,
                'cuis' => data_get($exitosa?->respuesta, 'cuis'),
                'error' => $exitosa === null ? data_get($deEste->first()?->respuesta, 'error') : null,
                'intentada' => $deEste->isNotEmpty(),
            ];
        }

        foreach ($grupos as &$grupo) {
            usort($grupo['pruebas'], fn (array $a, array $b): int => strcmp($a['version'], $b['version']));
        }

        // Orden del portal: primero el PV 1, despues el PV 0.
        uksort($grupos, fn (int $a, int $b): int => $b <=> $a);

        return $grupos;
    }

    /**
     * Las pruebas de un solo punto de venta, o null si no tiene en la etapa I.
     *
     * @return list<array{caso: CasoPrueba, version: string, contada: bool, cuis: ?string, error: ?string, intentada: bool}>|null
     */
    public function delPuntoVenta(Empresa $empresa, int $codigoSucursal, int $codigoPuntoVenta): ?array
    {
        $grupo = $this->porPuntoVenta($empresa)[$codigoPuntoVenta] ?? null;

        return $grupo !== null && $grupo['codigo_sucursal'] === $codigoSucursal ? $grupo['pruebas'] : null;
    }
}
