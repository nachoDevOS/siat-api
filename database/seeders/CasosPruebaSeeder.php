<?php

namespace Database\Seeders;

use App\Models\CasoPrueba;
use Illuminate\Database\Seeder;

/**
 * Carga los casos de prueba del piloto (seccion 12.2). Viven en base de datos
 * para poder editarlos cuando el SIN cambie el manual, sin tocar codigo.
 *
 * Los pasos 1 al 10 son estructurales y no cambian; los demas dependen de la
 * especificacion que genera el SIN al confirmar cada asociacion.
 */
class CasosPruebaSeeder extends Seeder
{
    public function run(): void
    {
        // Secuencia de la fase 3 (piloto por cliente). tipo = operacion a ejecutar.
        $pasos = [
            [1, 'Verificar comunicacion', 'verificarComunicacion'],
            [2, 'Verificar el NIT del contribuyente', 'verificarNit'],
            [3, 'Sincronizar fecha y hora del SIN', 'fechaHora'],
            [4, 'Solicitar CUIS', 'cuis'],
            [5, 'Solicitar CUFD', 'cufd'],
            [6, 'Sincronizar catalogos parametricos globales', 'sincronizarGlobales'],
            [7, 'Sincronizar actividades economicas del NIT', 'listaActividades'],
            [8, 'Sincronizar productos-servicios homologados', 'listaProductos'],
            [9, 'Sincronizar leyendas de factura', 'listaLeyendas'],
            [10, 'Registrar punto de venta', 'registroPuntoVenta'],
            [11, 'Emitir factura contado - efectivo', 'recepcionFactura'],
            [12, 'Emitir factura con descuento', 'recepcionFacturaDescuento'],
            [13, 'Emitir factura a NIT de empresa', 'recepcionFacturaNit'],
            [14, 'Anular una factura', 'anulacionFactura'],
            [15, 'Registrar evento significativo', 'registroEvento'],
            [16, 'Emitir en contingencia y enviar paquete', 'recepcionPaquete'],
            [17, 'Marcar el cliente como PILOTO_APROBADO', 'marcarAprobado'],
        ];

        foreach ($pasos as [$orden, $nombre, $tipo]) {
            CasoPrueba::updateOrCreate(
                ['fase' => CasoPrueba::FASE_PILOTO, 'orden' => $orden],
                [
                    'nombre' => $nombre,
                    'tipo' => $tipo,
                    'descripcion' => null,
                    'obligatorio' => true,
                ],
            );
        }
    }
}
