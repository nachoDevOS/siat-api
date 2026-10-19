<?php

namespace App\Models;

use Database\Factories\CafcFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Codigo de Autorizacion de Facturas de Contingencia (CAFC) de una empresa.
 *
 * Lo entrega el SIN para un NIT con un rango de numeros. Solo se usa en los
 * eventos significativos 5, 6 y 7, cuando el sistema no puede emitir y se
 * factura a mano: esas facturas llevan el CAFC y un numero de su rango, no
 * del correlativo del punto de venta.
 */
class Cafc extends Model
{
    /** @use HasFactory<CafcFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'numero_inicio' => 'integer',
            'numero_fin' => 'integer',
            'ultimo_numero' => 'integer',
            'activo' => 'boolean',
        ];
    }

    /**
     * Cuantos numeros del rango ya se usaron.
     */
    public function usados(): int
    {
        return $this->ultimo_numero === null ? 0 : $this->ultimo_numero - $this->numero_inicio + 1;
    }

    /**
     * Cuantos numeros del rango quedan por usar.
     */
    public function disponibles(): int
    {
        return $this->numero_fin - $this->numero_inicio + 1 - $this->usados();
    }

    public function estaAgotado(): bool
    {
        return $this->disponibles() <= 0;
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
