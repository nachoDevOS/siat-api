<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Credenciales del emisor ante el SIN
    |--------------------------------------------------------------------------
    | Un solo NIT por instancia de esta API.
    | Para múltiples emisores, desplegar múltiples instancias con su propio .env.
    */
    'token'              => env('SIAT_TOKEN'),
    'nit'                => env('SIAT_NIT'),
    'codigo_sistema'     => env('SIAT_CODIGO_SISTEMA'),
    'razon_social'       => env('SIAT_RAZON_SOCIAL'),
    'municipio'          => env('SIAT_MUNICIPIO'),
    'telefono'           => env('SIAT_TELEFONO'),

    /*
    |--------------------------------------------------------------------------
    | Parámetros de ambiente y modalidad
    |--------------------------------------------------------------------------
    | codigoAmbiente: 1 = Producción, 2 = Piloto/Pruebas
    | codigoModalidad: 1 = Electrónica en línea, 2 = Computarizada en línea
    | codigoDocumentoSector: 1 = Compra/Venta (el más común)
    | tipoFacturaDocumento: 1 = Con crédito fiscal, 2 = Sin crédito fiscal
    */
    'codigo_ambiente'          => (int) env('SIAT_CODIGO_AMBIENTE', 2),
    'codigo_modalidad'         => (int) env('SIAT_CODIGO_MODALIDAD', 2),
    'codigo_documento_sector'  => (int) env('SIAT_CODIGO_DOCUMENTO_SECTOR', 1),
    'tipo_factura_documento'   => (int) env('SIAT_TIPO_FACTURA_DOCUMENTO', 1),

    /*
    |--------------------------------------------------------------------------
    | Modo offline manual
    |--------------------------------------------------------------------------
    | Si SIAT_MODO_OFFLINE=true, todas las facturas se emiten en contingencia
    | sin intentar conectarse al SIN. Útil para mantenimientos programados.
    */
    'modo_offline' => filter_var(env('SIAT_MODO_OFFLINE', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | CAFC (Código de Autorización de Facturación en Contingencia)
    |--------------------------------------------------------------------------
    | Solo necesario cuando se emite en modo fuera de línea (contingencia).
    | cafc_inicio / cafc_fin: rango de numeración autorizado por el SIN.
    | cafc_fin = 0 significa sin límite superior.
    */
    'cafc'        => env('SIAT_CAFC'),
    'cafc_inicio' => (int) env('SIAT_CAFC_INICIO', 1),
    'cafc_fin'    => (int) env('SIAT_CAFC_FIN', 0),

    /*
    |--------------------------------------------------------------------------
    | Endpoints SOAP del SIN
    |--------------------------------------------------------------------------
    | base_piloto  = ambiente de pruebas
    | base_produccion = ambiente real
    | La URL activa se resuelve automáticamente según codigo_ambiente.
    */
    'endpoints' => [
        'piloto'     => 'https://pilotosiatservicios.impuestos.gob.bo/v2/',
        'produccion' => 'https://siatservicios.impuestos.gob.bo/v2/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeouts SOAP (segundos)
    |--------------------------------------------------------------------------
    */
    'timeout' => [
        'default' => (int) env('SIAT_TIMEOUT_DEFAULT', 5),
        'paquete' => (int) env('SIAT_TIMEOUT_PAQUETE', 15),
    ],
];
