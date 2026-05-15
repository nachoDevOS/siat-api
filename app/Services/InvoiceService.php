<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoicePackage;
use App\Models\SiatActividad;
use App\Models\SiatCufd;
use App\Models\SiatCuis;
use App\Models\SiatEventoSignificativo;
use App\Models\SiatLeyenda;
use App\Services\Siat\CodigosService;
use App\Services\Siat\FacturacionService;
use App\Services\Siat\OperacionesService;
use Carbon\Carbon;
use DateTime;
use Illuminate\Support\Facades\DB;
use PharData;
use SimpleXMLElement;
use SoapFault;

/**
 * Servicio principal de facturación SIAT.
 *
 * Responsabilidades:
 * - Sincronizar CUIS/CUFD (renovar si vencieron).
 * - Generar el CUF (algoritmo mod11 + base16 + codigoControl del CUFD).
 * - Construir el XML según el schema del SIN.
 * - Emitir facturas en línea o guardar en contingencia.
 * - Reconciliar facturas 902 con el SIN antes de enviar paquetes.
 * - Construir y enviar paquetes de contingencia.
 * - Anular y revertir anulaciones.
 */
class InvoiceService
{
    public function __construct(
        private readonly CodigosService     $codigos,
        private readonly FacturacionService $facturacion,
        private readonly OperacionesService $operaciones,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // SECCIÓN 1: GESTIÓN DE CUIS/CUFD
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Retorna true si el registro no existe, ya venció, o su fechaVigencia pasó.
     * fechaVigencia null = vigente indefinidamente.
     */
    public function isExpired(?object $record): bool
    {
        if (!$record) {
            return true;
        }
        if (!$record->fechaVigencia) {
            return false;
        }

        return now()->gte(Carbon::parse($record->fechaVigencia));
    }

    /**
     * Obtiene el contexto SIAT vigente para una sucursal/PV:
     * CUIS, CUFD, codigoSucursal y codigoPuntoVenta.
     * Si alguno vence, lo renueva automáticamente antes de retornar.
     *
     * @throws \Exception si no hay conexión al SIN y los códigos están vencidos
     */
    public function getContext(int $codigoSucursal, int $codigoPuntoVenta): array
    {
        $cuisRecord = SiatCuis::active($codigoSucursal, $codigoPuntoVenta)->first();
        $cufdRecord = SiatCufd::active($codigoSucursal, $codigoPuntoVenta)->first();

        if ($this->isExpired($cuisRecord) || $this->isExpired($cufdRecord)) {
            $synced = $this->syncCodes(
                $codigoSucursal,
                $codigoPuntoVenta,
                $this->isExpired($cuisRecord) ? null : $cuisRecord,
                $this->isExpired($cufdRecord) ? null : $cufdRecord,
            );

            if (!$synced) {
                throw new \Exception('CUIS/CUFD vencidos y sin conexión con SIAT para renovarlos.');
            }

            // Re-fetch tras la renovación
            $cuisRecord = SiatCuis::active($codigoSucursal, $codigoPuntoVenta)->first();
            $cufdRecord = SiatCufd::active($codigoSucursal, $codigoPuntoVenta)->first();
        }

        if ($this->isExpired($cuisRecord)) {
            throw new \Exception('No existe CUIS vigente para la sucursal/PV indicados.');
        }
        if ($this->isExpired($cufdRecord)) {
            throw new \Exception('No existe CUFD vigente para la sucursal/PV indicados.');
        }

        return [
            'codigoSucursal'   => $codigoSucursal,
            'codigoPuntoVenta' => $codigoPuntoVenta,
            'cuis'             => $cuisRecord->codigo,
            'cufd'             => $cufdRecord,
        ];
    }

    /**
     * Sincroniza CUIS y/o CUFD con el SIN, solo renovando los que vencieron.
     *
     * Regla de integridad: primero obtiene los datos del SIN y luego escribe
     * en BD dentro de una transacción. Si falla cualquier paso, no deja datos
     * parciales en la BD.
     *
     * @return array|null  Array con resultado si hubo éxito, null si el SIN no responde
     */
    public function syncCodes(
        int $codigoSucursal,
        int $codigoPuntoVenta,
        ?SiatCuis $activeCuis = null,
        ?SiatCufd $activeCufd = null
    ): ?array {
        // 1. Verificar conectividad antes de intentar renovar
        $ping = $this->codigos->verificarComunicacion();
        if ($ping instanceof SoapFault) {
            return null;
        }

        $renovarCuis = $this->isExpired($activeCuis);
        $renovarCufd = $this->isExpired($activeCufd);
        $cuisData    = null;
        $cuisCode    = $activeCuis?->codigo;

        // 2. Obtener CUIS nuevo si es necesario
        if ($renovarCuis) {
            $res = $this->codigos->cuis($codigoSucursal, $codigoPuntoVenta);
            if ($res instanceof SoapFault || !isset($res->RespuestaCuis->codigo)) {
                return null;
            }
            $cuisData = $res->RespuestaCuis;
            $cuisCode = $cuisData->codigo;
        }

        if (!$cuisCode) {
            return null;
        }

        // 3. Obtener CUFD nuevo si es necesario (requiere CUIS válido)
        $cufdData = null;
        if ($renovarCufd) {
            $res = $this->codigos->cufd($codigoSucursal, $codigoPuntoVenta, $cuisCode);
            if ($res instanceof SoapFault || !isset($res->RespuestaCufd->codigo)) {
                return null;
            }
            $cufdData = $res->RespuestaCufd;
        }

        // 4. Escritura atómica — solo toca lo que realmente se renovó
        try {
            DB::transaction(function () use ($codigoSucursal, $codigoPuntoVenta, $renovarCuis, $renovarCufd, $cuisData, $cufdData) {
                if ($renovarCuis) {
                    SiatCuis::where('status', 1)
                        ->where('codigo_sucursal', $codigoSucursal)
                        ->where('codigo_punto_venta', $codigoPuntoVenta)
                        ->update(['status' => 0]);

                    SiatCuis::create([
                        'codigo_sucursal'   => $codigoSucursal,
                        'codigo_punto_venta' => $codigoPuntoVenta,
                        'codigo'            => $cuisData->codigo,
                        'fechaVigencia'     => $cuisData->fechaVigencia ?? null,
                        'transaccion'       => $cuisData->transaccion ?? false,
                        'status'            => 1,
                    ]);
                }

                if ($renovarCufd) {
                    SiatCufd::where('status', 1)
                        ->where('codigo_sucursal', $codigoSucursal)
                        ->where('codigo_punto_venta', $codigoPuntoVenta)
                        ->update(['status' => 0]);

                    SiatCufd::create([
                        'codigo_sucursal'   => $codigoSucursal,
                        'codigo_punto_venta' => $codigoPuntoVenta,
                        'codigo'            => $cufdData->codigo,
                        'codigoControl'     => $cufdData->codigoControl ?? '',
                        'direccion'         => $cufdData->direccion ?? null,
                        'fechaVigencia'     => $cufdData->fechaVigencia ?? null,
                        'transaccion'       => $cufdData->transaccion ?? false,
                        'status'            => 1,
                    ]);
                }
            });
        } catch (\Throwable) {
            return null;
        }

        return [
            'synced'      => $renovarCuis || $renovarCufd,
            'synced_cuis' => $renovarCuis,
            'synced_cufd' => $renovarCufd,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECCIÓN 2: ALGORITMO CUF
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Genera el CUF (Código Único de Factura) según el algoritmo oficial del SIN.
     *
     * Algoritmo:
     *   cadena = nitEmisor(13) + fechaEmision(YYYYMMDDHHmmssmmm)(17)
     *          + codigoSucursal(4) + codigoModalidad(1) + tipoEmision(1)
     *          + tipoFactura(1) + tipoDocumentoSector(2)
     *          + numeroFactura(10) + codigoPuntoVenta(4)
     *   modulo11 = calculaDigitoMod11(cadena)
     *   cadena   = cadena + modulo11
     *   hex      = base16(cadena)          ← aritmética big-integer
     *   CUF      = hex + cufd.codigoControl ← los últimos 8 chars del CUF final
     *
     * @param  DateTime $fecha          Fecha y hora de emisión
     * @param  int      $numeroFactura  Número correlativo de la factura
     * @param  int      $tipoEmision    1=En línea, 2=Fuera de línea
     * @param  string   $codigoControl  Campo codigoControl del CUFD (8 chars)
     */
    public function generarCuf(
        DateTime $fecha,
        int $numeroFactura,
        int $codigoSucursal,
        int $codigoPuntoVenta,
        int $tipoEmision,
        string $codigoControl
    ): string {
        $nitEmisor            = str_pad((string) config('siat.nit'), 13, '0', STR_PAD_LEFT);
        $fechaStr             = $fecha->format('YmdHisv'); // YYYYMMDDHHmmssmmm (17 chars)
        $sucursal             = str_pad((string) $codigoSucursal, 4, '0', STR_PAD_LEFT);
        $modalidad            = (string) config('siat.codigo_modalidad');
        $tipoFactura          = '1';
        $tipoDocumentoSector  = str_pad((string) config('siat.codigo_documento_sector'), 2, '0', STR_PAD_LEFT);
        $nroFacturaPadded     = str_pad((string) $numeroFactura, 10, '0', STR_PAD_LEFT);
        $pvPadded             = str_pad((string) $codigoPuntoVenta, 4, '0', STR_PAD_LEFT);

        $cadena = $nitEmisor
            . $fechaStr
            . $sucursal
            . $modalidad
            . $tipoEmision
            . $tipoFactura
            . $tipoDocumentoSector
            . $nroFacturaPadded
            . $pvPadded;

        $modulo11 = $this->obtenerModulo11($cadena);
        $cadena  .= $modulo11;

        $hex = $this->base16($cadena);

        return $hex . $codigoControl;
    }

    /**
     * Calcula el dígito verificador Módulo 11 de la cadena.
     * Parámetros fijos del SIN: numDig=1, limMult=9, x10=false.
     */
    private function obtenerModulo11(string $cadena): string
    {
        $suma = 0;
        $mult = 2;

        for ($i = strlen($cadena) - 1; $i >= 0; $i--) {
            $suma += $mult * (int) substr($cadena, $i, 1);
            if (++$mult > 9) {
                $mult = 2;
            }
        }

        $dig = $suma % 11;

        // Casos especiales del algoritmo oficial
        if ($dig === 10) return '1';
        if ($dig === 11) return '0';

        return (string) $dig;
    }

    /**
     * Convierte un número decimal (como string) a hexadecimal.
     * Usa bcmath para manejar números grandes (la cadena del CUF supera 64 bits).
     */
    private function base16(string $cadena): string
    {
        $hexvalues = ['0','1','2','3','4','5','6','7','8','9','A','B','C','D','E','F'];
        $hexval    = '';

        while ($cadena !== '0') {
            $hexval = $hexvalues[(int) bcmod($cadena, '16')] . $hexval;
            $cadena = bcdiv($cadena, '16', 0);
        }

        return $hexval;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECCIÓN 3: NÚMERO DE FACTURA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Obtiene el próximo número de factura.
     *
     * - Online: MAX(nroFactura) + 1 sobre invoices (incluye soft-deleted).
     * - Offline con CAFC: contador persistente en siat_cafc_contadores con lockForUpdate.
     *   El contador nunca retrocede aunque se borren facturas, lo que garantiza que el
     *   SIN no reciba el mismo número dos veces en distintas emisiones.
     */
    public function nextNroFactura(int $tipoEmision): int
    {
        if ($tipoEmision === 2 && config('siat.cafc')) {
            return $this->nextNroFacturaCafc();
        }

        $ultimo = Invoice::withTrashed()
            ->whereNotNull('nroFactura')
            ->orderByRaw('CAST(nroFactura AS UNSIGNED) DESC')
            ->lockForUpdate()
            ->value('nroFactura');

        return (int) $ultimo + 1;
    }

    private function nextNroFacturaCafc(): int
    {
        $cafc      = config('siat.cafc');
        $cafcInicio = (int) config('siat.cafc_inicio', 1);
        $cafcFin    = (int) config('siat.cafc_fin', 0);

        $contador = DB::table('siat_cafc_contadores')
            ->where('cafc', $cafc)
            ->lockForUpdate()
            ->first();

        if ($contador) {
            $numero = $contador->ultimo_numero + 1;
            DB::table('siat_cafc_contadores')
                ->where('cafc', $cafc)
                ->update(['ultimo_numero' => $numero, 'updated_at' => now()]);
        } else {
            $numero = $cafcInicio;
            DB::table('siat_cafc_contadores')->insert([
                'cafc'          => $cafc,
                'ultimo_numero' => $numero,
                'inicio'        => $cafcInicio,
                'fin'           => $cafcFin,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        // Si se supera el fin del rango, volver al inicio
        if ($cafcFin > 0 && $numero > $cafcFin) {
            $numero = $cafcInicio;
            DB::table('siat_cafc_contadores')
                ->where('cafc', $cafc)
                ->update(['ultimo_numero' => $numero, 'updated_at' => now()]);
        }

        return $numero;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECCIÓN 4: CONSTRUCCIÓN DEL XML
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Construye el XML de la factura según el schema facturaComputarizadaCompraVenta.xsd del SIN.
     *
     * @param  array    $cabecera   Datos de la cabecera (ver buildCabecera())
     * @param  array    $detalles   Array de ítems de venta (ver buildDetalle())
     * @param  string   $cuf        CUF generado previamente
     * @param  string   $cufd       Código CUFD del día
     * @param  string   $direccion  URL de verificación (viene del CUFD)
     * @param  int      $tipoEmision
     * @return string   XML como string
     */
    public function buildXml(
        array  $cabecera,
        array  $detalles,
        string $cuf,
        string $cufd,
        string $direccion,
        int    $tipoEmision,
        int    $codigoSucursal,
        int    $codigoPuntoVenta,
        int    $numeroFactura
    ): string {
        $leyenda = SiatLeyenda::inRandomOrder()->value('descripcionLeyenda') ?? '';
        $actividad = SiatActividad::inRandomOrder()->value('codigoCaeb') ?? '';

        $facturaArray = [
            [
                'cabecera' => [
                    'nitEmisor'                    => config('siat.nit'),
                    'razonSocialEmisor'            => config('siat.razon_social'),
                    'municipio'                    => config('siat.municipio'),
                    'telefono'                     => config('siat.telefono'),
                    'numeroFactura'                => $numeroFactura,
                    'cuf'                          => $cuf,
                    'cufd'                         => $cufd,
                    'codigoSucursal'               => $codigoSucursal,
                    'direccion'                    => $direccion,
                    'codigoPuntoVenta'             => $codigoPuntoVenta,
                    'fechaEmision'                 => $cabecera['fechaEmision'],
                    'nombreRazonSocial'            => $cabecera['nombreRazonSocial'] ?? null,
                    'codigoTipoDocumentoIdentidad' => $cabecera['codigoTipoDocumentoIdentidad'] ?? 1,
                    'numeroDocumento'              => $cabecera['numeroDocumento'],
                    'complemento'                  => $cabecera['complemento'] ?? null,
                    'codigoCliente'                => $cabecera['codigoCliente'] ?? $cabecera['numeroDocumento'],
                    'codigoMetodoPago'             => $cabecera['codigoMetodoPago'] ?? 1,
                    'numeroTarjeta'                => $cabecera['numeroTarjeta'] ?? null,
                    'montoTotal'                   => $cabecera['montoTotal'],
                    'montoTotalSujetoIva'          => $cabecera['montoTotalSujetoIva'] ?? $cabecera['montoTotal'],
                    'codigoMoneda'                 => $cabecera['codigoMoneda'] ?? 1,
                    'tipoCambio'                   => $cabecera['tipoCambio'] ?? 1,
                    'montoTotalMoneda'             => $cabecera['montoTotalMoneda'] ?? $cabecera['montoTotal'],
                    'montoGiftCard'                => 0,
                    'descuentoAdicional'           => $cabecera['descuentoAdicional'] ?? 0,
                    'codigoExcepcion'              => $cabecera['codigoExcepcion'] ?? 0,
                    // cafc va nulo en emisión online; en contingencia va el valor del .env
                    'cafc'    => $tipoEmision === 1 ? null : config('siat.cafc'),
                    'leyenda' => $leyenda,
                    'usuario' => $cabecera['usuario'] ?? 'api',
                    'codigoDocumentoSector' => config('siat.codigo_documento_sector'),
                ],
            ],
        ];

        foreach ($detalles as $detalle) {
            $facturaArray[] = [
                'detalle' => [
                    'actividadEconomica' => $detalle['actividadEconomica'] ?? $actividad,
                    'codigoProductoSin'  => $detalle['codigoProductoSin'],
                    'codigoProducto'     => $detalle['codigoProducto'],
                    'descripcion'        => $detalle['descripcion'],
                    'cantidad'           => $detalle['cantidad'],
                    'unidadMedida'       => $detalle['unidadMedida'],
                    'precioUnitario'     => $detalle['precioUnitario'],
                    'montoDescuento'     => $detalle['montoDescuento'] ?? 0,
                    'subTotal'           => $detalle['subTotal'],
                    'numeroSerie'        => null,
                    'numeroImei'         => null,
                ],
            ];
        }

        $xmlElement = new SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<facturaComputarizadaCompraVenta'
            . ' xsi:noNamespaceSchemaLocation="facturaComputarizadaCompraVenta.xsd"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '</facturaComputarizadaCompraVenta>'
        );

        $this->arrayToXml($facturaArray, $xmlElement);

        return $xmlElement->asXML();
    }

    /**
     * Convierte recursivamente un array en nodos XML.
     * Campos null → atributo xsi:nil="true" (requerido por el schema del SIN).
     */
    private function arrayToXml(array $data, SimpleXMLElement $xml): void
    {
        $xsi = 'http://www.w3.org/2001/XMLSchema-instance';

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (!is_numeric($key)) {
                    $child = $xml->addChild((string) $key);
                    $this->arrayToXml($value, $child);
                } else {
                    $this->arrayToXml($value, $xml);
                }
            } else {
                if ($value === null || ($value === '' && $value !== '0')) {
                    $child = $xml->addChild((string) $key, htmlspecialchars((string) $value));
                    $child->addAttribute('xsi:nil', 'true', $xsi);
                } else {
                    $xml->addChild((string) $key, htmlspecialchars((string) $value));
                }
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECCIÓN 5: EMISIÓN DE FACTURA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Punto de entrada principal para emitir una factura.
     *
     * Flujo:
     *  1. Verificar límite de contingencia (máx 500 facturas 902 por sucursal/PV).
     *  2. Determinar modo (online/offline).
     *  3. Obtener número de factura.
     *  4. Generar CUF.
     *  5. Construir XML.
     *  6. Guardar XML en disco.
     *  7. Enviar al SIN si está online.
     *  8. Guardar en BD.
     *
     * @param  array  $cabecera   Datos de la cabecera de la factura
     * @param  array  $detalles   Ítems de la factura
     * @param  int    $codigoSucursal
     * @param  int    $codigoPuntoVenta
     * @param  string|null $externalId  ID de venta en el sistema cliente (opcional)
     * @return Invoice  La factura creada en BD
     * @throws \Exception si se supera el límite de contingencia u ocurre un error crítico
     */
    public function emit(
        array  $cabecera,
        array  $detalles,
        int    $codigoSucursal,
        int    $codigoPuntoVenta,
        ?string $externalId = null
    ): Invoice {
        $context = $this->getContext($codigoSucursal, $codigoPuntoVenta);
        $cufdRecord = $context['cufd'];
        $cuis       = $context['cuis'];

        $tipoEmision = $this->detectarModoEmision();

        // Verificar límite de contingencia ANTES de asignar número de factura
        $pendientes = Invoice::where('codigoEstado', '902')
            ->where('codigo_sucursal', $codigoSucursal)
            ->where('codigo_punto_venta', $codigoPuntoVenta)
            ->count();

        if ($tipoEmision === 2 && $pendientes >= 500) {
            throw new \Exception(
                'Límite de contingencia alcanzado: ya existen 500 facturas pendientes. '
                . 'Debe procesar el paquete de contingencia antes de registrar nuevas ventas.'
            );
        }

        $fecha    = new DateTime();
        $fechaStr = $fecha->format('Y-m-d\TH:i:s.v');

        $numeroFactura = DB::transaction(fn () => $this->nextNroFactura($tipoEmision));

        $cuf = $this->generarCuf(
            $fecha,
            $numeroFactura,
            $codigoSucursal,
            $codigoPuntoVenta,
            $tipoEmision,
            $cufdRecord->codigoControl
        );

        $xml = $this->buildXml(
            array_merge($cabecera, ['fechaEmision' => $fechaStr]),
            $detalles,
            $cuf,
            $cufdRecord->codigo,
            $cufdRecord->direccion ?? '',
            $tipoEmision,
            $codigoSucursal,
            $codigoPuntoVenta,
            $numeroFactura
        );

        // Persistir XML en disco y comprimir
        $paths = $this->saveXmlToDisk($cuf, $xml);

        $gzData     = file_get_contents($paths['gz']);
        $hashArchivo = hash('sha256', $gzData);

        $res = null;

        if ($tipoEmision === 1) {
            $res = $this->facturacion->recepcionFactura(
                $gzData,
                $fechaStr,
                $hashArchivo,
                $cufdRecord->codigo,
                $cuis,
                $codigoSucursal,
                $codigoPuntoVenta,
                $tipoEmision
            );

            // Si el SIN confirmó, mover a enviados
            if (isset($res->RespuestaServicioFacturacion->transaccion)
                && $res->RespuestaServicioFacturacion->transaccion) {
                $this->moveToSent($cuf);
            }
        }

        $codigoEstado = $res->RespuestaServicioFacturacion->codigoEstado ?? '902';

        // Si aún en contingencia y se llega al límite con esta factura, rollback
        if ($codigoEstado === '902' && $pendientes >= 500) {
            throw new \Exception(
                'La factura no pudo emitirse en línea y ya se alcanzó el límite de 500 pendientes.'
            );
        }

        return Invoice::create([
            'external_id'         => $externalId,
            'nroFactura'          => $numeroFactura,
            'cuf'                 => $cuf,
            'codigo_sucursal'     => $codigoSucursal,
            'codigo_punto_venta'  => $codigoPuntoVenta,
            'fechaEmision'        => $fechaStr,
            'codigoMetodoPago'    => $cabecera['codigoMetodoPago'] ?? 1,
            'montoTotal'          => $cabecera['montoTotal'],
            'montoTotalSujetoIva' => $cabecera['montoTotalSujetoIva'] ?? $cabecera['montoTotal'],
            'descuentoAdicional'  => $cabecera['descuentoAdicional'] ?? 0,
            'xml'                 => $xml,
            'codigoEstado'        => $codigoEstado,
            'codigoDescripcion'   => $res->RespuestaServicioFacturacion->codigoDescripcion ?? 'PENDIENTE DE ENVIO',
            'codigoRecepcion'     => $res->RespuestaServicioFacturacion->codigoRecepcion ?? null,
            'transaccion'         => $res->RespuestaServicioFacturacion->transaccion ?? false,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECCIÓN 6: PAQUETE DE CONTINGENCIA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Reconcilia facturas 902 locales con el SIN.
     * Consulta cada factura pendiente; si el SIN ya la tiene validada,
     * la actualiza a 908 localmente para no reenviarla en el paquete.
     *
     * @param  string|null $fechaInicio  Filtro de fecha de emisión (Y-m-d o Y-m-d\TH:i:s)
     * @param  string|null $fechaFin     Filtro de fecha de emisión
     * @return array ['verificadas'=>int, 'corregidas'=>int, 'pendientes'=>int, 'detalle'=>array]
     */
    public function reconcilePending(
        int $codigoSucursal,
        int $codigoPuntoVenta,
        ?string $fechaInicio = null,
        ?string $fechaFin = null,
    ): array {
        $context = $this->getContext($codigoSucursal, $codigoPuntoVenta);

        $query = Invoice::where('codigoEstado', '902')
            ->whereNull('invoice_package_id')
            ->where('codigo_sucursal', $codigoSucursal)
            ->where('codigo_punto_venta', $codigoPuntoVenta);

        if ($fechaInicio) {
            $query->whereDate('fechaEmision', '>=', $fechaInicio);
        }
        if ($fechaFin) {
            $query->whereDate('fechaEmision', '<=', $fechaFin);
        }

        $invoices = $query->orderBy('fechaEmision')->get();

        $verificadas = $corregidas = $faults = 0;
        $detalle = [];

        foreach ($invoices as $invoice) {
            $verificadas++;
            $cuf = (string) $invoice->cuf;

            if ($cuf === '') {
                $faults++;
                $detalle[] = ['id' => $invoice->id, 'cuf' => $cuf, 'resultado' => 'sin_cuf'];
                continue;
            }

            try {
                $res = $this->facturacion->verificacionEstado(
                    $context['cufd']->codigo,
                    $cuf,
                    $context['cuis'],
                    $codigoSucursal,
                    $codigoPuntoVenta
                );
            } catch (\Throwable) {
                $faults++;
                $detalle[] = ['id' => $invoice->id, 'cuf' => $cuf, 'resultado' => 'error_soap'];
                continue;
            }

            if ($res instanceof SoapFault) {
                $faults++;
                $detalle[] = ['id' => $invoice->id, 'cuf' => $cuf, 'resultado' => 'error_soap'];
                continue;
            }

            $respuesta = $res->RespuestaServicioFacturacion ?? null;
            if (!$this->isValidatedResponse($respuesta)) {
                $detalle[] = ['id' => $invoice->id, 'cuf' => $cuf, 'resultado' => 'pendiente'];
                continue;
            }

            // SIAT confirma que ya está validada: corregir BD local
            $updated = Invoice::whereKey($invoice->id)
                ->where('codigoEstado', '902')
                ->update([
                    'codigoEstado'      => '908',
                    'codigoDescripcion' => $respuesta->codigoDescripcion ?? 'VALIDADA',
                    'transaccion'       => true,
                    'invoice_package_id' => null,
                ]);

            if ($updated) {
                $corregidas++;
                $this->moveToSent($cuf);
            }

            $detalle[] = ['id' => $invoice->id, 'cuf' => $cuf, 'resultado' => 'corregida'];
        }

        $pendientes = Invoice::where('codigoEstado', '902')
            ->whereNull('invoice_package_id')
            ->where('codigo_sucursal', $codigoSucursal)
            ->where('codigo_punto_venta', $codigoPuntoVenta)
            ->count();

        return [
            'verificadas' => $verificadas,
            'corregidas'  => $corregidas,
            'pendientes'  => $pendientes,
            'detalle'     => $detalle,
        ];
    }

    /**
     * Construye un paquete .tar.gz y lo envía al SIN.
     * Debe llamarse después de reconcilePending().
     *
     * @param  int         $codigoEvento    ID del evento significativo ya registrado
     * @param  int|null    $packageId       ID del InvoicePackage a asociar
     * @param  array       $filters         ['start'=>Carbon, 'end'=>Carbon, 'cufdEvento'=>string, 'cafc'=>string|null]
     * @return object  Respuesta del SIN
     */
    public function sendPackage(
        int $codigoSucursal,
        int $codigoPuntoVenta,
        string $cuis,
        string $cufd,
        int $codigoEvento,
        ?int $packageId,
        array $filters = []
    ): object {
        $pendientesPath = $this->pendientesPath();
        $maxFacturas    = 500;

        if (!isset($filters['codigoSucursal'], $filters['codigoPuntoVenta'])) {
            $filters['codigoSucursal']   = $codigoSucursal;
            $filters['codigoPuntoVenta'] = $codigoPuntoVenta;
        }

        // Seleccionar facturas pendientes del rango, con XML físico disponible
        $query = Invoice::where('codigoEstado', '902')
            ->whereNull('invoice_package_id')
            ->where('codigo_sucursal', $codigoSucursal)
            ->where('codigo_punto_venta', $codigoPuntoVenta)
            ->orderBy('fechaEmision');

        if (isset($filters['start'])) {
            $query->where('fechaEmision', '>=', $filters['start']->format('Y-m-d\TH:i:s.v'));
        }
        if (isset($filters['end'])) {
            $query->where('fechaEmision', '<=', $filters['end']->format('Y-m-d\TH:i:s.v'));
        }

        $invoices = $query->get()
            ->filter(function (Invoice $inv) use ($filters) {
                // Si se especifica cufdEvento, incluir solo facturas con ese CUFD en el XML
                if (empty($filters['cufdEvento'])) {
                    return true;
                }
                try {
                    $xml = new SimpleXMLElement($inv->xml);
                    return (string) $xml->cabecera->cufd === (string) $filters['cufdEvento'];
                } catch (\Throwable) {
                    return false;
                }
            })
            ->filter(fn (Invoice $inv) => file_exists("{$pendientesPath}/factura_{$inv->cuf}.xml"))
            ->take($maxFacturas)
            ->values();

        if ($invoices->isEmpty()) {
            return (object) [
                'RespuestaServicioFacturacion' => (object) [
                    'transaccion'      => false,
                    'codigoDescripcion' => 'RECHAZADA',
                    'mensajesList'     => (object) ['descripcion' => 'No hay facturas pendientes para empaquetar.'],
                ],
            ];
        }

        $cafc      = $filters['cafc'] ?? null;
        $timestamp = date('YmdHis');
        $tarPath   = storage_path("app/public/siat/paquete_{$timestamp}.tar");
        $gzPath    = $tarPath . '.gz';

        if (file_exists($tarPath)) unlink($tarPath);
        if (file_exists($gzPath)) unlink($gzPath);

        // Construir el TAR con los XMLs normalizados (ajustar nodo <cafc> si hace falta)
        $tar              = new PharData($tarPath);
        $cantidadFacturas = 0;
        $idsProcesados    = [];

        foreach ($invoices as $invoice) {
            $filePath = "{$pendientesPath}/factura_{$invoice->cuf}.xml";

            $xmlActual     = file_get_contents($filePath);
            $xmlNormalizado = $this->normalizeXmlCafc($xmlActual, $cafc);

            if ($xmlNormalizado !== $xmlActual) {
                file_put_contents($filePath, $xmlNormalizado);
                file_put_contents($filePath . '.gz', gzencode($xmlNormalizado, 9));
                $invoice->update(['xml' => $xmlNormalizado]);
            }

            $tar->addFile($filePath, basename($filePath));
            $cantidadFacturas++;
            $idsProcesados[] = $invoice->id;
        }

        if (!extension_loaded('zlib')) {
            throw new \Exception("La extensión 'zlib' no está habilitada. Es obligatoria para comprimir el paquete SIAT.");
        }

        $tar->compress(4096); // Phar::GZIP

        $archivoBinario = file_get_contents($gzPath);
        $hashArchivo    = hash('sha256', $archivoBinario);
        $fechaEnvio     = date('Y-m-d\TH:i:s.v');

        $res = $this->facturacion->recepcionPaquete(
            $archivoBinario,
            $fechaEnvio,
            $hashArchivo,
            $cufd,
            $cuis,
            $codigoSucursal,
            $codigoPuntoVenta,
            $codigoEvento,
            $cantidadFacturas,
            $cafc
        );

        if (file_exists($tarPath)) unlink($tarPath);
        if (file_exists($gzPath)) unlink($gzPath);

        if ($res instanceof SoapFault) {
            throw new \Exception('Error SOAP al enviar paquete: ' . $res->getMessage());
        }

        // Si el SIN dio código de recepción, el paquete fue aceptado
        if (isset($res->RespuestaServicioFacturacion->codigoRecepcion)) {
            Invoice::whereIn('id', $idsProcesados)->update([
                'codigoRecepcion'    => $res->RespuestaServicioFacturacion->codigoRecepcion,
                'codigoEstado'       => $res->RespuestaServicioFacturacion->codigoEstado,
                'codigoDescripcion'  => $res->RespuestaServicioFacturacion->codigoDescripcion,
                'invoice_package_id' => $packageId,
            ]);
        }

        // Caso especial: todas ya existían en el SIN → marcar como validadas
        $mensajes = $res->RespuestaServicioFacturacion->mensajesList ?? null;
        if ($mensajes && !empty($idsProcesados)) {
            $lista = is_array($mensajes) ? $mensajes : [$mensajes];
            $todasExisten = !empty($lista) && collect($lista)->every(
                fn ($m) => str_contains(strtoupper((string) ($m->descripcion ?? '')), 'CUF ENVIADO YA EXISTE')
            );

            if ($todasExisten) {
                Invoice::whereIn('id', $idsProcesados)->update([
                    'codigoEstado'       => '908',
                    'codigoDescripcion'  => 'VALIDADA',
                    'transaccion'        => true,
                    'invoice_package_id' => $packageId,
                ]);

                return (object) [
                    '_cufYaExistia' => true,
                    'RespuestaServicioFacturacion' => (object) [
                        'transaccion'       => true,
                        'codigoDescripcion' => 'VALIDADA',
                        'codigoEstado'      => '908',
                        'codigoRecepcion'   => null,
                        'mensajesList'      => (object) [
                            'descripcion' => count($idsProcesados) . ' facturas ya estaban en el SIN. Marcadas como enviadas.',
                        ],
                    ],
                ];
            }
        }

        return $res;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECCIÓN 7: ANULACIÓN Y REVERSIÓN
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Anula una factura en el SIN.
     * El contexto (cufd, cuf, sucursal, PV, cuis) se extrae del XML guardado en la factura.
     *
     * @param  int $codigoMotivo  Código de siat_motivos_anulacion.codigoClasificador
     * @throws \Exception si la factura ya fue revertida o falta el XML
     */
    public function anular(Invoice $invoice, int $codigoMotivo): mixed
    {
        if ($invoice->reversed) {
            throw new \Exception('No se puede anular: la factura ya fue revertida una vez.');
        }

        $context = $this->getContextFromXml($invoice);

        return $this->facturacion->anulacionFactura(
            $context['cufd'],
            $context['cuf'],
            $context['cuis'],
            $context['codigoSucursal'],
            $context['codigoPuntoVenta'],
            $codigoMotivo
        );
    }

    /**
     * Revierte la anulación de una factura.
     * Solo se puede hacer una vez (el SIN bloquea intentos posteriores).
     *
     * @throws \Exception si ya fue revertida
     */
    public function revertirAnulacion(Invoice $invoice): mixed
    {
        if ($invoice->reversed) {
            throw new \Exception('Esta factura ya fue revertida una vez. No se puede revertir de nuevo.');
        }

        $context = $this->getContextFromXml($invoice);

        return $this->facturacion->reversionAnulacion(
            $context['cufd'],
            $context['cuf'],
            $context['cuis'],
            $context['codigoSucursal'],
            $context['codigoPuntoVenta']
        );
    }

    /**
     * Extrae el contexto SIAT desde el XML guardado en la factura.
     * Útil para anulación/reversión donde el CUFD puede ser distinto al vigente.
     *
     * @throws \Exception si la factura no tiene XML o está malformado
     */
    public function getContextFromXml(Invoice $invoice): array
    {
        if (!$invoice->xml) {
            throw new \Exception('La factura no tiene XML guardado.');
        }

        $xml = @simplexml_load_string($invoice->xml);
        if (!$xml || !isset($xml->cabecera)) {
            throw new \Exception('XML de la factura inválido o malformado.');
        }

        $cabecera         = $xml->cabecera;
        $cufd             = (string) ($cabecera->cufd ?? '');
        $cuf              = (string) ($cabecera->cuf ?? $invoice->cuf ?? '');
        $codigoSucursal   = (int) ($cabecera->codigoSucursal ?? -1);
        $codigoPuntoVenta = (int) ($cabecera->codigoPuntoVenta ?? -1);

        if ($cufd === '' || $cuf === '' || $codigoSucursal < 0 || $codigoPuntoVenta < 0) {
            throw new \Exception('El XML de la factura no contiene los datos de contexto SIAT necesarios.');
        }

        // Buscar CUIS vigente para esa sucursal/PV
        $cuisRecord = SiatCuis::active($codigoSucursal, $codigoPuntoVenta)->first();
        if ($this->isExpired($cuisRecord)) {
            throw new \Exception('No existe CUIS vigente para la sucursal/PV de la factura.');
        }

        return [
            'cufd'             => $cufd,
            'cuf'              => $cuf,
            'cuis'             => $cuisRecord->codigo,
            'codigoSucursal'   => $codigoSucursal,
            'codigoPuntoVenta' => $codigoPuntoVenta,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECCIÓN 8: HELPERS DE ARCHIVOS Y MODO
    // ─────────────────────────────────────────────────────────────────────────

    /** Detecta si se debe emitir en línea o en contingencia. */
    public function detectarModoEmision(): int
    {
        if (config('siat.modo_offline')) {
            return 2;
        }

        $ping = $this->codigos->verificarComunicacion();

        return ($ping instanceof SoapFault || ($ping->RespuestaComunicacion->transaccion ?? false) === false)
            ? 2 : 1;
    }

    /** Guarda XML en disco y lo comprime en GZ. Retorna las rutas. */
    public function saveXmlToDisk(string $cuf, string $xml): array
    {
        $pendientes = $this->pendientesPath();
        $xmlPath    = "{$pendientes}/factura_{$cuf}.xml";

        if (!is_dir($pendientes)) {
            mkdir($pendientes, 0775, true);
        }

        file_put_contents($xmlPath, $xml);
        $gz = gzencode($xml, 9);
        file_put_contents($xmlPath . '.gz', $gz);

        return ['xml' => $xmlPath, 'gz' => $xmlPath . '.gz'];
    }

    /** Mueve los archivos de una factura de pendientes/ a enviados/. */
    public function moveToSent(string $cuf): void
    {
        $pendientes = $this->pendientesPath();
        $enviados   = $this->enviadosPath();

        if (!is_dir($enviados)) {
            mkdir($enviados, 0775, true);
        }

        foreach (['.xml', '.xml.gz'] as $ext) {
            $src = "{$pendientes}/factura_{$cuf}{$ext}";
            $dst = "{$enviados}/factura_{$cuf}{$ext}";
            if (file_exists($src)) {
                rename($src, $dst);
            }
        }
    }

    /** Ajusta el nodo <cafc> del XML antes de empaquetarlo.
     *  null → xsi:nil="true"; string → valor del CAFC.
     */
    public function normalizeXmlCafc(string $xml, ?string $cafc): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        if (!$dom->loadXML($xml)) {
            return $xml;
        }

        $xpath     = new \DOMXPath($dom);
        $cafcNodes = $xpath->query('//*[local-name()="cabecera"]/*[local-name()="cafc"]');
        $cafcNode  = $cafcNodes->length ? $cafcNodes->item(0) : null;

        if (!$cafcNode) {
            $cabeceraNodes = $xpath->query('//*[local-name()="cabecera"]');
            if (!$cabeceraNodes->length) {
                return $xml;
            }
            $cafcNode = $dom->createElement('cafc');
            $cabeceraNodes->item(0)->appendChild($cafcNode);
        }

        while ($cafcNode->firstChild) {
            $cafcNode->removeChild($cafcNode->firstChild);
        }

        if ($cafc === null || $cafc === '') {
            $cafcNode->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:nil', 'true');
        } else {
            $cafcNode->removeAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'nil');
            $cafcNode->appendChild($dom->createTextNode($cafc));
        }

        return $dom->saveXML() ?: $xml;
    }

    /** Retorna true si la respuesta del SIN indica factura validada. */
    public function isValidatedResponse(?object $response): bool
    {
        if (!$response) {
            return false;
        }

        $estado      = (string) ($response->codigoEstado ?? '');
        $descripcion = strtoupper((string) ($response->codigoDescripcion ?? ''));

        return in_array($estado, ['908', '690'], true)
            || in_array($descripcion, ['VALIDADA', 'VALIDA'], true);
    }

    private function pendientesPath(): string
    {
        return storage_path('app/public/siat/pendientes');
    }

    private function enviadosPath(): string
    {
        return storage_path('app/public/siat/enviados');
    }
}
