<?php

namespace App\Services\Contingencia;

use App\Models\EventoSignificativo;
use App\Models\Factura;
use App\Models\Paquete;
use App\Models\PuntoVenta;
use Illuminate\Support\Facades\DB;

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

        // Solo cambia el ESTADO. El tipo de emision se queda como esta, aunque
        // la factura termine viajando en un paquete de contingencia.
        //
        // El motivo: tipo_emision es uno de los nueve campos que entran al CUF,
        // y el CUF ya se calculo, ya se le devolvio al cliente y probablemente
        // ya se imprimio. Cambiarlo aca dejaba una factura que declaraba
        // 'codigoEmision = 2' con un CUF que codifica 1: el SIN revalida el CUF
        // contra los campos y rechazaba el paquete entero.
        //
        // Y es fiel a lo que paso: esta factura SI se emitio en linea; lo que
        // fallo fue transmitirla. Las que se emitan de aca en adelante, con el
        // evento ya abierto, nacen con tipo_emision = 2 y un CUF que lo refleja
        // (ver EmisorFactura).
        $factura->update(['estado' => Factura::ESTADO_CONTINGENCIA]);

        return $evento;
    }

    /**
     * Cierra el evento cuando el SIAT vuelve y arma el paquete con las facturas
     * de contingencia acumuladas, listo para enviar.
     */
    public function recuperar(EventoSignificativo $evento): ?Paquete
    {
        return DB::transaction(function () use ($evento) {
            // Se congela el conjunto: las facturas sin paquete asignado que
            // existen AHORA. Una que entre a contingencia despues de este punto
            // quedara para el paquete siguiente y no se dara por enviada.
            $ids = Factura::where('punto_venta_id', $evento->punto_venta_id)
                ->where('estado', Factura::ESTADO_CONTINGENCIA)
                ->whereNull('paquete_id')
                ->lockForUpdate()
                ->pluck('id');

            $evento->update(['estado' => 'CERRADO', 'fecha_fin' => now()]);

            if ($ids->isEmpty()) {
                return null;
            }

            $paquete = Paquete::create([
                'empresa_id' => $evento->empresa_id,
                'punto_venta_id' => $evento->punto_venta_id,
                'evento_id' => $evento->id,
                'cantidad_facturas' => $ids->count(),
                'estado' => 'PENDIENTE',
            ]);

            Factura::whereIn('id', $ids)->update(['paquete_id' => $paquete->id]);

            return $paquete;
        });
    }

    /**
     * Si el punto de venta esta operando bajo un evento de contingencia abierto.
     *
     * La emision lo pregunta ANTES de calcular el CUF: mientras el evento este
     * abierto, cada factura nueva nace fuera de linea y su CUF tiene que
     * codificarlo. Preguntarlo despues no sirve, porque el CUF ya estaria hecho.
     */
    public function hayContingenciaAbierta(PuntoVenta $puntoVenta): bool
    {
        return EventoSignificativo::where('punto_venta_id', $puntoVenta->id)
            ->where('estado', 'ABIERTO')
            ->exists();
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
