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
     * Si el SIN ya le asigno codigo a este punto de venta.
     *
     * Registrarlo dos veces no es un reintento inocuo: el SIN crea uno NUEVO
     * cada vez y un punto de venta no se puede borrar, solo cerrar, y un punto
     * de venta cerrado no se reabre.
     */
    public function estaRegistradoEnSiat(): bool
    {
        return $this->registrado_en_siat !== null;
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function cuis(): HasMany
    {
        return $this->hasMany(Cuis::class);
    }

    public function cufds(): HasMany
    {
        return $this->hasMany(Cufd::class);
    }

    public function cafcs(): HasMany
    {
        return $this->hasMany(Cafc::class);
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
