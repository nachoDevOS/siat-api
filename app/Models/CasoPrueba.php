<?php

namespace App\Models;

use Database\Factories\CasoPruebaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Caso de prueba ante el SIN. Vive en base de datos (no en codigo) para poder
 * editarlo cuando el SIN cambie el manual de pruebas.
 */
class CasoPrueba extends Model
{
    /** @use HasFactory<CasoPruebaFactory> */
    use HasFactory;

    // Fase 1 = pruebas del sistema con tu NIT. Fase 3 = piloto por cliente.
    public const FASE_SISTEMA = 1;

    public const FASE_PILOTO = 3;

    /**
     * Etapas del portal del SIN (Seguimiento de Autorizacion de Sistemas), con
     * el nombre que muestra el portal. Se agregan a medida que se implementan.
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
    ];

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

    public function ejecuciones(): HasMany
    {
        return $this->hasMany(EjecucionPrueba::class, 'caso_id');
    }
}
