<?php

namespace App\Services\Factura;

use App\Models\Empresa;
use App\Models\LeyendaFactura;
use App\Models\ProductoServicio;

/**
 * Resuelve, desde los catalogos sincronizados del NIT, los dos datos que el XSD
 * del SIN exige y que la venta que manda el cliente no trae:
 *
 *   - la <actividadEconomica> de cada detalle, deducida del codigo de producto
 *     homologado (tabla productos_servicios);
 *   - la <leyenda> de la cabecera, que depende de esa actividad.
 *
 * Antes los dos viajaban siempre con xsi:nil y el SIN habria rechazado toda
 * factura. Se resuelven en la emision (no al armar el XML) para que queden
 * guardados en la factura: es un documento congelado, y si el SIN reasigna
 * manana un producto a otra actividad, lo ya emitido no puede cambiar.
 */
class ResolutorActividad
{
    /**
     * Actividad economica a la que el SIN homologo ese producto.
     *
     * @return string|null null si la empresa todavia no sincronizo catalogos.
     */
    public function actividadDeProducto(Empresa $empresa, int|string $codigoProductoSin): ?string
    {
        return ProductoServicio::where('empresa_id', $empresa->id)
            ->where('codigo_producto', (string) $codigoProductoSin)
            ->value('codigo_actividad');
    }

    /**
     * Leyenda obligatoria para esa actividad.
     *
     * El SIN devuelve varias por actividad y espera que se vayan alternando
     * entre facturas, no que se repita siempre la misma; por eso se elige una
     * al azar del conjunto en vez de tomar la primera.
     */
    public function leyendaDeActividad(Empresa $empresa, ?string $codigoActividad): ?string
    {
        if (blank($codigoActividad)) {
            return null;
        }

        return LeyendaFactura::where('empresa_id', $empresa->id)
            ->where('codigo_actividad', $codigoActividad)
            ->inRandomOrder()
            ->value('descripcion_leyenda');
    }

    /**
     * Si la empresa ya sincronizo su catalogo de productos homologados.
     *
     * Se pregunta antes de exigir que un producto exista: mientras el catalogo
     * este vacio (cliente recien dado de alta) no se puede bloquear la emision,
     * pero una vez sincronizado, un producto ausente es un error real que el
     * SIN rechazaria.
     */
    public function tieneCatalogoDeProductos(Empresa $empresa): bool
    {
        return ProductoServicio::where('empresa_id', $empresa->id)->exists();
    }
}
