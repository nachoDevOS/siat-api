# Análisis del sistema — siat-api

> Estado verificado el 2026-08-09 corriendo las migraciones reales (28 tablas, FKs e
> índices comprobados) y la suite completa de tests. No es una lectura a ojo de los
> archivos.
>
> **Revisión del 2026-08-09 (tarde).** Se cerró la deuda técnica de los puntos 5, 6, 7 y
> 9; el punto 4 se descartó por decisión de producto (ver §8); se reescribió el panel; y
> se dejaron preparados —sin terminar— los tres bloqueantes que dependen de material del
> SIN. Los cambios están marcados con **[2026-08-09]** a lo largo del documento.
>
> Se descubrió además que **las migraciones no corrían contra MySQL**: el índice único de
> `productos_servicios` generaba un nombre de 70 caracteres y MySQL admite 64. La
> verificación original se había hecho sobre sqlite. Corregido con nombre explícito.

---

> **Alta de un cliente:** el procedimiento completo, con los requisitos y de quién es cada
> uno, está en [ALTA-DE-CLIENTE.md](ALTA-DE-CLIENTE.md).

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
| Tamaño | 86 archivos en `app/`, 13 vistas Blade + 5 componentes |
| Tests | **173 verdes** + 1 aparte del grupo `mysql`, 27 archivos |

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

**[2026-08-09] Eso ya está cubierto.** `tests/Feature/CorrelativoConcurrenciaTest.php`
corre **contra MySQL de verdad**, sobre una base aparte (`siat_api_test`), y lanza cuatro
procesos PHP reales emitiendo en paralelo por el mismo punto de venta. Está en el grupo
`mysql`, excluido de la corrida normal en `phpunit.xml`:

```bash
sudo systemctl start mysql
php artisan test --group=mysql
```

Se verificó que el test detecta la regresión: quitando el `lockForUpdate`, las cuatro
cajas sacan solo dos números distintos.

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
UNIQUE (cuf)                              [2026-08-09] era INDEX; ahora la base impide un CUF duplicado
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

**[2026-08-09] Los 17 pasos están cableados.** Antes solo tres (`verificarComunicacion`,
`fechaHora`, `cuis`) hacían algo y el resto fallaba con "aún no implementado". Ahora:

- **1 a 10** — estructurales, se ejecutan enteros. Los pasos de CUIS y CUFD **guardan** el
  código devuelto, porque los pasos siguientes lo necesitan vigente en la base.
- **11 a 16** — emiten documentos reales (factura, anulación, evento, contingencia) por el
  mismo camino que la API. Los datos que llevan (qué venta, qué motivo, qué código de
  evento) los define la especificación que el SIN genera por contribuyente: se leen de
  `casos_prueba.payload_ejemplo` y **no se inventan**. Si falta, el paso falla diciendo
  exactamente qué cargar, y se carga desde el panel.
- **17** — comprueba que los 16 anteriores estén en `EXITOSO`. **No cambia el estado de la
  empresa**: quien aprueba el piloto es el SIN, el panel solo ofrece el botón para
  reflejarlo. Antes el sistema se auto-aprobaba.

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
- `FabricaServicios` — se resuelve del contenedor en vez de hacer `new`, que es lo que
  hace testeable toda la capa. **[2026-08-09]** Ya no queda un solo `new SiatClient` ni
  `new ServicioX` fuera de esta carpeta: sincronizadores, `EjecutorPruebas`,
  `Admin/CodigoController` y los comandos pasan todos por la fábrica, que además expone
  `cliente()` para las herramientas de diagnóstico.

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
PENDIENTE ──► RECIBIDA ──► VALIDADA ──► ANULADA
    │            │                          │
    │            └──► OBSERVADA             │ (si el SIN rechaza la
    │                    ▲                  │  anulación, vuelve al
    └──► CONTINGENCIA    │                  ▼  estado anterior)
             │           │            estado_anterior
             └─► ENVIADA ┘
              (paquete enviado)
```

**[2026-08-10]** `RECIBIDA` dejó de ser un estado muerto: nunca se asignaba en
ningún lado. Ahora la distinción es real y significa algo distinto en cada camino:

| Estado | Cuándo |
|---|---|
| `RECIBIDA` | el SIN acusó recibo de **esa** factura (`recepcionFactura` devolvió su código de recepción) |
| `ENVIADA` | la factura viajó dentro de un **paquete** de contingencia: el acuse es del paquete, no de cada factura |
| `OBSERVADA` | el SIN la rechazó, sea al recibirla o al verificarla |

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
| `/admin/empresas` | listado de clientes con filtro por estado y buscador por NIT/nombre |
| `/admin/empresas/{id}` | ficha completa: etapa, checklist, certificado, estructura, códigos |
| `/admin/empresas/{id}/estado` | refleja el avance de etapa que ya ocurrió ante el SIN |
| `/admin/puntos-venta/{id}/{cuis,cufd,cafc}` | solicitar al SIAT o cargar manual |
| `/admin/empresas/{id}/pruebas` | panel del piloto, los 17 pasos |
| `/admin/monitor` | explorador de `logs_siat` |
| `/admin/api` | consola: endpoints y acceso por empresa |
| `/admin/configuracion` | **[2026-08-09]** identidad del proveedor, ambientes y transporte. **Solo lectura** |

Autenticación por sesión con throttle (5 intentos / 60 s por email + IP).

### [2026-08-09] Rediseño del panel

Dos clases concentran todo lo que el panel sabe sobre estados, y las vistas no deciden
nada por su cuenta:

| Clase | Responsabilidad |
|---|---|
| `App\Services\Panel\EstadosVisuales` | Paleta única: verde listo · azul en curso · violeta hito · ámbar atención · rojo bloqueante · gris no empezó. Un color significa lo mismo en todas las pantallas. `CONTINGENCIA` es ámbar y no rojo: esa factura ya es válida. |
| `App\Services\Panel\RequisitosEtapa` | Qué le falta a cada cliente para pasar a la etapa siguiente, y el progreso del piloto. Alimenta listado, stepper, ficha, checklist y panel del piloto. |

Cinco componentes Blade en `resources/views/components/`: `badge`, `estado-empresa`,
`estado-factura`, `semaforo`, `stepper`. Cada patrón repetido existe una sola vez.

**Ficha del cliente**, todo en una pantalla: callout con el siguiente paso concreto,
stepper del ciclo de vida, checklist automático de requisitos con el botón de avance
deshabilitado hasta completarlo, semáforos de certificado (avisa 30 días antes), token, y
CUIS / CUFD / CAFC por punto de venta (el CUFD avisa con 2 h, igual que el cron).

**Alta guiada** sin asistente aparte: la ficha habilita cada acción cuando corresponde.
Sin CUIS, los botones de CUFD y CAFC quedan deshabilitados explicando por qué; sin token
y certificado no se entra al piloto.

**Panel del piloto**: los 17 pasos con estado individual, botón por paso y "todos en
orden", respuesta cruda de cada uno, progreso X/17, editor del `payload_ejemplo` por paso,
y al completar los 17 se **ofrece** marcar `PILOTO_APROBADO`.

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

- El filtro anti-SSRF no cubre DNS rebinding (que la IP cambie entre la comprobación y la
  conexión real). Para eso habría que fijar la IP en el cliente HTTP.

**Decisión de producto [2026-08-09]: el sistema no va a tener roles ni permisos.**

Al panel entra un único administrador con usuario y contraseña (`admin@admin.com` /
`password` por defecto, a cambiar en producción). Los clientes no entran al panel: se
conectan solo por la API REST con su `X-Api-Key`, y ese aislamiento ya está resuelto y
probado. La ausencia de policies deja de ser deuda y pasa a ser una decisión: no hay un
segundo tipo de usuario al que limitar.

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

**[2026-08-09, revisión 2] Dos de los tres se cerraron** contrastando contra el proyecto
`ventas`, un sistema que factura en producción ante el SIN (modalidad computarizada,
sistema propio), y contra la solicitud de autorización R-1359 del SIN.

Ese contraste destapó **cinco defectos que no daban error en local**: la factura se emitía
igual y el SIN la habría rechazado. Ver §11.

Queda abierto solo el primero.

1. **XAdES-BES incompleto** — `app/Services/Factura/FirmadorXml.php`. La firma XML-DSig
   base funciona y es verificable localmente, pero falta el bloque
   `<Object><QualifyingProperties>` con `SignedProperties` (SigningTime +
   SigningCertificate) y su segunda `Reference`. El SIN rechaza toda factura sin esto.
   **Preparado:** el método `construirQualifyingProperties()` está escrito y aislado, pero
   **no está enchufado**, y lleva anotadas las cinco decisiones que no se pueden deducir
   (versión del namespace XAdES, SHA-1 vs SHA-256 del certificado, orden de los RDN del
   emisor, si hace falta `SignedDataObjectProperties`, patrón de los `Id`). Un bloque con
   la estructura equivocada hace rechazar *todas* las facturas: peor que la firma actual.
2. ~~**Módulo 11 del CUF sin verificar**~~ — **CERRADO.** Era la variante equivocada. Ver §11.
3. ~~**Nombres de operaciones y campos SOAP sin validar**~~ — **CERRADO en lo esencial.** Los
   nombres de operación y de campo coinciden con el sistema en producción; las cuatro
   rutas de WSDL coinciden con la solicitud de autorización. Sigue sin contrastarse el
   orden exacto del XSD para la modalidad **electrónica** (la referencia es computarizada)
   y la respuesta de las paramétricas. Para eso está el comando, que solo reporta:

   ```bash
   php artisan siat:inspeccionar-wsdl {empresa} --servicio=compra_venta --tipos
   ```

Falta todavía un `.p12` real para probar la firma de punta a punta.

### Dónde vive cada dato

La configuración está partida en dos según quién la cambia y cada cuánto:

| | Dónde | Por qué |
|---|---|---|
| **Del proveedor** — NIT, razón social, código de sistema, URLs por ambiente, timeouts | `.env` → `config('siat.proveedor')` | Se cargan una vez al desplegar y valen para todos los clientes. Un dedazo dejaría sin facturar a todos a la vez, así que el panel los muestra pero no los edita |
| **De cada cliente** — NIT, token delegado, certificado, ambiente, modalidad, código de sistema | Columnas de `empresas` | Cambian por contribuyente y los edita el operador desde la ficha |

El código de sistema aparece en las dos filas a propósito: el alta de un cliente lo
precarga con el del proveedor, pero cada empresa guarda el suyo. Si el SIN emite uno
propio por cada contribuyente asociado, se pisa desde el panel y la configuración global
no se toca. Mientras eso no esté confirmado, el sistema funciona de las dos maneras.

### Datos del proveedor (solicitud R-1359, 09/08/2026)

| | |
|---|---|
| NIT | 7633685015 |
| Razón social | MOLINA GUZMAN IGNACIO |
| Código de sistema | 22848EC6F66C9401C16F7 |
| Sistema | SolucionDigital-api · **tipo PROVEEDOR** |
| Modalidad | Electrónica en línea (código 1) |
| Ambientes | 1 = Producción · 2 = Pruebas |

Funcionalidades registradas ante el SIN: creación de punto de venta, descuento global,
descuento en detalle, pago con gift card, emisión fuera de línea, códigos especiales,
manuales de contingencia, registro de compras, y todos los catálogos de unidad de medida,
formas de pago, tipos de moneda y documentos de identidad.

### Deuda técnica

| # | Estado |
|---|---|
| 4. Sin autorización por rol en el panel | **Descartado [2026-08-09]** — decisión de producto: un solo administrador, sin roles (ver §8) |
| 5. `EmisorFactura` no filtraba el punto de venta por `activo` | **Cerrado** — filtra, con test |
| 6. `facturas.cuf` sin índice único | **Cerrado** — migración nueva con `UNIQUE`, con test |
| 7. Catálogos, Pruebas y `CodigoController` instanciaban SOAP a mano | **Cerrado** — todo por `FabricaServicios`, con los tests que faltaban |
| 8. Códigos `901` / `902` / `908` en `VerificarEstadoFactura` | **Nombrado, sin resolver** — pasaron a constantes con su duda documentada; el comportamiento es idéntico hasta confirmarlos contra el manual |
| 9. `EnviarPaqueteContingencia` con `release(300)` fijo | **Cerrado** — respeta su `backoff`; agotados los intentos el paquete queda `PENDIENTE` y la falla va a `failed_jobs` |

Nuevo, encontrado al escribir el test de concurrencia: **las migraciones no corrían contra
MySQL** (índice de 70 caracteres en `productos_servicios`). Cerrado.

---

## 10. Cobertura de tests — 144 verdes

| Archivo | Qué cubre |
|---|---|
| `SiatFase1Test` | modelos, cifrado del token, vigencia de CUFD, URLs del WSDL |
| `AuthTest` | login, logout, protección del panel |
| `AdminPanelTest` | render de las vistas, alta de empresa, validaciones |
| `CodigosPanelTest` | carga manual y solicitud al SIAT de CUIS/CUFD/CAFC |
| `ApiFacturaTest` | emisión, idempotencia, 401/403/422, endpoint de estado |
| `ApiAnulacionTest` | anulación, estados no anulables, aislamiento entre empresas |
| `ContingenciaTest` | evento único por racha, congelado del paquete, armado |
| `JobsSiatTest` | reintentos vs contingencia, códigos del SIN, backoff del paquete |
| `WebhookTest` | filtro SSRF (6 casos), firma HMAC |
| `SeguridadApiTest` | rate limit, invalidación de caché, rotación de key, throttle |
| `MantenimientoTest` | purga de logs |
| `FacturaServiciosTest` | CUF, totales, validador, firma XML |
| **`IntegridadEmisionTest`** | CUF duplicado rechazado por la base, punto de venta dado de baja |
| **`CatalogosSincronizacionTest`** | sincronizadores global y por empresa, respuesta de un solo elemento, resincronización sin duplicar |
| **`EjecutorPruebasTest`** | los 17 pasos del piloto: códigos que se guardan, emisión, anulación, contingencia, y que el paso 17 no cambia el estado solo |
| **`PanelClientesTest`** | filtro y buscador, checklist de requisitos, avance de etapa, progreso del piloto, semáforos, carga de payload |
| **`CufModulo11Test`** | dígito verificador con casos calculados a mano, ciclo de pesos y forma del CUF |
| **`ContratoSiatTest`** | los detalles del contrato con el SIN que ningún test de negocio detecta: `xsi:nil`, orden de la cabecera, hash del gzip, CUIS en la recepción, y qué significa cada código de estado |
| **`CorrelativoConcurrenciaTest`** | grupo `mysql`: 4 emisiones concurrentes reales sobre el mismo punto de venta |

**El hueco de cobertura de la deuda #7 quedó cerrado.**

Lo único que sigue sin poder probarse de verdad es la **firma XAdES-BES**: el sistema de
referencia es computarizado y esa modalidad no firma. Hace falta un XML firmado de
ejemplo del SIN y un `.p12` real.

---

## 11. [2026-08-09] Contraste contra un sistema en producción

Se contrastó la capa SIAT contra el proyecto `ventas` (sistema propio, modalidad
computarizada, facturando en producción ante el SIN) y contra la solicitud de
autorización R-1359. El algoritmo del CUF, los nombres de operación, los campos de
cada solicitud y el formato del XML son comunes a ambas modalidades; lo que cambia es
que la electrónica va firmada y usa otro documento raíz.

**Cinco defectos encontrados. Ninguno daba error en local:** la factura se emitía, se
guardaba y se encolaba igual, y el rechazo habría llegado del SIN.

| # | Qué estaba mal | Consecuencia | Archivo |
|---|---|---|---|
| 1 | Token en `Authorization: Token {token}` | El SIN usa su propia cabecera `apikey: TokenApi {token}`. **Ninguna** operación SOAP habría autenticado | `SiatClient` |
| 2 | Dígito verificador `11 - (suma % 11)` | Es la variante de otros países. El SIN usa el **resto**: `suma % 11`, con 10 → 1 y 11 → 0. Todos los CUF salían mal | `GeneradorCuf` |
| 3 | `tipoFactura` ocupaba 2 dígitos | La cadena del CUF son **53** dígitos, no 54. Un cero de más corre todo y cambia verificador y hexadecimal | `GeneradorCuf` |
| 4 | `hashArchivo` calculado sobre el XML plano | El SIN compara el hash del **gzip**. Rechazo por integridad | `ServicioFacturacion` |
| 5 | Los campos vacíos se omitían del XML | El XSD declara una secuencia: faltar un elemento corre a los demás. Deben ir presentes con `xsi:nil="true"` | `ConstructorXml` |

**Y un error de interpretación con consecuencia para el cliente:** el código `902` se
daba por *validada*. Es **PENDIENTE**. Se le confirmaba al cliente —y se le disparaba el
webhook `factura.validada`— por un documento que el SIN todavía no había aceptado. Ahora
`902` reintenta sin tocar el estado; validan `908` y `690`, y cualquier otro código deja
la factura `OBSERVADA`.

Se agregó además lo que faltaba en la solicitud: el `cuis` viaja también en
`recepcionFactura`, junto con `tipoFacturaDocumento`, y el `SoapClient` negocia gzip.

Todo esto quedó fijado en `tests/Feature/ContratoSiatTest.php`, que vive aparte
justamente porque son detalles que ningún test de negocio detecta.

**Lo que el contraste NO resuelve:** el sistema de referencia es *computarizado*, y esa
modalidad no lleva firma digital. La firma XAdES-BES sigue siendo el único bloqueante
real, y sigue necesitando un XML firmado de ejemplo del SIN.

---

## 12. [2026-08-10] Segunda revisión: el camino feliz que nunca se conectó

La revisión anterior cerró los defectos del **contrato** con el SIN (cabecera del
token, módulo 11, hash del gzip, `xsi:nil`, códigos de estado). Esta encontró algo
distinto: tres piezas del camino normal de una factura que estaban escritas pero
**no cableadas**. Ninguna daba error en local, y las tres terminaban en rechazo del
SIN o en una factura que no llegaba nunca.

### Bloqueantes cerrados

| # | Qué pasaba | Por qué no se notaba | Archivos |
|---|---|---|---|
| 1 | **La contingencia era de ida y no de vuelta.** `GestorContingencia::recuperar()` y `EnviarPaqueteContingencia` solo se invocaban desde el paso 16 del piloto. En operación normal nada cerraba el evento ni armaba el paquete | La factura quedaba `CONTINGENCIA`, que es un estado válido: se podía imprimir y consultar. Solo el SIN no la veía, para siempre | `SiatRecuperarContingencia` (nuevo), `routes/console.php` |
| 2 | **El rechazo del SIN se leía como aceptación.** El SIN no usa `SoapFault` para rechazar un documento: responde 200 con `transaccion=false` y el motivo en `mensajesList`. Solo se leía `codigoRecepcion` | La factura se marcaba enviada con el código de recepción vacío y el motivo se descartaba | `RespuestaSiat` (nuevo), `EnviarFacturaAlSiat`, `AnularFacturaEnSiat`, `EnviarPaqueteContingencia` |
| 3 | **`actividadEconomica` y `leyenda` viajaban siempre vacíos.** El XSD los exige; las tablas `productos_servicios` y `leyendas_factura` se sincronizaban y no las usaba nadie. `leyenda` ni siquiera tenía regla en el FormRequest, así que la clave nunca llegaba | Con el arreglo de `xsi:nil` iban presentes pero nulos: el XML era válido en forma y el SIN lo habría rechazado por contenido | `ResolutorActividad` (nuevo), `EmisorFactura`, `ConstructorXml`, `EmitirFacturaRequest` |

La actividad se deduce del producto homologado y **se guarda en el ítem**, no se
resuelve al armar el XML: la factura es un documento congelado, y si el SIN
reasigna mañana ese producto a otra actividad, lo ya emitido no puede cambiar. Un
producto ausente del catálogo corta la emisión con 422 — pero solo si la empresa
ya sincronizó, porque con el catálogo vacío bloquear dejaría el sistema inusable.

### Otros defectos cerrados

| Qué | Ahora |
|---|---|
| Se emitía **sin firmar** si la empresa no tenía certificado activo, y la API respondía 201 | Corta con `FacturaInvalidaException`: la modalidad electrónica no admite un documento sin firma |
| El cron de 5 min re-despachaba facturas `PENDIENTE` con un job todavía en vuelo | Solo toma las que llevan >10 min (el job agota sus 3 intentos en ~7,5). Igual el de verificación, con 20 min |
| La anulación se marcaba en local y **nunca revertía** si el SIN la rechazaba: quedaba `ANULADA` acá y vigente allá | Se guarda `estado_anterior`; un rechazo devuelve la factura ahí y registra el motivo. `facturas_anuladas` lleva `estado` (PENDIENTE/CONFIRMADA/RECHAZADA) |
| `release()` fijo contradiciendo el `backoff` declarado en `AnularFacturaEnSiat` y `VerificarEstadoFactura` (la deuda #9 se había cerrado solo en el job del paquete) | Los tres respetan su backoff |
| `facturas` sin índice por `estado`: los dos crons escaneaban la tabla entera cada 5 y 15 min | Índice `(estado, id)` |
| El `token_delegado` descifrado se imprimía en el HTML del formulario | Campo `password` que nunca se rellena; vacío significa "no lo toques" |
| Borrar una empresa cascadeaba a sus facturas, que son documentos fiscales | Se rechaza si tiene facturas emitidas; para dejar de atenderla está `OBSERVADO` |
| `.env.example` no listaba `SIAT_WEBHOOK_SECRET` ni las URLs, timeouts, rate limit y retención. Sin secreto los webhooks salen **sin firmar y sin avisar** | Todas documentadas |
| `tipo_factura` a mano en el cálculo del CUF existiendo la constante en config | Sale de `config('siat.codigos.tipo_factura_documento')` |

### Cobertura nueva — 173 verdes

| Archivo | Qué fija |
|---|---|
| `RechazoSiatTest` | `transaccion=false` es rechazo aunque haya código de recepción; una factura rechazada queda `OBSERVADA` y **no** va a contingencia; la anulación rechazada revierte; el paquete rechazado no libera sus facturas |
| `ActividadLeyendaTest` | actividad y leyenda salen del catálogo del NIT y llegan al XML con valor; la leyenda del cliente gana; producto no homologado → 422; sin certificado no se emite; la factura emitida queda firmada |
| `RecuperacionContingenciaTest` | el evento se cierra y el paquete se despacha solo cuando el SIAT responde **y** el SIN acepta el registro del evento |
| `SeguridadPanelTest` | el token no vuelve al navegador ni se borra al guardar vacío; no se elimina un cliente con facturas |
| `MantenimientoTest` (ampliado) | el cron no re-despacha una factura con job en vuelo |
| `CertificadoFactory::firmable()` | `.p12` autofirmado real, cacheado por corrida: la emisión ahora exige un certificado que se pueda abrir de verdad |

### Lo que sigue abierto

**La firma XAdES-BES sigue siendo el único bloqueante real**, y por la misma razón
de siempre: hace falta un XML firmado de ejemplo del SIN para confirmar las cinco
decisiones anotadas en `FirmadorXml::construirQualifyingProperties()`. Sigue sin
enchufarse a propósito.

Tampoco se contrastó todavía el orden exacto del XSD de la modalidad **electrónica**
(la referencia disponible es computarizada) ni la forma real de `mensajesList`, que
`RespuestaSiat` tolera en sus dos variantes (objeto suelto y arreglo) justamente
porque no está confirmada.
