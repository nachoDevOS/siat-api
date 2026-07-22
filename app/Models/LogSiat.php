<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Auditoria de una peticion SOAP al SIAT. Un log no se actualiza nunca, solo
 * se agrega; por eso solo maneja created_at.
 */
class LogSiat extends Model
{
    protected $table = 'logs_siat';

    protected $guarded = ['id'];

    // El registro solo tiene created_at, no updated_at.
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'exitoso' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
