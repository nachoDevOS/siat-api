<?php

namespace App\Models;

use Database\Factories\CasoPruebaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Caso de prueba ante el SIN. Vive en base de datos (no en codigo) para poder
 * editarlo cuando el SIN cambie el manual de pruebas.
 */
class CasoPrueba extends Model
{
    /** @use HasFactory<CasoPruebaFactory> */
    use HasFactory;

    // Fase 3 = piloto por cliente, la unica que corre este sistema. (La fase
    // 1, pruebas del sistema con el NIT del proveedor, se hizo en el portal.)
    public const FASE_PILOTO = 3;

    /**
     * El portal tiene dos recorridos: el original (etapas I a XI, cuenta las
     * llamadas por /v2) y el V.3 (cuenta las de /sfd), con menos pruebas. Las
     * del V.3 se numeran aca con este desplazamiento: 101 = etapa I V.3.
     */
    public const DESPLAZAMIENTO_V3 = 100;

    /**
     * Etapas de CUIS de los dos recorridos: las corre el alta de sucursal.
     *
     * @var list<int>
     */
    public const ETAPAS_DE_CUIS = [1, 101];

    /**
     * Etapas del portal del SIN (Seguimiento de Autorizacion de Sistemas), con
     * el nombre que muestra el portal. Se agregan a medida que se implementan.
     * Las V.3 (101 en adelante) se agregan cuando se tiene su detalle del
     * portal: sus cantidades no siguen el reparto del original.
     *
     * @var array<int, string>
     */
    public const ETAPAS = [
        1 => 'Obtencion de CUIS',
        2 => 'Sincronizacion de Catalogos',
        3 => 'Obtencion CUFD',
        4 => 'Consumo de metodos de emision individual',
        5 => 'Registro de Eventos Significativos',
        6 => 'Consumo de metodos de emision de paquetes',
        7 => 'Anulacion',
        8 => 'Firma Digital',
        11 => 'Reversion',
        101 => 'Obtencion de CUIS',
        102 => 'Sincronizacion de Catalogos',
        103 => 'Obtencion CUFD',
    ];

    public static function esV3(int $etapa): bool
    {
        return $etapa > self::DESPLAZAMIENTO_V3;
    }

    /**
     * Numero de la etapa tal como lo muestra el portal (I, II, ..., XI).
     */
    public static function numeroRomano(int $etapa): string
    {
        $numero = self::esV3($etapa) ? $etapa - self::DESPLAZAMIENTO_V3 : $etapa;

        return [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI', 7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI'][$numero] ?? (string) $numero;
    }

    /**
     * "etapa I" o "etapa I V.3": como se nombra en los mensajes.
     */
    public static function nombreCorto(int $etapa): string
    {
        return 'etapa '.self::numeroRomano($etapa).(self::esV3($etapa) ? ' V.3' : '');
    }

    /**
     * Tipos que firman un documento: sin certificado .p12 activo no pueden
     * correr. El resto (codigos, catalogos, consultas) solo necesita el token,
     * asi que exigir el certificado ahi bloqueaba etapas que no lo usan.
     *
     * @var list<string>
     */
    private const TIPOS_QUE_FIRMAN = [
        'recepcionFactura',
        'recepcionFacturaDescuento',
        'recepcionFacturaNit',
        'anulacionFactura',
        'recepcionPaquete',
        'emisionIndividual',
        'paqueteContingencia',
    ];

    protected $table = 'casos_prueba';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload_ejemplo' => 'array',
            'obligatorio' => 'boolean',
            'etapa' => 'integer',
            'pruebas_esperadas' => 'integer',
        ];
    }

    public function requiereCertificado(): bool
    {
        return in_array($this->tipo, self::TIPOS_QUE_FIRMAN, true);
    }
}
