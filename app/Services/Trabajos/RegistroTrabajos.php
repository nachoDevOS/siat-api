<?php

namespace App\Services\Trabajos;

use App\Models\CasoPrueba;
use App\Models\Factura;
use App\Models\Paquete;
use App\Models\PuntoVenta;
use App\Models\Trabajo;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobRetryRequested;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lleva el historial de la cola en la tabla trabajos, escuchando sus eventos
 * (se registra en AppServiceProvider). Los jobs no saben que existe: no hubo
 * que tocar ninguno.
 *
 * Todo va en try/catch: registrar el historial nunca puede romper un job ni
 * el envio de una factura.
 */
class RegistroTrabajos
{
    /** Clave del latido del worker y del scheduler en cache. */
    public const LATIDO_WORKER = 'siat:latido:worker';

    public const LATIDO_SCHEDULER = 'siat:latido:scheduler';

    /**
     * Propiedad del job => modelo que afecta. El primero que tenga el job
     * define el "relacionado" y, de ahi, la empresa.
     *
     * @var array<string, array{0: string, 1: class-string}>
     */
    private const RELACIONES = [
        'facturaId' => ['factura', Factura::class],
        'paqueteId' => ['paquete', Paquete::class],
        'puntoVentaId' => ['punto_venta', PuntoVenta::class],
        'casoId' => ['caso_prueba', CasoPrueba::class],
    ];

    private ?int $ultimoLatido = null;

    public function encolado(JobQueued $evento): void
    {
        $this->seguro(function () use ($evento) {
            $payload = $evento->payload();
            $comando = is_object($evento->job) ? $evento->job : null;

            Trabajo::updateOrCreate(['uuid' => $payload['uuid']], [
                'clase' => $comando ? $comando::class : (string) ($payload['displayName'] ?? 'desconocido'),
                'cola' => $evento->queue ?: ($comando->queue ?? 'default'),
                'estado' => Trabajo::EN_COLA,
                'encolado_en' => now(),
                ...$this->relacionado($comando),
            ]);
        });
    }

    public function procesando(JobProcessing $evento): void
    {
        $this->latidoWorker();

        $this->seguro(fn () => $this->fila($evento->job)->fill([
            'estado' => Trabajo::PROCESANDO,
            'intentos' => $evento->job->attempts(),
            'iniciado_en' => now(),
            'terminado_en' => null,
        ])->save());
    }

    public function procesado(JobProcessed $evento): void
    {
        $this->latidoWorker();

        $this->seguro(function () use ($evento) {
            // Los jobs de este sistema se reencolan solos (release) cuando el
            // SIN no responde: eso es "reintentando", no exito.
            if ($evento->job->hasFailed()) {
                return; // Lo registra fallido().
            }

            $fila = $this->fila($evento->job);
            $fila->fill([
                'estado' => $evento->job->isReleased() ? Trabajo::REINTENTANDO : Trabajo::EXITOSO,
                'intentos' => $evento->job->attempts(),
                'terminado_en' => now(),
                'duracion_ms' => $this->duracion($fila),
            ])->save();
        });
    }

    public function excepcion(JobExceptionOccurred $evento): void
    {
        $this->seguro(function () use ($evento) {
            if ($evento->job->hasFailed()) {
                return;
            }

            $fila = $this->fila($evento->job);
            $fila->fill([
                'estado' => Trabajo::REINTENTANDO,
                'intentos' => $evento->job->attempts(),
                'error' => $this->mensaje($evento->exception),
                'terminado_en' => now(),
                'duracion_ms' => $this->duracion($fila),
            ])->save();
        });
    }

    public function fallido(JobFailed $evento): void
    {
        $this->seguro(function () use ($evento) {
            $fila = $this->fila($evento->job);
            $fila->fill([
                'estado' => Trabajo::FALLIDO,
                'intentos' => $evento->job->attempts(),
                'error' => $this->mensaje($evento->exception),
                'terminado_en' => now(),
                'duracion_ms' => $this->duracion($fila),
            ])->save();
        });
    }

    /**
     * "Reintentar" desde el panel o con queue:retry: vuelve a la cola.
     */
    public function reintentoPedido(JobRetryRequested $evento): void
    {
        $this->seguro(fn () => Trabajo::where('uuid', $evento->payload()['uuid'] ?? null)->update([
            'estado' => Trabajo::EN_COLA,
            'encolado_en' => now(),
            'terminado_en' => null,
        ]));
    }

    /**
     * El worker esta vivo. Se llama en cada vuelta de su bucle (incluso sin
     * trabajo), pero solo se escribe en cache cada 15 segundos.
     */
    public function latidoWorker(): void
    {
        $ahora = time();

        if ($this->ultimoLatido !== null && $ahora - $this->ultimoLatido < 15) {
            return;
        }

        $this->ultimoLatido = $ahora;
        $this->seguro(fn () => Cache::put(self::LATIDO_WORKER, $ahora, now()->addDay()));
    }

    /**
     * @return array{worker: ?Carbon, scheduler: ?Carbon}
     */
    public static function latidos(): array
    {
        $leer = fn (string $clave): ?Carbon => ($t = Cache::get($clave)) ? Carbon::createFromTimestamp($t, config('app.timezone')) : null;

        return ['worker' => $leer(self::LATIDO_WORKER), 'scheduler' => $leer(self::LATIDO_SCHEDULER)];
    }

    /**
     * Semaforo de los dos procesos que tienen que estar siempre corriendo.
     *
     * El worker late en cada vuelta de su bucle (aunque no haya trabajo) y el
     * scheduler cada minuto. Si el latido es viejo, el proceso esta apagado o
     * colgado; si ademas hay trabajos esperando, se dice cuantos.
     *
     * @return array{worker: array{activo: bool, texto: string}, scheduler: array{activo: bool, texto: string}}
     */
    public static function estadoProcesos(): array
    {
        $latidos = self::latidos();
        $esperando = DB::table('jobs')
            ->whereNull('reserved_at')
            ->where('available_at', '<=', now()->subMinutes(2)->getTimestamp())
            ->count();

        $worker = $latidos['worker'] !== null && $latidos['worker']->greaterThan(now()->subSeconds(60));
        $scheduler = $latidos['scheduler'] !== null && $latidos['scheduler']->greaterThan(now()->subSeconds(150));

        $hace = fn (?Carbon $t): string => $t ? 'último latido '.$t->diffForHumans() : 'nunca latió';

        return [
            'worker' => [
                'activo' => $worker,
                'texto' => $worker
                    ? 'Worker activo ('.$hace($latidos['worker']).')'
                    : 'Worker APAGADO ('.$hace($latidos['worker']).')'
                        .($esperando > 0 ? ": hay {$esperando} trabajo(s) esperando hace más de 2 min" : '')
                        .'. Levántelo con: php artisan queue:work --queue=facturas,default,piloto',
            ],
            'scheduler' => [
                'activo' => $scheduler,
                'texto' => $scheduler
                    ? 'Scheduler activo ('.$hace($latidos['scheduler']).')'
                    : 'Scheduler APAGADO ('.$hace($latidos['scheduler']).'): no se renuevan códigos ni se reintenta nada. Levántelo con: php artisan schedule:work',
            ],
        ];
    }

    /**
     * La fila del job; si no se vio encolarse (encolado antes de existir el
     * historial, o desde otra conexion) se crea en ese momento.
     */
    private function fila(Job $job): Trabajo
    {
        $payload = $job->payload();
        $comando = $this->comando($payload);

        return Trabajo::firstOrNew(['uuid' => $job->uuid()], [
            'clase' => $comando ? $comando::class : $job->resolveName(),
            'cola' => $job->getQueue() ?: 'default',
            'encolado_en' => now(),
            ...$this->relacionado($comando),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function comando(array $payload): ?object
    {
        try {
            $comando = unserialize((string) data_get($payload, 'data.command'), ['allowed_classes' => true]);

            return is_object($comando) ? $comando : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{empresa_id?: ?int, relacionado_tipo?: string, relacionado_id?: int}
     */
    private function relacionado(?object $comando): array
    {
        if ($comando === null) {
            return [];
        }

        foreach (self::RELACIONES as $propiedad => [$tipo, $modelo]) {
            if (isset($comando->{$propiedad})) {
                $id = (int) $comando->{$propiedad};

                return [
                    'relacionado_tipo' => $tipo,
                    'relacionado_id' => $id,
                    'empresa_id' => $comando->empresaId ?? $this->empresaDe($modelo, $id),
                ];
            }
        }

        return isset($comando->empresaId) ? ['empresa_id' => (int) $comando->empresaId] : [];
    }

    /**
     * @param  class-string  $modelo
     */
    private function empresaDe(string $modelo, int $id): ?int
    {
        return match ($modelo) {
            PuntoVenta::class => PuntoVenta::with('sucursal')->find($id)?->sucursal?->empresa_id,
            CasoPrueba::class => null,
            default => $modelo::whereKey($id)->value('empresa_id'),
        };
    }

    private function duracion(Trabajo $fila): ?int
    {
        return $fila->iniciado_en ? (int) $fila->iniciado_en->diffInMilliseconds(now()) : null;
    }

    private function mensaje(?Throwable $e): ?string
    {
        if ($e === null) {
            return null;
        }

        return mb_substr(class_basename($e).': '.$e->getMessage(), 0, 2000);
    }

    private function seguro(callable $accion): void
    {
        try {
            $accion();
        } catch (Throwable $e) {
            Log::warning('No se pudo registrar el historial de un trabajo.', ['error' => $e->getMessage()]);
        }
    }
}
