<?php

namespace App\Models;

use Database\Factories\CatalogoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Catalogo parametrico GLOBAL del SIN (unidades de medida, metodos de pago,
 * motivos de anulacion, etc.). Todos los tipos comparten esta tabla y se
 * distinguen por la columna "tipo".
 */
class Catalogo extends Model
{
    /** @use HasFactory<CatalogoFactory> */
    use HasFactory;

    protected $table = 'catalogos';

    protected $guarded = ['id'];

    /**
     * Filtra por un tipo de catalogo. Ej: Catalogo::deTipo('unidades_medida').
     */
    public function scopeDeTipo(Builder $query, string $tipo): Builder
    {
        return $query->where('tipo', $tipo);
    }
}
