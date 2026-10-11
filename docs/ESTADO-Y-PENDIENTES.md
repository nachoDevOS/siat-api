# Estado actual y pendientes

> Foto del sistema al **2026-10-11**. Actualizar este archivo con cada avance.

---

## 1. Resumen

| Área | Estado |
|---|---|
| Emisión sector 1 en línea | ✅ Completa; falta confirmar la firma con el `.p12` real (Etapa IV) |
| API REST v1 + webhooks | ✅ Plan de API completo (Sanctum, códigos automáticos, endpoints, OpenAPI) |
| Panel `/admin` | ✅ |
| Piloto | I, II, III, V hechas · IV, VIII esperan el `.p12` · VI espera el CAFC · VII y XI dependen de IV |
| Contingencia en producción | ⚠️ Funciona en el piloto; el flujo automático de producción tiene defectos (abajo) |
| Tests | 243 verdes (`php artisan test --compact`); núcleo (CUF, XML, firma, hash, respuestas del SIN) en `NucleoFacturaTest` |

---

## 2. Bloqueantes de producción

| # | Problema | Dónde | Plan |
|---|---|---|---|
| B1 | **Firma XAdES-BES** sin confirmar. Hoy se firma XML-DSig simple | `FirmadorXml` | Probar en Etapa IV con el `.p12` real; si el SIN rechaza, enchufar `construirQualifyingProperties()` |
| B2 | **Passphrase del `.p12`** desconocida (`~/Escritorio/certificado-prueba.p12`) | — | Conseguirla con la entidad certificadora |
| B3 | **Contingencia — tipo de emisión mezclado.** Las facturas derivadas a contingencia conservan un CUF de emisión 1 (en línea) y el paquete viaja con `codigoEmision = 2`; el SIN recalcula el CUF y rechazaría el paquete entero. También nacen en línea las facturas emitidas durante los ~7,5 min de reintentos antes de abrir el evento | `GestorContingencia::derivar`, `EnviarPaqueteContingencia` | Reenviar las derivadas por `recepcionFactura` cuando vuelva el SIAT y usar paquete solo para las de emisión 2. Se hace con la Etapa VI |
| B4 | **Contingencia — fecha del evento.** `fecha_inicio` es el momento en que se abre el evento, posterior a la `fecha_emision` de las facturas que lo causaron | `GestorContingencia::eventoAbierto` | Fijarla en la emisión de la primera factura que falló |
| B5 | **Contingencia — CUFD vencido bloquea la venta.** Si el CUFD vence durante una caída, `EmisorFactura` responde 503 aunque haya evento abierto | `EmisorFactura::emitir` | En contingencia, emitir con el CUFD del evento |
| B6 | **Paquete sin tope.** El SIN admite hasta 500 facturas por paquete | `GestorContingencia::recuperar` | Partir en paquetes de 500 |
| B7 | **CAFC** eliminado del esquema; hace falta para los motivos 5–7 | migración `2026_09_14_...` | Volver a incorporarlo cuando el SIN lo entregue (Etapa VI) |

## 3. Plan de la API como servicio

Los sistemas externos **solo venden**: esta API arma, firma y envía, y maneja CUIS/CUFD sola.

| Fase | Contenido | Estado |
|---|---|---|
| 1 | Sanctum: tokens por empresa con permisos, vencimiento, IPs, revocación; `logs_api` | ✅ 2026-10-11 |
| 2 | Códigos 100 % automáticos: CUFD en el momento al emitir sin CUFD vigente, renovación automática de CUIS; `GET .../codigos` (solo vigencias) y `POST .../cufd` de emergencia (`codigos:renovar`) | ✅ 2026-10-11 |
| 3 | Endpoints: `GET /sucursales`, `GET /nit/{nit}/verificar`, `GET /catalogos/productos` y `/leyendas` | ✅ 2026-10-11 |
| 4 | `docs/integracion/openapi.yaml` para Postman y API.md final; test que impide que la especificación se desincronice de las rutas | ✅ 2026-10-11 |

## 4. Otros pendientes

| # | Problema | Dónde |
|---|---|---|
| P3 | **URL de producción del SIAT** (servicios y consulta del QR) sin verificar | `config/siat.php` |
| P4 | **Orden de campos del XML** sin contrastar contra el XSD oficial | `ConstructorXml` |
| P5 | `validacionRecepcionPaqueteFactura` solo se usa en el piloto; en producción las facturas del paquete quedan `ENVIADA` hasta la verificación individual | `EnviarPaqueteContingencia` |
| P6 | Moneda extranjera no soportada (solo Bs) | `EmitirFacturaRequest` |
| P8 | Los tests de concurrencia contra MySQL (grupo `mysql`) se borraron con la suite Feature antigua (`d5bc968`); recuperables desde `7ba2acd` | `tests/` |
| P9 | El código de evento de contingencia automática es fijo en 1 (corte de internet) | `GestorContingencia` |

---

## 5. Historial de cambios

### 2026-10-11 — Piloto: cerrar operaciones en un clic

- **Cerrar operaciones y pedir CUIS nuevo** (Etapa I) cierra y **repite la prueba** en el mismo
  clic; antes había que volver a ejecutarla a mano.
- Un CUIS nuevo obtenido en el piloto también renueva el CUFD del punto de venta local (si no,
  las facturas de la etapa IV volvían con 913).
- Los errores del piloto (bloqueos, fallos, rechazos del SIN) se muestran en rojo; antes iban en
  verde como si fueran éxitos.

### 2026-10-11 — Modal de confirmación global

Un solo modal para todo el panel (en `layouts/admin.blade.php`) reemplaza al `confirm()` del
navegador. Cualquier formulario lo usa con atributos: `data-confirmar` (mensaje),
`data-titulo`, `data-boton` y `data-peligro` (botón rojo para lo irreversible). Lo usan eliminar
empresa, registrar punto de venta en el SIN, generar certificado de prueba, revocar y regenerar
token, limpiar el conteo del piloto y cerrar operaciones. Un test impide volver a usar
`confirm()`. El diálogo de *Solicitar CUIS* (componente `x-dialogo-cuis`) usa el mismo estilo.

### 2026-10-11 — Diálogo de "Solicitar CUIS"

El botón abre un diálogo donde el operador decide: **Revisar CUIS** (el SIN devuelve el mismo
mientras siga vigente) o **Forzar CUIS nuevo** (`GestorCodigos::forzarCuisNuevo`: cierre de
operaciones con el CUIS que el SIN reconoce, CUIS nuevo y su CUFD), con aviso de las facturas
PENDIENTES del punto de venta. Las acciones de códigos vuelven a la pantalla desde donde se hizo
clic y los errores se muestran en rojo (antes iban en verde, como si fueran éxitos).

### 2026-10-11 — CUIS nuevo renueva el CUFD · error del SIN cacheado en la ficha

| Tema | Antes | Ahora |
|---|---|---|
| CUIS nuevo | El botón *Solicitar CUIS* y la renovación diaria no tocaban el CUFD, que quedaba atado al CUIS anterior (913 cuando ese CUIS deja de valer) | Si el SIN entrega un CUIS nuevo se pide un CUFD atado a él; si devuelve el mismo (980), nada (`GestorCodigos::renovarCuis`) |
| Lista de puntos de venta del SIN en la ficha | Un error (ej. `[913] CUIS INVALIDO`) quedaba cacheado 30 min aunque ya estuviera resuelto | Solo se cachean las respuestas buenas |

### 2026-10-11 (decisión) — Un token por empresa

Decisión del proveedor: cada empresa tiene **un solo token**, que su sistema de ventas usa para
todas sus sucursales y puntos de venta (cada venta indica dónde se emite). En *Acceso API*:
**Generar token** / **Regenerar token** (revoca el anterior en la misma transacción) / Revocar.
Perfil por defecto: *Administración*. Las empresas con varios tokens de antes ven un aviso para
dejar uno solo.

### 2026-10-11 (orden del panel) — Pantalla "Acceso API" por empresa

Los tokens, el webhook y su secreto estaban mezclados en la ficha de la empresa con el
certificado, las sucursales, los catálogos y el piloto. Ahora tienen pantalla propia
(Empresa → **Acceso API**) en cuatro bloques: datos de conexión para el cliente, tokens, webhook
y últimas llamadas. La URL del webhook se edita ahí (salió del formulario "Editar empresa") y la
ficha muestra solo un resumen con el botón. Tests en `TokensApiTest`.

### 2026-10-11 (limpieza) — Código sin uso eliminado

Rastreo automático de cada función, constante, import, vista y clave de configuración contra todo
el proyecto (código, vistas, rutas, configuración y tests). Eliminado lo que nadie usaba:

| Qué | Dónde |
|---|---|
| `cerrarPuntoVenta()` y `consultarEvento()` (operaciones SOAP nunca llamadas) | `ServicioOperaciones` |
| `listaMensajes()` (el piloto pide ese catálogo por `parametrica()`) | `ServicioSincronizacion` |
| `esAceptable()` | `DestinoWebhook` |
| `esValida()` | `ValidadorFactura` |
| Constante `FASE_SISTEMA` | `CasoPrueba` |
| Import sin uso de `Empresa` | `SincronizadorEmpresa` |
| Vista `welcome.blade.php` (la raíz redirige al panel) | `resources/views` |
| Claves `siat.codigos.casa_matriz` y `siat.codigos.modalidad.computarizada` | `config/siat.php` |

Segunda pasada, con aprobación:

| Qué | Por qué |
|---|---|
| Frontend Vite/Tailwind (`package.json`, `package-lock.json`, `vite.config.js`, `.npmrc`, `resources/css`, `resources/js`, la *skill* de Tailwind) | Solo lo usaba `welcome`; el panel tiene CSS propio. El proyecto ya no necesita Node. Se quitó el script `composer dev` (usaba `npm`); los procesos se levantan como indica el README |
| `tests/Unit/ExampleTest.php` y la suite `Unit` de `phpunit.xml` | Test de ejemplo que no probaba nada |
| Propiedades `xmlEnviado` / `xmlRecibido` de `SiatException` | Nadie las leía: el XML de cada llamada ya queda en `logs_siat` |
| Columna `facturas.anticipo` (migración `eliminar_anticipo_de_facturas`) | Siempre valía 0: el sector 1 no admite anticipo |

Un rastreo final no encuentra funciones, constantes, imports ni vistas sin uso.

Conectado en vez de borrado: `siat.ambiente_por_defecto` (`SIAT_AMBIENTE`) y
`siat.codigos.modalidad.electronica` ahora los usa el formulario de empresas, que tenía esos
valores escritos a mano. Si alguna operación eliminada hace falta (por ejemplo cerrar un punto de
venta desde el panel), se recupera del historial de git.

### 2026-10-11 (revisión final, parte 2) — PDF, panel de facturas y operación

| Tema | Antes | Ahora |
|---|---|---|
| PDF | Sin monto en literal ni la leyenda de documento digital; sin datos de sucursal ni unidad de medida | Representación gráfica completa: "FACTURA (Con Derecho a Crédito Fiscal)", casa matriz / sucursal, dirección, teléfono, municipio, punto de venta, unidad de medida, subtotal / descuento / total / gift card / monto a pagar / importe base crédito fiscal, **"Son: … 00/100 BOLIVIANOS"**, leyendas y QR |
| Panel de facturas | No existía | Listado con filtros, detalle con motivo de observación, descarga de PDF/XML, enviar y verificar a mano |
| `GET /facturas` | Solo filtro de fechas | + `estado`, `sucursal`/`punto_venta`, `referencia_externa` |
| Token delegado | Su vencimiento no se veía | Se lee del JWT; ficha con semáforo; checklist; aviso diario |
| Alertas diarias | Solo en la consola (se perdían) | También al log |
| Límite de peticiones | Solo por empresa | Por token (60/min) y por empresa (120/min) |
| `logs_api` | Solo por SQL | Tabla en el Monitor, filtrable |
| Proxies | Solo documentado | `TRUSTED_PROXIES` |
| Código muerto | `FacturaObservadaException` y su `catch` | Eliminados |

Tests en `PanelYOperacionTest`.

### 2026-10-11 (revisión final) — Motivo de observación, colas y certificado vencido

| Tema | Antes | Ahora |
|---|---|---|
| Motivo de una factura OBSERVADA | Solo en el log del servidor | Columna `motivo_observacion`, expuesto en la API (`GET /facturas/{cuf}`) y en el webhook (`motivo`) |
| Colas | Una sola: el piloto atrasaba las facturas reales | `facturas` > `default` > `piloto`; el worker debe correr con `--queue=facturas,default,piloto` |
| Certificado vencido | Se firmaba igual y el SIN rechazaría todo | La emisión se corta con `422` antes de reservar número |

Migración: `agregar_motivo_observacion_a_facturas`.

Pendiente: correo al comprador y alertas por correo (cuando esté el servicio de correo).

### 2026-10-11 (cierre) — Fases 2, 3 y 4 del plan de API

| Tema | Antes | Ahora |
|---|---|---|
| CUFD vencido al emitir | `503` aunque el SIN estuviera disponible | Se pide en el momento, con lock por punto de venta; `503` solo si el SIN falla (60 s sin reintentar) o hay contingencia |
| CUIS vencido | `siat:revisar-codigos` solo avisaba; `RenovarCufd` se saltaba el punto de venta | Se pide solo (diario y al renovar CUFD) |
| `RenovarCufd` | Corría también sobre puntos de venta no registrados y empresas sin token, fallando cada hora | Los ignora |
| Códigos por API | — | `GET .../codigos` (solo vigencias, nunca códigos) y `POST .../cufd` (emergencia, 3/hora) |
| Endpoints nuevos | — | `GET /sucursales`, `GET /nit/{nit}/verificar` (con caché de 1 h), `catalogos/productos`, `catalogos/leyendas` |
| `GET /estado` | `puede_facturar` exigía CUFD vigente | Exige punto de venta registrado; suma `puntos_venta_registrados_en_siat` |
| OpenAPI | — | `docs/integracion/openapi.yaml`; un test compara rutas y permisos con la especificación |

Tests en `ApiServicioTest`.

### 2026-10-11 (noche) — Sanctum (fase 1 del plan de API)

| Tema | Antes | Ahora |
|---|---|---|
| Autenticación | Una API key por empresa en `X-Api-Key` | Tokens Bearer de Sanctum, dueño la `Empresa`, varios por empresa, prefijo `siat_` |
| Permisos | Cualquier clave podía todo | Cada ruta exige un permiso; perfiles *Caja / POS*, *Administración*, *Solo consulta* |
| Vencimiento, IPs, revocación | No existían | Por token, desde la ficha de la empresa |
| Registro de llamadas | No había | `logs_api` (incluye rechazos), purgado por `siat:purgar-logs` |
| Sesión del panel | — | No autentica la API (`sanctum.guard = []`) |

Migraciones: `create_personal_access_tokens_table` (con `ips_permitidas`), `create_logs_api_table`,
`eliminar_api_key_hash_de_empresas`. Nueva dependencia: `laravel/sanctum` 4.3. Tests en
`TokensApiTest`.

### 2026-10-11 — Auditoría completa y correcciones

Corregido (con tests en `EmisionSectorUnoTest` e `IntegracionSiatTest`):

| Tema | Antes | Ahora |
|---|---|---|
| Doble envío | `EnviarFacturaAlSiat` solo ignoraba las `VALIDADA`: un job duplicado reenviaba `RECIBIDA`/`OBSERVADA`/`ANULADA` o mandaba en línea una `CONTINGENCIA` | Solo actúa sobre `PENDIENTE` |
| Anticipo | Se restaba del sujeto a IVA; el sector 1 no tiene anticipo y el SIN observaba | Sujeto a IVA = total − gift card; la API rechaza anticipo > 0 |
| Montos negativos | Descuentos o gift card mayores al total pasaban | `ValidadorFactura` los rechaza antes de reservar número |
| Tarjeta | Se guardaba y firmaba completa | Enmascarada (4 + ceros + 4) |
| PDF | Una falla de dompdf impedía enviar la factura | Se loguea y se envía igual; la descarga genera el PDF si falta |
| Anulaciones colgadas | Con el SIAT caído terminaban en `failed_jobs` y nadie las retomaba | Quedan `PENDIENTE`, tarea `reintentar-anulaciones` cada 15 min, job único por factura |
| Moneda | `montoTotalMoneda` ignoraba el tipo de cambio | Solo se acepta bolivianos |
| `codigoExcepcion` | Siempre nil: una venta a un NIT inválido no tenía salida | Campo `codigo_excepcion` (0/1, solo con NIT); XML con 0 por defecto |
| Verificación | En el último intento hacía `release` y terminaba en `failed_jobs` | No se reencola; la retoma la tarea programada |
| Webhooks | Un solo secreto HMAC para todos los clientes | Secreto por empresa, visible en su ficha |
| DNS rebinding | Se validaba la IP pero se volvía a resolver al conectar | IP fijada con `CURLOPT_RESOLVE` |
| Timeout WSDL | La descarga del WSDL no tenía timeout de lectura | Usa `siat.timeout` |
| Validación inmediata | Aunque la recepción devolviera 908 se consultaba de nuevo | 908/690 en la recepción → `VALIDADA` directo |

Migración nueva: `2026_10_11_113416_agregar_codigo_excepcion_a_facturas`.

### 2026-10-11 (tarde) — Catálogos del NIT, QR y avisos del panel

| Tema | Antes | Ahora |
|---|---|---|
| Catálogos del NIT (ex P1) | Solo se sincronizaban desde el piloto; `SincronizarCatalogosEmpresa` nunca se despachaba | Tarea diaria `sincronizar-catalogos-empresas` (03:30) y botón **Sincronizar ahora** en la ficha con el conteo de cada catálogo |
| QR (ex P2) | Siempre `siat.impuestos.gob.bo` con `t=1` | URL según el ambiente de la empresa y `t=2`, configurables (`SIAT_QR_*`) |
| Avisos de error del panel | Los `->with('error', ...)` nunca se mostraban (borrar empresa con facturas, certificado inválido) | El layout los muestra en rojo |

Refactor: `Empresa::puntoVentaConCuisVigente()` reemplaza la búsqueda copiada en el job y en
`siat:sincronizar-globales`. Tests en `CatalogosNitYQrTest` (117 verdes en total).

### Anteriores

El detalle de las auditorías del 2026-08-09 y 2026-08-10 está en
[ANALISIS-SISTEMA.md](ANALISIS-SISTEMA.md) (documento histórico).
