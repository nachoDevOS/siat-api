# Documentación técnica — siat-api

> Para desarrolladores: cómo está construido el sistema, cómo habla con el SIN y cómo se guardan los
> datos. Operar el servidor: [INSTALACION-Y-DESPLIEGUE.md](INSTALACION-Y-DESPLIEGUE.md). Integrar un sistema de ventas:
> [integracion/API.md](integracion/API.md).

**Contenido:** [Arquitectura](#arquitectura) · [Contrato con el SIAT](#contrato-con-el-siat) · [Base de datos](#base-de-datos) · [Pendientes](#pendientes)

---

## Arquitectura

Cómo está construido el sistema y por qué.

---

### Qué es

`siat-api` es un **proveedor de facturación electrónica** ante el SIAT (Servicio de Impuestos
Nacionales de Bolivia). Atiende a **varios clientes** (ferreterías, restaurantes, etc.) desde
una sola instalación.

| Aspecto | Valor |
|---|---|
| Modalidad | Electrónica en línea (`codigoModalidad = 1`) |
| Documento | **Sector 1 — Factura Compra Venta** (`codigoDocumentoSector = 1`), con derecho a crédito fiscal (`tipoFacturaDocumento = 1`) |
| Ambientes | 1 = producción, 2 = piloto (cada empresa tiene el suyo) |
| Stack | PHP 8.3 · Laravel 13 · MySQL · colas en base de datos · Blade (panel) · Pest 4 |

El sistema de ventas del cliente **no habla SOAP ni firma nada**: manda un JSON a la API REST y
recibe el CUF al toque. Todo lo demás —CUIS, CUFD, CUF, XML, firma digital, envío, verificación,
anulación, contingencia, catálogos— lo resuelve este sistema.

```mermaid
flowchart LR
    POS[Sistema de ventas<br/>del cliente] -- JSON + Bearer token --> API[API REST v1]
    API --> EMI[EmisorFactura]
    EMI --> DB[(MySQL)]
    EMI --> Q[[Cola de jobs]]
    Q --> SOAP[Services/Siat<br/>SoapClient]
    SOAP -- SOAP + apikey TokenApi --> SIN[SIAT del SIN]
    Q -- webhook firmado --> POS
    ADMIN[Panel /admin] --> DB
    ADMIN --> SOAP
```

---

### Mapa de carpetas

| Carpeta | Qué contiene |
|---|---|
| `app/Http/Controllers/Api/V1` | API REST para el sistema de ventas: facturas, puntos de venta, catálogos, estado |
| `app/Http/Controllers/Admin` | Panel `/admin`: empresas, certificados, sucursales, puntos de venta, códigos, piloto, monitor |
| `app/Http/Middleware` | `siat.registro`, `siat.token`, `siat.permiso`, `siat.rate`, `siat.produccion`, `siat.idempotencia` |
| `app/Http/Requests` | Validación de forma del JSON (`EmitirFacturaRequest`, `AnularFacturaRequest`) |
| `app/Services/Factura` | Emisión: validación, totales, CUF, XML, firma, PDF, QR |
| `app/Services/Siat` | **Única frontera SOAP**: cliente, servicios por WSDL, gestor de CUIS/CUFD, lectura de respuestas |
| `app/Services/Contingencia` | Eventos significativos y armado de paquetes |
| `app/Services/Catalogos` | Sincronización de catálogos globales y por NIT |
| `app/Services/Pruebas` | Ejecutor del piloto ante el SIN |
| `app/Services/Panel` | Lógica de presentación del panel (colores de estado, checklist de etapa) |
| `app/Services/Webhooks` | Validación del destino de los webhooks (anti-SSRF) |
| `app/Jobs` | Trabajo en segundo plano (envío, verificación, anulación, paquetes, webhooks, CUFD, piloto) |
| `app/Console/Commands` | Comandos `siat:*` (ver [INSTALACION-Y-DESPLIEGUE.md](INSTALACION-Y-DESPLIEGUE.md#5-comandos-siat)) |
| `app/Http/Controllers/FacturaPublicaController.php` | Factura pública con enlace firmado (`Factura::urlPublica`): página, PDF carta y ticket 80 mm |
| `app/Http/Controllers/DocumentacionApiController.php` | Documentación pública `/docs/api` (Redoc + `openapi.yaml` con el servidor real) |
| `routes/console.php` | Tareas programadas |
| `config/siat.php` | Toda la configuración propia del dominio |

---

### Reglas de diseño

1. **Nada de SOAP fuera de `app/Services/Siat/`.** Nadie más construye un `SoapClient`. Jobs,
   comandos y controladores piden los servicios a `FabricaServicios`, que se resuelve del
   contenedor y en los tests se reemplaza con Mockery.
2. **La venta nunca espera al SIN.** La emisión es síncrona hasta tener el XML firmado (~150 ms)
   y el viaje al SIN es asíncrono.
3. **Multi-empresa por `empresa_id`.** Cada consulta de la API filtra por la empresa autenticada;
   cada empresa tiene su token, su certificado, su ambiente y sus catálogos.
4. **El documento firmado es inmutable.** Actividad económica, leyenda, código de excepción y
   montos se resuelven al emitir y quedan guardados: un cambio posterior de catálogos no altera
   una factura ya emitida.
5. **El SIN rechaza sin SoapFault.** Responde HTTP 200 con `transaccion = false`; por eso toda
   respuesta pasa por `RespuestaSiat`, que distingue aceptación de rechazo y extrae el motivo.
6. **Sin roles.** Un solo administrador del panel (decisión de producto).

---

### Flujo de emisión

#### Parte síncrona (`POST /api/v1/facturas`)

```mermaid
sequenceDiagram
    participant POS as Sistema de ventas
    participant MW as Middleware
    participant E as EmisorFactura
    participant DB as MySQL
    participant Q as Cola
    POS->>MW: JSON + Authorization Bearer + X-Idempotency-Key
    MW->>MW: logs_api · token → empresa (IP) · rate limit · permiso · ¿PRODUCCION? · idempotencia
    MW->>E: venta validada (EmitirFacturaRequest)
    E->>DB: ¿ya existe esa referencia_externa? → devolverla
    E->>E: ValidadorFactura (reglas de negocio)
    E->>DB: punto de venta activo y conocido por el SIN · CUFD vigente
    E->>DB: actividad por producto (catálogo del NIT)
    E->>E: CalculadorTotales
    E->>DB: BEGIN · lockForUpdate(punto_venta) · reservar número
    E->>E: ¿evento de contingencia abierto? → tipo de emisión
    E->>E: GeneradorCuf · crear factura + items
    E->>E: ConstructorXml · FirmadorXml (certificado activo)
    E->>DB: guardar xml_firmado · COMMIT
    E-->>Q: EnviarFacturaAlSiat (afterCommit)
    E-->>POS: 201 con CUF (202 si nació en contingencia)
```

Detalles clave (todos en `app/Services/Factura/EmisorFactura.php`):

- **Correlativo sin huecos ni duplicados.** El número se reserva dentro de la transacción con
  bloqueo de fila del punto de venta. Si algo falla después (por ejemplo, la firma), el rollback
  devuelve el número.
- **Fecha sin milisegundos.** `fecha_emision` se guarda en segundos y el CUF lleva la fecha con
  milisegundos: se usa `now()->startOfSecond()` para que el CUF y `<fechaEmision>` coincidan.
- **Tipo de emisión antes del CUF.** El tipo de emisión es uno de los nueve campos del CUF; se
  decide (en línea o contingencia) antes de calcularlo.
- **Certificado.** Sin certificado activo no se emite (la modalidad electrónica exige firma). Un
  certificado **vencido** tampoco firma (el SIN rechazaría todo), y un autofirmado de prueba no
  puede firmar en ambiente de producción.
- **Tarjeta.** `numero_tarjeta` se enmascara antes de guardar (4 primeros + ceros + 4 últimos).
- **Idempotencia.** Índice único `(empresa_id, referencia_externa)`; si dos peticiones iguales
  corren en paralelo, la que pierde la carrera devuelve la factura de la que ganó.

#### Parte asíncrona

```mermaid
flowchart TD
    A[EnviarFacturaAlSiat] -->|solo si PENDIENTE| B[PDF: si falla se loguea y sigue]
    B --> C{recepcionFactura}
    C -->|transaccion=false| O[OBSERVADA + webhook factura.observada]
    C -->|908 / 690| V[VALIDADA + webhook factura.validada]
    C -->|otro código| R[RECIBIDA]
    R --> D[VerificarEstadoFactura +15 s]
    D -->|902 pendiente| D
    D -->|908 / 690| V
    D -->|otro| O
    C -->|SiatException| E{¿quedan intentos?}
    E -->|sí, backoff 30/120/300 s| A
    E -->|no| F[GestorContingencia::derivar → CONTINGENCIA]
```

---

### Máquina de estados de la factura

| Estado | Significado | Sale hacia |
|---|---|---|
| `PENDIENTE` | Emitida y firmada; todavía no viajó | `RECIBIDA`, `VALIDADA`, `OBSERVADA`, `CONTINGENCIA` |
| `RECIBIDA` | El SIN acusó recibo individual, sin validar aún | `VALIDADA`, `OBSERVADA`, `ANULADA` |
| `ENVIADA` | Viajó dentro de un paquete de contingencia | `VALIDADA`, `OBSERVADA`, `ANULADA` |
| `VALIDADA` | El SIN la aceptó (908/690) | `ANULADA` |
| `OBSERVADA` | El SIN la rechazó; no se reintenta (el documento no cambia) | — |
| `CONTINGENCIA` | Válida e imprimible, pendiente de viajar en un paquete | `ENVIADA` |
| `ANULADA` | Anulación registrada (ver `facturas_anuladas.estado`) | vuelve al estado anterior si el SIN rechaza la anulación |

Anulación (`facturas_anuladas.estado`): `PENDIENTE` → `CONFIRMADA` | `RECHAZADA` (la factura vuelve
a su `estado_anterior`) | `REVERTIDA` (reversión hecha en el piloto).

---

### Trabajo asíncrono

#### Jobs

Van en tres colas que el worker atiende por prioridad (`--queue=facturas,default,piloto`):
`facturas` (envío, verificación, anulación, paquetes), `default` (webhooks, CUFD, catálogos) y
`piloto` (pruebas). Así una etapa del piloto de un cliente nuevo no atrasa la facturación de los
demás. La cola se declara con el atributo `#[Queue('...')]` en cada job.

| Job | Disparado por | Intentos / backoff | Qué hace |
|---|---|---|---|
| `EnviarFacturaAlSiat` | Emisión (afterCommit), tarea `reintentar-pendientes` | 3 · 30/120/300 s | Genera PDF y llama `recepcionFactura`. Solo actúa sobre facturas `PENDIENTE`. Agotados los intentos, deriva a contingencia |
| `VerificarEstadoFactura` | `EnviarFacturaAlSiat`, tarea `verificar-enviadas` | 5 · 60/120/300/600 s | Llama `verificacionEstadoFactura`. 902 = reintenta. En el último intento no se reencola: la retoma la tarea programada |
| `AnularFacturaEnSiat` | `POST .../anular`, tarea `reintentar-anulaciones` | 5 · 30/120/300/900 s | Llama `anulacionFactura`. **Único por factura** (`ShouldBeUnique`, 1 h). Si el SIN rechaza, revierte la factura. Agotados los intentos queda `PENDIENTE` |
| `EnviarPaqueteContingencia` | `siat:recuperar-contingencia`, piloto | 3 · 60/300/900 s | Arma el TAR, llama `recepcionPaqueteFactura`, marca las facturas `ENVIADA` |
| `NotificarWebhook` | Cambios de estado | 5 · 10/30/60/120 s | POST firmado al `webhook_url` del cliente |
| `RenovarCufd` | `siat:renovar-cufds` | 3 | Pide un CUFD nuevo (y el CUIS antes si venció). Ignora puntos de venta no registrados en el SIN y empresas sin token |
| `EjecutarCasoPrueba` | Panel del piloto | — | Ejecuta una llamada del piloto (etapas largas) |
| `SincronizarCatalogosEmpresa` | Tarea `sincronizar-catalogos-empresas` | 3 · 300/900 s | Sincroniza actividades, productos homologados y leyendas del NIT con un punto de venta que tenga CUIS vigente (se salta la empresa si no hay). El panel tiene además el botón **Sincronizar ahora** (síncrono) |

#### Historial de trabajos

`App\Services\Trabajos\RegistroTrabajos` escucha los eventos de la cola (`JobQueued`,
`JobProcessing`, `JobProcessed`, `JobExceptionOccurred`, `JobFailed`, `JobRetryRequested`, `Looping`)
y lleva la tabla `trabajos`: una fila por job (uuid del payload) con estado `EN_COLA` →
`PROCESANDO` → `EXITOSO` / `REINTENTANDO` / `FALLIDO`, intentos, duración, error, empresa y lo que
afecta (factura, paquete, punto de venta). Los jobs no saben que existe. También lleva el **latido**
del worker (cache, cada 15 s como máximo) y lee el del scheduler (tarea `latido-scheduler`, cada
minuto) para el semáforo del panel. Todo va en `try/catch`: el historial nunca rompe un job.
Pantalla: `Admin\TrabajoController`. Limpieza: `siat:purgar-logs` (exitosos 7 días, resto 30).

#### Tareas programadas (`routes/console.php`)

| Frecuencia | Tarea | Qué hace |
|---|---|---|
| cada 5 min | `reintentar-pendientes` | Reencola facturas `PENDIENTE` con más de 10 min (máx. 200) |
| cada 10 min | `siat:recuperar-contingencia` | Si el SIAT responde: registra el evento, lo cierra y despacha el paquete |
| cada 15 min | `verificar-enviadas` | Reencola la verificación de `ENVIADA`/`RECIBIDA` con más de 20 min |
| cada 15 min | `reintentar-anulaciones` | Reencola anulaciones `PENDIENTE` con más de 30 min |
| cada hora | `siat:renovar-cufds` | Renueva los CUFD que vencen en menos de 2 h |
| diario 02:00 | `siat:revisar-codigos` | Pide un CUIS nuevo a los puntos de venta que lo perdieron (no se puede antes: con uno vigente el SIN devuelve el mismo) |
| diario 04:00 | `siat:purgar-logs` | Borra `logs_siat` más viejos que la retención |
| diario 07:00 | `siat:avisar-certificados` | Alerta certificados y tokens delegados vencidos o que vencen en menos de 30 días (también al log) |
| diario 03:30 | `sincronizar-catalogos-empresas` | Despacha `SincronizarCatalogosEmpresa` por cada empresa con token |
| domingo 03:00 | `siat:sincronizar-globales` | Catálogos paramétricos globales |

#### CUFD en dos capas

Los sistemas de venta **no manejan códigos**: la API los consigue sola.

- **Preventiva:** `siat:renovar-cufds` cada hora renueva lo que vence en menos de 2 h.
- **Reactiva:** si al emitir no hay CUFD vigente, `EmisorFactura` lo pide en ese momento
  (`GestorCodigos::asegurarCufd`, con lock por punto de venta para que dos cajas no pidan dos).
  Si el SIN falla, responde `503 SIAT_NO_DISPONIBLE` y no reintenta durante 60 s; en contingencia
  ni lo intenta (solo sumaría la espera del timeout).
- **CUIS:** `siat:revisar-codigos` diario y, si falta al pedir un CUFD, se pide antes. Cuando el
  SIN entrega un CUIS **nuevo**, se pide en seguida un CUFD atado a él (`GestorCodigos::renovarCuis`):
  el SIN rechaza con 913 un CUFD que viaja con un CUIS distinto al que lo generó.
- **Emergencia:** `POST /api/v1/puntos-venta/{s}/{pv}/cufd` (permiso `codigos:renovar`, 3 por hora).

---

### Frontera SOAP (`app/Services/Siat`)

| Clase | Responsabilidad |
|---|---|
| `SiatClient` | Construye el `SoapClient`: URL del WSDL por ambiente, cabecera `apikey: TokenApi {token}`, gzip, traza, y **timeout de lectura** (`default_socket_timeout`) tanto al bajar el WSDL como al invocar |
| `ServicioBase` | Cabecera común (`codigoAmbiente`, `codigoSistema`, `codigoModalidad`, `nit`), invocación y auditoría en `logs_siat` (XML enviado/recibido, duración, éxito). Envuelve todo error en `SiatException` |
| `ServicioCodigos` | `verificarComunicacion`, `cuis`, `cufd`, `verificarNit` |
| `ServicioSincronizacion` | `sincronizarFechaHora`, actividades, productos/servicios, leyendas y cualquier otra paramétrica por el nombre de su operación (`parametrica()`) |
| `ServicioOperaciones` | `registroPuntoVenta`, `consultaPuntoVenta`, `cierreOperacionesSistema`, `registroEventoSignificativo` |
| `ServicioFacturacion` | `recepcionFactura`, `verificacionEstadoFactura`, `anulacionFactura`, `reversionAnulacionFactura`, `recepcionPaqueteFactura`, `validacionRecepcionPaqueteFactura`. Comprime en gzip y calcula el SHA-256 **del gzip** |
| `GestorCodigos` | Único lugar que pide y guarda CUIS/CUFD (`asegurarCuis`, `asegurarCufd`, `renovarCufd`, `renovarCuis`, `forzarCuisNuevo`). Rechaza respuestas sin código o sin código de control; usa la vigencia que declara el SIN; ata cada CUFD al CUIS que lo generó |
| `RespuestaSiat` | Interpreta `transaccion`, `codigoRecepcion`, `codigoEstado`, `mensajesList` |
| `FabricaServicios` | Construye los servicios por empresa; punto de sustitución en tests |

Detalles del contrato con el SIN (qué está verificado y qué no) en
[Contrato con el SIAT](#contrato-con-el-siat).

---

### Emisión: componentes de `app/Services/Factura`

| Clase | Qué hace |
|---|---|
| `ValidadorFactura` | Reglas que el FormRequest no cubre: descuentos que superan la línea, total negativo, gift card mayor al total, excepción de NIT sin documento NIT. También la usa el piloto, que emite sin FormRequest |
| `CalculadorTotales` | Subtotales por línea, monto total = bruto − descuentos de línea − descuento global; **sujeto a IVA = monto total − gift card**. Redondeo a 2 decimales |
| `ResolutorActividad` | Actividad económica por producto homologado y leyenda (al azar entre las de la actividad) |
| `GeneradorCuf` | 9 campos a ancho fijo (53 dígitos) + verificador módulo 11 → base 16 + código de control del CUFD |
| `ConstructorXml` | `facturaElectronicaCompraVenta` con `xsi:noNamespaceSchemaLocation`; los vacíos van con `xsi:nil="true"` |
| `FirmadorXml` | Firma XML-DSig enveloped RSA-SHA256 con el `.p12`. **XAdES-BES pendiente** |
| `MontoEnLetras` | Monto en literal para el "Son: … 00/100 BOLIVIANOS" |
| `GeneradorPdf` / `GeneradorQr` | Representación imprimible (dompdf) y QR de verificación; la URL del QR depende del ambiente de la empresa (`siat.qr.urls`) |
| `GeneradorCertificadoPrueba` | `.p12` autofirmado, solo para el piloto |

---

### Contingencia (`app/Services/Contingencia`)

1. `EnviarFacturaAlSiat` agota sus intentos → `GestorContingencia::derivar()` marca la factura
   `CONTINGENCIA` y abre un `EventoSignificativo` (motivo 1) con el CUFD en uso.
2. Mientras el evento está abierto, cada factura nueva nace con `tipo_emision = 2` y su CUF lo
   refleja.
3. `siat:recuperar-contingencia` (cada 10 min) sondea con `verificarComunicacion`; si el SIAT
   responde, registra el evento en el SIN (pidiendo CUFD nuevo si hace falta), lo cierra y arma un
   `Paquete` con las facturas acumuladas.
4. `EnviarPaqueteContingencia` envía el TAR (un XML firmado por factura) con `codigoEvento`.

> ⚠️ Este flujo tiene problemas abiertos que se resuelven junto con la Etapa VI y el CAFC: las
> facturas derivadas conservan un CUF de emisión en línea y el paquete viaja con
> `codigoEmision = 2`; la fecha de inicio del evento es posterior a esas facturas; no hay tope de
> facturas por paquete. Ver [Pendientes](#pendientes).

---

### Seguridad

| Tema | Cómo se resuelve |
|---|---|
| Autenticación API | Tokens de **Laravel Sanctum** cuyo dueño es la `Empresa` (no un usuario). **Uno por empresa** (regenerarlo revoca el anterior en la misma transacción: `Empresa::reemplazarTokenApi`), prefijo `siat_`, solo se guarda su hash, se muestran una vez. Vencimiento, IPs permitidas y revocación desde el panel (Empresa → *Acceso API*). La sesión del panel no autentica la API (`sanctum.guard = []`) |
| Autorización API | Cada ruta exige un permiso (`siat.permiso:...`); perfiles *Caja / POS*, *Administración*, *Solo consulta* en `config/siat.php` |
| Registro de llamadas | `logs_api`: token, empresa, ruta, código HTTP, IP, duración (también los rechazos); purgado a los 90 días |
| Token delegado, `.p12`, passphrase | Cifrados en reposo (cast `encrypted`, `APP_KEY`); ocultos al serializar |
| Aislamiento | Toda consulta de la API filtra por `empresa_id` |
| Rate limit | 60 peticiones/min por token y 120 por empresa (`SIAT_RATE_LIMIT_TOKEN`, `SIAT_RATE_LIMIT`) |
| Proxies | `TRUSTED_PROXIES`: solo a esos proxies se les cree `X-Forwarded-For` |
| Panel | Middleware `auth`; login con 5 intentos y bloqueo de 60 s |
| Webhooks | Destino validado contra SSRF (https, IP pública); IP fijada con `CURLOPT_RESOLVE` contra DNS rebinding; cuerpo firmado HMAC-SHA256 con un **secreto por empresa** |
| Tarjetas | Enmascaradas antes de guardar y firmar |
| Auditoría SOAP | `logs_siat` con XML completo, purgado a los 90 días |

---

## Contrato con el SIAT

Cómo habla este sistema con el SIN, con el nivel de confianza de cada dato.

Niveles:

- ✅ **Verificado**: contrastado con el WSDL del piloto, con respuestas reales del SIN o con
  `ventas` (sistema del mismo autor que factura en producción, modalidad computarizada).
- ⚠️ **Pendiente**: sale de documentación o deducción; confirmar antes de producción.

---

### Identidad del proveedor

Solicitud R-1359 nº 9484 (09/08/2026): NIT 7633685015 · sistema *SolucionDigital-api* ·
código de sistema `22848EC6F66C9401C16F7` · tipo PROVEEDOR · modalidad electrónica en línea.

---

### Transporte

| Tema | Valor | Nivel |
|---|---|---|
| URL piloto | `https://pilotosiatservicios.impuestos.gob.bo/v2/{Servicio}?wsdl` | ✅ |
| URL producción | `https://siatrest.impuestos.gob.bo/v2/{Servicio}?wsdl` | ⚠️ |
| Servicios | `FacturacionCodigos`, `FacturacionSincronizacion`, `FacturacionOperaciones`, `ServicioFacturacionCompraVenta` | ✅ |
| Token | Cabecera HTTP **`apikey: TokenApi {token_delegado}`** (no `Authorization`) | ✅ |
| Rechazos | HTTP 200 con `transaccion = false` y `mensajesList`, **no** SoapFault | ✅ |
| Fechas | Hora de Bolivia, formato `Y-m-d\TH:i:s.v` | ✅ |

Cabecera común de casi toda solicitud: `codigoAmbiente`, `codigoSistema`, `codigoModalidad`,
`nit`.

---

### Códigos: CUIS y CUFD

| Tema | Detalle | Nivel |
|---|---|---|
| CUIS | Operación `cuis`. Dura ~1 año. Si ya hay uno vigente el SIN responde `transaccion=false` con **código 980** pero **devuelve el mismo CUIS**: no es error | ✅ |
| Vigencia | Se usa la `fechaVigencia` que declara el SIN, no "ahora + 1 año": un CUIS existente vence antes | ✅ |
| CUFD | Operación `cufd`. Dura 24 h. **Cada solicitud devuelve uno nuevo**. Trae `codigo`, `codigoControl`, `direccion`, `fechaVigencia` | ✅ |
| CUFD ↔ CUIS | Cada CUFD queda atado al CUIS con que se pidió. Mandar un CUFD con otro CUIS da **913 "CUIS INVALIDO"**. Por eso, cuando el SIN entrega un CUIS **nuevo**, el sistema pide también un CUFD nuevo; si devuelve el mismo (980), no | ✅ |
| CUFD vigente | El último insertado (`latest('id')`) no vencido; no por fecha, que se rompió al corregir la zona horaria | ✅ |
| Punto de venta 0 | Existe en el SIN sin registrarlo; tiene su propio CUIS y CUFD | ✅ |
| Registrar PV | `registroPuntoVenta` crea uno **nuevo cada vez**; un PV no se borra, solo se cierra, y cerrado no se reabre | ✅ |

---

### CUF

```
nit(13) + fecha AAAAMMDDHHIISSmmm(17) + sucursal(4) + modalidad(1) + tipoEmision(1)
+ tipoFactura(1) + documentoSector(2) + numeroFactura(10) + puntoVenta(4)   = 53 dígitos
→ + dígito verificador módulo 11                                             = 54 dígitos
→ a base 16 (mayúsculas)
→ + codigoControl del CUFD
```

| Tema | Detalle | Nivel |
|---|---|---|
| Anchos | `tipoFactura` ocupa **1** dígito, `documentoSector` 2 | ✅ |
| Módulo 11 | Pesos 2..9 ciclando de derecha a izquierda; dígito = **`suma % 11`** (no `11 − resto`); 10 → 1, 11 → 0 | ✅ |
| Fecha | La del CUF debe ser idéntica a `<fechaEmision>`; por eso se emite con milisegundos en `000` | ✅ |
| Tipo de emisión | 1 en línea, 2 fuera de línea; entra al CUF | ✅ |

Implementación: `app/Services/Factura/GeneradorCuf.php`.

---

### XML de la factura (sector 1, electrónica)

| Tema | Detalle | Nivel |
|---|---|---|
| Raíz | `facturaElectronicaCompraVenta` (la computarizada es `facturaComputarizadaCompraVenta`) | ✅ |
| Atributos raíz | `xmlns:xsi` + `xsi:noNamespaceSchemaLocation="facturaElectronicaCompraVenta.xsd"` | ✅ |
| Vacíos | Siempre presentes con `xsi:nil="true"`, nunca omitidos (el XSD es una secuencia) | ✅ |
| Orden de campos | El de `ConstructorXml` | ⚠️ contrastar con el XSD |
| `codigoExcepcion` | `0` normal; `1` = NIT no validado (solo documento 5) | ✅ (`ventas`) |
| `cafc` | Hoy siempre nil (ver Etapa VI) | ⚠️ |
| `montoTotalSujetoIva` | `montoTotal − montoGiftCard` | ✅ (`ventas`, con gift card 0) |
| `montoTotalMoneda` / `tipoCambio` | Hoy solo bolivianos: igual a `montoTotal`, cambio 1 | ✅ |
| `actividadEconomica` | Del catálogo de productos homologados del NIT | ✅ |
| `leyenda` | Del catálogo de leyendas de la actividad, al azar | ✅ |

Cabecera, en orden: `nitEmisor`, `razonSocialEmisor`, `municipio`, `telefono`, `numeroFactura`,
`cuf`, `cufd`, `codigoSucursal`, `direccion`, `codigoPuntoVenta`, `fechaEmision`,
`nombreRazonSocial`, `codigoTipoDocumentoIdentidad`, `numeroDocumento`, `complemento`,
`codigoCliente`, `codigoMetodoPago`, `numeroTarjeta`, `montoTotal`, `montoTotalSujetoIva`,
`codigoMoneda`, `tipoCambio`, `montoTotalMoneda`, `montoGiftCard`, `descuentoAdicional`,
`codigoExcepcion`, `cafc`, `leyenda`, `usuario`, `codigoDocumentoSector`.

Detalle, en orden: `actividadEconomica`, `codigoProductoSin`, `codigoProducto`, `descripcion`,
`cantidad`, `unidadMedida`, `precioUnitario`, `montoDescuento`, `subTotal`, `numeroSerie`,
`numeroImei`.

---

### Firma digital

| Tema | Detalle | Nivel |
|---|---|---|
| Tipo | XML-DSig enveloped, RSA-SHA256, C14N, digest SHA-256, `X509Certificate` en `KeyInfo` | Implementado |
| Canonicalización | El `SignedInfo` se canonicaliza **en su lugar** (hereda `xmlns:xsi` de la raíz); suelto no verifica | ✅ (openssl_verify) |
| Perfil XAdES-BES | `QualifyingProperties` armado pero **no enchufado** (`FirmadorXml::construirQualifyingProperties`) | ⚠️ **bloqueante** |
| Dudas XAdES | Versión del namespace (1.3.2 vs 1.4.1), `SignedDataObjectProperties`, digest del certificado, formato de `X509IssuerName`, patrón de los `Id` | ⚠️ |

Se confirma en la Etapa IV/VIII del piloto con el `.p12` real. Si el SIN acepta la firma XML-DSig
simple, el bloque XAdES no hace falta.

---

### Recepción, estado y anulación

| Tema | Detalle | Nivel |
|---|---|---|
| Struct base `solicitudRecepcion` | `codigoAmbiente`, `codigoDocumentoSector`, `codigoEmision`, `codigoModalidad`, `codigoPuntoVenta`, `codigoSistema`, `codigoSucursal`, `cufd`, `cuis`, `nit`, `tipoFacturaDocumento` | ✅ WSDL piloto |
| `recepcionFactura` | + `archivo` (XML firmado en **gzip**), `fechaEnvio`, `hashArchivo` | ✅ |
| `hashArchivo` | SHA-256 **del gzip**, no del XML | ✅ |
| `verificacionEstadoFactura` | + `cuf` | ✅ |
| `anulacionFactura` | + `cuf`, `codigoMotivo`; con el **CUFD vigente**, no el de la emisión | ✅ |
| `reversionAnulacionFactura` | + `cuf` (sin motivo) | ✅ WSDL piloto |
| Códigos | **908 y 690 = validada**; **902 = pendiente** (no es rechazo) | ✅ |
| 935 | `fechaEnvio` fuera de tolerancia (zona horaria) | ✅ |

---

### Eventos y paquetes (contingencia)

| Tema | Detalle | Nivel |
|---|---|---|
| `registroEventoSignificativo` | `codigoMotivoEvento`, `descripcion`, `cuis`, `cufd` (vigente), `cufdEvento` (el de la caída, **distinto** del vigente), `fechaHoraInicioEvento`, `fechaHoraFinEvento`, sucursal y PV. Respuesta bajo `RespuestaListaEventos`, código en `codigoRecepcionEventoSignificativo` | ✅ WSDL piloto |
| Motivos | 1 corte de internet · 2 inaccesibilidad del servicio web · 3 zonas sin internet · 4 venta sin internet · 5 virus/falla de software · 6 cambio de infraestructura/hardware · 7 corte de energía | ✅ portal |
| `recepcionPaqueteFactura` | Struct base + `cafc`, `cantidadFacturas`, `codigoEvento`, `archivo`, `fechaEnvio`, `hashArchivo` | ✅ WSDL piloto |
| Archivo | **TAR** con un XML firmado por factura, comprimido en gzip | ✅ |
| `validacionRecepcionPaqueteFactura` | Confirma el procesamiento del paquete | ✅ nombre |
| Máximo por paquete | 500 facturas (el portal pide "igual a 500" y "menor a 500") | ✅ portal |
| CAFC | Necesario para los motivos 5–7 (facturación manual) | ⚠️ en trámite con el SIN |

---

### Sincronización de catálogos

Operaciones: `sincronizarFechaHora`, `sincronizarActividades`,
`sincronizarListaProductosServicios`, `sincronizarListaLeyendasFactura`,
`sincronizarListaMensajesServicios`, `sincronizarListaActividadesDocumentoSector` y las
paramétricas `sincronizarParametrica*` (unidad de medida, tipo de moneda, documento de
identidad, método de pago, motivo de anulación, documento sector, país de origen, tipo de punto
de venta, entre otras). Todas requieren `cuis`, `codigoSucursal`, `codigoPuntoVenta`. Un rechazo
viene con `transaccion=false` dentro del envoltorio de cada operación (`RespuestaSiat::rechazoDeCatalogo`).

---

### QR de verificación

| Tema | Valor | Nivel |
|---|---|---|
| Piloto | `https://pilotosiat.impuestos.gob.bo/consulta/QR` | ✅ (`ventas`) |
| Producción | `https://siat.impuestos.gob.bo/consulta/QR` | ⚠️ mismo camino en el dominio principal; confirmar |
| Parámetros | `nit`, `cuf`, `numero`, `t` | ✅ (`ventas`) |
| `t` | `2` (media página / carta; `1` = rollo), configurable con `SIAT_QR_TAMANIO` | ✅ valor de `ventas`; significado a confirmar |

---

## Base de datos

Esquema vigente (MySQL; los tests usan SQLite en memoria). Fuente de verdad: `database/migrations/`.

```mermaid
erDiagram
    empresas ||--o{ certificados : tiene
    empresas ||--o{ sucursales : tiene
    sucursales ||--o{ puntos_venta : tiene
    puntos_venta ||--o{ cuis : historial
    puntos_venta ||--o{ cufd : historial
    cuis ||--o{ cufd : genera
    empresas ||--o{ facturas : emite
    puntos_venta ||--o{ facturas : numera
    cufd ||--o{ facturas : "entra al CUF"
    facturas ||--o{ factura_items : detalle
    facturas ||--o| facturas_anuladas : anulacion
    paquetes ||--o{ facturas : transporta
    eventos_significativos ||--o{ paquetes : justifica
    empresas ||--o{ actividades_economicas : catalogo
    empresas ||--o{ productos_servicios : catalogo
    empresas ||--o{ leyendas_factura : catalogo
    empresas ||--o{ logs_siat : auditoria
    casos_prueba ||--o{ ejecuciones_prueba : ejecuta
```

---

### Cliente

#### `empresas`
| Columna | Tipo | Notas |
|---|---|---|
| `nombre_comercial`, `razon_social` | string | |
| `nit` | string(20) | Único junto con `codigo_ambiente` |
| `codigo_sistema` | string null | Asignado por el SIN |
| `token_delegado` | text null | **Cifrado** (`encrypted`) |
| `codigo_ambiente` | tinyint (2) | 1 producción, 2 piloto |
| `codigo_modalidad` | tinyint (1) | 1 electrónica |
| `estado` | string | `EN_REGISTRO` → `EN_PRUEBAS` → `PILOTO_APROBADO` → `PRODUCCION`; `OBSERVADO` |
| `webhook_url` | string null | Validada contra SSRF al guardar |

#### `personal_access_tokens` (Sanctum)
Tokens de API. `tokenable_type`/`tokenable_id` → la **empresa** dueña · `name` · `token` (hash
SHA-256) · `abilities` (permisos, json) · `last_used_at` · `expires_at` · `ips_permitidas` (json,
propia de este sistema; vacío = cualquier IP). Modelo `App\Models\TokenApi`.

#### `certificados`
`empresa_id` · `contenido_p12` (base64, **cifrado**) · `passphrase` (**cifrada**) · `emitido_por`
(`AUTOFIRMADO - solo pruebas` para los de prueba) · `vence_el` · `activo`. Ambos campos
sensibles están en `$hidden`.

---

### Estructura física

#### `sucursales`
`empresa_id` · `codigo_sucursal` (0 = casa matriz; único por empresa) · `nombre` · `municipio` ·
`direccion` · `telefono`. Se copian de la Oficina Virtual; el sistema no las crea en el SIN.

#### `puntos_venta`
| Columna | Notas |
|---|---|
| `sucursal_id`, `codigo_punto_venta` | Únicos juntos. Código del SIN |
| `tipo_punto_venta` | Catálogo del SIN |
| `siguiente_factura` | **Correlativo**; se reserva con `lockForUpdate` |
| `activo` | Los dados de baja quedan por su historial |
| `registrado_en_siat` | Fecha del `registroPuntoVenta`. Null = solo local (salvo el PV 0) |

---

### Códigos del SIN (historial)

#### `cuis`
`punto_venta_id` · `codigo` · `fecha_vigencia` (la que declara el SIN). `updateOrCreate` por
código: pedirlo de nuevo no duplica.

#### `cufd`
`punto_venta_id` · `cuis_id` (CUIS que lo generó) · `codigo` · `codigo_control` (entra al CUF) ·
`direccion` · `fecha_vigencia`. El vigente es el último por `id` no vencido.

---

### Facturación

#### `facturas`
| Columna | Notas |
|---|---|
| `empresa_id`, `punto_venta_id`, `cufd_id`, `paquete_id` | `paquete_id` solo para contingencia |
| `cuf` | **Único** |
| `numero_factura`, `fecha_emision` | Fecha en segundos (ms = 000) |
| `comprador_*` | Tipo y número de documento, complemento, razón social, email |
| `metodo_pago`, `numero_tarjeta` | Tarjeta **enmascarada** |
| `codigo_excepcion` | 0 normal, 1 = NIT no validado |
| `moneda`, `tipo_cambio` | Hoy siempre 1 y 1 |
| `subtotal`, `descuento_global`, `gift_card`, `monto_total`, `monto_total_moneda`, `monto_total_sujeto_iva` | decimal(14,2) |
| `leyenda`, `usuario` | |
| `codigo_documento_sector` | 1 |
| `tipo_emision` | 1 en línea, 2 contingencia (entra al CUF) |
| `estado` | Ver [Máquina de estados](#máquina-de-estados-de-la-factura). Índice `(estado, id)` |
| `codigo_recepcion`, `codigo_estado_siat` | Respuesta del SIN |
| `motivo_observacion` | Por qué el SIN la observó (`[código] descripción`); null si no fue observada |
| `xml_firmado` | **Documento legal** |
| `ruta_pdf` | `storage/app/siat/pdf/{cuf}.pdf` |
| `referencia_externa` | Única por empresa: idempotencia |
| `enviada_en`, `validada_en` | |

#### `factura_items`
`factura_id` · `codigo_producto_sin` · `codigo_actividad` (resuelta al emitir) · `codigo_interno` ·
`descripcion` · `cantidad` decimal(14,4) · `unidad_medida` · `precio_unitario` · `descuento` ·
`subtotal` · `numero_serie` · `numero_imei`.

#### `facturas_anuladas`
`factura_id` · `motivo` · `anulada_en` · `codigo_recepcion` · `estado` (`PENDIENTE`, `CONFIRMADA`,
`RECHAZADA`, `REVERTIDA`) · `motivo_rechazo` · `estado_anterior` (para revertir si el SIN rechaza).

---

### Operación

#### `eventos_significativos`
`empresa_id` · `punto_venta_id` · `codigo_evento` (motivo 1–7) · `descripcion` · `cufd_evento`
(CUFD en uso al caer) · `fecha_inicio`, `fecha_fin` · `codigo_recepcion` (del SIN) · `estado`
(`ABIERTO`, `CERRADO`).

#### `paquetes`
`empresa_id` · `punto_venta_id` · `evento_id` · `cantidad_facturas` · `codigo_recepcion` ·
`estado` (`PENDIENTE`, `ENVIADO`) · `enviado_en`.

#### `logs_api`
`empresa_id` · `token_id` (sin FK: el historial sobrevive al token revocado) · `metodo` · `ruta` ·
`estado_http` · `ip` · `duracion_ms` · `created_at`. No guarda el cuerpo. Se purga a los 90 días
(`SIAT_API_RETENCION_LOGS_DIAS`).

#### `trabajos`
`uuid` (único; el del payload, igual en reintentos y en `failed_jobs`) · `clase` · `cola` · `estado`
(`EN_COLA`, `PROCESANDO`, `EXITOSO`, `REINTENTANDO`, `FALLIDO`) · `intentos` · `empresa_id` ·
`relacionado_tipo` / `relacionado_id` · `error` · `duracion_ms` · `encolado_en`, `iniciado_en`,
`terminado_en`. Se purga: exitosos a los 7 días, el resto a los 30.

### `logs_siat`
`empresa_id` · `servicio` · `operacion` · `xml_enviado` · `xml_recibido` · `duracion_ms` ·
`exitoso` · `mensaje_error` · `created_at`. Contiene datos del comprador: se purga a los 90 días.

---

### Catálogos

| Tabla | Alcance | Clave |
|---|---|---|
| `catalogos` | Global (`tipo`, `codigo_clasificador`, `descripcion`) | único `(tipo, codigo_clasificador)` |
| `actividades_economicas` | Por empresa | único `(empresa_id, codigo_actividad)` |
| `productos_servicios` | Por empresa: producto homologado → actividad | único `productos_servicios_unico` (nombre explícito: MySQL limita a 64 caracteres) |
| `leyendas_factura` | Por empresa y actividad | |

---

### Piloto

#### `casos_prueba`
`fase` · `etapa` (null = flujo original de 17 pasos) · `orden` · `nombre` · `descripcion` · `tipo`
(operación a ejecutar) · `payload_ejemplo` (json, parámetros del portal) · `obligatorio` ·
`pruebas_esperadas`.

#### `ejecuciones_prueba`
`empresa_id` · `caso_id` · `estado` · `respuesta` (json) · `duracion_ms` · `intento` ·
`ejecutado_en`.

---

### Laravel

`users` (un solo administrador) · `sessions` · `password_reset_tokens` · `cache` · `cache_locks`
(locks de jobs únicos) · `jobs` · `job_batches` · `failed_jobs`.

---

### Integridad

- Borrar una empresa cascadea a todo lo suyo, por eso el panel **impide borrar** una empresa que
  ya emitió facturas (documentos con obligación de conservación); para dejar de atenderla está
  el estado `OBSERVADO`.
- `facturas.cufd_id` y `paquetes.evento_id` quedan en null si se borra el padre.
- `logs_siat.empresa_id` queda en null: la auditoría sobrevive.

---

## Pendientes

Lo que falta, casi todo esperando algo externo. El historial de cambios está en `git log`.

### Bloqueantes de producción

| # | Problema | Dónde | Plan |
|---|---|---|---|
| B1 | **Firma XAdES-BES** sin confirmar. Hoy se firma XML-DSig simple | `FirmadorXml` | Probar en Etapa IV con el `.p12` real; si el SIN rechaza, enchufar `construirQualifyingProperties()` |
| B2 | **Passphrase del `.p12`** desconocida (`~/Escritorio/certificado-prueba.p12`) | — | Conseguirla con la entidad certificadora |
| B3 | **Contingencia — tipo de emisión mezclado.** Las facturas derivadas a contingencia conservan un CUF de emisión 1 (en línea) y el paquete viaja con `codigoEmision = 2`; el SIN recalcula el CUF y rechazaría el paquete entero. También nacen en línea las facturas emitidas durante los ~7,5 min de reintentos antes de abrir el evento | `GestorContingencia::derivar`, `EnviarPaqueteContingencia` | Reenviar las derivadas por `recepcionFactura` cuando vuelva el SIAT y usar paquete solo para las de emisión 2. Se hace con la Etapa VI |
| B4 | **Contingencia — fecha del evento.** `fecha_inicio` es el momento en que se abre el evento, posterior a la `fecha_emision` de las facturas que lo causaron | `GestorContingencia::eventoAbierto` | Fijarla en la emisión de la primera factura que falló |
| B5 | **Contingencia — CUFD vencido bloquea la venta.** Si el CUFD vence durante una caída, `EmisorFactura` responde 503 aunque haya evento abierto | `EmisorFactura::emitir` | En contingencia, emitir con el CUFD del evento |
| B6 | **Paquete sin tope.** El SIN admite hasta 500 facturas por paquete | `GestorContingencia::recuperar` | Partir en paquetes de 500 |
| B7 | **CAFC** eliminado del esquema; hace falta para los motivos 5–7 | migración `2026_09_14_...` | Volver a incorporarlo cuando el SIN lo entregue (Etapa VI) |

### Otros

| # | Problema | Dónde |
|---|---|---|
| P3 | **URL de producción del SIAT** (servicios y consulta del QR) sin verificar | `config/siat.php` |
| P4 | **Orden de campos del XML** sin contrastar contra el XSD oficial | `ConstructorXml` |
| P5 | `validacionRecepcionPaqueteFactura` solo se usa en el piloto; en producción las facturas del paquete quedan `ENVIADA` hasta la verificación individual | `EnviarPaqueteContingencia` |
| P6 | Moneda extranjera no soportada (solo Bs) | `EmitirFacturaRequest` |
| P8 | Los tests de concurrencia contra MySQL (grupo `mysql`) se borraron con la suite Feature antigua (`d5bc968`); recuperables desde `7ba2acd` | `tests/` |
| P9 | El código de evento de contingencia automática es fijo en 1 (corte de internet) | `GestorContingencia` |
