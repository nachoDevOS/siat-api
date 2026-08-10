# Alta de un cliente — guía completa

> Cómo se registra una empresa contribuyente en este sistema, qué requisitos hace falta
> reunir, **de quién es cada uno** y en qué orden va todo.
>
> Este sistema opera como **PROVEEDOR** ante el SIN (solicitud R-1359, código de sistema
> `22848EC6F66C9401C16F7`). Cada cliente es un contribuyente distinto, con su propio NIT,
> su propio token y su propio certificado.

---

## 1. Lo primero: de quién es cada cosa

La confusión más común del alta es creer que las credenciales son del proveedor. **No lo
son.** Solo una cosa es tuya.

| Requisito | ¿De quién es? | ¿Quién lo tramita? | ¿Se comparte entre clientes? |
|---|---|---|---|
| **Código de sistema** | del **sistema** (tuyo) | vos, una sola vez (R-1359) | ✅ **sí**, el mismo para todos |
| **Token delegado** | del **cliente** | lo generás **vos**, después de que el cliente te delegue | ❌ **nunca** |
| **Certificado `.p12`** | del **cliente** | el cliente, ante una entidad certificadora | ❌ nunca |
| **NIT** | del **cliente** | — | ❌ nunca |
| **Sucursales** | del **cliente** | el cliente, en su Oficina Virtual | ❌ nunca |
| **Puntos de venta** | del **cliente** | **este sistema**, llamando al SIAT | ❌ nunca |
| **API key** | del **cliente** | **este sistema**, al dar de alta | ❌ nunca |
| URLs, timeouts, nombre del sistema | del **proveedor** | vos, en el `.env` | ✅ sí |

### El token delegado, en una frase

Es como un poder notarial: **la autoridad es del cliente, el papel lo tenés vos en la
mano.** El cliente te delega desde su Oficina Virtual; recién entonces su NIT aparece en
tu desplegable de *NIT Delegado* y vos generás el token desde **tu** cuenta.

Sin delegación previa del cliente no hay token que generar, y eso no lo podés hacer por él.

### Por qué el token no se puede reciclar

El token lleva adentro el NIT delegado (es un JWT) y en cada llamada viaja junto al NIT
de la empresa:

```php
// app/Services/Siat/ServicioBase.php
'codigoSistema'   => $this->empresa->codigo_sistema,   // compartido
'nit'             => (int) $this->empresa->nit,        // del cliente
```

Si el par (token, NIT) no coincide, el SIN rechaza la operación. Un token por NIT, siempre.

---

## 2. Requisitos por dueño

### 2.1 Del proveedor — una sola vez, ya está hecho

- [x] Solicitud de autorización **R-1359** aprobada, tipo de sistema **PROVEEDOR**
- [x] Código de sistema: `22848EC6F66C9401C16F7`
- [x] `.env` cargado con `SIAT_PROVEEDOR_*`, URLs por ambiente y `SIAT_WEBHOOK_SECRET`

Verificable en `/admin/configuracion` (solo lectura: un dedazo acá dejaría sin facturar a
todos los clientes a la vez).

### 2.2 Del cliente — antes de que toques el panel

El cliente hace esto **en su propia Oficina Virtual del SIN**. Sin esto no se puede
avanzar:

| # | Qué | Resultado |
|---|---|---|
| 1 | Solicitud de autorización de sistema, eligiendo **sistema de terceros / proveedor** → te selecciona a vos (`SolucionDigital-api`) | queda delegado |
| 2 | Registro de sus **sucursales** (la casa matriz es la sucursal `0`) | códigos de sucursal |
| 3 | Compra del **certificado digital `.p12`** ante una entidad certificadora autorizada | archivo `.p12` + passphrase |

Lo que te tiene que entregar: el **archivo `.p12` con su passphrase**, y los **códigos y
datos de sus sucursales** (código, municipio, dirección, teléfono).

> **Sobre la passphrase:** no la entrega el SIN. Es la contraseña que protege el archivo
> `.p12`, y la define quien lo genera — la entidad certificadora al emitirlo, o vos mismo
> si es un certificado de prueba.

> **Qué entidad certificadora:** **ADSIB** es la autoridad de certificación raíz del Estado
> boliviano, y la lista de entidades autorizadas la publica ATT/ADSIB. Cuáles acepta el SIN
> para facturación electrónica conviene confirmarlo con soporte del SIN antes de contratar
> (ver §10). Es un trámite con costo y demora.

> **Un `.p12` autofirmado no le sirve al SIN.** Sirve para probar local (ver §7), no para
> operar.

### 2.3 Tuyo, por cada cliente — después de la delegación

| # | Qué | Dónde |
|---|---|---|
| 4 | Generar el **token delegado** de ese NIT | tu Oficina Virtual → *Token Delegado* → seleccionar el NIT en **NIT Delegado** → **Solicitar** |
| 5 | Dar de alta la empresa y entregarle la **API key** | este panel |

---

## 3. Paso a paso

### Paso 0 — Prerrequisitos

Confirmá que el cliente ya hizo los tres puntos de §2.2 y que tenés en mano:

- NIT, razón social y nombre comercial
- Token delegado (JWT completo — ver aviso abajo)
- Archivo `.p12` + passphrase
- Códigos y datos de las sucursales

> ⚠️ **Copiá el token entero.** En la pantalla del SIN se ve cortado. Clic dentro del
> cuadro, `Ctrl+A`, `Ctrl+C`. Si falta un pedazo, **todas** las llamadas fallan por
> autenticación y el error no dice que el token esté truncado.

### Paso 1 — Crear la empresa

`/admin/empresas/create`

| Campo | Qué va | Obligatorio |
|---|---|---|
| Nombre comercial | como se lo conoce | ✅ |
| Razón social | como figura en el NIT | ✅ |
| NIT | **el del cliente** | ✅ |
| Código de sistema | viene precargado con el del proveedor — **dejalo** salvo que el SIN le haya dado uno propio | — |
| Token delegado | el JWT del cliente | — (pero sin él no se puede operar) |
| Ambiente | **Piloto** (2) | ✅ |
| Modalidad | **Electronica** (1) | ✅ |
| Estado | **EN_REGISTRO** | ✅ |
| Webhook URL | opcional, `https` y host público | — |

**Al guardar aparece la API key una única vez.** Se guarda solo su hash SHA-256; no se
puede recuperar después. Entregásela al cliente por un canal seguro — es lo que su sistema
de ventas pone en `X-Api-Key`.

> El sistema permite el mismo NIT dos veces si el ambiente es distinto
> (`UNIQUE(nit, codigo_ambiente)`): así el cliente puede existir en piloto y en producción
> a la vez, que es lo que pasa durante la homologación.

### Paso 2 — Cargar el certificado

En la ficha del cliente: archivo `.p12` + passphrase (+ emisor y fecha de vencimiento,
opcionales pero recomendados: el panel avisa 30 días antes de que venza).

Solo queda **un certificado activo** por empresa; al cargar uno nuevo el anterior pasa a
historial. Contenido y passphrase se guardan cifrados.

### Paso 3 — Cargar las sucursales

Código, nombre y —importante— **municipio, dirección y teléfono**: van dentro del XML de
cada factura.

La casa matriz es la sucursal **`0`**.

> Las sucursales **no** las crea este sistema en el SIAT: las registra el cliente en su
> Oficina Virtual y acá solo se copian para poder referenciarlas.

### Paso 4 — Crear los puntos de venta

Código, nombre y tipo de punto de venta. Arrancan con `siguiente_factura = 1`.

> Estos **sí** los registra este sistema llamando al SIAT (paso 10 del piloto).

### Paso 5 — Pedir los códigos del SIN

En cada punto de venta, **en este orden** (el sistema lo exige):

| Código | Vigencia | Para qué |
|---|---|---|
| **CUIS** | ~1 año | identifica el punto de venta ante el SIN. Necesario para pedir CUFD y CAFC |
| **CUFD** | 24 h | su **código de control entra al cálculo del CUF** — sin CUFD no hay factura |
| **CAFC** | por rango | opcional: reserva para emitir en contingencia |

Cada botón tiene dos variantes: **solicitar al SIAT** (uso real) o **cargar manual** (para
probar sin conexión, ver §7).

El CUFD se renueva solo: cron horario que renueva los que vencen en menos de 2 horas.

### Paso 6 — Correr el piloto

Pasá el cliente a **EN_PRUEBAS** y andá a `/admin/empresas/{id}/pruebas`.

Son 17 pasos. Los podés correr todos seguidos o de a uno para reintentar el que falló.

```
 1  Verificar comunicacion              10  Registrar punto de venta
 2  Verificar el NIT del contribuyente  11  Emitir factura contado - efectivo
 3  Sincronizar fecha y hora del SIN    12  Emitir factura con descuento
 4  Solicitar CUIS                      13  Emitir factura a NIT de empresa
 5  Solicitar CUFD                      14  Anular una factura
 6  Sincronizar catalogos globales      15  Registrar evento significativo
 7  Sincronizar actividades del NIT     16  Emitir en contingencia + paquete
 8  Sincronizar productos homologados   17  Marcar cliente PILOTO_APROBADO
 9  Sincronizar leyendas de factura
```

- **1 a 10** — estructurales, se ejecutan enteros. Los pasos de CUIS y CUFD **guardan** el
  código: los siguientes lo necesitan vigente.
- **11 a 16** — emiten documentos reales. Los datos que llevan (qué venta, qué motivo, qué
  código de evento) **los define la especificación que el SIN genera por contribuyente**:
  se cargan en `payload_ejemplo` desde el panel y no se inventan. Si falta, el paso falla
  diciendo exactamente qué cargar.
- **17** — solo verifica que los 16 anteriores estén en EXITOSO. **No cambia el estado**:
  quien aprueba el piloto es el SIN.

### Paso 7 — Producción

Cuando **el SIN** apruebe el piloto:

1. Marcá el cliente como **PILOTO_APROBADO**
2. Cambiá el **ambiente a Producción (1)**
3. Regenerá el **token delegado de producción** (el de piloto no sirve: son ambientes
   separados con credenciales separadas)
4. Pasá el estado a **PRODUCCION**

Recién ahí `POST /api/v1/facturas` deja de responder `403 EMPRESA_NO_HABILITADA`.

---

## 4. El checklist del panel

La ficha del cliente calcula solo qué le falta para la etapa siguiente y **deshabilita el
botón de avance** hasta completarlo. No hace falta acordarse de nada.

**En EN_REGISTRO** pide:
- Token delegado cargado
- Código de sistema asignado
- Certificado `.p12` activo
- Al menos una sucursal
- Al menos un punto de venta

**En EN_PRUEBAS / OBSERVADO** pide:
- CUIS vigente en algún punto de venta
- CUFD vigente en algún punto de venta
- Los 17 casos del piloto en EXITOSO

**En PILOTO_APROBADO** pide:
- Certificado vigente
- Ambiente de producción configurado (`codigo_ambiente = 1`)
- Webhook configurado (opcional, recomendado)

---

## 5. Ciclo de vida del estado

```
EN_REGISTRO → EN_PRUEBAS → PILOTO_APROBADO → PRODUCCION
                    ▲              │
                    └──────────────┴──► OBSERVADO
```

| Estado | Significa |
|---|---|
| `EN_REGISTRO` | reuniendo credenciales y estructura |
| `EN_PRUEBAS` | corriendo el piloto ante el SIN |
| `PILOTO_APROBADO` | el SIN aprobó; falta el trámite de habilitación |
| `PRODUCCION` | **único estado que puede facturar por la API** |
| `OBSERVADO` | el SIN observó algo; vuelve a pruebas a corregir |

El cambio de estado es **manual a propósito**: quien aprueba es el SIN, el panel solo
refleja lo que ya pasó afuera.

> Para dejar de atender a un cliente, pasalo a `OBSERVADO`. **No lo elimines**: el borrado
> cascadea a sus facturas, que son documentos fiscales con obligación de conservación. El
> sistema lo rechaza si tiene facturas emitidas.

---

## 6. Vencimientos y renovaciones

| Qué | Dura | Se renueva |
|---|---|---|
| **Token delegado** | ~1 año | a mano, en tu Oficina Virtual |
| **Certificado `.p12`** | según emisor | el cliente lo compra de nuevo; el panel avisa 30 días antes |
| **CUFD** | 24 h | automático, cron horario (2 h de anticipación) |
| **CUIS** | ~1 año | avisa `siat:revisar-codigos`, diario 02:00 |
| **CAFC** | por rango | avisa `siat:revisar-codigos` cuando se agota |

**Si todas las llamadas de un cliente empiezan a fallar por autenticación, mirá primero el
token.** Es la causa más común y el error del SIN no lo dice claro.

---

## 7. Alta de prueba, sin tocar el SIN

Para probar el panel, la emisión, el CUF y la firma sin credenciales reales:

1. Generá un `.p12` autofirmado:

```bash
openssl req -x509 -newkey rsa:2048 -keyout /tmp/clave.pem -out /tmp/cert.pem \
  -days 365 -nodes -subj "/CN=prueba-siat"
openssl pkcs12 -export -out ~/certificado-prueba.p12 \
  -inkey /tmp/clave.pem -in /tmp/cert.pem -passout pass:secreto
```

2. Creá la empresa con NIT y token inventados, **estado `PRODUCCION`** (para saltear el
   piloto).
3. Subí ese `.p12` (passphrase `secreto`), sucursal `0`, punto de venta `0`.
4. **Cargá el CUFD manual** — el código de control es obligatorio, ej. `A1B2C3`. CUIS y
   CAFC no hacen falta para emitir.
5. Emitir:

```bash
curl -X POST http://localhost:8000/api/v1/facturas \
  -H "X-Api-Key: TU_API_KEY" -H "Content-Type: application/json" \
  -d '{"sucursal":0,"punto_venta":0,"referencia_externa":"VENTA-001",
       "comprador":{"tipo_documento":1,"numero_documento":"1023456","razon_social":"JUAN PEREZ"},
       "metodo_pago":1,
       "items":[{"codigo_producto_sin":99100,"descripcion":"Tornillo","cantidad":10,
                 "unidad_medida":57,"precio_unitario":1.5}]}'
```

Esperás **201** con el CUF. La factura queda `PENDIENTE` porque el worker no puede hablar
con el SIN: eso es correcto.

Sin catálogos sincronizados, `actividadEconomica` y `leyenda` van vacíos. Es a propósito:
un cliente recién dado de alta tiene que poder emitir. Para probarlos, cargá una fila en
`productos_servicios` y otra en `leyendas_factura`.

---

## 8. Verificar la conexión real

Antes de correr el piloto entero, probá solo el transporte:

```bash
php artisan siat:probar {id_empresa}
```

No emite nada: resuelve el WSDL y confirma que el token es aceptado. Si carga, seguí con
los pasos 1 y 2 del panel del piloto.

Para ver qué expone el contrato del SIN:

```bash
php artisan siat:inspeccionar-wsdl {id_empresa} --servicio=compra_venta --tipos
```

---

## 9. Errores frecuentes

| Síntoma | Causa | Solución |
|---|---|---|
| Todas las operaciones fallan por autenticación | token truncado al copiar, o vencido, o de otro ambiente | copiá el JWT completo; regeneralo para el ambiente correcto |
| `403 EMPRESA_NO_HABILITADA` | la empresa no está en `PRODUCCION` | es correcto durante el piloto; la consulta y descarga sí funcionan |
| `503 SIAT_NO_DISPONIBLE` | no hay CUFD vigente en ese punto de venta | pedí un CUFD; revisá que el cron horario esté corriendo |
| `422` con "el codigo de producto N no esta homologado" | el producto no está en el catálogo del NIT | sincronizá catálogos, o corregí el código en la venta |
| `422` con "no tiene un certificado digital activo" | falta el `.p12` | la modalidad electrónica no admite documentos sin firmar |
| `404` al resolver el punto de venta | código de sucursal/PV inexistente, o el PV está inactivo | revisá los códigos del SIN, no los ids internos |
| El NIT del cliente no aparece en *NIT Delegado* | el cliente no completó la delegación | tiene que hacerlo él, en su Oficina Virtual |

---

## 10. Referencia rápida

**Trámites del cliente (su Oficina Virtual):** delegación del sistema · registro de
sucursales · certificado digital

**Trámites tuyos (tu Oficina Virtual):** token delegado de ese NIT — uno por cliente,
por ambiente

**En el panel:** crear empresa → certificado → sucursales → puntos de venta → CUIS → CUFD
→ piloto → producción

**Soporte del SIN:** https://siatinfo.impuestos.gob.bo/ · siat.facturacion@impuestos.gob.bo
· 800-10-3444

---

**Ver también:** [ANALISIS-SISTEMA.md](ANALISIS-SISTEMA.md) — arquitectura, modelo de datos
y estado de la implementación.
