<?php

namespace App\Services\Factura;

use App\Exceptions\CufdVencidoException;
use App\Exceptions\FacturaInvalidaException;
use App\Jobs\EnviarFacturaAlSiat;
use App\Models\Cufd;
use App\Models\Factura;
use App\Models\FacturaItem;
use App\Models\PuntoVenta;
use App\Services\Contingencia\GestorContingencia;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Emite una nota de credito-debito (sector 24): la devolucion total o parcial
 * de una factura de compra-venta ya validada.
 *
 * El sistema de ventas solo dice QUE se devuelve (linea de la factura y
 * cantidad); precios, descuentos, montos y el 13% de credito fiscal salen de
 * la factura original, para que la nota no pueda contradecirla.
 *
 * Despues de firmar, la nota sigue el mismo camino que una factura: queda
 * PENDIENTE y EnviarFacturaAlSiat la transmite al servicio de documentos de
 * ajuste del SIN.
 */
class EmisorNotaCreditoDebito
{
    public function __construct(
        private readonly EmisorFactura $emisorFactura,
        private readonly GeneradorCuf $generadorCuf,
        private readonly ConstructorXml $constructorXml,
        private readonly FirmadorXml $firmadorXml,
        private readonly GestorContingencia $contingencia,
    ) {}

    /**
     * Emite la nota sobre la factura de origen.
     *
     * @param  array{
     *     items: list<array{linea: int, cantidad: int|float|string}>,
     *     referencia_externa?: string|null,
     *     usuario?: string|null
     * }  $devolucion  'linea' es la posicion (desde 1) del item en la factura.
     *
     * @throws FacturaInvalidaException si la devolucion no es posible.
     * @throws CufdVencidoException si el punto de venta no tiene CUFD vigente.
     */
    public function emitir(Factura $origen, array $devolucion): Factura
    {
        $referencia = $devolucion['referencia_externa'] ?? null;

        // Idempotencia, igual que la factura: la misma referencia devuelve la
        // nota ya emitida en vez de devolver la plata dos veces.
        $existente = $this->buscarExistente($origen, $referencia);

        if ($existente !== null) {
            return $existente;
        }

        $origen->loadMissing(['empresa', 'puntoVenta.sucursal', 'items']);

        $this->validarOrigen($origen);

        $puntoVenta = $origen->puntoVenta;

        // El servicio de documentos de ajuste del SIN no recibe paquetes: una
        // nota no puede emitirse fuera de linea y viajar despues, como una
        // factura. Se corta antes de reservar numero.
        if ($this->contingencia->hayContingenciaAbierta($puntoVenta)) {
            throw new FacturaInvalidaException([
                'El punto de venta esta en contingencia (el SIAT no responde) y las notas de credito-debito solo se emiten en linea. Intente cuando el SIAT vuelva.',
            ]);
        }

        $cufd = $this->emisorFactura->obtenerCufd($puntoVenta);

        try {
            return $this->emitirEnTransaccion($origen, $puntoVenta, $cufd, $devolucion);
        } catch (UniqueConstraintViolationException $e) {
            // Dos peticiones con la misma referencia entraron a la vez.
            $ganadora = $this->buscarExistente($origen, $referencia);

            if ($ganadora !== null) {
                return $ganadora;
            }

            throw $e;
        }
    }

    /**
     * Solo se ajusta una factura de compra-venta que el SIN ya valido.
     *
     * @throws FacturaInvalidaException
     */
    private function validarOrigen(Factura $origen): void
    {
        if ($origen->esNotaCreditoDebito()) {
            throw new FacturaInvalidaException(['Una nota de credito-debito no se puede ajustar con otra nota.']);
        }

        if ($origen->estado !== Factura::ESTADO_VALIDADA) {
            throw new FacturaInvalidaException([
                "Solo se emite una nota sobre una factura VALIDADA por el SIN; esta esta {$origen->estado}.",
            ]);
        }

        // Con gift card, la base del credito fiscal de la factura no es su
        // total, y la regla con la que el SIN prorratea esa parte en la nota
        // no esta verificada. Mejor rechazar que emitir un credito fiscal
        // que el SIN observe.
        if ((float) $origen->gift_card > 0) {
            throw new FacturaInvalidaException([
                'Todavia no se emiten notas sobre facturas pagadas en parte con gift card.',
            ]);
        }

        if (! $origen->puntoVenta->activo || ! $origen->puntoVenta->existeEnElSiat()) {
            throw new FacturaInvalidaException([
                'El punto de venta de la factura ya no esta activo en el SIAT: no se puede emitir la nota contra el.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $devolucion
     */
    private function emitirEnTransaccion(Factura $origen, PuntoVenta $puntoVenta, Cufd $cufd, array $devolucion): Factura
    {
        return DB::transaction(function () use ($origen, $puntoVenta, $cufd, $devolucion) {
            // Bloquea la factura de origen: dos notas a la vez sobre la misma
            // factura podrian pasar cada una el control de cantidades y, entre
            // las dos, devolver mas de lo que se vendio.
            Factura::whereKey($origen->id)->lockForUpdate()->first();

            $devueltos = $this->lineasDevueltas($origen, $devolucion['items']);
            $montos = $this->montos($origen, $devueltos);

            $numero = $this->reservarNumero($puntoVenta);
            // Sin milisegundos, por la misma razon que la factura: el CUF y el
            // XML tienen que llevar exactamente la misma fecha.
            $fecha = now()->startOfSecond();

            $cuf = $this->generadorCuf->generar([
                'nit' => $origen->empresa->nit,
                'fecha' => $fecha->format('YmdHisv'),
                'sucursal' => $puntoVenta->sucursal->codigo_sucursal,
                'modalidad' => $origen->empresa->codigo_modalidad,
                // Siempre en linea: el servicio de ajuste no recibe paquetes.
                'tipo_emision' => Factura::EMISION_EN_LINEA,
                'tipo_factura' => config('siat.codigos.nota_credito_debito.tipo_factura_documento'),
                'tipo_documento_sector' => config('siat.codigos.nota_credito_debito.documento_sector'),
                'numero_factura' => $numero,
                'punto_venta' => $puntoVenta->codigo_punto_venta,
            ], $cufd->codigo_control);

            $nota = $this->crearNota($origen, $puntoVenta, $cufd, $devolucion, $devueltos, $montos, $numero, $cuf, $fecha);

            $xml = $this->constructorXml->construir($nota);
            $xml = $this->firmadorXml->firmar($xml, $this->emisorFactura->certificadoFirmable($origen->empresa));

            $nota->update(['xml_firmado' => $xml]);

            EnviarFacturaAlSiat::dispatch($nota->id)->afterCommit();

            return $nota;
        });
    }

    /**
     * Resuelve cada linea pedida contra los items de la factura y controla
     * que no se devuelva mas de lo vendido, contando las notas anteriores que
     * no esten anuladas.
     *
     * @param  list<array{linea: int, cantidad: int|float|string}>  $pedidas
     * @return list<array{item: FacturaItem, cantidad: float}> una entrada por linea.
     *
     * @throws FacturaInvalidaException
     */
    private function lineasDevueltas(Factura $origen, array $pedidas): array
    {
        $items = $origen->items->values();
        $yaDevuelto = $this->cantidadesYaDevueltas($origen);

        // Si la misma linea viene dos veces se suman: es una sola devolucion.
        $porLinea = [];
        foreach ($pedidas as $pedida) {
            $linea = (int) $pedida['linea'];
            $porLinea[$linea] = ($porLinea[$linea] ?? 0) + (float) $pedida['cantidad'];
        }

        $errores = [];
        $devueltos = [];

        foreach ($porLinea as $linea => $cantidad) {
            $item = $items->get($linea - 1);

            if ($item === null) {
                $errores[] = "La factura no tiene linea {$linea}: tiene {$items->count()}.";

                continue;
            }

            $disponible = round((float) $item->cantidad - ($yaDevuelto[$item->id] ?? 0), 4);

            if (round($cantidad, 4) > $disponible) {
                $errores[] = "Linea {$linea}: se piden devolver {$cantidad} y quedan {$disponible} por devolver.";

                continue;
            }

            $devueltos[] = ['item' => $item, 'cantidad' => $cantidad];
        }

        if ($errores !== []) {
            throw new FacturaInvalidaException($errores);
        }

        return $devueltos;
    }

    /**
     * Cantidad ya devuelta de cada item de la factura, en notas vigentes.
     *
     * @return array<int, float> item_origen_id => cantidad.
     */
    private function cantidadesYaDevueltas(Factura $origen): array
    {
        return FacturaItem::query()
            ->where('codigo_detalle_transaccion', config('siat.codigos.nota_credito_debito.detalle_devuelto'))
            ->whereHas('factura', fn ($q) => $q
                ->where('factura_origen_id', $origen->id)
                ->where('estado', '!=', Factura::ESTADO_ANULADA))
            ->groupBy('item_origen_id')
            ->selectRaw('item_origen_id, SUM(cantidad) as devuelta')
            ->pluck('devuelta', 'item_origen_id')
            ->map(fn ($cantidad) => (float) $cantidad)
            ->all();
    }

    /**
     * Montos de la nota, todos derivados de la factura (precios NETOS, XSD V3):
     *   - cada linea devuelta, a su precio original y con la parte
     *     proporcional de su descuento;
     *   - devuelto (subTotalNetoDevuelto): la suma de esas lineas;
     *   - descuento (montoDescuentoDevuelto): el descuento adicional de la
     *     factura, en la proporcion devuelta;
     *   - total (totalPrecioNetoDevuelto): devuelto - descuento;
     *   - efectivo (ivaDevuelto): el 13% de total, el credito fiscal que se
     *     revierte. total + efectivo es el montoaPagarDevuelto.
     *
     * Cuadra con el XML de ejemplo del SIN: 50 de descuento sobre 723.02,
     * devolviendo 63.80 -> 4.41 de descuento, 59.39 neto, 7.72 de IVA.
     *
     * @param  list<array{item: FacturaItem, cantidad: float}>  $devueltos
     * @return array{lineas: list<array{descuento: float, subtotal: float}>, devuelto: float, descuento: float, total: float, efectivo: float}
     */
    private function montos(Factura $origen, array $devueltos): array
    {
        $lineas = [];

        foreach ($devueltos as ['item' => $item, 'cantidad' => $cantidad]) {
            $proporcion = $cantidad / (float) $item->cantidad;
            $descuento = round((float) $item->descuento * $proporcion, 2);

            $lineas[] = [
                'descuento' => $descuento,
                'subtotal' => round($cantidad * (float) $item->precio_unitario - $descuento, 2),
            ];
        }

        $devuelto = round(array_sum(array_column($lineas, 'subtotal')), 2);

        // El descuento adicional se aplica sobre la suma de las lineas (ya
        // con sus descuentos), no sobre facturas.subtotal, que es antes de
        // ellos: esa es la base del prorrateo.
        $baseDescuento = (float) $origen->items->sum(fn (FacturaItem $item) => (float) $item->subtotal);

        $descuento = $baseDescuento > 0
            ? round((float) $origen->descuento_global * $devuelto / $baseDescuento, 2)
            : 0.0;

        $total = round($devuelto - $descuento, 2);

        return [
            'lineas' => $lineas,
            'devuelto' => $devuelto,
            'descuento' => $descuento,
            'total' => $total,
            'efectivo' => round($total * config('siat.codigos.nota_credito_debito.alicuota_iva'), 2),
        ];
    }

    /**
     * Las notas llevan su propio correlativo por punto de venta, aparte del
     * de facturas, y se reserva igual: con bloqueo de fila.
     */
    private function reservarNumero(PuntoVenta $puntoVenta): int
    {
        $bloqueado = PuntoVenta::whereKey($puntoVenta->id)->lockForUpdate()->first();
        $numero = (int) $bloqueado->siguiente_nota;

        $bloqueado->update(['siguiente_nota' => $numero + 1]);

        return $numero;
    }

    /**
     * Crea la nota con los datos del comprador de la factura y sus dos listas
     * de items: TODOS los de la factura original (1) y los devueltos (2).
     *
     * @param  array<string, mixed>  $devolucion
     * @param  list<array{item: FacturaItem, cantidad: float}>  $devueltos
     * @param  array<string, mixed>  $montos
     */
    private function crearNota(
        Factura $origen,
        PuntoVenta $puntoVenta,
        Cufd $cufd,
        array $devolucion,
        array $devueltos,
        array $montos,
        int $numero,
        string $cuf,
        Carbon $fecha,
    ): Factura {
        $nota = Factura::create([
            'empresa_id' => $origen->empresa_id,
            'punto_venta_id' => $puntoVenta->id,
            'cufd_id' => $cufd->id,
            'factura_origen_id' => $origen->id,
            'cuf' => $cuf,
            // En una nota es el numeroNotaCreditoDebito, de su propio correlativo.
            'numero_factura' => $numero,
            'fecha_emision' => $fecha,
            'comprador_tipo_documento' => $origen->comprador_tipo_documento,
            'comprador_numero_documento' => $origen->comprador_numero_documento,
            'comprador_complemento' => $origen->comprador_complemento,
            'comprador_razon_social' => $origen->comprador_razon_social,
            'comprador_email' => $origen->comprador_email,
            'metodo_pago' => $origen->metodo_pago,
            'codigo_excepcion' => $origen->codigo_excepcion,
            'moneda' => $origen->moneda,
            'tipo_cambio' => $origen->tipo_cambio,
            // Lo DEVUELTO; lo original se lee de la factura (ver la migracion
            // agregar_iva_transparente_a_facturas).
            'subtotal' => $montos['devuelto'],
            'descuento_global' => $montos['descuento'],
            'gift_card' => 0,
            'total_precio_neto' => $montos['total'],
            'iva' => $montos['efectivo'],
            'monto_total' => round($montos['total'] + $montos['efectivo'], 2),
            'monto_total_moneda' => round($montos['total'] + $montos['efectivo'], 2),
            'monto_efectivo_credito_debito' => $montos['efectivo'],
            'leyenda' => $origen->leyenda,
            'usuario' => $devolucion['usuario'] ?? $origen->usuario,
            'codigo_documento_sector' => config('siat.codigos.nota_credito_debito.documento_sector'),
            'tipo_emision' => Factura::EMISION_EN_LINEA,
            'estado' => Factura::ESTADO_PENDIENTE,
            'referencia_externa' => $devolucion['referencia_externa'] ?? null,
        ]);

        foreach ($origen->items as $item) {
            $nota->items()->create($this->copiaDeItem($item, [
                'codigo_detalle_transaccion' => config('siat.codigos.nota_credito_debito.detalle_original'),
            ]));
        }

        foreach ($devueltos as $i => ['item' => $item, 'cantidad' => $cantidad]) {
            $nota->items()->create($this->copiaDeItem($item, [
                'cantidad' => $cantidad,
                'descuento' => $montos['lineas'][$i]['descuento'],
                'subtotal' => $montos['lineas'][$i]['subtotal'],
                'codigo_detalle_transaccion' => config('siat.codigos.nota_credito_debito.detalle_devuelto'),
            ]));
        }

        return $nota;
    }

    /**
     * Copia un item de la factura, con lo que cambia en la nota.
     *
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function copiaDeItem(FacturaItem $item, array $cambios): array
    {
        return array_merge([
            'codigo_producto_sin' => $item->codigo_producto_sin,
            'codigo_actividad' => $item->codigo_actividad,
            'codigo_interno' => $item->codigo_interno,
            'descripcion' => $item->descripcion,
            'cantidad' => $item->cantidad,
            'unidad_medida' => $item->unidad_medida,
            'precio_unitario' => $item->precio_unitario,
            'descuento' => $item->descuento,
            'subtotal' => $item->subtotal,
            'item_origen_id' => $item->id,
        ], $cambios);
    }

    /**
     * La nota ya emitida con esa referencia. Si la referencia la uso otro
     * documento (una venta, o una nota de otra factura) es un error del
     * cliente, no una repeticion: devolver ese documento lo confundiria.
     *
     * @throws FacturaInvalidaException
     */
    private function buscarExistente(Factura $origen, ?string $referencia): ?Factura
    {
        if (blank($referencia)) {
            return null;
        }

        $existente = Factura::where('empresa_id', $origen->empresa_id)
            ->where('referencia_externa', $referencia)
            ->first();

        if ($existente !== null && $existente->factura_origen_id !== $origen->id) {
            throw new FacturaInvalidaException([
                "La referencia {$referencia} ya la usa otro documento: cada nota necesita la suya.",
            ]);
        }

        return $existente;
    }
}
