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
        // La descripcion dice si el paso necesita datos cargados a mano: los del
        // 11 al 16 dependen de la especificacion que el SIN genera por cliente.
        $pasos = [
            [1, 'Verificar comunicacion', 'verificarComunicacion', null],
            [2, 'Verificar el NIT del contribuyente', 'verificarNit', 'Necesita CUIS vigente (paso 4).'],
            [3, 'Sincronizar fecha y hora del SIN', 'fechaHora', null],
            [4, 'Solicitar CUIS', 'cuis', 'Guarda el codigo devuelto; los pasos siguientes lo usan.'],
            [5, 'Solicitar CUFD', 'cufd', 'Guarda codigo y codigo de control, insumo del CUF.'],
            [6, 'Sincronizar catalogos parametricos globales', 'sincronizarGlobales', null],
            [7, 'Sincronizar actividades economicas del NIT', 'listaActividades', null],
            [8, 'Sincronizar productos-servicios homologados', 'listaProductos', null],
            [9, 'Sincronizar leyendas de factura', 'listaLeyendas', null],
            [10, 'Registrar punto de venta', 'registroPuntoVenta', null],
            [11, 'Emitir factura contado - efectivo', 'recepcionFactura',
                'Cargar en el payload la venta que pide la especificacion del SIN.'],
            [12, 'Emitir factura con descuento', 'recepcionFacturaDescuento',
                'Cargar en el payload la venta con descuento que pide la especificacion.'],
            [13, 'Emitir factura a NIT de empresa', 'recepcionFacturaNit',
                'Cargar en el payload la venta con comprador NIT que pide la especificacion.'],
            [14, 'Anular una factura', 'anulacionFactura',
                'Cargar el codigo de motivo del catalogo del SIN: {"motivo": N}.'],
            [15, 'Registrar evento significativo', 'registroEvento',
                'Cargar los datos del evento (codigo de evento del catalogo del SIN).'],
            [16, 'Emitir en contingencia y enviar paquete', 'recepcionPaquete',
                'Deriva la ultima factura a contingencia y encola el paquete.'],
            [17, 'Marcar el cliente como PILOTO_APROBADO', 'marcarAprobado',
                'Solo verifica que los 16 anteriores esten en EXITOSO; el estado se cambia a mano.'],
        ];

        foreach ($pasos as [$orden, $nombre, $tipo, $descripcion]) {
            // payload_ejemplo queda fuera del update a proposito: reseedear no
            // debe borrar los datos que el operador ya cargo para su cliente.
            CasoPrueba::updateOrCreate(
                ['fase' => CasoPrueba::FASE_PILOTO, 'orden' => $orden],
                [
                    'nombre' => $nombre,
                    'tipo' => $tipo,
                    'descripcion' => $descripcion,
                    'obligatorio' => true,
                ],
            );
        }
    }
}
