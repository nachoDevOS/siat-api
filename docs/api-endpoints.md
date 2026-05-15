# SIAT API — Documentación de Endpoints

**Base URL:** `https://tu-dominio.com/api/v1`  
**Autenticación:** todas las rutas requieren `Authorization: Bearer {token}` (Sanctum).  
**Formato:** JSON en request y response. `Content-Type: application/json`.

---

## Índice

1. [Códigos SIAT](#1-códigos-siat-cuis--cufd--nit)
2. [Facturas](#2-facturas)
3. [Paquetes de Contingencia](#3-paquetes-de-contingencia)
4. [Catálogos](#4-catálogos)
5. [Códigos de estado de factura](#5-códigos-de-estado-de-factura)
6. [Códigos de error HTTP](#6-códigos-de-error-http)

---

## 1. Códigos SIAT (CUIS / CUFD / NIT)

### `GET /codigos/ping`

Verifica que el SIN (Servicio de Impuestos Nacionales) esté respondiendo.  
No requiere parámetros.

**Respuesta exitosa `200`:**
```json
{
  "ok": true,
  "transaccion": true,
  "mensajes": { "descripcion": "..." }
}
```

**Respuesta sin conexión `503`:**
```json
{
  "ok": false,
  "mensaje": "Sin conexión con SIAT: ..."
}
```

---

### `POST /codigos/sync`

Sincroniza CUIS y/o CUFD con el SIN para una sucursal/PV.  
Solo renueva los que vencieron. Si ambos están vigentes, no llama al SIN.

**Body:**
```json
{
  "codigo_sucursal":    0,
  "codigo_punto_venta": 0
}
```

| Campo | Tipo | Descripción |
|---|---|---|
| `codigo_sucursal` | integer ≥ 0 | Código de sucursal SIAT (`branches.codigo_sucursal` en el sistema cliente) |
| `codigo_punto_venta` | integer ≥ 0 | Punto de venta SIAT (`users.codigo_punto_venta` en el sistema cliente) |

**Respuesta — ya vigentes `200`:**
```json
{
  "ok": true,
  "sincronizado": false,
  "mensaje": "CUIS y CUFD vigentes. No se realizó ninguna renovación.",
  "cuis": "A3D9F2...",
  "cufd": "B7E1C4...",
  "cufd_vigencia": "2026-05-16T00:00:00"
}
```

**Respuesta — renovados `200`:**
```json
{
  "ok": true,
  "sincronizado": true,
  "cuis": "A3D9F2...",
  "cufd": "B7E1C4...",
  "cufd_vigencia": "2026-05-16T00:00:00"
}
```

**Sin conexión con SIAT `503`:**
```json
{
  "ok": false,
  "mensaje": "Sin conexión con SIAT. No se pudieron renovar los códigos."
}
```

---

### `GET /codigos/context`

Retorna el contexto SIAT vigente (CUIS + CUFD) de una sucursal/PV.  
Si algún código venció, lo renueva automáticamente antes de responder.

**Query params:**
```
?codigo_sucursal=0&codigo_punto_venta=0
```

**Respuesta `200`:**
```json
{
  "ok": true,
  "cuis": "A3D9F2...",
  "cufd": "B7E1C4...",
  "cufd_vigencia": "2026-05-16T00:00:00",
  "cufd_direccion": "https://siatservicios.impuestos.gob.bo/...",
  "cufd_control": "XYZ123"
}
```

| Campo | Descripción |
|---|---|
| `cuis` | Código CUIS vigente (vigencia anual) |
| `cufd` | Código CUFD vigente (vigencia diaria) |
| `cufd_vigencia` | Timestamp de vencimiento del CUFD |
| `cufd_direccion` | URL de verificación QR de la factura |
| `cufd_control` | Sufijo que se concatena al CUF calculado |

---

### `POST /codigos/verificar-nit`

Consulta al SIN si un NIT está habilitado en el padrón tributario.  
Usar antes de emitir una factura cuando `codigoTipoDocumentoIdentidad = 5` (NIT).

**Body:**
```json
{
  "codigo_sucursal": 0,
  "nit": 123456789
}
```

**Respuesta `200`:**
```json
{
  "ok": true,
  "transaccion": true,
  "mensajes": { "descripcion": "NIT HABILITADO" }
}
```

> El campo `transaccion: true` indica que el NIT está habilitado. `false` indica que no está en el padrón.

---

## 2. Facturas

### `POST /facturas`

Emite una factura al SIN en tiempo real (modo en línea).  
Si el SIN no responde en el timeout configurado, la factura se guarda en estado `902` (contingencia pendiente) y el XML queda en disco para enviarse después como paquete.

**Body:**
```json
{
  "codigo_sucursal":              0,
  "codigo_punto_venta":           0,
  "external_id":                  "uuid-del-sistema-cliente",

  "nitEmisor":                    123456789,
  "razonSocialEmisor":            "Mi Empresa SRL",
  "municipio":                    "La Paz",
  "telefono":                     "70000000",

  "numeroDocumento":              12345678,
  "complemento":                  null,
  "codigoTipoDocumentoIdentidad": 1,
  "nombreRazonSocial":            "Juan Pérez",

  "codigoMetodoPago":             1,
  "montoTotal":                   100.00,
  "montoTotalSujetoIva":          100.00,
  "descuentoAdicional":           0,
  "codigoMoneda":                 1,
  "tipoCambio":                   1,

  "codigoActividad":              "47110",
  "leyenda":                      "Ley 453 - El proveedor...",
  "usuario":                      "cajero01",

  "detalles": [
    {
      "actividadEconomica":   "47110",
      "codigoProductoSin":    99900,
      "codigoProducto":       "P001",
      "descripcion":          "Ibuprofeno 400mg",
      "cantidad":             2,
      "unidadMedida":         58,
      "precioUnitario":       50.00,
      "montoDescuento":       0,
      "subTotal":             100.00
    }
  ]
}
```

#### Campos de cabecera

| Campo | Tipo | Req | Descripción |
|---|---|---|---|
| `codigo_sucursal` | integer ≥ 0 | ✓ | Código de sucursal SIAT |
| `codigo_punto_venta` | integer ≥ 0 | ✓ | Código de punto de venta SIAT |
| `external_id` | string | — | ID propio del sistema cliente (para rastrear la factura en su BD) |
| `nitEmisor` | integer | — | NIT del emisor. Si se omite, usa `SIAT_NIT` del config |
| `razonSocialEmisor` | string | — | Razón social del emisor. Si se omite, usa `SIAT_RAZON_SOCIAL` |
| `municipio` | string | — | Municipio. Si se omite, usa `SIAT_MUNICIPIO` |
| `telefono` | string | — | Teléfono. Si se omite, usa `SIAT_TELEFONO` |
| `numeroDocumento` | string/int | ✓ | Documento de identidad del comprador |
| `complemento` | string (2) | — | Complemento del CI (2 dígitos). Solo CI boliviano |
| `codigoTipoDocumentoIdentidad` | integer | ✓ | Tipo de doc: 1=CI, 2=Pasaporte, 3=Otro, 5=NIT (catálogo `siat_tipo_documentos`) |
| `nombreRazonSocial` | string | ✓ | Nombre completo o razón social del comprador |
| `codigoMetodoPago` | integer | ✓ | Catálogo `siat_metodo_pagos` (ej: 1=Efectivo, 2=Tarjeta) |
| `montoTotal` | decimal | ✓ | Monto total de la factura en BOB |
| `montoTotalSujetoIva` | decimal | ✓ | Monto sujeto a IVA (generalmente igual a `montoTotal`) |
| `descuentoAdicional` | decimal | — | Descuento adicional global. Default `0` |
| `codigoMoneda` | integer | — | Catálogo `siat_tipo_monedas`. Default `1` (BOB) |
| `tipoCambio` | decimal | — | Tipo de cambio respecto a BOB. Default `1` |
| `codigoActividad` | string | ✓ | Código CAEB de la actividad económica (catálogo `siat_actividades`) |
| `leyenda` | string | — | Texto de leyenda aleatoria según actividad (catálogo `siat_leyendas`) |
| `usuario` | string | — | Identificador del cajero/usuario. Default `"API"` |

#### Campos de detalle (array `detalles`, mínimo 1)

| Campo | Tipo | Req | Descripción |
|---|---|---|---|
| `actividadEconomica` | string | ✓ | Código CAEB del producto/servicio |
| `codigoProductoSin` | integer | ✓ | Código del producto en catálogo SIN (`siat_productos`) |
| `codigoProducto` | string | ✓ | Código interno del producto en el sistema cliente |
| `descripcion` | string | ✓ | Descripción del producto o servicio |
| `cantidad` | decimal | ✓ | Cantidad vendida |
| `unidadMedida` | integer | ✓ | Catálogo `siat_unidad_medidas` (ej: 58=Unidades) |
| `precioUnitario` | decimal | ✓ | Precio por unidad en BOB |
| `montoDescuento` | decimal | — | Descuento por línea. Default `0` |
| `subTotal` | decimal | ✓ | `(precioUnitario × cantidad) - montoDescuento` |

**Respuesta exitosa — emitida en línea `201`:**
```json
{
  "ok": true,
  "invoice_id": 42,
  "cuf": "11223344556677882026050100000011000100100000000001AC2B",
  "nroFactura": 1001,
  "codigoEstado": "908",
  "codigoDescripcion": "VALIDADA",
  "contingencia": false
}
```

**Respuesta — guardada en contingencia `201`:**
```json
{
  "ok": true,
  "invoice_id": 43,
  "cuf": "11223344556677882026050100000011000200100000000002XY9Z",
  "nroFactura": 1002,
  "codigoEstado": "902",
  "codigoDescripcion": null,
  "contingencia": true
}
```

> Cuando `contingencia: true`, el XML quedó guardado en disco (`storage/app/public/siat/pendientes/`). Enviar luego como paquete via `POST /paquetes/enviar`.

---

### `GET /facturas/{id}`

Retorna los datos de una factura por su ID interno.

**Respuesta `200`:**
```json
{
  "ok": true,
  "invoice": {
    "id": 42,
    "external_id": "uuid-del-sistema-cliente",
    "nroFactura": 1001,
    "cuf": "...",
    "codigo_sucursal": 0,
    "codigo_punto_venta": 0,
    "fechaEmision": "2026-05-15T10:30:00.000",
    "codigoMetodoPago": 1,
    "montoTotal": "100.00",
    "montoTotalSujetoIva": "100.00",
    "descuentoAdicional": "0.00",
    "codigoEstado": "908",
    "codigoDescripcion": "VALIDADA",
    "codigoRecepcion": "...",
    "transaccion": true,
    "reversed": false,
    "invoice_package_id": null
  }
}
```

---

### `POST /facturas/{id}/anular`

Anula una factura en el SIN.  
El contexto (CUFD, sucursal, PV, CUIS) se extrae automáticamente del XML guardado en la factura, para usar exactamente los códigos con los que fue emitida.

**Condiciones de rechazo:**
- `reversed = true` → fue revertida, no se puede anular de nuevo
- La factura no existe en BD local

**Body:**
```json
{
  "codigo_motivo": 2
}
```

| Campo | Descripción |
|---|---|
| `codigo_motivo` | `codigoClasificador` del catálogo `siat_motivos_anulacion` (ej: 1=Error de facturación, 2=Devolución de mercadería) |

**Respuesta `200`:**
```json
{
  "ok": true,
  "respuesta": {
    "RespuestaServicioFacturacion": {
      "codigoEstado": "908",
      "codigoDescripcion": "ANULADA",
      "transaccion": true
    }
  }
}
```

**Rechazada (factura ya revertida) `422`:**
```json
{
  "ok": false,
  "mensaje": "La factura ya fue revertida y no puede volver a anularse."
}
```

---

### `POST /facturas/{id}/revertir`

Revierte la anulación de una factura en el SIN.  
Solo se puede revertir una vez; después `reversed = true` bloquea nuevos intentos.

Sin body adicional.

**Respuesta `200`:**
```json
{
  "ok": true,
  "respuesta": {
    "RespuestaServicioFacturacion": {
      "codigoEstado": "908",
      "codigoDescripcion": "VALIDADA",
      "transaccion": true
    }
  }
}
```

---

### `GET /facturas/{id}/estado`

Consulta el estado actual de una factura directamente en el SIN por su CUF.  
Útil para reconciliar facturas en estado `902` que podrían haber sido recibidas por SIAT pero cuya respuesta no llegó (timeout).

**Respuesta `200`:**
```json
{
  "ok": true,
  "invoice_id": 43,
  "cuf": "...",
  "codigoEstado": "908",
  "codigoDescripcion": "VALIDADA",
  "transaccion": true,
  "mensajes": null
}
```

---

## 3. Paquetes de Contingencia

El flujo de contingencia se activa cuando hay facturas en estado `902` (emitidas sin conexión o cuya respuesta del SIN no llegó). El proceso es:

```
1. GET  /paquetes/pendientes   → ver cuántas facturas están en 902
2. POST /paquetes/reconciliar  → verificar si SIAT ya tiene algunas (las corrige a 908)
3. POST /paquetes/enviar       → registrar evento + empaquetar + enviar al SIN
4. GET  /paquetes/{id}/estado  → confirmar que el SIN procesó el paquete
```

---

### `GET /paquetes/pendientes`

Lista las facturas en estado `902` de una sucursal/PV, opcionalmente filtradas por rango de fecha.

**Query params:**
```
?codigo_sucursal=0&codigo_punto_venta=0
&fecha_inicio=2026-05-01&fecha_fin=2026-05-15
```

**Respuesta `200`:**
```json
{
  "ok": true,
  "total": 3,
  "facturas": [
    {
      "id": 43,
      "cuf": "...",
      "nroFactura": 1002,
      "fechaEmision": "2026-05-15T11:00:00.000",
      "montoTotal": "200.00",
      "codigoEstado": "902"
    }
  ]
}
```

---

### `POST /paquetes/reconciliar`

Verifica cada factura `902` contra el SIN. Si el SIN ya la tiene como válida (`908`), la actualiza localmente y mueve su XML a la carpeta de enviados.  
**Ejecutar antes de `POST /paquetes/enviar`** para evitar enviar facturas que el SIN ya registró.

**Body:**
```json
{
  "codigo_sucursal":    0,
  "codigo_punto_venta": 0,
  "fecha_inicio":       "2026-05-01",
  "fecha_fin":          "2026-05-15"
}
```

**Respuesta `200`:**
```json
{
  "ok": true,
  "verificadas": 5,
  "corregidas":  2,
  "pendientes":  3,
  "detalle": [
    { "id": 43, "cuf": "...", "resultado": "corregida" },
    { "id": 44, "cuf": "...", "resultado": "pendiente" },
    { "id": 45, "cuf": "...", "resultado": "error_soap" }
  ]
}
```

| Campo | Descripción |
|---|---|
| `verificadas` | Total de facturas `902` procesadas |
| `corregidas` | Actualizadas a `908` porque el SIN ya las tenía |
| `pendientes` | Siguen en `902` → son contingencia real, se deben empaquetar |
| `detalle[].resultado` | `corregida` / `pendiente` / `error_soap` / `sin_cuf` |

---

### `POST /paquetes/enviar`

Flujo completo del envío de paquete:
1. Reconcilia las `902` del rango (preverificación automática).
2. Si quedan `902` reales, registra el evento significativo en el SIN.
3. Construye el archivo `.tar.gz` con los XMLs pendientes (máximo 500 por paquete).
4. Llama a `recepcionPaquete` en el SIN.
5. Retorna el resultado del SIN.

> Si tras reconciliar no quedan facturas `902`, responde con `sin_paquete: true` y no crea paquete.

**Body:**
```json
{
  "codigo_sucursal":             0,
  "codigo_punto_venta":          0,
  "fecha_inicio":                "2026-05-15T00:00:00",
  "fecha_fin":                   "2026-05-15T23:59:59",
  "codigo_evento_significativo": 1,
  "descripcion_evento":          "Corte de internet del proveedor"
}
```

| Campo | Tipo | Descripción |
|---|---|---|
| `fecha_inicio` | datetime | Inicio del período de contingencia. También filtra las facturas `902` a incluir |
| `fecha_fin` | datetime | Fin del período. `fecha_fin >= fecha_inicio` |
| `codigo_evento_significativo` | integer | `codigoClasificador` del catálogo `siat_eventos_significativos` |
| `descripcion_evento` | string | Descripción libre del evento (se envía al SIN) |

**Respuesta — sin facturas pendientes tras reconciliar `200`:**
```json
{
  "ok": true,
  "sin_paquete": true,
  "mensaje": "No quedan facturas 902 pendientes. No se generó paquete.",
  "corregidas": 3
}
```

**Respuesta — paquete enviado `201`:**
```json
{
  "ok": true,
  "codigoRecepcion": "88F3A2...",
  "codigoEstado": "904",
  "codigoDescripcion": "EN PROCESO DE VALIDACION",
  "transaccion": true,
  "mensajes": null
}
```

> El estado `904` es normal inmediatamente después del envío. El SIN procesa el paquete de forma asíncrona. Usar `GET /paquetes/{id}/estado` para confirmar el estado final (`908 VALIDADA`).

---

### `GET /paquetes/{id}/estado`

Consulta en el SIN el estado de validación de un paquete ya enviado.  
Actualiza automáticamente el estado local si el SIN retorna uno diferente.

**Respuesta `200`:**
```json
{
  "ok": true,
  "package_id": 1,
  "codigoRecepcion": "88F3A2...",
  "codigoEstado": "908",
  "codigoDescripcion": "VALIDADA",
  "transaccion": true,
  "mensajes": null
}
```

---

### `GET /paquetes`

Lista los paquetes enviados, opcionalmente filtrados por sucursal/PV. Respuesta paginada.

**Query params:**
```
?codigo_sucursal=0&codigo_punto_venta=0&per_page=20
```

**Respuesta `200`:**
```json
{
  "ok": true,
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "codigo_sucursal": 0,
        "codigo_punto_venta": 0,
        "codigoRecepcion": "88F3A2...",
        "codigoEstado": "908",
        "codigoDescripcion": "VALIDADA",
        "transaccion": true,
        "invoices_count": 10
      }
    ],
    "total": 1,
    "per_page": 20
  }
}
```

---

## 4. Catálogos

Los catálogos son las tablas paramétricas del SIN. Se sincronizan una vez y se consultan localmente sin llamar al SIN en cada request.

### Endpoints de consulta local (GET)

| Endpoint | Tabla local | Descripción |
|---|---|---|
| `GET /catalogos/actividades` | `siat_actividades` | Actividades económicas (CAEB). Incluye `codigoCaeb` y descripción |
| `GET /catalogos/leyendas?codigo_actividad=47110` | `siat_leyendas` | Leyendas de factura. Filtrar por actividad para mostrar las que corresponden |
| `GET /catalogos/motivos-anulacion` | `siat_motivos_anulacion` | Motivos de anulación. `codigoClasificador` es lo que se envía en `POST /facturas/{id}/anular` |
| `GET /catalogos/eventos-significativos` | `siat_eventos_significativos` | Tipos de evento de contingencia. `codigoClasificador` va en `POST /paquetes/enviar` |

**Respuesta estándar:**
```json
{
  "ok": true,
  "data": [
    { "id": 1, "codigoClasificador": 1, "descripcion": "Error en facturación" }
  ]
}
```

---

### Endpoints de sincronización (POST)

Llaman al SIN para actualizar la tabla local. Requieren CUIS/CUFD vigente para la sucursal/PV indicados.

**Body para todos:**
```json
{
  "codigo_sucursal":    0,
  "codigo_punto_venta": 0
}
```

| Endpoint | Tabla actualizada | Nota |
|---|---|---|
| `POST /catalogos/sync/todos` | Todas (10 tablas) | Sincroniza todo de una vez. Puede ser lento |
| `POST /catalogos/sync/actividades` | `siat_actividades` | |
| `POST /catalogos/sync/leyendas` | `siat_leyendas` | |
| `POST /catalogos/sync/motivos-anulacion` | `siat_motivos_anulacion` | Necesario para habilitar el botón de anulación |
| `POST /catalogos/sync/eventos-significativos` | `siat_eventos_significativos` | Necesario antes de enviar paquetes |
| `POST /catalogos/sync/tipos-documento` | `siat_tipo_documentos` | |
| `POST /catalogos/sync/metodos-pago` | `siat_metodo_pagos` | |
| `POST /catalogos/sync/unidades-medida` | `siat_unidad_medidas` | |
| `POST /catalogos/sync/productos-servicios` | `siat_productos` | El más lento; >20 000 registros |
| `POST /catalogos/sync/tipos-punto-venta` | `siat_tipo_punto_ventas` | |
| `POST /catalogos/sync/tipos-moneda` | `siat_tipo_monedas` | |

**Respuesta exitosa `200`:**
```json
{
  "ok": true,
  "catalogo": "motivosAnulacion",
  "respuesta": { ... }
}
```

**Respuesta `POST /catalogos/sync/todos` `200`:**
```json
{
  "ok": true,
  "resultados": {
    "actividades":           "ok",
    "leyendas":              "ok",
    "motivosAnulacion":      "ok",
    "eventosSignificativos": "ok",
    "tiposDocumento":        "ok",
    "metodosPago":           "ok",
    "unidadesMedida":        "ok",
    "tiposPuntoVenta":       "ok",
    "tiposMoneda":           "ok",
    "productosServicios":    "ok"
  }
}
```

---

## 5. Códigos de estado de factura

| Código | Descripción |
|---|---|
| `902` | Pendiente / contingencia. El SIN no respondió. XML en disco en `pendientes/` |
| `904` | En proceso de validación (respuesta inmediata del paquete) |
| `908` | Validada. El SIN la aceptó |
| `900` | Rechazada por el SIN |

---

## 6. Códigos de error HTTP

| Código | Cuándo ocurre |
|---|---|
| `200` | Operación exitosa (GET / acciones sin creación) |
| `201` | Recurso creado (POST /facturas, POST /paquetes/enviar) |
| `404` | Factura o paquete no encontrado |
| `422` | Error de validación del request, o la operación no puede realizarse (ej: factura `reversed`) |
| `503` | Sin conexión con SIAT, o CUIS/CUFD vencidos y no renovables |

---

## Notas de integración

### Modo offline / contingencia

Si `SIAT_MODO_OFFLINE=true` en el `.env`, todas las facturas se guardan directamente en estado `902` sin intentar la emisión en línea. Útil para integraciones donde la conectividad al SIN no está garantizada.

### CAFC (contingencia con autorización previa)

Si el período de contingencia requiere CAFC (catálogo `siat_eventos_significativos` con códigos 5, 6 o 7), configurar en `.env`:
```
SIAT_CAFC=XXXX...
SIAT_CAFC_INICIO=1
SIAT_CAFC_FIN=1000
```
Los nroFactura offline se tomarán del rango CAFC en lugar del secuencial normal.

### Autenticación con Sanctum

Crear un token vía `php artisan tinker`:
```php
$user = \App\Models\User::first();
$token = $user->createToken('sistema-cliente')->plainTextToken;
echo $token;
```
Usar ese token como `Authorization: Bearer {token}` en cada request.

### Ambientes SIAT

| `SIAT_CODIGO_AMBIENTE` | Ambiente |
|---|---|
| `1` | Producción |
| `2` | Piloto (para pruebas) |
