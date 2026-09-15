<?php

namespace App\Jobs;

use App\Exceptions\SiatException;
use App\Models\Cufd;
use App\Models\PuntoVenta;
use App\Services\Siat\FabricaServicios;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Solicita un CUFD nuevo para un punto de venta y lo guarda como historial.
 * Lo usan tanto el cron preventivo (cada hora) como la capa reactiva al emitir.
 */
class RenovarCufd implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $puntoVentaId) {}

    public function handle(FabricaServicios $fabrica): void
    {
        $puntoVenta = PuntoVenta::with('sucursal.empresa')->find($this->puntoVentaId);

        if ($puntoVenta === null) {
            return;
        }

        $empresa = $puntoVenta->sucursal->empresa;
        $cuis = $puntoVenta->cuisVigente();

        // Sin CUIS vigente no se puede pedir CUFD; eso lo resuelve otro flujo.
        if ($cuis === null) {
            return;
        }

        $respuesta = $fabrica->codigos($empresa)->solicitarCufd($puntoVenta, $cuis->codigo);

        $codigo = (string) data_get($respuesta, 'RespuestaCufd.codigo');
        $codigoControl = (string) data_get($respuesta, 'RespuestaCufd.codigoControl');

        // Sin esta comprobacion, un rechazo del SIN (200 con transaccion=false)
        // guardaba un CUFD con codigo y codigo_control vacios, vigente 24 h.
        // cufdVigente() toma el ultimo por id, asi que ese CUFD vacio pasaba a
        // ser "el vigente" y envenenaba el CUF de toda factura siguiente. Peor:
        // este job corre cada hora, asi que lo repetia cada hora.
        if (blank($codigo) || blank($codigoControl)) {
            throw new SiatException(
                "El SIAT no devolvio un CUFD valido para el punto de venta {$puntoVenta->id}.",
            );
        }

        // Se guarda el nuevo CUFD; nunca se sobreescribe el anterior.
        Cufd::create([
            'punto_venta_id' => $puntoVenta->id,
            'codigo' => $codigo,
            'codigo_control' => $codigoControl,
            'direccion' => (string) data_get($respuesta, 'RespuestaCufd.direccion'),
            // El CUFD dura 24 horas desde su emision.
            'fecha_vigencia' => now()->addDay(),
        ]);
    }
}
