<?php

namespace App\Services\Siat;

use App\Models\SiatCuis;
use SoapFault;

/**
 * Servicio SOAP para el endpoint FacturacionOperaciones del SIN.
 * Responsabilidades: gestión de puntos de venta y eventos significativos.
 */
class OperacionesService extends SiatBaseService
{
    private const WSDL = 'FacturacionOperaciones?wsdl';

    /**
     * Registra un punto de venta en el SIN.
     * Usa el CUIS del PV 0 de la sucursal (PV administrativo).
     * Devuelve el codigoPuntoVenta asignado por el SIN.
     *
     * @return mixed  Objeto de respuesta o SoapFault
     */
    public function registroPuntoVenta(
        int $codigoSucursal,
        string $cuis,         // CUIS del PV 0 de la sucursal
        string $nombre = 'Caja',
        string $descripcion = 'Caja de Cobranza',
        int $codigoTipoPuntoVenta = 5
    ): mixed {
        try {
            $cliente = $this->buildClient(self::WSDL);

            return $cliente->registroPuntoVenta([
                'SolicitudRegistroPuntoVenta' => [
                    'codigoAmbiente'       => config('siat.codigo_ambiente'),
                    'codigoModalidad'      => config('siat.codigo_modalidad'),
                    'codigoSistema'        => config('siat.codigo_sistema'),
                    'codigoSucursal'       => $codigoSucursal,
                    'codigoTipoPuntoVenta' => $codigoTipoPuntoVenta,
                    'cuis'                 => $cuis,
                    'descripcion'          => $descripcion,
                    'nit'                  => (int) config('siat.nit'),
                    'nombrePuntoVenta'     => $nombre,
                ],
            ]);
        } catch (SoapFault $e) {
            return $e;
        }
    }

    /**
     * Cierra un punto de venta en el SIN.
     * También usa el CUIS del PV 0.
     *
     * @return mixed
     */
    public function cierrePuntoVenta(
        int $codigoSucursal,
        int $codigoPuntoVenta,
        string $cuis
    ): mixed {
        try {
            $cliente = $this->buildClient(self::WSDL);

            return $cliente->cierrePuntoVenta([
                'SolicitudCierrePuntoVenta' => [
                    'codigoAmbiente'   => config('siat.codigo_ambiente'),
                    'codigoPuntoVenta' => $codigoPuntoVenta,
                    'codigoSistema'    => config('siat.codigo_sistema'),
                    'codigoSucursal'   => $codigoSucursal,
                    'cuis'             => $cuis,
                    'nit'              => (int) config('siat.nit'),
                ],
            ]);
        } catch (SoapFault $e) {
            return $e;
        }
    }

    /**
     * Consulta los puntos de venta registrados para una sucursal.
     *
     * @return mixed
     */
    public function consultaPuntoVenta(int $codigoSucursal, string $cuis): mixed
    {
        try {
            $cliente = $this->buildClient(self::WSDL);

            return $cliente->consultaPuntoVenta([
                'SolicitudConsultaPuntoVenta' => [
                    'codigoAmbiente' => config('siat.codigo_ambiente'),
                    'codigoSistema'  => config('siat.codigo_sistema'),
                    'codigoSucursal' => $codigoSucursal,
                    'cuis'           => $cuis,
                    'nit'            => (int) config('siat.nit'),
                ],
            ]);
        } catch (SoapFault $e) {
            return $e;
        }
    }

    /**
     * Registra un evento significativo en el SIN antes de enviar un paquete de contingencia.
     *
     * El evento indica el motivo y período de la contingencia. El SIN devuelve un
     * codigoRecepcionEventoSignificativo que se usa como codigoEvento en el paquete.
     *
     * @param  object $evento  Objeto con codigoClasificador, descripcion, fechaHoraInicioEvento, fechaHoraFinEvento
     * @param  string $cufd    CUFD vigente al momento del registro del evento
     * @param  string $cufdEvento CUFD que se usó durante la contingencia (puede diferir del vigente)
     * @return mixed
     */
    public function registroEventoSignificativo(
        object $evento,
        string $cuis,
        string $cufd,
        string $cufdEvento,
        int $codigoSucursal,
        int $codigoPuntoVenta
    ): mixed {
        try {
            $cliente = $this->buildClient(self::WSDL);

            return $cliente->registroEventoSignificativo([
                'SolicitudEventoSignificativo' => [
                    'codigoAmbiente'         => config('siat.codigo_ambiente'),
                    'codigoMotivoEvento'      => $evento->codigoClasificador,
                    'codigoPuntoVenta'        => $codigoPuntoVenta,
                    'codigoSistema'           => config('siat.codigo_sistema'),
                    'codigoSucursal'          => $codigoSucursal,
                    'cufd'                    => $cufd,
                    'cufdEvento'              => $cufdEvento,
                    'cuis'                    => $cuis,
                    'descripcion'             => $evento->descripcion,
                    'fechaHoraFinEvento'      => $evento->fechaHoraFinEvento,
                    'fechaHoraInicioEvento'   => $evento->fechaHoraInicioEvento,
                    'nit'                     => (int) config('siat.nit'),
                ],
            ]);
        } catch (SoapFault $e) {
            return $e;
        }
    }

    /**
     * Consulta los eventos significativos registrados en una fecha.
     * Útil para recuperar el codigoEvento cuando el registro ya fue hecho en un intento previo.
     *
     * @return mixed
     */
    public function consultaEventoSignificativo(
        string $fechaEvento,  // formato Y-m-d
        string $cuis,
        string $cufd,
        int $codigoSucursal,
        int $codigoPuntoVenta
    ): mixed {
        try {
            $cliente = $this->buildClient(self::WSDL);

            return $cliente->consultaEventoSignificativo([
                'SolicitudConsultaEvento' => [
                    'codigoAmbiente'   => config('siat.codigo_ambiente'),
                    'codigoPuntoVenta' => $codigoPuntoVenta,
                    'codigoSistema'    => config('siat.codigo_sistema'),
                    'codigoSucursal'   => $codigoSucursal,
                    'cufd'             => $cufd,
                    'cuis'             => $cuis,
                    'fechaEvento'      => $fechaEvento,
                    'nit'              => (int) config('siat.nit'),
                ],
            ]);
        } catch (SoapFault $e) {
            return $e;
        }
    }
}
