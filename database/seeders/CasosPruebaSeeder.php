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
            // 'etapa' => null: sin el, el paso 1 viejo y la prueba 1 de una
            // etapa se pisarian entre si (comparten fase y orden).
            CasoPrueba::updateOrCreate(
                ['fase' => CasoPrueba::FASE_PILOTO, 'etapa' => null, 'orden' => $orden],
                [
                    'nombre' => $nombre,
                    'tipo' => $tipo,
                    'descripcion' => $descripcion,
                    'obligatorio' => true,
                ],
            );
        }

        $this->etapas();
    }

    /**
     * Pruebas por etapa, copiadas del portal del SIN (Seguimiento de
     * Autorizacion de Sistemas > Listado de Pruebas).
     *
     * Aca el payload SI se pisa al reseedear: no lo carga el operador, son los
     * parametros que fija el portal para cada prueba.
     */
    private function etapas(): void
    {
        // [etapa, orden, nombre, tipo, parametros del portal, pruebas esperadas]
        $pruebas = [
            // Etapa I: un CUIS para cada punto de venta de la casa matriz.
            [1, 1, 'CUIS sucursal 0 / punto de venta 1', 'solicitudCuis',
                ['codigoSucursal' => 0, 'codigoPuntoVenta' => 1], 1],
            [1, 2, 'CUIS sucursal 0 / punto de venta 0', 'solicitudCuis',
                ['codigoSucursal' => 0, 'codigoPuntoVenta' => 0], 1],
        ];

        // Etapa II: cada catalogo 50 veces, primero con el PV 1 y despues con el
        // PV 0, en el mismo orden del portal (impares PV 1, pares PV 0).
        // [operacion del WSDL, resultado esperado que muestra el portal]
        $catalogos = [
            ['sincronizarActividades', 'LISTADO TOTAL DE ACTIVIDADES'],
            ['sincronizarFechaHora', 'FECHA Y HORA ACTUAL'],
            ['sincronizarListaActividadesDocumentoSector', 'LISTADO TOTAL DE ACTIVIDADES DOCUMENTO SECTOR'],
            ['sincronizarListaLeyendasFactura', 'LISTADO TOTAL DE LEYENDAS DE FACTURAS'],
            ['sincronizarListaMensajesServicios', 'LISTADO TOTAL DE MENSAJES DE SERVICIOS'],
            ['sincronizarListaProductosServicios', 'LISTADO TOTAL DE PRODUCTOS Y SERVICIOS'],
            ['sincronizarParametricaEventosSignificativos', 'LISTADO TOTAL DE EVENTOS SIGNIFICATIVOS'],
            ['sincronizarParametricaMotivoAnulacion', 'LISTADO TOTAL DE MOTIVO DE ANULACION'],
            ['sincronizarParametricaPaisOrigen', 'LISTADO TOTAL DE PAISES'],
            ['sincronizarParametricaTipoDocumentoIdentidad', 'LISTADO TOTAL DE TIPOS DE DOCUMENTO DE IDENTIDAD'],
            ['sincronizarParametricaTipoDocumentoSector', 'LISTADO TOTAL DE TIPOS DE DOCUMENTO SECTOR'],
            ['sincronizarParametricaTipoEmision', 'LISTADO TOTAL DE TIPO EMISION'],
            ['sincronizarParametricaTipoHabitacion', 'LISTADO TOTAL DE TIPO HABITACION'],
            ['sincronizarParametricaTipoMetodoPago', 'LISTADO TOTAL DE METODO DE PAGO'],
            ['sincronizarParametricaTipoMoneda', 'LISTADO TOTAL DE TIPOS DE MONEDA'],
            ['sincronizarParametricaTipoPuntoVenta', 'LISTADO TOTAL DE TIPOS DE PUNTO DE VENTA'],
            ['sincronizarParametricaTiposFactura', 'LISTADO TOTAL DE TIPOS DE FACTURA'],
            ['sincronizarParametricaUnidadMedida', 'LISTADO TOTAL DE UNIDAD DE MEDIDA'],
        ];

        $orden = 1;

        foreach ($catalogos as [$operacion, $resultado]) {
            foreach ([1, 0] as $puntoVenta) {
                $pruebas[] = [2, $orden++, "{$resultado} (PV {$puntoVenta})", 'sincronizacionCatalogo', [
                    'codigoSucursal' => 0,
                    'codigoPuntoVenta' => $puntoVenta,
                    'operacion' => $operacion,
                    'resultadoEsperado' => $resultado,
                ], 50];
            }
        }

        // Etapa III: 100 CUFD por punto de venta, con su CUIS vigente.
        $pruebas[] = [3, 1, 'CUFD sucursal 0 / punto de venta 1', 'solicitudCufd',
            ['codigoSucursal' => 0, 'codigoPuntoVenta' => 1], 100];
        $pruebas[] = [3, 2, 'CUFD sucursal 0 / punto de venta 0', 'solicitudCufd',
            ['codigoSucursal' => 0, 'codigoPuntoVenta' => 0], 100];

        // Etapa IV: 125 facturas de compra-venta en linea por punto de venta
        // (codigoEmision 1, tipoFacturaDocumento 1, documento sector 1).
        $pruebas[] = [4, 1, 'Factura en linea sucursal 0 / punto de venta 1', 'emisionIndividual',
            ['codigoSucursal' => 0, 'codigoPuntoVenta' => 1, 'codigoEmision' => 1, 'tipoFacturaDocumento' => 1, 'codigoDocumentoSector' => 1], 125];
        $pruebas[] = [4, 2, 'Factura en linea sucursal 0 / punto de venta 0', 'emisionIndividual',
            ['codigoSucursal' => 0, 'codigoPuntoVenta' => 0, 'codigoEmision' => 1, 'tipoFacturaDocumento' => 1, 'codigoDocumentoSector' => 1], 125];

        // Etapa V: los 7 motivos de evento significativo, 5 veces por punto de
        // venta (impares PV 1, pares PV 0). Descripciones tal cual el portal.
        $motivos = [
            1 => 'CORTE DEL SERVICIO DE INTERNET',
            2 => 'INACCESIBILIDAD AL SERVICIO WEB DE LA ADMINISTRACION TRIBUTARIA',
            3 => 'INGRESO A ZONAS SIN INTERNET POR DESPLIEGUE DE PUNTO DE VENTA',
            4 => 'VENTA EN LUGARES SIN INTERNET',
            5 => 'VIRUS INFORMATICO O FALLA DE SOFTWARE',
            6 => 'CAMBIO DE INFRAESTRUCTURA DE SISTEMA O FALLA DE HARDWARE',
            7 => 'CORTE DE SUMINISTRO DE ENERGIA ELECTRICA',
        ];

        $orden = 1;

        foreach ($motivos as $motivo => $descripcion) {
            foreach ([1, 0] as $puntoVenta) {
                $pruebas[] = [5, $orden++, "Evento {$motivo}: {$descripcion} (PV {$puntoVenta})", 'eventoSignificativo', [
                    'codigoSucursal' => 0,
                    'codigoPuntoVenta' => $puntoVenta,
                    'codigoMotivoEvento' => $motivo,
                    'descripcion' => $descripcion,
                ], 5];
            }
        }

        // Etapa VI: por cada motivo, 10 paquetes en el PV 1 con 500 facturas
        // ("igual a 500") y 10 en el PV 0 con menos ("menor a 500": se usan 10
        // para no firmar de mas). Al final, la validacion de los 70 paquetes de
        // cada punto de venta.
        $orden = 1;

        foreach ($motivos as $motivo => $descripcion) {
            foreach ([1 => 500, 0 => 10] as $puntoVenta => $cantidad) {
                $pruebas[] = [6, $orden++, "Paquete motivo {$motivo}: {$cantidad} facturas (PV {$puntoVenta})", 'paqueteContingencia', [
                    'codigoSucursal' => 0,
                    'codigoPuntoVenta' => $puntoVenta,
                    'codigoMotivoEvento' => $motivo,
                    'descripcion' => $descripcion,
                    'codigoEmision' => 2,
                    'cantidadFacturas' => $cantidad,
                ], 10];
            }
        }

        foreach ([1, 0] as $puntoVenta) {
            $pruebas[] = [6, $orden++, "Validar paquetes enviados (PV {$puntoVenta})", 'validacionPaquete', [
                'codigoSucursal' => 0,
                'codigoPuntoVenta' => $puntoVenta,
                'codigoEmision' => 2,
            ], 70];
        }

        // Etapa VII: anular 125 facturas por punto de venta, con el motivo
        // FACTURA MAL EMITIDA. Se anulan las que valido la etapa IV.
        foreach ([1 => 1, 0 => 2] as $puntoVenta => $ordenAnulacion) {
            $pruebas[] = [7, $ordenAnulacion, "Anular factura (PV {$puntoVenta})", 'anulacionEtapa', [
                'codigoSucursal' => 0,
                'codigoPuntoVenta' => $puntoVenta,
                'codigoEmision' => 1,
                'codigoMotivo' => 'FACTURA MAL EMITIDA',
            ], 125];
        }

        // Etapa VIII: 115 facturas en linea firmadas por punto de venta. Es el
        // mismo flujo que la etapa IV (lo que se evalua es que la firma del XML
        // sea valida), asi que reusa el tipo emisionIndividual.
        $pruebas[] = [8, 1, 'Factura firmada sucursal 0 / punto de venta 1', 'emisionIndividual',
            ['codigoSucursal' => 0, 'codigoPuntoVenta' => 1, 'codigoEmision' => 1, 'codigoModalidad' => 1, 'tipoFacturaDocumento' => 1, 'codigoDocumentoSector' => 1], 115];
        $pruebas[] = [8, 2, 'Factura firmada sucursal 0 / punto de venta 0', 'emisionIndividual',
            ['codigoSucursal' => 0, 'codigoPuntoVenta' => 0, 'codigoEmision' => 1, 'codigoModalidad' => 1, 'tipoFacturaDocumento' => 1, 'codigoDocumentoSector' => 1], 115];

        // Etapa XI: revertir 125 anulaciones por punto de venta. Revierte las
        // facturas que anulo la etapa VII.
        foreach ([1 => 1, 0 => 2] as $puntoVenta => $ordenReversion) {
            $pruebas[] = [11, $ordenReversion, "Revertir anulacion (PV {$puntoVenta})", 'reversionAnulacion', [
                'codigoSucursal' => 0,
                'codigoPuntoVenta' => $puntoVenta,
                'codigoEmision' => 1,
            ], 125];
        }

        foreach ($pruebas as [$etapa, $orden, $nombre, $tipo, $parametros, $esperadas]) {
            CasoPrueba::updateOrCreate(
                ['fase' => CasoPrueba::FASE_PILOTO, 'etapa' => $etapa, 'orden' => $orden],
                [
                    'nombre' => $nombre,
                    'tipo' => $tipo,
                    'payload_ejemplo' => $parametros,
                    'pruebas_esperadas' => $esperadas,
                    'obligatorio' => true,
                ],
            );
        }
    }
}
