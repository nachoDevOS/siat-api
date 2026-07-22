<?php

namespace App\Services\Contingencia;

use App\Models\EventoSignificativo;
use App\Models\Factura;
use App\Models\Paquete;
use App\Models\PuntoVenta;

/**
 * Gestiona el paso a contingencia y la recuperacion cuando el SIAT se cae.
 *
 * Regla: la venta nunca se bloquea. Si el envio falla, la factura ya tiene CUF
 * y es valida; solo queda pendiente de transmitir dentro de un paquete.
 */
class GestorContingencia
{
    /**
     * Deriva una factura a contingencia: la marca y garantiza que exista un
     * evento significativo abierto para su punto de venta.
     */
    public function derivar(Factura $factura): EventoSignificativo
    {
        $evento = $this->eventoAbierto($factura->puntoVenta);

        // La factura queda valida pero pendiente de transmision.
        $factura->update([
            'estado' => Factura::ESTADO_CONTINGENCIA,
            'tipo_emision' => Factura::EMISION_CONTINGENCIA,
        ]);

        return $evento;
    }

    /**
     * Cierra el evento cuando el SIAT vuelve y arma el paquete con las facturas
     * de contingencia acumuladas, listo para enviar.
     */
    public function recuperar(EventoSignificativo $evento): ?Paquete
    {
        $facturas = Factura::where('punto_venta_id', $evento->punto_venta_id)
            ->where('estado', Factura::ESTADO_CONTINGENCIA)
            ->get();

        if ($facturas->isEmpty()) {
            $evento->update(['estado' => 'CERRADO', 'fecha_fin' => now()]);

            return null;
        }

        $evento->update(['estado' => 'CERRADO', 'fecha_fin' => now()]);

        return Paquete::create([
            'empresa_id' => $evento->empresa_id,
            'punto_venta_id' => $evento->punto_venta_id,
            'evento_id' => $evento->id,
            'cantidad_facturas' => $facturas->count(),
            'estado' => 'PENDIENTE',
        ]);
    }

    /**
     * Devuelve el evento significativo abierto del punto de venta, creando uno
     * nuevo si no habia (primer fallo de la racha de contingencia).
     */
    private function eventoAbierto(PuntoVenta $puntoVenta): EventoSignificativo
    {
        $existente = EventoSignificativo::where('punto_venta_id', $puntoVenta->id)
            ->where('estado', 'ABIERTO')
            ->latest('fecha_inicio')
            ->first();

        if ($existente !== null) {
            return $existente;
        }

        return EventoSignificativo::create([
            'empresa_id' => $puntoVenta->sucursal->empresa_id,
            'punto_venta_id' => $puntoVenta->id,
            // Codigo de evento por caida del sistema; verificar catalogo del SIN.
            'codigo_evento' => 1,
            'descripcion' => 'Corte de conexion con el SIAT',
            'cufd_evento' => optional($puntoVenta->cufdVigente())->codigo,
            'fecha_inicio' => now(),
            'estado' => 'ABIERTO',
        ]);
    }
}
