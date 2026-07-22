# Sistema de Facturación SIAT — Proveedor Multi-Cliente
## Documento maestro de implementación

> **Cómo usar este archivo:** copiar la sección 1 como prompt inicial en Visual Studio Code
> (Claude Code, Copilot o el asistente que uses). El resto del documento es el contexto
> técnico que debe leerse completo antes de escribir la primera línea de código.

---

## Índice

1. [Prompt de implementación](#1-prompt-de-implementación)
2. [Entorno verificado](#2-entorno-verificado)
3. [Alcance del sistema](#3-alcance-del-sistema)
4. [Arquitectura general](#4-arquitectura-general)
5. [Estructura de carpetas](#5-estructura-de-carpetas)
6. [Modelo de datos](#6-modelo-de-datos)
7. [Comunicación con el SIAT](#7-comunicación-con-el-siat)
8. [Ciclos de vida y sincronización](#8-ciclos-de-vida-y-sincronización)
9. [Emisión de facturas](#9-emisión-de-facturas)
10. [Contrato de la API REST](#10-contrato-de-la-api-rest)
11. [Rendimiento y autenticación](#11-rendimiento-y-autenticación)
12. [Fases de prueba ante el SIN](#12-fases-de-prueba-ante-el-sin)
13. [Contingencia](#13-contingencia)
14. [Seguridad](#14-seguridad)
15. [Plan de construcción](#15-plan-de-construcción)
16. [Estado actual](#16-estado-actual)

---

## 1. Prompt de implementación

```
Actúa como desarrollador senior de Laravel especializado en integraciones
con servicios web SOAP gubernamentales.

CONTEXTO
Estoy construyendo un sistema de facturación electrónica para el SIAT de
Impuestos Nacionales de Bolivia. Mi rol es PROVEEDOR: distribuyo software
como servicio (SaaS) a varios clientes —ferreterías, restaurantes, venta de
electrodomésticos— y este sistema es la API central que todos ellos usarán
para facturar.

Los sistemas de venta de mis clientes NUNCA se conectan al SIAT directamente.
Solo consumen mi API REST. Mi sistema encapsula todo el SOAP, la firma digital,
los códigos CUIS/CUFD/CAFC, los catálogos y la contingencia.

MODALIDAD
Facturación electrónica en línea (con firma digital).
Documento sector: 1 — Factura de compra-venta.

ENTORNO VERIFICADO
- PHP 8.3.30 sobre Windows
- Laravel 13
- MySQL / MariaDB
- Frontend del panel: Blade (sin React, sin Vue)
- Extensiones requeridas: soap, openssl

REGLAS DE TRABAJO — cumplir estrictamente
1. Escribir el código en su forma MÁS SIMPLE posible. Prefiero claridad
   sobre elegancia. Este código es para aprender y mantener, no para lucir.
2. TODOS los comentarios en español, explicando el PORQUÉ de la decisión,
   no el qué hace la línea.
3. Mantener patrones consistentes entre archivos: si entiendo un servicio,
   debo entender todos los demás.
4. NO asumir nada. Si falta un dato o hay ambigüedad, preguntar antes de
   escribir código.
5. Avanzar por fases. Al terminar una fase, detenerse, explicar qué se hizo
   y esperar mi confirmación antes de continuar.
6. Nada de SOAP fuera de la carpeta app/Services/Siat/. Los controladores
   nunca tocan el SIAT directamente.
7. Antes de implementar cualquier operación SOAP, verificar los nombres de
   campos contra el WSDL vigente. No inventar nombres.

FORMATO DE ENTREGA POR FASE
- Qué archivos se crean o modifican y dónde
- El código completo de cada archivo
- Cómo probar que funciona
- Qué queda pendiente para la fase siguiente

EMPEZAR
Leer el documento completo de arquitectura antes de proponer nada.
Luego confirmar el plan de la fase que corresponda y esperar mi aprobación.
```

---

## 2. Entorno verificado

| Componente | Versión / valor |
|---|---|
| PHP | 8.3.30 |
| Laravel | 13 |
| Sistema operativo | Windows |
| Base de datos | MySQL / MariaDB |
| Panel | Blade |
| Colas | `database` en desarrollo · Redis en producción |
| Caché | Archivo en desarrollo · Redis en producción |
| Extensiones PHP | `soap`, `openssl` (activar en `php.ini`) |

**Endpoints del SIAT:**

```
Piloto:      https://pilotosiatservicios.impuestos.gob.bo/v2
Producción:  https://siatrest.impuestos.gob.bo/v2

Formato:     {base}/{Servicio}?wsdl
```

**Códigos constantes:**

```
codigoAmbiente:   1 = Producción   ·   2 = Piloto
codigoModalidad:  1 = Electrónica  ·   2 = Computarizada
codigoSucursal:   0 = Casa matriz
```

---

## 3. Alcance del sistema

**Dentro del alcance:**

- Administración multi-cliente de contribuyentes emisores
- Custodia y uso de certificados digitales por cliente
- Gestión de sucursales y puntos de venta
- Ciclo completo de CUIS, CUFD y CAFC
- Sincronización de catálogos globales y por empresa
- Generación de CUF, XML, firma digital, envío y verificación
- Anulación de facturas
- Emisión en contingencia y envío por paquetes
- Eventos significativos
- Generación de PDF con código QR
- API REST para sistemas de venta externos
- Panel de ejecución de pruebas piloto
- Auditoría completa de peticiones SOAP

**Fuera del alcance (fase posterior):**

- Otros documentos sector distintos al 1
- Notas de crédito-débito
- Facturación por terceros
- Portal de autoservicio para el contribuyente
- Facturación en moneda extranjera con múltiples tasas

---

## 4. Arquitectura general

```
FUERA DEL SISTEMA
Sistema ferretería · Sistema restaurante · Sistema X
Cada uno con su propia base de datos
        |  REST + JSON + API key
        v
TU SISTEMA — una instancia, una base de datos
  [ Panel /admin (Blade) ]  [ API REST /api/v1 ]  [ Motor SIAT (SOAP + firma) ]
  [ Workers: colas, reintentos, cron, sincronización ]
        |  SOAP + XML firmado + GZIP
        v
SIAT — Impuestos Nacionales
```

**Regla de frontera:** el sistema de ventas del cliente nunca conoce el CUFD,
el XML, el SOAP ni el certificado. Solo envía una venta y recibe un CUF.

**Qué es compartido y qué es por cliente:**

| Compartido (tuyo) | Por cliente |
|---|---|
| El código del motor SIAT | Token delegado |
| La lógica de CUF, XML y firma | Certificado `.p12` |
| Los catálogos paramétricos | NIT y código de sistema |
| Las colas y los workers | CUIS, CUFD, CAFC |
| El panel de administración | Sucursales y puntos de venta |
| | Correlativos y facturas |
| | Actividades, productos y leyendas |

---

## 5. Estructura de carpetas

```
siat-api/
├── app/
│   ├── Models/
│   │   ├── Empresa.php                  el cliente contribuyente
│   │   ├── Certificado.php              .p12 cifrado
│   │   ├── Sucursal.php
│   │   ├── PuntoVenta.php
│   │   ├── Cuis.php
│   │   ├── Cufd.php
│   │   ├── Cafc.php
│   │   ├── Factura.php
│   │   ├── FacturaItem.php
│   │   ├── EventoSignificativo.php
│   │   ├── Paquete.php
│   │   ├── ActividadEconomica.php       por empresa
│   │   ├── ProductoServicio.php         por empresa
│   │   ├── LeyendaFactura.php           por empresa
│   │   ├── Catalogo.php                 paramétricas globales
│   │   ├── CasoPrueba.php
│   │   ├── EjecucionPrueba.php
│   │   └── LogSiat.php
│   ├── Services/
│   │   ├── Siat/                        ÚNICO lugar con SOAP
│   │   │   ├── SiatClient.php           construye el SoapClient con el token
│   │   │   ├── ServicioSincronizacion.php
│   │   │   ├── ServicioCodigos.php      CUIS · CUFD · CAFC
│   │   │   ├── ServicioOperaciones.php  puntos de venta · eventos
│   │   │   └── ServicioFacturacion.php  recepción · anulación · verificación
│   │   ├── Factura/
│   │   │   ├── GeneradorCuf.php         módulo 11 + base 16
│   │   │   ├── ConstructorXml.php       arma el XML según el XSD
│   │   │   ├── FirmadorXml.php          firma XAdES con el .p12
│   │   │   ├── ValidadorFactura.php     valida antes de enviar
│   │   │   ├── CalculadorTotales.php
│   │   │   ├── GeneradorPdf.php
│   │   │   └── GeneradorQr.php
│   │   ├── Catalogos/
│   │   │   ├── SincronizadorGlobal.php
│   │   │   └── SincronizadorEmpresa.php
│   │   ├── Pruebas/
│   │   │   └── EjecutorPruebas.php      corre los casos piloto en orden
│   │   └── Contingencia/
│   │       ├── GestorContingencia.php
│   │       └── ArmadorPaquete.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Api/V1/  (Factura, PuntoVenta, Catalogo, Estado)
│   │   │   └── Admin/   (Empresa, Certificado, Sucursal, PuntoVenta, PruebaPiloto, Factura, Monitor)
│   │   ├── Middleware/
│   │   │   ├── AutenticarApiKey.php     identifica la empresa
│   │   │   ├── VerificarEstadoEmpresa.php
│   │   │   ├── Idempotencia.php         evita duplicados
│   │   │   └── LimitarPeticiones.php    rate limit por empresa
│   │   ├── Requests/  (EmitirFacturaRequest, AnularFacturaRequest)
│   │   └── Resources/ (FacturaResource, PuntoVentaResource)
│   ├── Jobs/  (EnviarFacturaAlSiat, VerificarEstadoFactura, RenovarCufd,
│   │           SincronizarCatalogosEmpresa, EjecutarCasoPrueba,
│   │           EnviarPaqueteContingencia, NotificarWebhook)
│   ├── Console/Commands/  (SiatProbarConexion, SiatRenovarCufds,
│   │           SiatSincronizarGlobales, SiatRevisarCodigos, SiatAvisarCertificados)
│   └── Exceptions/  (SiatException, CufdVencidoException, FacturaObservadaException)
├── config/siat.php                      URLs, servicios, timeouts
├── database/
│   ├── migrations/
│   └── seeders/CasosPruebaSeeder.php
├── resources/views/
│   ├── layouts/admin.blade.php
│   ├── admin/ (empresas, certificados, sucursales, pruebas, monitor)
│   └── factura/pdf.blade.php
├── routes/  (api.php /api/v1/*, web.php /admin/*, console.php cron)
└── storage/app/siat/
    ├── xml/                             XML firmados — documento legal
    ├── pdf/
    └── logs/
```

**Principio de organización:** los controladores no saben nada del SIAT.
Solo llaman a `Services/`. Si el SIN cambia algo, se toca una sola carpeta.

---

## 6. Modelo de datos

### 6.1 Bloque clientes

**`empresas`** — cada fila es un cliente tuyo

```
id, nombre_comercial, razon_social, nit, codigo_sistema,
token_delegado (cifrado), api_key_hash, codigo_ambiente,
codigo_modalidad, estado, webhook_url, timestamps

unique(nit, codigo_ambiente)
```

**`certificados`** — por empresa

```
id, empresa_id, contenido_p12 (cifrado), passphrase (cifrada),
emitido_por, vence_el, activo, timestamps
```

**`sucursales`** — por empresa

```
id, empresa_id, codigo_sucursal, nombre, municipio,
direccion, telefono, timestamps

unique(empresa_id, codigo_sucursal)
```

**`puntos_venta`** — por sucursal

```
id, sucursal_id, codigo_punto_venta, nombre, tipo_punto_venta,
siguiente_factura, activo, timestamps

unique(sucursal_id, codigo_punto_venta)
```

### 6.2 Bloque códigos — historial, nunca se sobreescribe

| Tabla | Alcance | Vigencia | Depende de |
|---|---|---|---|
| `cuis` | punto de venta | ~1 año | — |
| `cufd` | punto de venta | 24 horas | CUIS |
| `cafc` | punto de venta | por rango | CUIS |

```
cuis:  id, punto_venta_id, codigo, fecha_vigencia, timestamps
cufd:  id, punto_venta_id, codigo, codigo_control, direccion,
       fecha_vigencia, timestamps
cafc:  id, punto_venta_id, codigo, cantidad_facturas,
       facturas_usadas, fecha_vigencia, timestamps
```

### 6.3 Catálogos GLOBALES — una sola copia para todos

```
unidades_medida              tipos_moneda
tipos_documento_identidad    tipos_emision
tipos_metodo_pago            tipos_factura
tipos_documento_sector       motivos_anulacion
tipos_punto_venta            eventos_significativos_cat
paises_origen                mensajes_servicios
tipos_contingencia
```

Se pueden guardar en una sola tabla `catalogos` con las columnas
`tipo`, `codigo_clasificador`, `descripcion`, o en tablas separadas.
Recomendación: **una sola tabla** con índice compuesto, es más simple
de sincronizar y de consultar. Frecuencia: semanal.

### 6.4 Catálogos POR EMPRESA — llevan `empresa_id`

```
actividades_economicas   las actividades registradas de ESE NIT
productos_servicios      homologación del SIN según sus actividades
leyendas_factura         dependen de la actividad económica
```

Aunque el SIN los llame "sincronización", el contenido cambia según el NIT
que consulta. Se sincronizan al dar de alta al cliente y semanalmente.

### 6.5 Bloque facturación

**`facturas`**

```
id, empresa_id, punto_venta_id, cufd_id, cafc_id (nullable),
cuf, numero_factura, fecha_emision,
comprador_tipo_documento, comprador_numero_documento,
comprador_complemento, comprador_razon_social, comprador_email,
metodo_pago, numero_tarjeta, moneda, tipo_cambio,
subtotal, descuento_global, gift_card, anticipo,
monto_total, monto_total_moneda, monto_total_sujeto_iva,
leyenda, usuario, codigo_documento_sector, tipo_emision,
estado, codigo_recepcion, codigo_estado_siat,
xml_firmado (longtext), ruta_pdf, referencia_externa,
enviada_en, validada_en, timestamps

unique(empresa_id, referencia_externa)
index(cuf)
```

**`factura_items`**

```
id, factura_id, codigo_producto_sin, codigo_interno, descripcion,
cantidad, unidad_medida, precio_unitario, descuento,
subtotal, numero_serie, numero_imei
```

**`facturas_anuladas`**

```
id, factura_id, motivo, codigo_recepcion, anulada_en
```

### 6.6 Bloque operación

```
eventos_significativos:  id, empresa_id, punto_venta_id, codigo_evento,
                         descripcion, cufd_evento, fecha_inicio, fecha_fin,
                         codigo_recepcion, estado

paquetes:                id, empresa_id, punto_venta_id, evento_id,
                         cantidad_facturas, codigo_recepcion,
                         estado, enviado_en

logs_siat:               id, empresa_id, servicio, operacion,
                         xml_enviado, xml_recibido, duracion_ms,
                         exitoso, mensaje_error, created_at
```

### 6.7 Bloque pruebas

```
casos_prueba:        id, fase, orden, nombre, descripcion, tipo,
                     payload_ejemplo (json), obligatorio

ejecuciones_prueba:  id, empresa_id (nullable en fase 1), caso_id,
                     estado, respuesta (json), duracion_ms,
                     intento, ejecutado_en
```

Los casos viven en base de datos, no en código: cuando el SIN cambie
el manual de pruebas, se editan registros.

---

## 7. Comunicación con el SIAT

### 7.1 Los servicios SOAP

| Servicio | Para qué |
|---|---|
| `FacturacionSincronizacion` | fecha/hora del SIN y todos los catálogos |
| `FacturacionCodigos` | verificar comunicación, CUIS, CUFD, CAFC, verificar NIT |
| `FacturacionOperaciones` | puntos de venta, eventos significativos |
| `ServicioFacturacionCompraVenta` | recepción, anulación, verificación |
| `ServicioFacturacionElectronica` | operaciones propias de la modalidad |

### 7.2 Anatomía de toda petición

El token **no va en el cuerpo del mensaje**, va en la cabecera HTTP:

```
Authorization: Token {token_delegado_de_la_empresa}
Content-Type:  text/xml
```

Campos presentes en casi todas las operaciones:

```
codigoAmbiente      1 producción · 2 piloto
codigoSistema       el que asignó el SIN
codigoModalidad     1 electrónica
nit                 del cliente emisor
codigoSucursal      0 = casa matriz
codigoPuntoVenta
cuis                cuando aplica
cufd                cuando aplica
```

### 7.3 Operaciones que consume el sistema

```
SINCRONIZACIÓN             CÓDIGOS                 OPERACIONES
fechaHora                  verificarComunicacion   registroPuntoVenta
listaActividades           cuis                    consultaPuntoVenta
listaProductosServicios    cufd                    cierrePuntoVenta
listaLeyendas              cafc                    registroEvento
listaMensajes              verificarNit            consultaEvento
paramétricas (varias)

FACTURACIÓN
recepcionFactura            envío individual en línea
recepcionPaqueteFactura     envío agrupado de contingencia
validacionRecepcionPaquete  confirma el procesamiento del paquete
verificacionEstadoFactura   consulta el estado por CUF
anulacionFactura
```

> **Obligatorio:** contrastar nombres exactos de operaciones y campos contra
> el WSDL vigente antes de implementar. El SIN los ajusta entre versiones.
> Con `'trace' => true` en el `SoapClient` se puede leer el XML enviado
> mediante `__getLastRequest()`.

---

## 8. Ciclos de vida y sincronización

### 8.1 CUIS

```
Cuándo se pide:   al dar de alta un punto de venta
Duración:         aproximadamente un año
Depende de:       nada previo
Renovación:       cron diario que revisa vencimientos
```

### 8.2 CUFD — estrategia de dos capas

```
CAPA 1 — Preventiva (cron cada hora)
  Renueva los CUFD que vencen dentro de las próximas 2 horas.
  Evita que la primera venta del día tenga que esperar.

CAPA 2 — Reactiva (al emitir)
  Antes de armar cualquier factura se verifica el CUFD.
  Si venció, se solicita uno nuevo en ese instante.
  Garantiza que nunca se emita sin CUFD aunque el cron falle.
```

El `codigo_control` del CUFD es la pieza que entra al cálculo del CUF.

### 8.3 CAFC

```
Cuándo se pide:   por adelantado, como reserva
Para qué:         emitir cuando no hay internet o el SIAT está caído
Regla operativa:  cada punto de venta mantiene un CAFC vigente de reserva
Alerta:           si un punto de venta queda sin CAFC disponible
```

### 8.4 Catálogos

```
GLOBALES                        POR EMPRESA
Cron: domingos 03:00            Al alta del cliente + domingos 04:00
Una sola ejecución              Una ejecución por empresa activa
Usa credenciales de             Usa las credenciales de cada cliente
cualquier empresa activa

Antes de reescribir se compara: si el catálogo no cambió, no se toca.
```

### 8.5 Sucursales y puntos de venta

```
SUCURSALES
  El contribuyente las registra en su Oficina Virtual.
  Tu sistema NO las crea en el SIAT: las copia a tu base
  para poder referenciarlas.

PUNTOS DE VENTA
  Estos SÍ los crea tu sistema. Flujo completo:
    1. Alta en tu panel o vía API del cliente
    2. registroPuntoVenta al SIAT
    3. Confirmación del SIAT
    4. Solicitar CUIS de ese punto de venta
    5. Solicitar primer CUFD
    6. Queda habilitado para facturar

  Cierre: cierrePuntoVenta. Un PV cerrado no vuelve a abrirse.
```

### 8.6 Tareas programadas

| Frecuencia | Tarea |
|---|---|
| Cada 5 min | Reintentar facturas pendientes de envío |
| Cada 15 min | Verificar estado de facturas enviadas sin confirmar |
| Cada hora | Renovar CUFD próximos a vencer |
| Diario 02:00 | Revisar vigencia de CUIS y disponibilidad de CAFC |
| Diario 07:00 | Alertar certificados que vencen en menos de 30 días |
| Domingo 03:00 | Sincronizar catálogos globales |
| Domingo 04:00 | Sincronizar catálogos por empresa |

---

## 9. Emisión de facturas

### 9.1 Flujo completo

```
 1  Llega POST /api/v1/facturas con la API key
 2  Middleware identifica la empresa dueña de esa key
 3  Se valida el estado de la empresa (debe estar en PRODUCCIÓN)
 4  Se valida el JSON: comprador, ítems, montos, método de pago
 5  Se verifica CUFD vigente (si venció, se pide uno nuevo)
 6  Se reserva el siguiente número de factura del punto de venta
    con bloqueo de fila, para que dos cajas no tomen el mismo
 7  Se genera el CUF
 8  Se arma el XML según el XSD de compra-venta
 9  Se firma el XML con el .p12 del cliente
10  Se guarda la factura en estado PENDIENTE
11  Se responde al cliente con el CUF  <-- ~150 ms
12  Un worker comprime en GZIP, calcula el hash SHA-256
    y envía recepcionFactura al SIAT
13  Se actualiza el estado y se notifica por webhook
```

Los pasos 1 al 11 son síncronos. Del 12 en adelante, asíncronos.

### 9.2 Composición del CUF

```
Se concatenan en este orden exacto:

  NIT del emisor .................. 13 dígitos
  Fecha y hora de emisión ......... 17 dígitos
  Código de sucursal .............. 4
  Modalidad ....................... 1
  Tipo de emisión ................. 1
  Tipo de factura ................. 2
  Tipo documento sector ........... 2
  Número de factura ............... 10
  Punto de venta .................. 4
                                    54 dígitos

  -> dígito verificador con módulo 11
  -> conversión a base 16
  -> concatenar el código de control del CUFD

  = CUF
```

El CUF se calcula **localmente, antes de enviar**. Por eso una factura
emitida sin internet ya es legalmente válida y solo queda pendiente
de transmisión.

### 9.3 Estados de la factura

```
PENDIENTE --> ENVIADA --> RECIBIDA --> VALIDADA
    |             |
    |             +--> OBSERVADA --> corregir y reenviar
    |
    +--> CONTINGENCIA --> paquete --> VALIDADA

VALIDADA --> ANULADA (con motivo, dentro del plazo permitido)
```

---

## 10. Contrato de la API REST

### 10.1 Autenticación

```
X-Api-Key:          {clave de 48 caracteres}
X-Idempotency-Key:  {id único de la venta en el sistema del cliente}
Content-Type:       application/json
```

La API key identifica a la **empresa**, no a un usuario.
La clave de idempotencia evita que un reintento genere dos facturas:
si llega repetida, se devuelve la factura ya emitida.

### 10.2 `POST /api/v1/facturas` — lo que ENVÍA el sistema de ventas

```json
{
  "sucursal": 0,
  "punto_venta": 0,
  "referencia_externa": "VTA-2026-00184",
  "comprador": {
    "tipo_documento": 1,
    "numero_documento": "1023456",
    "complemento": null,
    "razon_social": "JUAN PEREZ",
    "email": "juan@correo.com"
  },
  "metodo_pago": 1,
  "numero_tarjeta": null,
  "moneda": 1,
  "tipo_cambio": 1.00,
  "descuento_global": 0.00,
  "gift_card": 0.00,
  "anticipo": 0.00,
  "usuario": "caja-01",
  "items": [
    {
      "codigo_producto_sin": 99100,
      "codigo_interno": "TOR-14",
      "descripcion": "Tornillo autoperforante 1/4",
      "cantidad": 100,
      "unidad_medida": 57,
      "precio_unitario": 1.50,
      "descuento": 0.00,
      "numero_serie": null,
      "numero_imei": null
    }
  ]
}
```

### 10.3 Lo que RECIBE el sistema de ventas

**Éxito — 201**

```json
{
  "exito": true,
  "factura": {
    "cuf": "8B4A2C9E1F...D3C1",
    "numero_factura": 128,
    "sucursal": 0,
    "punto_venta": 0,
    "fecha_emision": "2026-07-21T14:32:08",
    "estado": "PENDIENTE",
    "leyenda": "Ley N° 453: El proveedor debe...",
    "total": 150.00,
    "url_verificacion": "https://siat.impuestos.gob.bo/consulta/QR?...",
    "url_pdf": "https://tuapi.com/facturas/8B4A2C9E.pdf",
    "url_xml": "https://tuapi.com/facturas/8B4A2C9E.xml"
  }
}
```

**Observada por el SIAT — 422**

```json
{
  "exito": false,
  "error": "FACTURA_OBSERVADA",
  "mensaje": "El SIAT rechazó la factura",
  "detalles": [
    { "codigo": 1016, "descripcion": "Actividad económica no corresponde" }
  ]
}
```

**Emitida en contingencia — 202**

```json
{
  "exito": true,
  "factura": {
    "cuf": "8B4A2C9E1F...D3C1",
    "numero_factura": 128,
    "estado": "CONTINGENCIA",
    "mensaje": "Factura válida. Pendiente de envío al SIAT.",
    "url_pdf": "https://tuapi.com/facturas/8B4A2C9E.pdf"
  }
}
```

El cliente puede imprimir igual: la factura ya tiene CUF y es válida.

### 10.4 Endpoints completos

| Método | Ruta | Envía | Recibe |
|---|---|---|---|
| POST | `/api/v1/facturas` | venta completa | CUF + PDF |
| GET | `/api/v1/facturas/{cuf}` | — | estado actual |
| POST | `/api/v1/facturas/{cuf}/anular` | motivo | confirmación |
| GET | `/api/v1/facturas` | filtros de fecha | listado paginado |
| GET | `/api/v1/puntos-venta` | — | sus PV habilitados |
| POST | `/api/v1/puntos-venta` | nombre y tipo | PV creado en el SIAT |
| GET | `/api/v1/catalogos/{tipo}` | — | unidades, métodos de pago, actividades |
| GET | `/api/v1/estado` | — | salud: CUFD vigente, SIAT arriba |

### 10.5 Errores estandarizados

| HTTP | Código | Significado |
|---|---|---|
| 401 | `API_KEY_INVALIDA` | La clave no existe |
| 403 | `EMPRESA_NO_HABILITADA` | Aún en pruebas o suspendida |
| 422 | `DATOS_INVALIDOS` | Falla la validación del JSON |
| 422 | `FACTURA_OBSERVADA` | El SIAT la rechazó |
| 409 | `FACTURA_DUPLICADA` | Ya existe con esa referencia |
| 429 | `DEMASIADAS_PETICIONES` | Superó el límite por minuto |
| 202 | `EN_CONTINGENCIA` | Emitida, pendiente de envío |
| 503 | `SIAT_NO_DISPONIBLE` | Sin contingencia configurada |

### 10.6 Webhooks

Cuando una factura cambia de estado después de responder:

```json
POST {webhook_url configurada por el cliente}

{
  "evento": "factura.validada",
  "cuf": "8B4A2C9E1F...D3C1",
  "referencia_externa": "VTA-2026-00184",
  "estado": "VALIDADA",
  "codigo_recepcion": "9081726354"
}
```

Eventos: `factura.validada`, `factura.observada`, `factura.anulada`.

---

## 11. Rendimiento y autenticación

### 11.1 Autenticación: API key propia, no Sanctum

Sanctum está pensado para tokens ligados a un modelo `User`. Aquí la llave
identifica a una **empresa** en comunicación servidor-a-servidor.
Un middleware propio con la key hasheada y la empresa en caché es más simple
y más rápido.

```
Se guarda:      hash SHA-256 de la API key
Nunca:          la llave en texto plano
Al validar:     hash de la key recibida -> buscar en caché -> si no,
                consulta a base de datos -> cachear 5 minutos
```

### 11.2 Dónde se va el tiempo

```
Validar API key (caché) ..........    1 ms
Validar el JSON ..................    5 ms
Leer CUFD (caché) ................    1 ms
Generar el CUF ...................    1 ms
Armar el XML .....................   10 ms
Firmar con el .p12 ...............   60 ms
                       TOTAL LOCAL   ~80 ms

Enviar al SIAT (SOAP) .....  800 ms a 4 segundos   <- el cuello de botella
```

### 11.3 Decisión de diseño

```
Se responde al cliente apenas se firma el XML   -> ~150 ms
Un worker envía al SIAT en segundo plano        -> 1-3 s después
El webhook avisa el estado final                -> al confirmar
```

El cajero imprime en 150 milisegundos en vez de esperar 4 segundos.
Esto **no es contingencia**: la factura se emite normal, solo que
la transmisión es asíncrona.

### 11.4 Optimizaciones obligatorias en producción

| Elemento | Medida |
|---|---|
| API keys | Caché Redis, 5 minutos |
| CUFD vigente | Caché Redis con expiración al vencimiento |
| Catálogos | Caché Redis, invalidar al sincronizar |
| WSDL | `WSDL_CACHE_DISK` en producción (`WSDL_CACHE_NONE` solo en desarrollo) |
| Colas | Redis + Horizon para monitoreo |
| Respuestas | Compresión gzip |
| Listados | Paginación obligatoria |
| Rate limit | Por empresa, para que un cliente con un bug no afecte a los demás |
| Índices | `cuf`, `empresa_id + referencia_externa`, `punto_venta_id + fecha_vigencia` |

---

## 12. Fases de prueba ante el SIN

El SIN maneja **tres fases** y no son lo mismo. El panel debe reflejarlo.

```
FASE 1 — PRUEBAS (una sola vez, de tu sistema)
  Se realizan con tu propio NIT en el ambiente piloto.
  Al completarlas se presiona "Finalizar Pruebas" en el portal del SIN,
  lo que habilita programar fecha y hora de la inspección.

FASE 2 — INSPECCIÓN (una sola vez, supervisada)
  Pruebas funcionales bajo supervisión del SIN, para verificar que el
  sistema cumple la normativa y tiene las funcionalidades mínimas.
  Si se supera, el sistema queda autorizado.

FASE 3 — PRUEBAS PILOTO (cada vez que entra un cliente nuevo)
  Integración de tu sistema ya autorizado con el ecosistema de cada
  cliente. Pasos:
    1. El proveedor asocia su sistema con el contribuyente
    2. El contribuyente confirma la asociación
    3. El contribuyente genera el token delegado y lo entrega
    4. Ambos configuran el ecosistema: clientes, productos,
       sucursales, puntos de venta

  Estas pruebas NO son contabilizadas ni controladas por la
  Administración Tributaria: son responsabilidad del contribuyente
  y del proveedor. Tu panel es la evidencia.
```

### 12.1 El panel refleja las dos fases automatizables

```
/admin/sistema/pruebas              -> Fase 1, una sola vez
    Pruebas del sistema con tu propio NIT

/admin/empresas/{id}/pruebas        -> Fase 3, por cada cliente
    Requisitos previos (manuales, en el portal del SIN):
      [ ] Asociación registrada
      [ ] Contribuyente confirmó la asociación
      [ ] Token delegado recibido y cargado
      [ ] Certificado .p12 cargado
    > Iniciar pruebas
```

El botón solo se habilita cuando los cuatro requisitos están marcados.
De lo contrario se depuran errores de token que en realidad son de trámite.

### 12.2 Secuencia automatizada por cliente

```
 1  Verificar comunicación
 2  Verificar el NIT del contribuyente
 3  Sincronizar fecha y hora del SIN
 4  Solicitar CUIS
 5  Solicitar CUFD
 6  Sincronizar catálogos paramétricos globales
 7  Sincronizar actividades económicas del NIT
 8  Sincronizar productos-servicios homologados
 9  Sincronizar leyendas de factura
10  Registrar punto de venta
11  Emitir factura contado — efectivo
12  Emitir factura con descuento
13  Emitir factura a NIT de empresa
14  Anular una factura
15  Registrar evento significativo
16  Emitir en contingencia y enviar paquete
17  Marcar el cliente como PILOTO_APROBADO
```

Cada paso guarda su XML enviado, la respuesta cruda y el tiempo de ejecución.
Si uno falla, la ejecución se detiene ahí y puede reintentarse desde ese punto
sin repetir los anteriores.

### 12.3 Estados de la empresa

```
EN_REGISTRO --> EN_PRUEBAS --> PILOTO_APROBADO --> PRODUCCIÓN
                     |
                     +--> OBSERVADO (con el detalle del error)
```

La API rechaza facturas de cualquier empresa que no esté en `PRODUCCIÓN`.

> La lista definitiva de casos la fija el documento de especificaciones que
> genera el SIN al confirmar cada asociación. Los pasos 1 al 10 son
> estructurales y no cambian.

---

## 13. Contingencia

```
DETECCIÓN
  El envío al SIAT falla o supera el timeout.

REACCIÓN INMEDIATA
  1. Se registra el evento significativo correspondiente
  2. Las facturas se emiten con CAFC y tipo de emisión "contingencia"
  3. Se responde al cliente con 202: puede seguir vendiendo

RECUPERACIÓN
  4. Un worker detecta que el SIAT volvió
  5. Se cierra el evento significativo
  6. Las facturas acumuladas se agrupan en paquete
  7. Se envía recepcionPaqueteFactura
  8. Se consulta la validación del paquete
  9. Se actualizan estados y se notifica por webhook
```

El plazo de envío del paquete lo fija la normativa vigente, contado desde
el cierre del evento. El sistema debe alertar si se acerca el límite.

---

## 14. Seguridad

| Elemento | Tratamiento |
|---|---|
| Token delegado | Cifrado en base de datos |
| Certificado `.p12` | Cifrado, nunca expuesto por la API |
| Passphrase | Cifrada, separada del archivo |
| API keys | Hash SHA-256, únicas por empresa, revocables |
| Aislamiento | Toda consulta filtra por `empresa_id` |
| Auditoría | Cada petición SOAP registrada con su XML |
| Respaldo | XML firmados almacenados: son el documento legal |
| HTTPS | Obligatorio en producción |
| Rate limit | Por empresa |

---

## 15. Plan de construcción

| Fase | Entregable | Estado |
|---|---|---|
| 1 | Base multi-cliente + conexión al piloto (CUIS, CUFD) | Entregada |
| 2 | Panel `/admin` en Blade: clientes, certificados, sucursales, PV | Entregada |
| 3 | Sincronización de catálogos globales y por empresa | Entregada |
| 4 | Generador de CUF + constructor de XML + firma digital | Entregada |
| 5 | Emisión, verificación y anulación de facturas | Entregada |
| 6 | Panel de pruebas piloto automatizado (fases 1 y 3) | Entregada |
| 7 | API REST pública + documentación para clientes | Entregada |
| 8 | PDF con QR, colas, contingencia, webhooks, caché | Entregada |
| + | Login del panel + carga de códigos CUIS/CUFD/CAFC por panel | Entregada |

---

## 16. Estado actual

**Las 8 fases están construidas** (más login del panel y carga de códigos por
panel). Suite de pruebas: **36 tests Pest en verde**. Migraciones y seed corren
en SQLite. Formato con Pint limpio.

### Cómo levantar el proyecto

```
php artisan migrate:fresh --seed
php artisan serve        # panel en /admin, login en /login
php artisan queue:work   # procesa envíos al SIAT, webhooks, etc.
```

**Credenciales del panel (usuario sembrado):**

```
admin@admin.com  /  password        (cambiar en producción)
```

### Qué se puede hacer desde el panel (`/admin`)

- Alta/edición de empresas (con token delegado) — genera la API key una sola vez
- Carga del certificado `.p12` (cifrado en reposo)
- Sucursales y puntos de venta
- Por punto de venta: solicitar CUIS/CUFD/CAFC al SIAT **o** cargarlos a mano
  (para probar sin conexión al SIN). Sin CUFD vigente no se puede emitir.
- Panel de pruebas piloto (secuencia de casos) y monitor de peticiones SOAP

### API REST (`/api/v1`, la consume el sistema de ventas del cliente)

`POST /facturas`, `GET /facturas/{cuf}`, `POST /facturas/{cuf}/anular`,
`GET /facturas`, `GET|POST /puntos-venta`, `GET /catalogos/{tipo}`,
`GET /estado`, más descarga de PDF y XML. Autenticación por `X-Api-Key` e
idempotencia por `X-Idempotency-Key`.

### Pendiente antes de producción (trámite / verificación, no código)

- Activar `extension=soap` y `extension=openssl` en `php.ini`
- Token delegado del piloto y certificado `.p12` **reales** cargados por panel
- Verificar contra el **WSDL/XSD vigente del SIN** (regla 7): nombres exactos de
  operaciones y campos SOAP, el algoritmo módulo-11 del CUF (pesos, manejo de
  10/11) y el perfil **XAdES-BES** (falta el bloque `QualifyingProperties`,
  marcado con `TODO XADES` en `FirmadorXml`). Cada punto es cambio de un archivo.
- Poner el panel `/admin` detrás de HTTPS y revisar rate limit en producción
- Redis + Horizon para colas y caché (hoy `database`/`array` en desarrollo)

---

*Documento maestro versión 3 — julio 2026.
Los nombres de operaciones y campos SOAP deben validarse contra el WSDL
vigente del SIAT antes de implementar cada servicio.*
