<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una llamada de un sistema de ventas a la API REST. Como LogSiat, solo se
 * agrega y nunca se actualiza: maneja created_at y nada mas.
 */
class LogApi extends Model
{
    protected $table = 'logs_api';

    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
