<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de la anulacion de una factura. Se guarda aparte para no perder el
 * dato de la factura original, que queda en estado ANULADA.
 */
class FacturaAnulada extends Model
{
    /** Registrada en local, todavia sin respuesta del SIN. */
    public const ESTADO_PENDIENTE = 'PENDIENTE';

    /** El SIN la acepto y devolvio codigo de recepcion. */
    public const ESTADO_CONFIRMADA = 'CONFIRMADA';

    /** El SIN la rechazo: la factura sigue vigente ante el SIN. */
    public const ESTADO_RECHAZADA = 'RECHAZADA';

    /** La anulacion se revirtio ante el SIN: la factura volvio a ser valida. */
    public const ESTADO_REVERTIDA = 'REVERTIDA';

    /** Anulada por el servicio web del SIN (anulacionFactura). */
    public const TIPO_SIAT = 'SIAT';

    /**
     * Anulada por tramite ante el SIN, fuera de plazo, con resolucion. No pasa
     * por SOAP: aca solo se registra lo que el SIN ya hizo.
     */
    public const TIPO_ADMINISTRATIVA = 'ADMINISTRATIVA';

    protected $table = 'facturas_anuladas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'anulada_en' => 'datetime',
        ];
    }

    public function esAdministrativa(): bool
    {
        return $this->tipo === self::TIPO_ADMINISTRATIVA;
    }

    public function factura(): BelongsTo
    {
        return $this->belongsTo(Factura::class);
    }
}
