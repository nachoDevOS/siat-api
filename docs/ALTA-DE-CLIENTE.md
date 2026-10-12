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
| **Tokens de API** | del **cliente** (uno por sistema que conecta) | **este sistema**, en *Acceso API* de la empresa | ❌ nunca |
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
| 5 | Dar de alta la empresa y entregarle su **token de API** (uno por empresa) | este panel |

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

**Después, abrí *Acceso API*** (botón en la cabecera de la ficha). Es la pantalla de la
integración de esa empresa, en cuatro bloques:

1. **Datos de conexión**: la URL base y sus códigos de sucursal y punto de venta, listos para
   entregar.
2. **Token**: **uno por empresa**, que el sistema del cliente usa para todas sus sucursales y
   puntos de venta. **Generar token** con el perfil (por defecto *Administración*), vencimiento e
   IPs opcionales. **Se muestra una única vez** (Sanctum guarda solo su hash). Si se filtra,
   **Regenerar token**: el anterior deja de funcionar al instante (todas las cajas del cliente
   paran hasta que cargue el nuevo).
3. **Webhook**: la URL `https` del sistema del cliente y su **secreto** (propio de la empresa,
   derivado de `SIAT_WEBHOOK_SECRET`), con el que verifica la cabecera `X-Siat-Signature`.
4. **Últimas llamadas** de sus sistemas a la API.

Entregale al cliente, por un canal seguro, los datos del bloque 1, su token y el secreto, junto
con la carpeta de integración [integracion/](integracion/) (guía y especificación OpenAPI).

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
| **CUIS** | ~1 año | identifica el punto de venta ante el SIN. Necesario para pedir CUFD |
| **CUFD** | 24 h | su **código de control entra al cálculo del CUF** — sin CUFD no hay factura |

Desde el panel se piden con **Solicitar CUIS** y **Solicitar CUFD** (siempre al SIAT; la carga
manual se quitó). **Solicitar CUIS** abre un diálogo con dos opciones:

- **Revisar CUIS** (la segura): mientras el actual siga vigente, el SIN devuelve **el mismo**
  (código 980) y no cambia nada.
- **Forzar CUIS nuevo**: cierra las operaciones del sistema en ese punto de venta, pide un CUIS
  **nuevo** y un CUFD atado a él. Avisa cuántas facturas PENDIENTES hay, porque pueden volver con
  913. Usalo sin ventas en curso y solo si hace falta (por ejemplo, el SIN rechaza el CUIS con 913).

Siempre que el SIN entrega un CUIS nuevo, el sistema pide también un CUFD atado a él: un CUFD
viejo con el CUIS nuevo hace rechazar las facturas (913). Hacerlo acá es opcional: **la API los maneja sola** — renueva cada hora los CUFD por
vencer, cada día los CUIS vencidos, y si al emitir falta el CUFD lo pide en ese momento. Los
sistemas de venta del cliente nunca ven ni manejan estos códigos.

### Paso 6 — Correr el piloto

Pasá el cliente a **EN_PRUEBAS** y andá a **Pruebas piloto** (botón en la cabecera de la ficha,
`/admin/empresas/{id}/pruebas`). El panel muestra las etapas tal como las lista el portal del SIN;
cómo correrlas y el estado de cada una está en [Piloto ante el SIN](#11-piloto-ante-el-sin), al
final de esta guía.

### Paso 7 — Producción

Cuando **el SIN** apruebe el piloto:

1. Marcá el cliente como **PILOTO_APROBADO**
2. Cambiá el **ambiente a Producción (1)**
3. Regenerá el **token delegado de producción** (el de piloto no sirve: son ambientes
   separados con credenciales separadas)
4. Pasá el estado a **PRODUCCION**

Recién ahí `POST /api/v1/facturas` deja de responder `403 EMPRESA_NO_HABILITADA`.

> Antes de facturar en producción, el certificado activo tiene que ser el de la entidad
> certificadora: con un autofirmado de prueba la emisión se rechaza. Después de pedir el CUIS
> de producción, tocá **Sincronizar ahora** en la tarjeta *Catálogos del NIT* de la ficha: sin
> productos homologados la emisión no puede resolver la actividad de cada ítem. De ahí en más
> se sincronizan solos cada día a las 03:30.

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
| **CUIS** | ~1 año | automático, `siat:revisar-codigos` diario 02:00 (avisa si no puede) |
| **Tokens de API** | el vencimiento que les pongas | *Acceso API* marca los vencidos; se crea uno nuevo |

**Si todas las llamadas de un cliente empiezan a fallar por autenticación, mirá primero el
token.** Es la causa más común y el error del SIN no lo dice claro.

---

## 7. Alta de prueba, sin tocar el SIN

Para probar el panel, la emisión, el CUF y la firma sin credenciales reales:

1. Creá la empresa con NIT y token delegado inventados, **ambiente piloto (2)** y
   **estado `PRODUCCION`** (para saltear el piloto).
2. En la ficha: **Generar certificado de prueba** (autofirmado; solo sirve en piloto), y
   sucursal `0` con punto de venta `0`.
3. Sin SIN no se puede pedir un CUFD desde el panel (la carga manual se quitó: un código de
   control mal pegado arruina todos los CUF). Para una prueba **solo local**, crealo a mano:

```bash
php artisan tinker --execute 'App\Models\Cufd::create(["punto_venta_id" => 1, "codigo" => "CUFD-LOCAL", "codigo_control" => "A1B2C3", "fecha_vigencia" => now()->addDay()]);'
```

4. En *Acceso API* → *Tokens*, creá un token con perfil *Caja / POS* y copialo.
5. Emitir:

```bash
curl -X POST http://localhost:8000/api/v1/facturas \
  -H "Authorization: Bearer 1|siat_TU_TOKEN" -H "Content-Type: application/json" \
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

**Ver también:** [TECNICO.md](TECNICO.md) — arquitectura, contrato con el SIN y base de datos ·
[INSTALACION-Y-DESPLIEGUE.md](INSTALACION-Y-DESPLIEGUE.md) — levantar y mantener el servidor.

---

## 11. Piloto ante el SIN

Cómo se corren las pruebas que el SIN exige para autorizar el sistema (*Oficina Virtual → Seguimiento de Autorización de Sistemas → Listado de Pruebas*) y en qué punto está cada etapa.

---

### Dónde y cómo

Panel → **Empresas → (empresa) → Pruebas** (`/admin/empresas/{id}/pruebas`).

- Cada etapa muestra sus pruebas con los parámetros del portal, las **esperadas** y las
  **correctas** (solo cuentan las respuestas con `transaccion = true`, o 908 en emisiones).
- **Correr prueba**: ejecuta una llamada en el momento (útil para depurar).
- **Completar**: encola solo lo que le falta a esa prueba.
- **Correr etapa**: hasta 10 llamadas van en el request; por encima, todo va a la cola
  (`EjecutarCasoPrueba`).
- **Limpiar conteo**: borra las ejecuciones **locales** (y sus jobs en cola) para volver a contar.
  No deshace nada ante el SIN ni borra facturas, CUIS o CUFD.
- **Cerrar operaciones**: aparece junto a un error de CUIS; llama `cierreOperacionesSistema` para
  que el SIN acepte emitir un CUIS nuevo.

Requisitos para correr: token delegado cargado (todas) y certificado `.p12` activo (solo las que
firman: IV, VI, VIII). Un autofirmado sirve **solo** en piloto.

> ⚠️ Las etapas largas corren en la cola `piloto`: el worker tiene que estar levantado **con las
> tres colas** y reiniciado después de cada cambio de código:
>
> ```bash
> php artisan queue:work --queue=facturas,default,piloto
> ```
>
> Sin `--queue` solo atiende `default`: las pruebas quedarían encoladas sin correr.

Las pruebas viven en `casos_prueba` (sembradas por `CasosPruebaSeeder`, con `etapa` y
`pruebas_esperadas`) y la lógica en `app/Services/Pruebas/EjecutorPruebas.php`.

---

### Etapas

Todas sobre la casa matriz (sucursal 0) con los puntos de venta **1** (registrado por API) y
**0** (implícito).

| Etapa | Qué pide el portal | Esperadas | Tipo de prueba | Estado |
|---|---|---|---|---|
| I | CUIS por punto de venta | 1 por PV | `solicitudCuis` | ✅ Hecha |
| II | Cada catálogo 50 veces por PV (18 catálogos) | 50 c/u | `sincronizacionCatalogo` | ✅ Hecha |
| III | 100 CUFD por PV | 100 c/u | `solicitudCufd` | ✅ Hecha |
| IV | 125 facturas compra-venta en línea por PV | 125 c/u | `emisionIndividual` | ⏳ Falta el `.p12` (passphrase) |
| V | 7 motivos de evento significativo × 5 por PV | 5 c/u | `eventoSignificativo` | ✅ Hecha |
| VI | Por motivo: 10 paquetes de 500 facturas (PV 1) y 10 de < 500 (PV 0); validar los 70 de cada PV | 10 / 70 | `paqueteContingencia`, `validacionPaquete` | ⏳ Siguiente, con CAFC |
| VII | Anular 125 facturas por PV (motivo FACTURA MAL EMITIDA) | 125 c/u | `anulacionEtapa` | ⏳ Depende de IV |
| VIII | 115 facturas firmadas por PV | 115 c/u | `emisionIndividual` | ⏳ Falta el `.p12` |
| XI | Revertir 125 anulaciones por PV | 125 c/u | `reversionAnulacion` | ⏳ Depende de VII |

Además siguen sembrados los **17 pasos del flujo original** (`etapa = null`): comunicación,
fecha/hora, NIT, CUIS, CUFD, catálogos, registro de PV, emisión, anulación, evento, paquete y
cierre. Los dos flujos están vivos.

---

### Notas por etapa

#### I — CUIS
Si ya existe un CUIS vigente, el SIN responde 980 con el mismo código: **no cuenta** como prueba,
pero el código se guarda. Junto al error aparece **Cerrar operaciones y pedir CUIS nuevo**: en un
solo clic cierra las operaciones del sistema en ese punto de venta (con el CUIS que el SIN tiene
vigente), repite la prueba —ahora el SIN entrega uno nuevo y cuenta— y pide un CUFD atado al
CUIS nuevo, para que las facturas de la etapa IV no vuelvan con 913.

Si el punto de venta del portal (p. ej. el PV 1) no está cargado en la ficha, la etapa lo crea
sola en la sucursal correspondiente y guarda ahí su CUIS y su CUFD. Las etapas II, III y IV hacen
lo mismo: si al punto de venta le falta el CUIS, lo piden al SIN antes de correr (980 = el mismo
CUIS vigente, se guarda igual). Solo fallan si la sucursal no está en la ficha o si el SIN no
entrega CUIS, mostrando su motivo.

#### II — Catálogos
Usa el CUIS del punto de venta de cada prueba. Un rechazo (`transaccion = false`) queda FALLIDO
con su motivo; antes se veía como "sincronizado: 0".

#### III — CUFD
Cada solicitud devuelve un CUFD nuevo atado al CUIS con el que se pidió.

#### IV y VIII — Emisión
Emite con `EmisorFactura` (mismo código que producción) sin encolar el envío, y envía en el
momento para leer la respuesta del SIN. Es la etapa que confirma si la firma XML-DSig alcanza o
hace falta XAdES-BES (ver [TECNICO.md → Firma digital](TECNICO.md#firma-digital)).

#### V — Eventos
Cada evento lleva el CUFD vigente y un `cufdEvento` distinto; el rango de fechas se calcula a
partir de la emisión del CUFD del evento.

#### VI — Paquetes (próxima)
- Los motivos **5, 6 y 7** corresponden a facturación manual y requieren **CAFC**, que ya se
  solicitó al SIN.
- Antes de correrla hay que:
  1. Volver a incorporar el CAFC (tabla, código por punto de venta y `<cafc>` en el XML y en el
     paquete). La migración `2026_09_14_..._eliminar_cafc_fuera_de_alcance_electronica` lo había
     quitado por considerarlo fuera de alcance.
  2. Resolver los problemas de contingencia de producción (ver
     [TECNICO.md → Pendientes](TECNICO.md#pendientes)); el generador del piloto
     (`GeneradorPaquetePrueba`) ya emite con `tipo_emision = 2` y CUF coherente.
  3. Contemplar el límite de 500 facturas por paquete.

#### VII y XI — Anulación y reversión
Anulan las facturas validadas en la etapa IV y luego revierten esas anulaciones
(`reversionAnulacionFactura`, sin motivo según el WSDL del piloto).

---

### Al terminar

1. Todas las pruebas en sus esperadas → el checklist de la ficha marca el requisito.
2. El SIN aprueba en el portal → en el panel, cambiar el estado a `PILOTO_APROBADO`.
3. Cargar el certificado de la entidad certificadora, pasar el ambiente a 1 y el estado a
   `PRODUCCION` (ver [ALTA-DE-CLIENTE.md](ALTA-DE-CLIENTE.md) paso 7).
