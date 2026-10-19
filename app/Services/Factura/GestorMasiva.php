<?php

namespace App\Services\Factura;

use App\Jobs\EnviarPaqueteMasivo;
use App\Models\Factura;
use App\Models\LoteMasivo;
use App\Models\Paquete;
use App\Models\PuntoVenta;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Abre y cierra los lotes de emision masiva de un punto de venta.
 *
 * Con el lote abierto, EmisorFactura emite con tipo de emision 3 y deja las
 * facturas en MASIVA. Al cerrarlo se arman paquetes de hasta 500 y cada uno
 * viaja en segundo plano (EnviarPaqueteMasivo): el cierre responde al toque
 * aunque el lote tenga miles de facturas.
 *
 * Requiere que el SIN haya autorizado la emision masiva para el NIT; sin esa
 * autorizacion rechaza los paquetes.
 */
class GestorMasiva
{
    /**
     * El lote abierto del punto de venta, o uno nuevo. Idempotente: abrir dos
     * veces devuelve el mismo lote.
     */
    public function abrir(PuntoVenta $puntoVenta): LoteMasivo
    {
        return DB::transaction(function () use ($puntoVenta) {
            // El mismo bloqueo que la emision: no se abren dos lotes a la vez.
            PuntoVenta::whereKey($puntoVenta->id)->lockForUpdate()->first();

            return $this->abierto($puntoVenta) ?? LoteMasivo::create([
                'empresa_id' => $puntoVenta->sucursal->empresa_id,
                'punto_venta_id' => $puntoVenta->id,
                'estado' => LoteMasivo::ESTADO_ABIERTO,
                'abierto_en' => now(),
            ]);
        });
    }

    public function abierto(PuntoVenta $puntoVenta): ?LoteMasivo
    {
        return LoteMasivo::where('punto_venta_id', $puntoVenta->id)
            ->where('estado', LoteMasivo::ESTADO_ABIERTO)
            ->latest('id')
            ->first();
    }

    /**
     * Cierra el lote abierto: arma los paquetes con sus facturas y los encola.
     * Las facturas que se emitan despues ya salen en linea.
     *
     * @throws RuntimeException si no hay lote abierto.
     */
    public function cerrar(PuntoVenta $puntoVenta): LoteMasivo
    {
        $lote = DB::transaction(function () use ($puntoVenta) {
            // Bloquea el punto de venta como la emision: ninguna factura entra
            // al lote mientras se congela su contenido.
            PuntoVenta::whereKey($puntoVenta->id)->lockForUpdate()->first();

            $lote = $this->abierto($puntoVenta);

            if ($lote === null) {
                throw new RuntimeException('El punto de venta no tiene un lote de emision masiva abierto.');
            }

            $lote->update(['estado' => LoteMasivo::ESTADO_CERRADO, 'cerrado_en' => now()]);

            $ids = Factura::where('lote_masivo_id', $lote->id)
                ->where('estado', Factura::ESTADO_MASIVA)
                ->whereNull('paquete_id')
                ->orderBy('numero_factura')
                ->pluck('id');

            foreach ($ids->chunk(LoteMasivo::FACTURAS_POR_PAQUETE) as $grupo) {
                $paquete = Paquete::create([
                    'empresa_id' => $lote->empresa_id,
                    'punto_venta_id' => $lote->punto_venta_id,
                    'tipo' => Paquete::TIPO_MASIVA,
                    'lote_masivo_id' => $lote->id,
                    'cantidad_facturas' => $grupo->count(),
                    'estado' => 'PENDIENTE',
                ]);

                Factura::whereIn('id', $grupo)->update(['paquete_id' => $paquete->id]);

                EnviarPaqueteMasivo::dispatch($paquete->id)->afterCommit();
            }

            return $lote;
        });

        return $lote->load('paquetes');
    }
}
