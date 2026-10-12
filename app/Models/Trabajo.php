<?php

namespace App\Models;

use App\Jobs\AnularFacturaEnSiat;
use App\Jobs\EjecutarCasoPrueba;
use App\Jobs\EnviarFacturaAlSiat;
use App\Jobs\EnviarPaqueteContingencia;
use App\Jobs\NotificarWebhook;
use App\Jobs\RenovarCufd;
use App\Jobs\SincronizarCatalogosEmpresa;
use App\Jobs\VerificarEstadoFactura;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un job de la cola y su recorrido: en cola → procesando → exitoso,
 * reintentando o fallido. Lo registra RegistroTrabajos con los eventos de la
 * cola; nadie lo escribe a mano.
 */
class Trabajo extends Model
{
    public const EN_COLA = 'EN_COLA';

    public const PROCESANDO = 'PROCESANDO';

    public const EXITOSO = 'EXITOSO';

    public const REINTENTANDO = 'REINTENTANDO';

    public const FALLIDO = 'FALLIDO';

    /**
     * Estados en el orden de las pestanas, con su etiqueta y color.
     *
     * @var array<string, array{etiqueta: string, color: string}>
     */
    public const ESTADOS = [
        self::EN_COLA => ['etiqueta' => 'En cola', 'color' => 'gris'],
        self::PROCESANDO => ['etiqueta' => 'En proceso', 'color' => 'azul'],
        self::EXITOSO => ['etiqueta' => 'Exitoso', 'color' => 'verde'],
        self::REINTENTANDO => ['etiqueta' => 'Reintentando', 'color' => 'ambar'],
        self::FALLIDO => ['etiqueta' => 'Fallido', 'color' => 'rojo'],
    ];

    /**
     * Nombre entendible de cada job; los que no estan aca muestran su clase.
     *
     * @var array<class-string, string>
     */
    public const NOMBRES = [
        EnviarFacturaAlSiat::class => 'Envío de factura al SIN',
        VerificarEstadoFactura::class => 'Verificación de estado',
        AnularFacturaEnSiat::class => 'Anulación en el SIN',
        EnviarPaqueteContingencia::class => 'Envío de paquete de contingencia',
        NotificarWebhook::class => 'Aviso por webhook',
        RenovarCufd::class => 'Renovación de CUFD',
        SincronizarCatalogosEmpresa::class => 'Sincronización de catálogos',
        EjecutarCasoPrueba::class => 'Prueba del piloto',
    ];

    protected $table = 'trabajos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'encolado_en' => 'datetime',
            'iniciado_en' => 'datetime',
            'terminado_en' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function nombre(): string
    {
        return self::NOMBRES[$this->clase] ?? class_basename($this->clase);
    }

    /**
     * El momento que ordena la lista: cuando termino, empezo o se encolo.
     */
    public function fecha(): ?Carbon
    {
        return $this->terminado_en ?? $this->iniciado_en ?? $this->encolado_en ?? $this->created_at;
    }
}
