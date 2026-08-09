# Análisis del sistema — siat-api

> Estado verificado el 2026-08-09 corriendo las migraciones reales (28 tablas, FKs e
> índices comprobados) y la suite completa de tests. No es una lectura a ojo de los
> archivos.

---

## 1. Qué es

SaaS de facturación electrónica para el **SIAT** (Servicio de Impuestos Nacionales,
Bolivia). El rol es **proveedor multi-cliente**: los sistemas de venta de los clientes
(ferreterías, restaurantes, farmacias) solo hablan REST/JSON contra esta API, y este
sistema encapsula todo lo complejo: SOAP, firma digital XAdES, ciclo de códigos
CUIS/CUFD/CAFC, catálogos del SIN, contingencia y pruebas del piloto.

**Alcance:** modalidad electrónica en línea, documento sector 1 (factura de compra-venta).

| | |
|---|---|
| Stack | PHP 8.3 · Laravel 13.21 · Pest 4 |
| Frontend | Blade con CSS inline (sin Vite, sin build step) |
| Dependencias de dominio | `barryvdh/laravel-dompdf`, `simplesoftwareio/simple-qrcode` |
| Extensiones PHP requeridas | `soap`, `openssl`, `bcmath`, `gd`, `dom`, `zlib` |
| Tamaño | 83 archivos / 5.394 líneas en `app/`, 13 vistas Blade |
| Tests | **73 verdes**, 172 aserciones, 16 archivos |

---

## 2. Base de datos

**Motor: MySQL.** Base `siat_api` en `127.0.0.1:3306`, usuario `root` sin contraseña.

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=siat_api
DB_USERNAME=root
DB_PASSWORD=
```

**Queue = database · Cache = database · Sessions = database.** Todo vive en la misma
base, sin Redis.

`phpunit.xml` sigue apuntando a sqlite `:memory:` **a propósito**, para que la suite
corra en ~3 segundos. Consecuencia a tener presente: los `lockForUpdate` recién
bloquean de verdad contra MySQL, en sqlite son un no-op.

### Puesta en marcha

```bash
sudo systemctl start mysql
mysql -u root -e "CREATE DATABASE IF NOT EXISTS siat_api CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan migrate:fresh --seed
```

El seeder crea el usuario del panel: **admin@admin.com / password** (cambiar en producción)
y carga los 17 casos de prueba del piloto.

### Inventario: 28 tablas

**19 de dominio** + **9 del framework** (`users`, `sessions`, `password_reset_tokens`,
`cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations`).

---

### Bloque 1 — Cliente (raíz del aislamiento multi-tenant)

**`empresas`** · 13 columnas. Cada fila es un contribuyente cliente. Prácticamente toda
consulta del sistema filtra por `empresa_id`.

| Columna | Tipo / Notas |
|---|---|
| `id` | PK |
| `nombre_comercial`, `razon_social` | string |
| `nit` | string(20) |
| `codigo_sistema` | lo asigna el SIN al contribuyente |
| `token_delegado` | text, **cifrado en reposo** (cast `encrypted`) |
| `api_key_hash` | string(64) `UNIQUE` — solo el SHA-256; la clave en claro se muestra una única vez al alta y nunca se puede recuperar |
| `codigo_ambiente` | tinyint · 1 = Producción, 2 = Piloto |
| `codigo_modalidad` | tinyint · 1 = Electrónica, 2 = Computarizada |
| `estado` | string · ciclo de vida ante el SIN |
| `webhook_url` | string · validado contra SSRF al guardar |
| `created_at`, `updated_at` | |

**Índices:** `UNIQUE(api_key_hash)`, `UNIQUE(nit, codigo_ambiente)`.

El único compuesto por NIT + ambiente permite que el mismo contribuyente exista en
piloto y en producción al mismo tiempo, que es exactamente lo que pasa durante la
homologación.

**Ciclo de vida del estado:**

```
EN_REGISTRO → EN_PRUEBAS → PILOTO_APROBADO → PRODUCCION
                                  │
                                  └──► OBSERVADO
```

Solo una empresa en `PRODUCCION` puede facturar por la API.

---

### Bloque 2 — Estructura física del contribuyente

```
empresas ─┬─ certificados       .p12 + passphrase (ambos cifrados), vence_el, activo
          │
          └─ sucursales         UNIQUE(empresa_id, codigo_sucursal)
                 │              codigo_sucursal 0 = casa matriz
                 │              + municipio, direccion, telefono
                 │
                 └─ puntos_venta UNIQUE(sucursal_id, codigo_punto_venta)
                                 tipo_punto_venta, activo
                                 siguiente_factura ← correlativo local
```

Distinción importante que el esquema refleja: las **sucursales** las registra el
contribuyente por su cuenta en la Oficina Virtual del SIN y acá solo se copian para
poder referenciarlas; los **puntos de venta** sí los crea este sistema llamando al SIAT.

`puntos_venta.siguiente_factura` es el correlativo local. Se reserva con bloqueo de fila
dentro de una transacción para que dos cajas nunca tomen el mismo número.

**`certificados`** guarda `contenido_p12` (LONGTEXT, base64 y luego cifrado) y
`passphrase` (cifrado). Ambos campos están en `$hidden` del modelo por si alguna vez se
serializa por error. Solo uno queda `activo` por empresa; el resto es historial.

---

### Bloque 3 — Códigos del SIN (tablas de historial)

Las tres cuelgan de `puntos_venta` con `cascadeOnDelete` e indexan
`(punto_venta_id, fecha_vigencia)`. **Nunca se sobrescriben**: cada solicitud al SIN
inserta una fila nueva. "El vigente" es el más reciente cuya `fecha_vigencia` todavía
no pasó.

| Tabla | Columnas | Vida | Para qué sirve |
|---|---|---|---|
| `cuis` | `codigo`, `fecha_vigencia` | ~1 año | identifica el punto de venta ante el SIN; se necesita para pedir CUFD y CAFC |
| `cufd` | `codigo`, **`codigo_control`**, `direccion`, `fecha_vigencia` | 24 h | su `codigo_control` **entra al cálculo del CUF** — sin CUFD no hay factura |
| `cafc` | `codigo`, `cantidad_facturas`, `facturas_usadas`, `fecha_vigencia` | rango | reserva para emitir en contingencia (sin internet o SIAT caído) |

El CUFD es el cuello de botella operativo: dura 24 horas y su código de control es
insumo del CUF. De ahí la estrategia de dos capas (ver sección 5).

---

### Bloque 4 — Facturación (el corazón del sistema)

**`facturas`** · **39 columnas**. Es el documento fiscal y está deliberadamente
desnormalizada: los datos del comprador se copian en vez de referenciarse, porque una
factura es un documento congelado en el tiempo, no una vista de otras tablas.

**Claves foráneas**
```
empresa_id      → empresas.id        cascade
punto_venta_id  → puntos_venta.id    cascade
cufd_id         → cufd.id            set null
cafc_id         → cafc.id            set null
paquete_id      → paquetes.id        set null
```

**Grupos de columnas**

| Grupo | Columnas |
|---|---|
| Identidad fiscal | `cuf`, `numero_factura`, `fecha_emision` |
| Comprador (desnormalizado) | `comprador_tipo_documento`, `comprador_numero_documento`, `comprador_complemento`, `comprador_razon_social`, `comprador_email` |
| Pago y moneda | `metodo_pago`, `numero_tarjeta`, `moneda`, `tipo_cambio` (decimal 12,5) |
| Montos (decimal 14,2) | `subtotal`, `descuento_global`, `gift_card`, `anticipo`, `monto_total`, `monto_total_moneda`, `monto_total_sujeto_iva` |
| Clasificación SIN | `codigo_documento_sector`, `tipo_emision`, `leyenda`, `usuario` |
| Estado y respuesta del SIN | `estado`, `codigo_recepcion`, `codigo_estado_siat` |
| Documentos | `xml_firmado` (LONGTEXT) — **el documento legal**; `ruta_pdf` — solo una representación imprimible |
| Trazabilidad | `referencia_externa`, `enviada_en`, `validada_en`, `created_at`, `updated_at` |

**Índices**
```
INDEX  (cuf)                              la API consulta la factura por su CUF
UNIQUE (empresa_id, referencia_externa)   idempotencia de negocio
```

El único compuesto es la pieza clave de la idempotencia: una misma venta del cliente
genera una sola factura. La deduplicación se apoya en esta restricción de la base, no en
un chequeo previo en PHP — dos peticiones concurrentes pueden pasar el chequeo, pero solo
una gana el `INSERT`, y la perdedora captura la violación y devuelve la factura ganadora.

**Tablas dependientes**

`factura_items` · 14 columnas · cascade
: `codigo_producto_sin`, `codigo_interno`, `descripcion`, `cantidad` (decimal 14,4),
`unidad_medida`, `precio_unitario`, `descuento`, `subtotal`, `numero_serie`, `numero_imei`.

`facturas_anuladas` · 7 columnas · cascade
: `factura_id`, `motivo` (código del catálogo del SIN), `codigo_recepcion` (lo devuelve
el SIN al confirmar), `anulada_en`.

---

### Bloque 5 — Operación

```
eventos_significativos   12 col
   empresa_id, punto_venta_id, codigo_evento, descripcion,
   cufd_evento, fecha_inicio, fecha_fin, codigo_recepcion,
   estado (ABIERTO | CERRADO)
        │
        └─ paquetes      10 col
              empresa_id, punto_venta_id, evento_id,
              cantidad_facturas, codigo_recepcion,
              estado (PENDIENTE | ENVIADO), enviado_en
                    │
                    └─ facturas.paquete_id
```

`facturas.paquete_id` congela el conjunto de facturas que viaja en cada paquete. Sin
esta columna, el paquete se armaba filtrando por punto de venta + estado, y una factura
que entrara a contingencia entre "armar" y "enviar" quedaba marcada como enviada sin
haber viajado nunca.

**`logs_siat`** · 10 columnas — auditoría de cada llamada SOAP.
```
empresa_id (set null), servicio, operacion,
xml_enviado (LONGTEXT), xml_recibido (LONGTEXT),
duracion_ms, exitoso, mensaje_error, created_at
INDEX (empresa_id, servicio, operacion)
```

Solo tiene `created_at`: un log no se actualiza, se agrega. Es la única forma de depurar
de verdad una respuesta del SIN. Como guarda el XML completo (que incluye datos del
comprador), tiene poda automática vía `siat:purgar-logs`, retención de 90 días por
defecto.

---

### Bloque 6 — Catálogos

Los **globales** van todos en una sola tabla porque comparten la misma forma:

**`catalogos`** · `tipo` + `codigo_clasificador` + `descripcion`
```
UNIQUE (tipo, codigo_clasificador)
INDEX  (tipo)
```
Ejemplos de `tipo`: `unidades_medida`, `tipos_metodo_pago`, `motivos_anulacion`.

Los que **dependen del NIT** van aparte, con `empresa_id`:

| Tabla | Único |
|---|---|
| `actividades_economicas` | `(empresa_id, codigo_actividad)` |
| `productos_servicios` | `(empresa_id, codigo_actividad, codigo_producto)` |
| `leyendas_factura` | — (varias leyendas por actividad) |

---

### Bloque 7 — Pruebas del piloto

`casos_prueba` · `fase`, `orden`, `nombre`, `descripcion`, `tipo`, `payload_ejemplo` (JSON), `obligatorio`
`ejecuciones_prueba` · `empresa_id` (nullable), `caso_id`, `estado`, `respuesta` (JSON), `duracion_ms`, `intento`, `ejecutado_en`

Los **17 pasos del piloto viven en base de datos, no en código**, para poder editarlos
cuando el SIN cambie el manual sin tocar la aplicación:

```
 1  Verificar comunicación              10  Registrar punto de venta
 2  Verificar NIT del contribuyente     11  Emitir factura contado - efectivo
 3  Sincronizar fecha y hora del SIN    12  Emitir factura con descuento
 4  Solicitar CUIS                      13  Emitir factura a NIT de empresa
 5  Solicitar CUFD                      14  Anular una factura
 6  Sincronizar catálogos globales      15  Registrar evento significativo
 7  Sincronizar actividades del NIT     16  Emitir en contingencia + paquete
 8  Sincronizar productos homologados   17  Marcar cliente PILOTO_APROBADO
 9  Sincronizar leyendas de factura
```

`ejecuciones_prueba.empresa_id` es nullable porque la fase 1 se corre con el NIT del
proveedor, sin empresa cliente.

---

### Integridad referencial

**24 foreign keys**, con un criterio consistente:

- **`cascade`** hacia abajo en la jerarquía del cliente — borrar una empresa se lleva
  certificados, sucursales, puntos de venta, códigos, facturas, ítems, eventos, paquetes
  y catálogos propios.
- **`set null`** donde el dato histórico debe sobrevivir al referente —
  `facturas.cufd_id`, `facturas.cafc_id`, `facturas.paquete_id`, `paquetes.evento_id`,
  `logs_siat.empresa_id`. Una factura emitida no puede perder validez porque se borró el
  CUFD que la originó.

---

## 3. Arquitectura

```
Sistema de ventas del cliente
        │  JSON  +  X-Api-Key  [+ X-Idempotency-Key]
        ▼
routes/api.php   (prefijo /api/v1)
        │
        ├─ siat.apikey        identifica la empresa por hash SHA-256
        │                     cachea 5 min; el modelo invalida al guardarse
        ├─ siat.rate          120 req/min por empresa (configurable)
        ├─ siat.produccion    ┐ solo en POST /facturas
        └─ siat.idempotencia  ┘
        ▼
Api/V1/FacturaController
        │
        ▼
EmisorFactura                          ← SÍNCRONO, objetivo ~150 ms
        ├─ ValidadorFactura            reglas de negocio locales
        ├─ CalculadorTotales           lógica pura, sin BD ni SOAP
        ├─ GeneradorCuf                ← LOCAL, no necesita internet
        ├─ ConstructorXml
        └─ FirmadorXml                 .p12 de la empresa, RSA-SHA256
                 │
                 ├─ guarda la factura en PENDIENTE
                 └─► cola: EnviarFacturaAlSiat  (afterCommit)
                              │
       FabricaServicios ──► ServicioFacturacion ──► SiatClient ──► SOAP al SIN
                              │                          │
                              │                          └─► logs_siat
                              │
                        falla tras 3 intentos
                              │
                              ▼
                     GestorContingencia  →  evento significativo  →  paquete
```

### La decisión de diseño central

El **CUF se calcula localmente** antes de tocar el SIAT. Por eso:

1. El cajero recibe respuesta en milisegundos, no espera al SIN (que tarda 0,8–4 s).
2. Una factura emitida **sin internet ya es legalmente válida**.
3. El envío al SIN es un detalle posterior que resuelve un worker en segundo plano.

Todo el resto de la arquitectura es consecuencia de esto: la cola, la contingencia, los
webhooks de cambio de estado y la máquina de estados de la factura existen porque la
emisión y la transmisión están desacopladas.

### Frontera SOAP

`app/Services/Siat/` es el **único lugar del sistema que construye un `SoapClient`**.

- `SiatClient` — resuelve la URL del WSDL según el ambiente de la empresa, mete el token
  delegado en la cabecera HTTP (el SIN lo exige en el transporte, no en el sobre SOAP),
  aplica timeouts y expone `__getLastRequest()` / `__getLastResponse()` para la auditoría.
- `ServicioBase` — cabecera común de campos, invocación y logging a `logs_siat`. Las
  hijas solo arman su payload; no vuelven a tocar transporte ni auditoría.
- `ServicioCodigos` · `ServicioSincronizacion` · `ServicioOperaciones` ·
  `ServicioFacturacion`
- `FabricaServicios` — los jobs la resuelven del contenedor en vez de hacer `new`, que es
  lo que hace testeable toda la capa.

Si el SIN cambia algo del transporte, se toca un solo archivo.

### Servicios por dominio

| Carpeta | Contenido |
|---|---|
| `Services/Siat/` | SiatClient, ServicioBase + 4 servicios, FabricaServicios |
| `Services/Factura/` | EmisorFactura, ValidadorFactura, CalculadorTotales, GeneradorCuf, ConstructorXml, FirmadorXml, GeneradorQr, GeneradorPdf |
| `Services/Contingencia/` | GestorContingencia, ArmadorPaquete |
| `Services/Catalogos/` | SincronizadorGlobal, SincronizadorEmpresa |
| `Services/Pruebas/` | EjecutorPruebas |
| `Services/Webhooks/` | DestinoWebhook |

---

## 4. Máquina de estados de la factura

```
PENDIENTE ──► ENVIADA ──► RECIBIDA ──► VALIDADA ──► ANULADA
    │                                      ▲
    │                                      │
    └──► CONTINGENCIA ──(paquete enviado)──┘
                │
                └──► OBSERVADA   (el SIN la rechazó)
```

Una factura en `CONTINGENCIA` **ya es válida y se puede imprimir**; solo le falta
transmitirse dentro de un paquete. Por eso la API responde **202 Accepted** y no un error.

`tipo_emision`: 1 = en línea, 2 = contingencia.

---

## 5. Trabajo asíncrono

### 8 jobs

| Job | tries | Qué hace |
|---|---|---|
| `EnviarFacturaAlSiat` | 3 | transmite la factura; agotados los reintentos deriva a contingencia |
| `VerificarEstadoFactura` | 5 | consulta el estado final (validada / observada) y notifica |
| `AnularFacturaEnSiat` | 5 | transmite al SIN la anulación registrada localmente |
| `EnviarPaqueteContingencia` | 3 | envía el paquete de facturas acumuladas |
| `RenovarCufd` | 3 | solicita un CUFD nuevo y lo guarda como historial |
| `SincronizarCatalogosEmpresa` | — | actividades, productos y leyendas del NIT |
| `NotificarWebhook` | 5 | avisa al cliente, con firma HMAC-SHA256 |
| `EjecutarCasoPrueba` | — | un paso del piloto, para no bloquear el panel |

### Tareas programadas

| Frecuencia | Tarea |
|---|---|
| cada 5 min | reintentar facturas en `PENDIENTE` (lote de 200) |
| cada 15 min | verificar estado de `ENVIADA` / `RECIBIDA` |
| cada hora | `siat:renovar-cufds` — renueva los que vencen en <2 h |
| diario 02:00 | `siat:revisar-codigos` — vigencia de CUIS y disponibilidad de CAFC |
| diario 04:00 | `siat:purgar-logs` — poda la auditoría fuera de retención |
| diario 07:00 | `siat:avisar-certificados` — los que vencen en <30 días |
| domingo 03:00 | `siat:sincronizar-globales` — catálogos paramétricos |

### Estrategia de dos capas del CUFD

El CUFD dura 24 horas y su código de control es insumo del CUF, así que si falta no se
puede facturar. Por eso hay dos capas:

- **Preventiva** — el cron horario renueva los que vencen en menos de 2 horas, para que
  la primera venta del día no espere.
- **Reactiva** — si aun así falta, `EmisorFactura` lo detecta y corta con
  `CufdVencidoException` → 503 `SIAT_NO_DISPONIBLE`.

---

## 6. API pública — 11 rutas

```
GET  /api/v1/estado                    salud: ¿puedo facturar ahora?
GET  /api/v1/catalogos/{tipo}          cacheado 1 h; "actividades" sale por empresa
GET  /api/v1/puntos-venta
POST /api/v1/puntos-venta
GET  /api/v1/facturas                  paginado 50, filtros desde/hasta
POST /api/v1/facturas                  emitir
GET  /api/v1/facturas/{cuf}
GET  /api/v1/facturas/{cuf}/pdf
GET  /api/v1/facturas/{cuf}/xml        el documento legal
POST /api/v1/facturas/{cuf}/anular
```

Solo `POST /facturas` exige empresa en producción e idempotencia. La consulta y descarga
funcionan en cualquier estado.

### Códigos de respuesta

| Código | Situación |
|---|---|
| 201 | factura emitida y encolada |
| 202 | emitida en contingencia (válida, pendiente de transmitir) |
| 401 | `API_KEY_INVALIDA` |
| 403 | `EMPRESA_NO_HABILITADA` — no está en `PRODUCCION` |
| 404 | la factura no existe o es de otra empresa |
| 409 | `FACTURA_NO_ANULABLE` — el SIN todavía no la conoce |
| 422 | `FACTURA_INVALIDA` + `detalles[]`, o validación del FormRequest |
| 429 | `DEMASIADAS_PETICIONES` + cabecera `Retry-After` |
| 503 | `SIAT_NO_DISPONIBLE` — sin CUFD vigente |

Forma consistente en todos los errores: `{exito, error, mensaje, detalles?}`.

### Webhooks

Se notifica a `empresas.webhook_url` en cada cambio de estado
(`factura.validada`, `factura.observada`, `factura.anulada`).

- El destino se valida contra SSRF: se resuelve el host y se rechazan loopback, rangos
  privados, link-local y `169.254.169.254` (metadatos de instancia cloud). Exige https.
- El cuerpo va firmado: `X-Siat-Signature: sha256=<hmac>` sobre los bytes exactos que
  viajan, más `X-Siat-Evento`.

---

## 7. Panel `/admin`

13 vistas Blade con CSS inline y sidebar responsive, sin build step (no hace falta
`npm run build`).

| Ruta | Qué hace |
|---|---|
| `/admin` | dashboard: métricas, facturas por estado, tasa de éxito SOAP |
| `/admin/empresas` | ABM de clientes; genera la API key una sola vez |
| `/admin/empresas/{id}` | ficha: certificados, sucursales, puntos de venta |
| `/admin/puntos-venta/{id}/{cuis,cufd,cafc}` | solicitar al SIAT o cargar manual |
| `/admin/empresas/{id}/pruebas` | panel del piloto, los 17 pasos |
| `/admin/monitor` | explorador de `logs_siat` |
| `/admin/api` | consola: endpoints y acceso por empresa |

Autenticación por sesión con throttle (5 intentos / 60 s por email + IP).

---

## 8. Seguridad

**Lo que está resuelto**

- `token_delegado`, `contenido_p12` y `passphrase` cifrados en reposo con la clave de la app.
- API key: solo se guarda el hash SHA-256; la clave en claro se muestra una única vez.
- Caché de la API key invalidada por el propio modelo al guardarse — una key revocada o
  una empresa sacada de producción dejan de servir al instante, no cuando venza el TTL.
- Rate limit por empresa, para que el bug de un cliente no afecte a los demás.
- Aislamiento multi-cliente: toda consulta de la API filtra por `empresa_id`.
- Webhooks con filtro anti-SSRF y firma HMAC.
- Throttle en el login del panel.
- Retención acotada de `logs_siat` (guardan datos personales del comprador).

**Lo que falta**

- **Sin roles ni policies en el panel**: cualquier usuario autenticado ve y edita todas
  las empresas. Aceptable si solo entra el staff del proveedor; no lo es si algún día
  entra un cliente.
- El filtro anti-SSRF no cubre DNS rebinding (que la IP cambie entre la comprobación y la
  conexión real). Para eso habría que fijar la IP en el cliente HTTP.

---

## 9. Veredicto

### Lo que está bien

La separación de capas es real, no decorativa. La frontera SOAP se sostiene: nadie fuera
de `app/Services/Siat/` construye un `SoapClient`. El XML solo se arma en un archivo, el
CUF solo se calcula en otro, la firma solo se aplica en un tercero. Si el SIN cambia
algo, se toca un archivo y el resto del sistema no se entera.

El modelo de datos acompaña: historial en vez de sobrescritura para los códigos,
desnormalización deliberada en la factura, un único compuesto que sostiene la
idempotencia, criterio consistente de cascade/set-null. Los casos de prueba en base de
datos en vez de en código es la decisión correcta para algo que el SIN cambia por su
cuenta.

Los comentarios explican el *porqué*, no el *qué*.

### Bloqueantes de producción

Los tres primeros son el mismo bloqueo de fondo: **no hay acceso al WSDL/XSD real del
SIN**. Cada uno es un cambio de un archivo el día que se tenga.

1. **XAdES-BES incompleto** — `app/Services/Factura/FirmadorXml.php:145`. La firma
   XML-DSig base funciona y es verificable localmente, pero falta el bloque
   `<Object><QualifyingProperties>` con `SignedProperties` (SigningTime +
   SigningCertificate) y su segunda `Reference`. El SIN rechaza toda factura sin esto.
2. **Módulo 11 del CUF sin verificar** — `app/Services/Factura/GeneradorCuf.php:94`.
   Hay que confirmar los pesos (2..9 vs 2..7) y el manejo del resto 10/11 contra el
   manual del SIN. Un CUF mal calculado significa factura rechazada.
3. **Nombres de operaciones y campos SOAP sin validar** contra el WSDL vigente, y la
   estructura del XML contra el XSD. Afecta a `ConstructorXml`, `ServicioFacturacion`,
   `ServicioCodigos` y `ArmadorPaquete`.

Falta también un token de piloto real y un `.p12` real para probar el flujo de punta a punta.

### Deuda técnica, por prioridad

4. Sin autorización por rol en el panel.
5. `EmisorFactura` resuelve el punto de venta sin filtrar `activo`.
6. `facturas.cuf` no tiene índice único: nada impide un CUF duplicado.
7. Los servicios de Catálogos y Pruebas, y `Admin/CodigoController`, todavía instancian
   SOAP a mano en vez de usar `FabricaServicios` — quedan sin cobertura de tests.
8. Los códigos de estado del SIN en `VerificarEstadoFactura` (`901`, `902`, `908`) están
   marcados como pendientes de verificar.
9. `EnviarPaqueteContingencia` declara `backoff` pero atrapa la excepción y hace
   `release(300)` fijo — la misma contradicción que ya se corrigió en
   `EnviarFacturaAlSiat`, aunque acá es menos grave.

---

## 10. Cobertura de tests — 73 verdes

| Archivo | Qué cubre |
|---|---|
| `SiatFase1Test` | modelos, cifrado del token, vigencia de CUFD, URLs del WSDL |
| `AuthTest` | login, logout, protección del panel |
| `AdminPanelTest` | render de las vistas, alta de empresa, validaciones |
| `CodigosPanelTest` | carga manual de CUIS/CUFD/CAFC |
| `ApiFacturaTest` | emisión, idempotencia, 401/403/422, endpoint de estado |
| `ApiAnulacionTest` | anulación, estados no anulables, aislamiento entre empresas |
| `ContingenciaTest` | evento único por racha, congelado del paquete, armado |
| `JobsSiatTest` | reintentos vs contingencia, códigos del SIN en el paquete |
| `WebhookTest` | filtro SSRF (6 casos), firma HMAC |
| `SeguridadApiTest` | rate limit, invalidación de caché, rotación de key, throttle |
| `MantenimientoTest` | purga de logs |
| `FacturaServiciosTest` | CUF, totales, validador, firma XML |

**Hueco de cobertura:** los sincronizadores de catálogos y el ejecutor de pruebas, por la
razón de la deuda #7.
