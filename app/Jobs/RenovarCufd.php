<?php

namespace App\Jobs;

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

        // Se guarda el nuevo CUFD; nunca se sobreescribe el anterior.
        Cufd::create([
            'punto_venta_id' => $puntoVenta->id,
            'codigo' => (string) data_get($respuesta, 'RespuestaCufd.codigo'),
            'codigo_control' => (string) data_get($respuesta, 'RespuestaCufd.codigoControl'),
            'direccion' => (string) data_get($respuesta, 'RespuestaCufd.direccion'),
            // El CUFD dura 24 horas desde su emision.
            'fecha_vigencia' => now()->addDay(),
        ]);
    }
}
