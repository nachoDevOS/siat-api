<?php

namespace App\Models;

use Database\Factories\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Un destino de webhook registrado por el cliente, suscrito a ciertos eventos
 * y con su propio secreto de firma.
 */
class WebhookEndpoint extends Model
{
    /** @use HasFactory<WebhookEndpointFactory> */
    use HasFactory;

    /** Destinos por empresa: suficiente para separar ERP, contabilidad, alertas... */
    public const MAXIMO_POR_EMPRESA = 10;

    protected $table = 'webhook_endpoints';

    protected $guarded = ['id'];

    protected $hidden = ['secreto'];

    protected function casts(): array
    {
        return [
            'eventos' => 'array',
            'secreto' => 'encrypted',
            'activo' => 'boolean',
        ];
    }

    /**
     * Secreto nuevo para firmar las entregas. Se muestra una sola vez.
     */
    public static function nuevoSecreto(): string
    {
        return 'whsec_'.Str::random(40);
    }

    /**
     * Si este destino quiere el evento: por '*', por grupo ('factura.*') o
     * por nombre exacto.
     */
    public function escucha(string $evento): bool
    {
        foreach ($this->eventos as $suscripcion) {
            if ($suscripcion === '*' || $suscripcion === $evento) {
                return true;
            }

            if (str_ends_with($suscripcion, '.*') && str_starts_with($evento, substr($suscripcion, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function entregas(): HasMany
    {
        return $this->hasMany(WebhookEntrega::class);
    }
}
