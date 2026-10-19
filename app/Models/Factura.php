<?php

namespace App\Models;

use Database\Factories\FacturaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\URL;

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

    /**
     * Emitida dentro de un lote de emision masiva abierto: valida e
     * imprimible, espera a que se cierre el lote para viajar en un paquete.
     */
    public const ESTADO_MASIVA = 'MASIVA';

    /**
     * Todos los estados, para validar filtros.
     *
     * @var list<string>
     */
    public const ESTADOS = [
        self::ESTADO_PENDIENTE,
        self::ESTADO_ENVIADA,
        self::ESTADO_RECIBIDA,
        self::ESTADO_VALIDADA,
        self::ESTADO_OBSERVADA,
        self::ESTADO_CONTINGENCIA,
        self::ESTADO_ANULADA,
        self::ESTADO_MASIVA,
    ];

    // Tipo de emision segun el SIN: 1 = en linea, 2 = contingencia.
    public const EMISION_EN_LINEA = 1;

    public const EMISION_CONTINGENCIA = 2;

    /** Emision masiva (lote): entra al CUF como cualquier tipo de emision. */
    public const EMISION_MASIVA = 3;

    protected $table = 'facturas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fecha_emision' => 'datetime',
            'enviada_en' => 'datetime',
            'validada_en' => 'datetime',
            'codigo_excepcion' => 'integer',
            'tipo_cambio' => 'decimal:5',
            'subtotal' => 'decimal:2',
            'descuento_global' => 'decimal:2',
            'gift_card' => 'decimal:2',
            'monto_total' => 'decimal:2',
            'monto_total_moneda' => 'decimal:2',
            'monto_total_sujeto_iva' => 'decimal:2',
            'monto_efectivo_credito_debito' => 'decimal:2',
            'codigo_documento_sector' => 'integer',
        ];
    }

    /**
     * Si el documento es una nota de credito-debito (sector 24) y no una
     * factura: cambia el XML, el servicio SOAP y la representacion grafica.
     */
    public function esNotaCreditoDebito(): bool
    {
        return $this->codigo_documento_sector === config('siat.codigos.nota_credito_debito.documento_sector');
    }

    /**
     * tipoFacturaDocumento del SIN: 1 = factura con credito fiscal, 3 =
     * documento de ajuste. Entra al CUF y viaja en cada solicitud SOAP.
     */
    public function tipoFacturaDocumento(): int
    {
        return $this->esNotaCreditoDebito()
            ? config('siat.codigos.nota_credito_debito.tipo_factura_documento')
            : config('siat.codigos.tipo_factura_documento');
    }

    /**
     * Factura que ajusta una nota de credito-debito. Null en una factura.
     */
    public function facturaOrigen(): BelongsTo
    {
        return $this->belongsTo(self::class, 'factura_origen_id');
    }

    /**
     * Notas de credito-debito emitidas sobre esta factura.
     */
    public function notas(): HasMany
    {
        return $this->hasMany(self::class, 'factura_origen_id');
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

    public function items(): HasMany
    {
        // En el orden en que se cargaron: es el orden del <detalle> del XML,
        // y en una nota los originales tienen que ir antes que los devueltos.
        return $this->hasMany(FacturaItem::class)->orderBy('id');
    }

    /**
     * Paquete de contingencia que transporta esta factura. Null mientras no se
     * haya armado ninguno.
     */
    public function paquete(): BelongsTo
    {
        return $this->belongsTo(Paquete::class);
    }

    /**
     * Registro de anulacion. Es hasOne: la clave foranea (factura_id) vive en
     * facturas_anuladas, no aca.
     */
    public function anulacion(): HasOne
    {
        return $this->hasOne(FacturaAnulada::class);
    }

    /**
     * Enlace PUBLICO y firmado de la factura, para que el sistema de ventas lo
     * abra o lo imprima sin token y el comprador la vea desde su telefono.
     *
     * La firma (HMAC con APP_KEY) es lo que impide adivinar facturas cambiando
     * el CUF: sin ella responde 404. No vence, porque una factura se conserva.
     * Va firmada sin el dominio (relative) para que valga igual detras de un
     * proxy o con otro host.
     *
     * @param  'ver'|'pdf'|'rollo'|'mini'|'zip'  $tipo  pagina, PDF carta, PDF
     *                                                  rollo 80 mm, mini ticket o ZIP.
     */
    public function urlPublica(string $tipo = 'ver'): string
    {
        $ruta = match ($tipo) {
            'pdf' => 'factura.publica.pdf',
            'rollo' => 'factura.publica.rollo',
            'mini' => 'factura.publica.mini',
            'zip' => 'factura.publica.zip',
            default => 'factura.publica',
        };

        return url(URL::signedRoute($ruta, ['cuf' => $this->cuf], absolute: false));
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
