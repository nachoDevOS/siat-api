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
        // Modalidad electronica en linea: la unica que emite este sistema.
        'modalidad' => [
            'electronica' => 1,
        ],
        // Documento sector 1 = Factura de compra-venta (unico alcance por ahora).
        'documento_sector' => 1,
        // tipoFacturaDocumento: 1 = factura con derecho a credito fiscal.
        // Viaja en recepcionFactura y tambien entra al calculo del CUF.
        'tipo_factura_documento' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | QR de verificacion de la factura
    |--------------------------------------------------------------------------
    | Pagina publica del SIN donde se consulta una factura por NIT, CUF y
    | numero. Depende del ambiente: una factura del piloto no existe en la
    | consulta de produccion y viceversa. La de piloto y el parametro t=2 son
    | los que usa 'ventas'; la de produccion, el mismo camino en el dominio
    | principal del SIN.
    */
    'qr' => [
        'urls' => [
            1 => env('SIAT_QR_URL_PRODUCCION', 'https://siat.impuestos.gob.bo/consulta/QR'),
            2 => env('SIAT_QR_URL_PILOTO', 'https://pilotosiat.impuestos.gob.bo/consulta/QR'),
        ],
        // Tamano de la representacion grafica: 1 = rollo, 2 = media pagina /
        // carta. El PDF de este sistema es de pagina completa.
        'tamanio' => (int) env('SIAT_QR_TAMANIO', 2),
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

        // Peticiones por minuto y por token (cada caja o sistema).
        'rate_limit_token' => (int) env('SIAT_RATE_LIMIT_TOKEN', 60),

        // Dias que se conserva el registro de llamadas a la API (logs_api).
        'retencion_logs_dias' => (int) env('SIAT_API_RETENCION_LOGS_DIAS', 90),

        /*
        | Permisos (abilities de Sanctum) que puede llevar un token. Cada ruta
        | de la API exige uno; un token sin ese permiso recibe 403.
        */
        'permisos' => [
            'estado:leer' => 'Consultar si la integracion puede facturar',
            'catalogos:leer' => 'Leer catalogos del SIN y del NIT',
            'estructura:leer' => 'Leer sucursales y puntos de venta',
            'estructura:gestionar' => 'Crear puntos de venta locales',
            'codigos:leer' => 'Ver la vigencia de CUIS y CUFD (nunca los codigos)',
            'codigos:renovar' => 'Forzar un CUFD nuevo (emergencia)',
            'facturas:emitir' => 'Emitir facturas',
            'facturas:leer' => 'Consultar y descargar facturas',
            'facturas:anular' => 'Anular facturas',
        ],

        /*
        | Perfiles que se eligen en el panel al crear un token. Una caja solo
        | vende y consulta; anular o tocar codigos queda para administracion,
        | asi un token de caja filtrado no puede anular facturas.
        */
        'perfiles' => [
            'caja' => [
                'nombre' => 'Caja / POS',
                'permisos' => ['estado:leer', 'catalogos:leer', 'estructura:leer', 'codigos:leer', 'facturas:emitir', 'facturas:leer'],
            ],
            'administracion' => [
                'nombre' => 'Administracion',
                'permisos' => ['estado:leer', 'catalogos:leer', 'estructura:leer', 'estructura:gestionar', 'codigos:leer', 'codigos:renovar', 'facturas:emitir', 'facturas:leer', 'facturas:anular'],
            ],
            'consulta' => [
                'nombre' => 'Solo consulta',
                'permisos' => ['estado:leer', 'catalogos:leer', 'estructura:leer', 'codigos:leer', 'facturas:leer'],
            ],
        ],
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
    | Proxies de confianza
    |--------------------------------------------------------------------------
    | IPs (o '*') de los proxies/balanceadores delante de la app, separadas por
    | coma. Sin esto, detras de un proxy todas las peticiones parecen venir de
    | la IP del proxy: la restriccion de IPs de los tokens deja de servir y
    | logs_api registra la IP equivocada. Vacio = no se confia en ninguno.
    */
    'proxies_confiables' => env('TRUSTED_PROXIES'),

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
    | openssl.cnf para generar certificados de prueba
    |--------------------------------------------------------------------------
    | PHP en Windows (Laragon, XAMPP) no encuentra un openssl.cnf por defecto y
    | sin el no se puede generar ni una clave. Se usa uno minimo del proyecto;
    | se puede apuntar a otro desde el .env.
    */
    'openssl_config' => env('SIAT_OPENSSL_CONF', resource_path('openssl/openssl.cnf')),

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
