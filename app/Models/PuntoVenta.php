<?php

namespace App\Models;

use Database\Factories\PuntoVentaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Punto de venta de una sucursal. A diferencia de la sucursal, este SI lo crea
 * el sistema en el SIAT. Lleva el correlativo local de facturas.
 */
class PuntoVenta extends Model
{
    /** @use HasFactory<PuntoVentaFactory> */
    use HasFactory;

    protected $table = 'puntos_venta';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'codigo_punto_venta' => 'integer',
            'tipo_punto_venta' => 'integer',
            'siguiente_factura' => 'integer',
            'activo' => 'boolean',
            'registrado_en_siat' => 'datetime',
        ];
    }

    /**
     * Codigo del punto de venta IMPLICITO de toda sucursal.
     *
     * El SIN lo da por existente sin que nadie lo registre: es el que queda
     * cuando la sucursal se crea en la Oficina Virtual. No aparece en la
     * respuesta de consultaPuntoVenta —que solo lista los registrados por API—
     * pero tiene su propio CUIS y CUFD y se puede facturar con el.
     *
     * VERIFICADO contra un sistema en produccion facturando ante el SIN: su
     * tablero muestra el PV 0 con CUIS vigente hasta 2027 y CUFD del dia, al
     * lado de los registrados por API (1, 7, 8, 9, 10).
     */
    public const CODIGO_IMPLICITO = 0;

    /**
     * Si este sistema ya registro el punto de venta en el SIN por API.
     *
     * Registrarlo dos veces no es un reintento inocuo: el SIN crea uno NUEVO
     * cada vez y un punto de venta no se puede borrar, solo cerrar, y un punto
     * de venta cerrado no se reabre. Por eso el paso 10 del piloto mira esto
     * antes de volver a llamar.
     */
    public function estaRegistradoEnSiat(): bool
    {
        return $this->registrado_en_siat !== null;
    }

    /**
     * Si el SIN conoce este punto de venta y por lo tanto se puede emitir.
     *
     * Es distinto de estaRegistradoEnSiat(): el punto de venta 0 existe del
     * lado del SIN sin que este sistema lo haya registrado nunca. Mirar solo la
     * marca de registro bloqueaba la emision con el PV 0, que es valido.
     */
    public function existeEnElSiat(): bool
    {
        return $this->estaRegistradoEnSiat()
            || (int) $this->codigo_punto_venta === self::CODIGO_IMPLICITO;
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * Facturas emitidas por este punto de venta. El correlativo es propio de
     * cada uno, asi que el conteo es lo que permite contrastarlo.
     */
    public function facturas(): HasMany
    {
        return $this->hasMany(Factura::class);
    }

    public function cuis(): HasMany
    {
        return $this->hasMany(Cuis::class);
    }

    public function cufds(): HasMany
    {
        return $this->hasMany(Cufd::class);
    }

    /**
     * CUFD vigente: el ULTIMO EMITIDO que todavia no vencio.
     *
     * Se ordena por id y no por fecha_vigencia. Parece lo mismo —un codigo mas
     * nuevo suele vencer despues— pero no lo es: al corregir la zona horaria a
     * America/La_Paz, los codigos guardados en UTC quedaron con una vigencia 4
     * horas mas lejana que los nuevos, asi que ordenando por vencimiento
     * "el vigente" pasaba a ser uno viejo, de otro punto de venta. El SIN
     * respondia entonces "PUNTO DE VENTA INEXISTENTE O INVALIDO".
     *
     * El id es monotono y no depende del reloj: el ultimo insertado es siempre
     * el ultimo que emitio el SIN.
     */
    public function cufdVigente(): ?Cufd
    {
        return $this->cufds()
            ->where('fecha_vigencia', '>', now())
            ->latest('id')
            ->first();
    }

    /**
     * CUIS que tiene que viajar junto a un CUFD: el MISMO con el que el SIN lo
     * emitio, no el mas nuevo del punto de venta.
     *
     * Comprobado en el piloto: despues de pedir un CUIS nuevo, una factura con
     * el CUFD anterior y el CUIS nuevo volvio con "[913] CUIS INVALIDO, CUIS
     * esperado <el anterior>". El SIN ata cada CUFD al CUIS que lo genero.
     * Los CUFD guardados antes de registrar ese vinculo caen al vigente.
     */
    public function cuisDe(?Cufd $cufd): ?Cuis
    {
        return $cufd?->cuis ?? $this->cuisVigente();
    }

    /**
     * CUIS vigente por la misma logica que el CUFD.
     */
    public function cuisVigente(): ?Cuis
    {
        return $this->cuis()
            ->where('fecha_vigencia', '>', now())
            ->latest('id')
            ->first();
    }
}
