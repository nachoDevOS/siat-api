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
    | Identidad del proveedor
    |--------------------------------------------------------------------------
    | Datos de la solicitud de autorizacion (formulario R-1359) con la que el
    | SIN habilito ESTE sistema. Son del proveedor, no de sus clientes: se
    | cargan una vez al desplegar y no los cambia un operador, por eso viven
    | en el .env y no en una tabla.
    |
    | El alta de un cliente los usa como valor por defecto, pero cada empresa
    | guarda el suyo: si el SIN emite un codigo de sistema distinto por cada
    | contribuyente asociado, se pisa desde el panel y aca no se toca nada.
    */
    'proveedor' => [
        'nit' => env('SIAT_PROVEEDOR_NIT'),
        'razon_social' => env('SIAT_PROVEEDOR_RAZON_SOCIAL'),
        'codigo_sistema' => env('SIAT_PROVEEDOR_CODIGO_SISTEMA'),
        'nombre_sistema' => env('SIAT_PROVEEDOR_NOMBRE_SISTEMA', 'SolucionDigital-api'),
    ],

    /*
    |--------------------------------------------------------------------------
    | URLs base por ambiente
    |--------------------------------------------------------------------------
    | El WSDL de cada servicio se arma como: {base}/{Servicio}?wsdl
    | La clave del arreglo es el codigo de ambiente para poder resolver la URL
    | directamente con $config[$empresa->codigo_ambiente].
    */
    'urls' => [
        // 1 = Produccion. PENDIENTE DE VERIFICAR: la solicitud de autorizacion
        // solo lista las rutas del ambiente de pruebas.
        1 => env('SIAT_URL_PRODUCCION', 'https://siatrest.impuestos.gob.bo/v2'),
        // 2 = Piloto. VERIFICADA contra la solicitud de autorizacion del SIN.
        2 => env('SIAT_URL_PILOTO', 'https://pilotosiatservicios.impuestos.gob.bo/v2'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Servicios SOAP del SIAT
    |--------------------------------------------------------------------------
    | Nombres tal como aparecen en la ruta del WSDL. Se centralizan aqui para
    | que ningun servicio arme la URL "a mano". Si el SIN renombra un servicio,
    | se cambia en un solo lugar.
    |
    | VERIFICADOS contra la solicitud de autorizacion del SIN (formulario
    | R-1359), que lista las cuatro rutas exactas del ambiente de pruebas.
    */
    'servicios' => [
        'sincronizacion' => 'FacturacionSincronizacion',
        'codigos' => 'FacturacionCodigos',
        'operaciones' => 'FacturacionOperaciones',
        'compra_venta' => 'ServicioFacturacionCompraVenta',
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
        // tipoFacturaDocumento: 1 = factura con derecho a credito fiscal.
        // Viaja en recepcionFactura y tambien entra al calculo del CUF.
        'tipo_factura_documento' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | API REST que consume el sistema de ventas del cliente
    |--------------------------------------------------------------------------
    | Estos valores se leen con config() y NO con env() directo: en produccion
    | se corre "config:cache" y ahi env() devuelve null, lo que dejaria el
    | limite en 0 y rechazaria todas las peticiones con 429.
    */
    'api' => [
        // Peticiones por minuto y por empresa.
        'rate_limit' => (int) env('SIAT_RATE_LIMIT', 120),

        // Minutos que se cachea la empresa resuelta desde su API key.
        'cache_apikey_minutos' => (int) env('SIAT_CACHE_APIKEY_MINUTOS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks hacia el sistema del cliente
    |--------------------------------------------------------------------------
    | La URL la elige el cliente, asi que es una entrada no confiable: se
    | resuelve el host y se rechazan las direcciones internas para que nadie
    | pueda usar nuestro servidor como proxy hacia su red (SSRF).
    */
    'webhooks' => [
        // Secreto con el que se firma el cuerpo (HMAC-SHA256) para que el
        // receptor pueda comprobar que la notificacion salio de aca.
        'secreto' => env('SIAT_WEBHOOK_SECRET'),

        // Permitir destinos privados/loopback. Solo para desarrollo local.
        'permitir_destinos_privados' => (bool) env('SIAT_WEBHOOK_PERMITIR_PRIVADOS', false),

        'timeout' => (int) env('SIAT_WEBHOOK_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retencion de la auditoria SOAP
    |--------------------------------------------------------------------------
    | logs_siat guarda el XML completo de cada llamada (incluye datos del
    | comprador), asi que crece rapido y no puede quedarse para siempre.
    */
    'retencion_logs_dias' => (int) env('SIAT_RETENCION_LOGS_DIAS', 90),

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
    |
    | Se usa el numero y no la constante WSDL_CACHE_NONE porque este archivo se
    | carga muy temprano (y se cachea con config:cache): si ext-soap no estuviera
    | disponible, la constante haria fallar el arranque entero.
    */
    'wsdl_cache' => (int) env('SIAT_WSDL_CACHE', 0),

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
