<?php

namespace App\Models;

use Database\Factories\WebhookEntregaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un aviso a un destino de webhook, con su resultado. El cuerpo es el exacto
 * que se firma y se envia, tambien en un reenvio.
 */
class WebhookEntrega extends Model
{
    /** @use HasFactory<WebhookEntregaFactory> */
    use HasFactory;

    public const ESTADO_PENDIENTE = 'PENDIENTE';

    public const ESTADO_ENTREGADA = 'ENTREGADA';

    public const ESTADO_FALLIDA = 'FALLIDA';

    protected $table = 'webhook_entregas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'entregada_en' => 'datetime',
            'intentos' => 'integer',
            'codigo_http' => 'integer',
        ];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
