<?php

namespace App\Models;

use Database\Factories\FacturaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una factura emitida. El XML firmado que guarda es el documento legal;
 * el estado sigue la maquina de la seccion 9.3 del documento maestro.
 */
class Factura extends Model
{
    /** @use HasFactory<FacturaFactory> */
    use HasFactory;

    // Estados internos de la factura (seccion 9.3).
    public const ESTADO_PENDIENTE = 'PENDIENTE';

    public const ESTADO_ENVIADA = 'ENVIADA';

    public const ESTADO_RECIBIDA = 'RECIBIDA';

    public const ESTADO_VALIDADA = 'VALIDADA';

    public const ESTADO_OBSERVADA = 'OBSERVADA';

    public const ESTADO_CONTINGENCIA = 'CONTINGENCIA';

    public const ESTADO_ANULADA = 'ANULADA';

    // Tipo de emision segun el SIN: 1 = en linea, 2 = contingencia.
    public const EMISION_EN_LINEA = 1;

    public const EMISION_CONTINGENCIA = 2;

    protected $table = 'facturas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fecha_emision' => 'datetime',
            'enviada_en' => 'datetime',
            'validada_en' => 'datetime',
            'tipo_cambio' => 'decimal:5',
            'subtotal' => 'decimal:2',
            'descuento_global' => 'decimal:2',
            'gift_card' => 'decimal:2',
            'anticipo' => 'decimal:2',
            'monto_total' => 'decimal:2',
            'monto_total_moneda' => 'decimal:2',
            'monto_total_sujeto_iva' => 'decimal:2',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function puntoVenta(): BelongsTo
    {
        return $this->belongsTo(PuntoVenta::class);
    }

    public function cufd(): BelongsTo
    {
        return $this->belongsTo(Cufd::class);
    }

    public function cafc(): BelongsTo
    {
        return $this->belongsTo(Cafc::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(FacturaItem::class);
    }

    public function anulacion(): BelongsTo
    {
        return $this->belongsTo(FacturaAnulada::class, 'id', 'factura_id');
    }

    /**
     * Una factura en contingencia ya es valida y se puede imprimir; solo le
     * falta transmitirse al SIAT dentro de un paquete.
     */
    public function esContingencia(): bool
    {
        return $this->estado === self::ESTADO_CONTINGENCIA;
    }
}
