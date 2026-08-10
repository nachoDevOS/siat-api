<?php

namespace App\Services\Panel;

use App\Models\Empresa;
use App\Models\Factura;

/**
 * Paleta unica de estados del panel.
 *
 * Existe para que un color signifique siempre lo mismo: si el verde es "listo"
 * en el listado de clientes, tiene que ser "listo" tambien en la ficha y en el
 * panel del piloto. Antes cada vista pintaba sus propios pills a mano y el
 * mismo estado se veia distinto en cada pantalla.
 *
 * Los colores son cinco y se leen asi:
 *   verde   = todo en orden
 *   azul    = en curso, nada que corregir
 *   violeta = hito alcanzado, falta el tramite siguiente
 *   ambar   = atencion: sirve, pero se vence o esta incompleto
 *   rojo    = bloqueante
 *   gris    = todavia no empezo
 */
class EstadosVisuales
{
    /**
     * Orden del ciclo de vida ante el SIN. OBSERVADO queda fuera a proposito:
     * no es una etapa mas, es una desviacion de la que hay que volver.
     *
     * @var list<string>
     */
    public const CICLO_EMPRESA = [
        Empresa::ESTADO_EN_REGISTRO,
        Empresa::ESTADO_EN_PRUEBAS,
        Empresa::ESTADO_PILOTO_APROBADO,
        Empresa::ESTADO_PRODUCCION,
    ];

    /**
     * @var array<string, array{etiqueta: string, color: string}>
     */
    private const EMPRESA = [
        Empresa::ESTADO_EN_REGISTRO => ['etiqueta' => 'En registro', 'color' => 'gris'],
        Empresa::ESTADO_EN_PRUEBAS => ['etiqueta' => 'En pruebas', 'color' => 'azul'],
        Empresa::ESTADO_PILOTO_APROBADO => ['etiqueta' => 'Piloto aprobado', 'color' => 'violeta'],
        Empresa::ESTADO_PRODUCCION => ['etiqueta' => 'Produccion', 'color' => 'verde'],
        Empresa::ESTADO_OBSERVADO => ['etiqueta' => 'Observado', 'color' => 'rojo'],
    ];

    /**
     * @var array<string, array{etiqueta: string, color: string}>
     */
    private const FACTURA = [
        Factura::ESTADO_PENDIENTE => ['etiqueta' => 'Pendiente', 'color' => 'gris'],
        Factura::ESTADO_ENVIADA => ['etiqueta' => 'Enviada', 'color' => 'azul'],
        Factura::ESTADO_RECIBIDA => ['etiqueta' => 'Recibida', 'color' => 'azul'],
        Factura::ESTADO_VALIDADA => ['etiqueta' => 'Validada', 'color' => 'verde'],
        // Una factura en contingencia YA es valida: no es un error, es ambar.
        Factura::ESTADO_CONTINGENCIA => ['etiqueta' => 'Contingencia', 'color' => 'ambar'],
        Factura::ESTADO_OBSERVADA => ['etiqueta' => 'Observada', 'color' => 'rojo'],
        Factura::ESTADO_ANULADA => ['etiqueta' => 'Anulada', 'color' => 'violeta'],
    ];

    /**
     * @return array{etiqueta: string, color: string}
     */
    public static function empresa(?string $estado): array
    {
        return self::EMPRESA[$estado] ?? ['etiqueta' => (string) $estado, 'color' => 'gris'];
    }

    /**
     * @return array{etiqueta: string, color: string}
     */
    public static function factura(?string $estado): array
    {
        return self::FACTURA[$estado] ?? ['etiqueta' => (string) $estado, 'color' => 'gris'];
    }

    /**
     * Estados de empresa para poblar filtros, con su etiqueta legible.
     *
     * @return array<string, string>
     */
    public static function estadosEmpresa(): array
    {
        return array_map(fn (array $datos): string => $datos['etiqueta'], self::EMPRESA);
    }

    /**
     * Posicion de la empresa dentro del ciclo (0 basada). OBSERVADO conserva
     * la posicion de "en pruebas", que es de donde se lo observa.
     */
    public static function posicionEnCiclo(string $estado): int
    {
        if ($estado === Empresa::ESTADO_OBSERVADO) {
            return 1;
        }

        $posicion = array_search($estado, self::CICLO_EMPRESA, true);

        return $posicion === false ? 0 : $posicion;
    }
}
