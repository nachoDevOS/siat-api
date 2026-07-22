<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ambiente activo del SIAT
    |--------------------------------------------------------------------------
    | El SIN identifica el ambiente con un numero, no con un nombre:
    |   1 = Produccion   2 = Piloto
    | Guardamos aqui el valor por defecto para comandos y pruebas, pero cada
    | empresa lleva su propio "codigo_ambiente" porque un mismo servidor puede
    | tener clientes en piloto y clientes en produccion al mismo tiempo.
    */
    'ambiente_por_defecto' => (int) env('SIAT_AMBIENTE', 2),

    /*
    |--------------------------------------------------------------------------
    | URLs base por ambiente
    |--------------------------------------------------------------------------
    | El WSDL de cada servicio se arma como: {base}/{Servicio}?wsdl
    | La clave del arreglo es el codigo de ambiente para poder resolver la URL
    | directamente con $config[$empresa->codigo_ambiente].
    */
    'urls' => [
        // 1 = Produccion
        1 => env('SIAT_URL_PRODUCCION', 'https://siatrest.impuestos.gob.bo/v2'),
        // 2 = Piloto
        2 => env('SIAT_URL_PILOTO', 'https://pilotosiatservicios.impuestos.gob.bo/v2'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Servicios SOAP del SIAT
    |--------------------------------------------------------------------------
    | Nombres tal como aparecen en la ruta del WSDL. Se centralizan aqui para
    | que ningun servicio arme la URL "a mano". Si el SIN renombra un servicio,
    | se cambia en un solo lugar.
    */
    'servicios' => [
        'sincronizacion' => 'FacturacionSincronizacion',
        'codigos' => 'FacturacionCodigos',
        'operaciones' => 'FacturacionOperaciones',
        'compra_venta' => 'ServicioFacturacionCompraVenta',
        'electronica' => 'ServicioFacturacionElectronica',
    ],

    /*
    |--------------------------------------------------------------------------
    | Codigos constantes de la normativa
    |--------------------------------------------------------------------------
    | Valores fijos que el SIN define y que se repiten en casi toda peticion.
    | Se nombran para no dejar "numeros magicos" sueltos en el codigo.
    */
    'codigos' => [
        'ambiente' => [
            'produccion' => 1,
            'piloto' => 2,
        ],
        'modalidad' => [
            'electronica' => 1,
            'computarizada' => 2,
        ],
        // El SIN usa 0 para la casa matriz; el resto son sucursales reales.
        'casa_matriz' => 0,
        // Documento sector 1 = Factura de compra-venta (unico alcance por ahora).
        'documento_sector' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeouts del SoapClient (segundos)
    |--------------------------------------------------------------------------
    | El SIAT puede tardar entre 0.8 y 4 segundos. Damos margen para no cortar
    | una respuesta valida, pero sin dejar colgado al worker indefinidamente.
    */
    'timeout' => (int) env('SIAT_TIMEOUT', 30),
    'connection_timeout' => (int) env('SIAT_CONNECTION_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | Cache del WSDL
    |--------------------------------------------------------------------------
    | En desarrollo conviene NO cachear el WSDL para ver cambios al toque.
    | En produccion se cachea en disco para no descargarlo en cada peticion.
    | Valores validos de PHP: WSDL_CACHE_NONE=0, WSDL_CACHE_DISK=1,
    | WSDL_CACHE_MEMORY=2, WSDL_CACHE_BOTH=3.
    */
    'wsdl_cache' => (int) env('SIAT_WSDL_CACHE', WSDL_CACHE_NONE),

    /*
    |--------------------------------------------------------------------------
    | Traza SOAP
    |--------------------------------------------------------------------------
    | Con la traza activa se puede leer el XML enviado y recibido con
    | __getLastRequest() y __getLastResponse(). Es la unica forma de auditar
    | el mensaje real, asi que lo dejamos activo por defecto.
    */
    'trace' => (bool) env('SIAT_TRACE', true),

];
