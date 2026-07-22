<?php

namespace App\Services\Factura;

/**
 * Valida los datos de una factura ANTES de armar el XML y enviarla.
 * Atrapa errores obvios en local para no gastar un viaje al SIAT (que es lento)
 * en algo que ya sabemos que va a ser observado.
 *
 * Trabaja sobre el arreglo normalizado de la venta, no sobre el modelo, para
 * poder usarse apenas llega la peticion.
 */
class ValidadorFactura
{
    /**
     * Devuelve la lista de errores encontrados. Vacia = la factura esta lista.
     *
     * @param  array<string, mixed>  $datos
     * @return list<string>
     */
    public function validar(array $datos): array
    {
        $errores = [];

        // Debe haber al menos un item.
        $items = $datos['items'] ?? [];

        if (! is_array($items) || count($items) === 0) {
            $errores[] = 'La factura debe tener al menos un item.';
        }

        foreach ($items as $i => $item) {
            $linea = $i + 1;

            if (($item['cantidad'] ?? 0) <= 0) {
                $errores[] = "Item {$linea}: la cantidad debe ser mayor a cero.";
            }

            if (($item['precio_unitario'] ?? 0) < 0) {
                $errores[] = "Item {$linea}: el precio unitario no puede ser negativo.";
            }

            if (blank($item['descripcion'] ?? null)) {
                $errores[] = "Item {$linea}: falta la descripcion.";
            }
        }

        // El comprador es obligatorio.
        $comprador = $datos['comprador'] ?? [];

        if (blank($comprador['razon_social'] ?? null)) {
            $errores[] = 'Falta la razon social del comprador.';
        }

        if (blank($comprador['numero_documento'] ?? null)) {
            $errores[] = 'Falta el numero de documento del comprador.';
        }

        return $errores;
    }

    /**
     * Atajo booleano cuando solo interesa saber si es valida.
     *
     * @param  array<string, mixed>  $datos
     */
    public function esValida(array $datos): bool
    {
        return count($this->validar($datos)) === 0;
    }
}
