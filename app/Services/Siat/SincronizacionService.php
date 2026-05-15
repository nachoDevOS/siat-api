<?php

namespace App\Services\Siat;

use SoapFault;

/**
 * Servicio SOAP para el endpoint FacturacionSincronizacion del SIN.
 * Sincroniza todas las tablas de catálogos/paramétricas locales desde el SIN.
 * Estas tablas se usan en la generación del XML de facturas.
 */
class SincronizacionService extends SiatBaseService
{
    private const WSDL = 'FacturacionSincronizacion?wsdl';

    /**
     * Construye el payload estándar de sincronización.
     */
    private function solicitud(int $codigoSucursal, int $codigoPuntoVenta, string $cuis): array
    {
        return [
            'SolicitudSincronizacion' => [
                'codigoAmbiente'   => config('siat.codigo_ambiente'),
                'codigoPuntoVenta' => $codigoPuntoVenta,
                'codigoSistema'    => config('siat.codigo_sistema'),
                'codigoSucursal'   => $codigoSucursal,
                'cuis'             => $cuis,
                'nit'              => (int) config('siat.nit'),
            ],
        ];
    }

    /** Actividades económicas (codigoCaeb) → siat_actividades */
    public function actividades(int $codigoSucursal, int $codigoPuntoVenta, string $cuis): mixed
    {
        try {
            return $this->buildClient(self::WSDL)
                ->sincronizarActividades($this->solicitud($codigoSucursal, $codigoPuntoVenta, $cuis));
        } catch (SoapFault $e) {
            return $e;
        }
    }

    /** Leyendas de factura → siat_leyendas */
    public function leyendas(int $codigoSucursal, int $codigoPuntoVenta, string $cuis): mixed
    {
        try {
            return $this->buildClient(self::WSDL)
                ->sincronizarListaLeyendasFactura($this->solicitud($codigoSucursal, $codigoPuntoVenta, $cuis));
        } catch (SoapFault $e) {
            return $e;
        }
    }

    /** Motivos de anulación → siat_motivos_anulacion */
    public function motivosAnulacion(int $codigoSucursal, int $codigoPuntoVenta, string $cuis): mixed
    {
        try {
            return $this->buildClient(self::WSDL)
                ->sincronizarParametricaMotivoAnulacion($this->solicitud($codigoSucursal, $codigoPuntoVenta, $cuis));
        } catch (SoapFault $e) {
            return $e;
        }
    }

    /** Eventos significativos → siat_eventos_significativos */
    public function eventosSignificativos(int $codigoSucursal, int $codigoPuntoVenta, string $cuis): mixed
    {
        try {
            return $this->buildClient(self::WSDL)
                ->sincronizarParametricaEventosSignificativos($this->solicitud($codigoSucursal, $codigoPuntoVenta, $cuis));
        } catch (SoapFault $e) {
            return $e;
        }
    }

    /** Tipos de documento de identidad → siat_tipo_documentos */
    public function tiposDocumento(int $codigoSucursal, int $codigoPuntoVenta, string $cuis): mixed
    {
        try {
            return $this->buildClient(self::WSDL)
                ->sincronizarParametricaTipoDocumentoIdentidad($this->solicitud($codigoSucursal, $codigoPuntoVenta, $cuis));
        } catch (SoapFault $e) {
            return $e;
        }
    }

    /** Métodos de pago → siat_metodo_pagos */
    public function metodosPago(int $codigoSucursal, int $codigoPuntoVenta, string $cuis): mixed
    {
        try {
            return $this->buildClient(self::WSDL)
                ->sincronizarParametricaTipoMetodoPago($this->solicitud($codigoSucursal, $codigoPuntoVenta, $cuis));
        } catch (SoapFault $e) {
            return $e;
        }
    }

    /** Unidades de medida → siat_unidad_medidas */
    public function unidadesMedida(int $codigoSucursal, int $codigoPuntoVenta, string $cuis): mixed
    {
        try {
            return $this->buildClient(self::WSDL)
                ->sincronizarParametricaUnidadMedida($this->solicitud($codigoSucursal, $codigoPuntoVenta, $cuis));
        } catch (SoapFault $e) {
            return $e;
        }
    }

    /** Productos y servicios SIN → siat_productos */
    public function productosServicios(int $codigoSucursal, int $codigoPuntoVenta, string $cuis): mixed
    {
        try {
            return $this->buildClient(self::WSDL)
                ->sincronizarListaProductosServicios($this->solicitud($codigoSucursal, $codigoPuntoVenta, $cuis));
        } catch (SoapFault $e) {
            return $e;
        }
    }

    /** Tipos de punto de venta → siat_tipo_punto_ventas */
    public function tiposPuntoVenta(int $codigoSucursal, int $codigoPuntoVenta, string $cuis): mixed
    {
        try {
            return $this->buildClient(self::WSDL)
                ->sincronizarParametricaTipoPuntoVenta($this->solicitud($codigoSucursal, $codigoPuntoVenta, $cuis));
        } catch (SoapFault $e) {
            return $e;
        }
    }

    /** Tipos de moneda → siat_tipo_monedas */
    public function tiposMoneda(int $codigoSucursal, int $codigoPuntoVenta, string $cuis): mixed
    {
        try {
            return $this->buildClient(self::WSDL)
                ->sincronizarParametricaTipoMoneda($this->solicitud($codigoSucursal, $codigoPuntoVenta, $cuis));
        } catch (SoapFault $e) {
            return $e;
        }
    }
}
