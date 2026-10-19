# API REST v1 — guía de integración

> Para el equipo que conecta un **sistema de ventas** (POS, ERP, tienda) con `siat-api`.
> El sistema de ventas solo manda JSON: el SOAP, la firma digital, los códigos del SIN y la
> contingencia los resuelve el proveedor.

Base: `https://{dominio-del-proveedor}/api/v1`

| En esta carpeta | Qué es |
|---|---|
| **API.md** (este archivo) | Guía de integración, referencia de cada endpoint y cómo probar con Postman |
| [openapi.yaml](openapi.yaml) | Especificación OpenAPI 3 (Postman, Insomnia, generadores de clientes) |
| [postman/](postman/) | `coleccion.json` (las 18 peticiones en orden) y `entorno.json` (sus variables) |

La misma documentación, navegable y con buscador: **`https://{dominio}/docs/api`** (desde ahí también
se descargan la colección, el entorno y el OpenAPI, ya apuntando al servidor correcto).

**Qué hace su sistema y qué hace esta API**

| Su sistema de ventas | Esta API |
|---|---|
| Registra la venta y manda el JSON | Calcula el CUF, arma y firma el XML, lo envía al SIN |
| Imprime la factura con el CUF recibido | Pide y renueva **sola** el CUIS y el CUFD |
| (Opcional) verifica el NIT antes de vender | Reintenta, verifica el estado y avisa por webhook |

---

## 0. Integración paso a paso

> ¿Prefiere probar con clics antes de programar? Vaya a la sección
> [7. Probar con Postman o Thunder Client](#7-probar-con-postman-o-thunder-client): importa una
> colección con todas las peticiones listas.

### Lo que le entrega el proveedor

| Dato | Para qué |
|---|---|
| URL base (`https://{dominio}/api/v1`) | Todas las llamadas |
| El **token** de su empresa | `Authorization: Bearer ...` en todas las llamadas, desde cualquier sucursal o caja |
| El **secreto de webhook** de su empresa (si usa webhooks) | Verificar `X-Siat-Signature` |
| Sus códigos de **sucursal** y **punto de venta** | Van en cada venta |

Para probar sin facturar de verdad, pida al proveedor una **empresa de pruebas** (ambiente piloto).

### Paso 1 — Configurar su sistema

Guarde la URL y el token como configuración secreta (variables de entorno), nunca en el código
ni en el navegador:

```bash
SIAT_API_URL=https://{dominio}/api/v1
SIAT_API_TOKEN=12|siat_live_4f8a...c91e
```

### Paso 2 — Probar la conexión

```bash
curl -s "$SIAT_API_URL/estado" -H "Authorization: Bearer $SIAT_API_TOKEN" -H "Accept: application/json"
```

Espere `"puede_facturar": true`. Si da `401`, el token está mal copiado (incluye el `12|`) o fue
revocado; si `puede_facturar` es `false`, la empresa aún no está en producción o no tiene puntos
de venta registrados: avise al proveedor.

### Paso 3 — Cargar sus datos de referencia (una vez al día)

```bash
curl -s "$SIAT_API_URL/sucursales" -H "Authorization: Bearer $SIAT_API_TOKEN"
curl -s "$SIAT_API_URL/catalogos/productos" -H "Authorization: Bearer $SIAT_API_TOKEN"
curl -s "$SIAT_API_URL/catalogos/unidades_medida" -H "Authorization: Bearer $SIAT_API_TOKEN"
curl -s "$SIAT_API_URL/catalogos/tipos_metodo_pago" -H "Authorization: Bearer $SIAT_API_TOKEN"
curl -s "$SIAT_API_URL/catalogos/tipos_documento_identidad" -H "Authorization: Bearer $SIAT_API_TOKEN"
```

Asocie cada producto de su sistema a un `codigo_producto_sin` homologado y a una unidad de medida.

### Paso 4 — (Opcional) Verificar el NIT del comprador

Solo cuando el comprador da un NIT (`tipo_documento = 5`):

```bash
curl -s "$SIAT_API_URL/nit/1023456028/verificar" -H "Authorization: Bearer $SIAT_API_TOKEN"
```

Mande en la venta el `codigo_excepcion` que devuelve (0 o 1).

### Paso 5 — Emitir

```bash
curl -s -X POST "$SIAT_API_URL/facturas" \
  -H "Authorization: Bearer $SIAT_API_TOKEN" \
  -H "X-Idempotency-Key: VENTA-000123" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{
    "sucursal": 0, "punto_venta": 0,
    "comprador": {"tipo_documento": 1, "numero_documento": "4567891", "razon_social": "JUAN PEREZ"},
    "metodo_pago": 1,
    "items": [{"codigo_producto_sin": 83141, "descripcion": "Martillo 16 oz", "cantidad": 2,
               "unidad_medida": 58, "precio_unitario": 45.50}]
  }'
```

Guarde el `cuf` de la respuesta junto a su venta e **imprima ya** abriendo `url_publica_rollo`
(ticket 80 mm) o `url_publica_pdf` (hoja): la factura es válida desde ese momento y **no tiene que
armarla usted**. Si hay un corte de red, repita la misma petición con la misma `X-Idempotency-Key`:
no se duplica.

### Paso 6 — Recibir el resultado

- **Con webhook** (recomendado): pida al proveedor que configure su URL https; recibirá
  `factura.validada` / `factura.observada` (§5).
- **Sin webhook**: consulte `GET /facturas/{cuf}` hasta que el estado sea `VALIDADA` u `OBSERVADA`
  (cada pocos minutos, no en un bucle).

### Paso 7 — Anular (perfil Administración)

```bash
curl -s -X POST "$SIAT_API_URL/facturas/{cuf}/anular" \
  -H "Authorization: Bearer $SIAT_API_TOKEN" -H "Content-Type: application/json" \
  -d '{"motivo": 1}'
```

### Paso 8 — Devolver mercadería: nota de crédito-débito (perfil Administración)

Si el comprador devuelve todo o parte de lo que compró, **no anule** la factura: emita una nota sobre
ella. Solo diga qué línea de la venta y cuánto se devuelve; la API calcula los montos (§2.10).

```bash
curl -s -X POST "$SIAT_API_URL/facturas/{cuf}/notas" \
  -H "Authorization: Bearer $SIAT_API_TOKEN" -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: DEVOLUCION-000045" \
  -d '{"items": [{"linea": 1, "cantidad": 1}]}'
```

### Lista de verificación antes de salir a producción

- [ ] Token guardado en el servidor de su sistema, no en cada caja ni en el navegador
- [ ] Token guardado como secreto; IP fija registrada si su servidor tiene una
- [ ] `X-Idempotency-Key` en cada emisión, con el id único de su venta
- [ ] Reintento ante timeout con la misma clave
- [ ] Manejo de `422` mostrando `detalles` al cajero
- [ ] Manejo de `503`: reintentar en unos minutos
- [ ] Webhook verificando `X-Siat-Signature` y deduplicando por `cuf` + `evento`
- [ ] Productos asociados a códigos homologados (`/catalogos/productos`)

---

## 1. Autenticación y cabeceras

La API usa **tokens Bearer** (Laravel Sanctum). Cada token pertenece a **una empresa** y solo ve
los datos de esa empresa.

```http
Authorization: Bearer 12|siat_live_4f8a...c91e
```

- **El token no se obtiene por la API:** lo genera el **sistema de facturación (su proveedor)**
  desde su panel de administración y se lo entrega a usted. No existe un endpoint de login.
- Su empresa tiene **un solo token**, que su sistema usa para todas sus sucursales y puntos de
  venta (cada venta indica en cuál se emite con `sucursal` y `punto_venta`).
- El proveedor se lo entrega **una sola vez**: no se puede recuperar. Si se pierde o se filtra,
  pida que lo **regeneren**: el anterior deja de funcionar en ese mismo momento y tiene que
  reemplazarlo en su sistema.
- El token tiene un **perfil de permisos**, puede **vencer** en una fecha y puede quedar
  restringido a ciertas **IPs**.
- Guárdelo como un secreto (variable de entorno o almacén de secretos), nunca en el código ni en
  el frontend.

### 1.1 Perfiles y permisos

| Permiso | Caja / POS | Administración | Solo consulta | Permite |
|---|:-:|:-:|:-:|---|
| `estado:leer` | ✅ | ✅ | ✅ | `GET /estado` |
| `catalogos:leer` | ✅ | ✅ | ✅ | `GET /catalogos/{tipo}` |
| `estructura:leer` | ✅ | ✅ | ✅ | `GET /puntos-venta` |
| `estructura:gestionar` | — | ✅ | — | `POST /puntos-venta` |
| `codigos:leer` | ✅ | ✅ | ✅ | `GET /puntos-venta/{s}/{pv}/codigos` |
| `codigos:renovar` | — | ✅ | — | `POST /puntos-venta/{s}/{pv}/cufd` |
| `facturas:emitir` | ✅ | ✅ | — | `POST /facturas`, `GET /nit/{nit}/verificar` |
| `facturas:leer` | ✅ | ✅ | ✅ | `GET /facturas`, `/{cuf}`, `/pdf`, `/xml` |
| `facturas:leer` | ✅ | ✅ | ✅ | también `GET /facturas/{cuf}/notas` |
| `facturas:anular` | — | ✅ | — | `POST /facturas/{cuf}/anular`, `/revertir-anulacion`, `/anulacion-administrativa`, `POST /facturas/{cuf}/notas` |
| `webhooks:gestionar` | — | ✅ | — | `/webhooks` (registrar, ver, cambiar, borrar, historial, reenviar) |

`facturas:emitir` permite también `POST /facturas/lote` y abrir/cerrar la emisión masiva;
`facturas:leer`, `GET /dashboard`; `estructura:leer`, `GET /certificado`; `estructura:gestionar`,
`POST /certificado` y `POST /puntos-venta/registrar-siat`.

> Los tokens de *Administración* generados antes de que existieran los webhooks por API no traen
> `webhooks:gestionar`: pida al proveedor que lo regenere.

Una caja **no puede anular ni emitir notas de crédito-débito**: las dos le devuelven plata al
comprador, así que un token de caja filtrado no sirve para ninguna.

### 1.2 Otras cabeceras

| Cabecera | Obligatoria | Uso |
|---|---|---|
| `X-Idempotency-Key` | Recomendada en `POST /facturas` y `POST /facturas/{cuf}/notas` | Id único de la venta en su sistema. Si el cuerpo no trae `referencia_externa`, se usa este valor |
| `Accept: application/json` | Recomendada | Los errores siempre vuelven en JSON |

**Límites:** 60 peticiones por minuto **por token** y 120 **por empresa** (los valores los
ajusta el proveedor). Al superarlos: `429 DEMASIADAS_PETICIONES` con cabecera `Retry-After`
(segundos). El límite por token hace que una caja con un bucle no frene a las demás.

**Ambientes (sandbox):** el token dice dónde factura. `siat_live_…` = **producción** (facturas con
validez fiscal). `siat_test_…` = **pruebas**: emite de verdad contra el SIAT piloto, con el mismo
flujo, CUF, PDF y webhooks, pero **sin validez fiscal**. Integre y pruebe con el de pruebas; para
pasar a producción solo cambia el token (la URL es la misma). Pida su token de pruebas al
proveedor; si ya factura en producción, él le crea una empresa *sandbox* aparte. `GET /estado`
responde `empresa.ambiente` (`PILOTO` o `PRODUCCION`) y `empresa.validez_fiscal`.

Cuando su empresa pasa del piloto a producción, el token de pruebas **deja de funcionar** y el
proveedor le entrega uno `siat_live_` nuevo: un token de pruebas nunca emite facturas reales.

**Habilitación:** consultar y descargar funciona en cualquier etapa. **Emitir** exige la empresa en
`PRODUCCION`; con un token de pruebas, basta con que el proveedor la tenga en pruebas.

---

## 2. Endpoints

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/estado` | Salud de la integración: ¿puedo facturar? |
| `GET` | `/catalogos/{tipo}` | Catálogos para armar la venta (incluye productos homologados y leyendas del NIT) |
| `GET` | `/sucursales` | Sucursales con sus puntos de venta activos |
| `GET` | `/puntos-venta` | Puntos de venta activos de la empresa |
| `POST` | `/puntos-venta` | Alta local de un punto de venta (no lo registra en el SIN) |
| `GET` | `/puntos-venta/{sucursal}/{pv}/codigos` | Vigencia de CUIS y CUFD (nunca los códigos) |
| `POST` | `/puntos-venta/{sucursal}/{pv}/cufd` | Forzar un CUFD nuevo (emergencia) |
| `GET` | `/nit/{nit}/verificar` | ¿El SIN reconoce este NIT? → `codigo_excepcion` |
| `POST` | `/facturas` | **Emite una factura** |
| `GET` | `/facturas` | Lista paginada (50 por página) |
| `GET` | `/facturas/{cuf}` | Estado de una factura |
| `GET` | `/facturas/{cuf}/pdf` | PDF imprimible |
| `GET` | `/facturas/{cuf}/xml` | XML firmado (documento legal) |
| `POST` | `/facturas/{cuf}/anular` | Anula una factura (o una nota) |
| `POST` | `/facturas/{cuf}/revertir-anulacion` | Revierte una anulación confirmada (una sola vez) |
| `POST` | `/facturas/{cuf}/anulacion-administrativa` | Registra una anulación aprobada por el SIN fuera de plazo |
| `POST` | `/facturas/{cuf}/notas` | **Emite una nota de crédito-débito** (devolución) |
| `GET` | `/facturas/{cuf}/notas` | Notas emitidas sobre una factura |
| `POST` | `/facturas/lote` | Emite hasta 20 ventas en una petición |
| `GET` | `/dashboard` | Resumen: mes, año, estados, tendencia |
| `POST` | `/masiva/{sucursal}/{pv}/abrir` · `/cerrar`, `GET /masiva/{sucursal}/{pv}` | Emisión masiva por lotes |
| `GET` `POST` `PUT` `DELETE` | `/webhooks…` | Sus destinos de webhook, historial y reenvío |
| `GET` `POST` | `/certificado` | Estado y renovación del certificado digital |
| `POST` | `/puntos-venta/registrar-siat` | Alta de un punto de venta en el SIN (irreversible) |

---

### 2.1 `GET /estado`

```json
{
  "exito": true,
  "empresa": { "estado": "PRODUCCION", "en_produccion": true, "ambiente": "PRODUCCION", "validez_fiscal": true },
  "puntos_venta_activos": 2,
  "puntos_venta_registrados_en_siat": 2,
  "puntos_venta_con_cufd": 1,
  "puede_facturar": true
}
```

`puede_facturar` = empresa habilitada para emitir (en producción, o en pruebas si es del ambiente
piloto) **y** al menos un punto de venta registrado en el SIN.
No hace falta CUFD vigente: si falta, la emisión lo pide en el momento.

### 2.2 `GET /catalogos/{tipo}`

Tipos globales (los sincroniza el proveedor cada semana):

| `tipo` | Contenido |
|---|---|
| `unidades_medida` | Para `items.*.unidad_medida` |
| `tipos_moneda` | Para `moneda` (hoy solo se acepta 1, bolivianos) |
| `tipos_documento_identidad` | Para `comprador.tipo_documento` (5 = NIT) |
| `tipos_metodo_pago` | Para `metodo_pago` |
| `motivos_anulacion` | Para `motivo` al anular |
| `tipos_documento_sector` | Referencia |
| `paises_origen` | Referencia |
| `tipos_punto_venta` | Para `tipo_punto_venta` |
| `actividades` | Actividades económicas **del NIT** de la empresa |
| `productos` | Productos **homologados** del NIT: los únicos `codigo_producto_sin` que se aceptan. Filas `{codigo, actividad, descripcion}` |
| `leyendas` | Leyendas por actividad. Filas `{actividad, descripcion}` |

Los catálogos del NIT se sincronizan solos cada día.

```json
{ "exito": true, "tipo": "unidades_medida", "datos": [ { "codigo": "58", "descripcion": "UNIDAD (SERVICIOS)" } ] }
```

### 2.2.1 `GET /sucursales`

```json
{
  "exito": true,
  "sucursales": [
    {
      "sucursal": 0, "nombre": "Casa matriz", "municipio": "La Paz", "direccion": "Av. 6 de Agosto 123", "telefono": "2222222",
      "puntos_venta": [ { "punto_venta": 0, "nombre": "Caja principal", "registrado_en_siat": true } ]
    }
  ]
}
```

### 2.3 `GET /puntos-venta`

```json
{
  "exito": true,
  "puntos_venta": [
    {
      "sucursal": 0,
      "punto_venta": 0,
      "nombre": "Caja principal",
      "tipo_punto_venta": 1,
      "activo": true,
      "registrado_en_siat": true,
      "tiene_cufd_vigente": true
    }
  ]
}
```

Solo se puede emitir con un punto de venta que tenga `registrado_en_siat: true` y
`tiene_cufd_vigente: true`. El punto de venta `0` de cada sucursal existe en el SIN sin registrarlo.

### 2.4 `POST /puntos-venta`

```json
{ "sucursal": 0, "nombre": "Caja 2", "tipo_punto_venta": 1 }
```

Crea el registro **local** con el siguiente código libre de la sucursal. Para poder facturar con
él, el proveedor debe registrarlo en el SIN desde el panel (es irreversible, por eso no se hace
solo). Responde `201` con el punto de venta.

---

### 2.4.1 `GET /puntos-venta/{sucursal}/{pv}/codigos`

La API pide y renueva **sola** el CUIS y el CUFD (cada hora renueva los CUFD por vencer, cada día
los CUIS vencidos, y si al emitir falta el CUFD lo pide en ese momento). Este endpoint solo
muestra el estado; **no devuelve los códigos**.

```json
{
  "exito": true, "sucursal": 0, "punto_venta": 0, "registrado_en_siat": true,
  "cuis": { "vigente": true, "vence": "2027-08-09T10:00:00-04:00" },
  "cufd": { "vigente": true, "vence": "2026-10-12T09:14:00-04:00" },
  "puede_facturar": true
}
```

### 2.4.2 `POST /puntos-venta/{sucursal}/{pv}/cufd` — emergencia

Fuerza un CUFD nuevo (y el CUIS antes, si venció). Normalmente **no hace falta**. Máximo **3 por
punto de venta y por hora**. Requiere perfil *Administración*.

| Respuesta | Cuándo |
|---|---|
| `201` `{ "cufd": { "vigente": true, "vence": "..." } }` | Obtenido |
| `409 PUNTO_VENTA_NO_REGISTRADO` | El punto de venta no existe en el SIN |
| `429 DEMASIADAS_RENOVACIONES` | Superó el máximo por hora (`Retry-After`) |
| `503 SIAT_NO_DISPONIBLE` | El SIN no respondió |

### 2.4.3 `GET /nit/{nit}/verificar`

Pregunta al SIN si el NIT existe y está activo. Úselo antes de facturar a un comprador con NIT
(tipo de documento 5) y mande en la venta el `codigo_excepcion` que devuelve. La respuesta se
recuerda 1 hora.

```json
{ "exito": true, "nit": "1023456028", "valido": false, "mensaje": "[994] NIT INEXISTENTE", "codigo_excepcion": 1 }
```

Errores: `422 NIT_INVALIDO` (no son solo dígitos) · `503 SIAT_NO_DISPONIBLE`.

### 2.5 `POST /facturas` — emitir

#### Cuerpo

| Campo | Tipo | Obligatorio | Notas |
|---|---|---|---|
| `sucursal` | entero ≥ 0 | sí | Código de sucursal del SIN (0 = casa matriz) |
| `punto_venta` | entero ≥ 0 | sí | Código de punto de venta del SIN |
| `referencia_externa` | texto ≤ 100 | no | Id de la venta en su sistema. Clave de idempotencia |
| `comprador.tipo_documento` | entero | sí | Catálogo `tipos_documento_identidad` (1 = CI, 5 = NIT…) |
| `comprador.numero_documento` | texto ≤ 50 | sí | |
| `comprador.complemento` | texto ≤ 5 | no | Complemento del CI |
| `comprador.razon_social` | texto ≤ 255 | sí | |
| `comprador.email` | email | no | |
| `codigo_excepcion` | 0 \| 1 | no | `1` = emitir aunque el NIT no valide en el SIN. **Solo con `tipo_documento = 5`** |
| `metodo_pago` | entero | sí | Catálogo `tipos_metodo_pago` |
| `numero_tarjeta` | texto ≤ 20 | no | Puede mandarse completo: se guarda **enmascarado** (4 primeros + ceros + 4 últimos) |
| `moneda` | entero | no | Solo `1` (bolivianos). Otro valor → 422 |
| `tipo_cambio` | número | no | Solo `1` |
| `descuento_global` | número ≥ 0 | no | Descuento adicional sobre el total |
| `gift_card` | número ≥ 0 | no | Monto pagado con gift card; no puede superar el total |
| `anticipo` | número | no | **No admitido** en compra-venta: solo `0` o ausente |
| `usuario` | texto ≤ 100 | no | Cajero |
| `leyenda` | texto ≤ 500 | no | Si no viene, se toma del catálogo de leyendas del NIT |
| `items` | arreglo ≥ 1 | sí | |
| `items.*.codigo_producto_sin` | entero | sí | Producto **homologado** por el SIN para el NIT |
| `items.*.codigo_interno` | texto ≤ 100 | no | Código en su sistema |
| `items.*.descripcion` | texto ≤ 500 | sí | |
| `items.*.cantidad` | número > 0 | sí | |
| `items.*.unidad_medida` | entero | sí | Catálogo `unidades_medida` |
| `items.*.precio_unitario` | número ≥ 0 | sí | En bolivianos |
| `items.*.descuento` | número ≥ 0 | no | Descuento de la línea; no puede superar cantidad × precio |
| `items.*.numero_serie` | texto ≤ 100 | no | |
| `items.*.numero_imei` | texto ≤ 100 | no | |

#### Cálculo de montos

```
subtotal línea   = cantidad × precio_unitario − descuento
monto total      = Σ (cantidad × precio_unitario) − Σ descuentos de línea − descuento_global
sujeto a IVA     = monto total − gift_card
```

Todo se redondea a 2 decimales.

#### Ejemplo

```http
POST /api/v1/facturas
Authorization: Bearer 12|siat_live_4f8a...c91e
X-Idempotency-Key: VENTA-000123
Content-Type: application/json

{
  "sucursal": 0,
  "punto_venta": 0,
  "comprador": {
    "tipo_documento": 5,
    "numero_documento": "1023456028",
    "razon_social": "FERRETERIA EL MARTILLO SRL",
    "email": "compras@martillo.bo"
  },
  "metodo_pago": 1,
  "items": [
    {
      "codigo_producto_sin": 83141,
      "codigo_interno": "MART-001",
      "descripcion": "Martillo de carpintero 16 oz",
      "cantidad": 2,
      "unidad_medida": 58,
      "precio_unitario": 45.50,
      "descuento": 1.00
    }
  ]
}
```

#### Respuesta `201 Created` (o `202 Accepted` si nació en contingencia)

```json
{
  "exito": true,
  "factura": {
    "cuf": "4A1B9C...F3E2",
    "numero_factura": 128,
    "sucursal": 0,
    "punto_venta": 0,
    "fecha_emision": "2026-10-11T10:15:42-04:00",
    "estado": "PENDIENTE",
    "codigo_estado_siat": null,
    "motivo_observacion": null,
    "leyenda": "Ley N° 453: ...",
    "total": 90.0,
    "referencia_externa": "VENTA-000123",
    "url_verificacion": "https://siat.impuestos.gob.bo/consulta/QR?nit=...&cuf=...&numero=128&t=2",
    "url_publica": "https://{dominio}/factura/4A1B9C...F3E2?signature=a8f3...",
    "url_publica_pdf": "https://{dominio}/factura/4A1B9C...F3E2/pdf?signature=...",
    "url_publica_rollo": "https://{dominio}/factura/4A1B9C...F3E2/rollo?signature=...",
    "url_pdf": "https://{dominio}/api/v1/facturas/4A1B9C...F3E2/pdf",
    "url_xml": "https://{dominio}/api/v1/facturas/4A1B9C...F3E2/xml"
  }
}
```

`url_verificacion` es la consulta pública del SIN (la que abre el QR): en piloto apunta a
`pilotosiat.impuestos.gob.bo`.

La factura **ya es válida** al recibir el CUF: puede imprimirse. El estado final (`VALIDADA` u
`OBSERVADA`) llega por webhook o consultando `GET /facturas/{cuf}`.

#### Idempotencia

Si se repite la petición con la misma `referencia_externa` (o `X-Idempotency-Key`), se devuelve la
factura ya emitida, **sin crear otra** y sin consumir número. Ante un timeout de red, reintente
siempre con la misma clave.

---

### 2.6 `GET /facturas`

Parámetros opcionales:

| Parámetro | Ejemplo | Para qué |
|---|---|---|
| `desde`, `hasta` | `2026-10-01` | Rango de fecha de emisión |
| `estado` | `OBSERVADA` | Uno de los estados de §4 (otro valor → 422) |
| `sucursal` + `punto_venta` | `0` y `1` | Facturas de un punto de venta (van juntos) |
| `referencia_externa` | `VENTA-000123` | **Encontrar su venta después de un timeout** sin respuesta |
| `page` | `2` | Página |

```json
{
  "exito": true,
  "facturas": [ { "cuf": "...", "estado": "VALIDADA", "...": "..." } ],
  "paginacion": { "total": 340, "pagina": 1, "por_pagina": 50 }
}
```

### 2.7 `GET /facturas/{cuf}`

Devuelve `{ "exito": true, "factura": { ... } }` con la misma forma que la emisión.

### Imprimir y compartir la factura (su sistema NO la arma)

La API ya arma la factura completa. En la respuesta de la emisión (y de `GET /facturas/{cuf}`)
vienen **enlaces públicos** que se abren **sin token**:

| Campo | Qué abre | Para qué |
|---|---|---|
| `url_publica` | Página con la factura, botones de descarga, impresión y verificación en el SIN | Mostrarla en pantalla o **mandársela al comprador** (WhatsApp, correo) |
| `url_publica_pdf` | PDF tamaño carta | Imprimir en hoja o archivar |
| `url_publica_rollo` | PDF para **impresora térmica de 80 mm** | Imprimir el ticket en caja |
| `url_publica_mini` | Mini ticket: emisor, número, monto y QR (sin detalle) | Comprobante corto de caja; el QR lleva a la factura completa |
| `url_publica_zip` | ZIP con el XML firmado, el PDF carta y el rollo | Archivar o mandar todo junto |

Su sistema solo abre o manda a imprimir uno de esos enlaces. Están **firmados**: nadie puede ver
otra factura cambiando el CUF (responde 404). No vencen. También llegan en el webhook (`url_publica`).

### 2.8 `GET /facturas/{cuf}/pdf` y `/xml`

- `pdf`: `application/pdf`. Si todavía no existe, se genera en el momento.
- `xml`: `application/xml`, el documento firmado que recibió el SIN.

### 2.9 `POST /facturas/{cuf}/anular`

```json
{ "motivo": 1 }
```

`motivo` del catálogo `motivos_anulacion`; un código fuera del catálogo responde `422`.

| Situación | Respuesta |
|---|---|
| Factura `ENVIADA`, `RECIBIDA` o `VALIDADA` | `200` — "Anulación registrada. Pendiente de confirmación del SIAT." |
| Ya estaba `ANULADA` | `200` — "La factura ya estaba anulada." |
| Otro estado (`PENDIENTE`, `OBSERVADA`, `CONTINGENCIA`) | `409 FACTURA_NO_ANULABLE` |
| Factura con notas de crédito-débito vigentes | `409 FACTURA_NO_ANULABLE` — anule primero las notas |
| `motivo` fuera del catálogo | `422` |

La factura pasa a `ANULADA` de inmediato y la anulación se transmite al SIN en segundo plano:
- Si el SIN la confirma → webhook `factura.anulada`.
- Si el SIN la rechaza (fuera de plazo, motivo inválido) → la factura **vuelve a su estado
  anterior** y llega el webhook `factura.anulacion_rechazada`.
- Si el SIAT está caído, se reintenta sola hasta que responda.

Una **nota de crédito-débito** se anula igual, con su propio CUF.

La respuesta de `GET /facturas/{cuf}` trae `anulacion` (`tipo` `SIAT` o `ADMINISTRATIVA`, `estado`,
`motivo`, `numero_resolucion`, `anulada_en`) cuando se pidió alguna vez la anulación.

### 2.9.1 `POST /facturas/{cuf}/revertir-anulacion`

Sin cuerpo. La factura (o nota) anulada vuelve a estar vigente ante el SIN. Es **síncrono**: espera
la respuesta del SIN.

| Situación | Respuesta |
|---|---|
| Anulación `CONFIRMADA` por servicio web | `200` con la factura (`estado` anterior, `anulacion.estado = REVERTIDA`) + webhook `factura.anulacion_revertida` |
| No está anulada, la anulación sigue `PENDIENTE` o fue administrativa | `409 FACTURA_NO_REVERTIBLE` con el porqué en `mensaje` |
| El SIN la rechazó o no respondió | `502 SIAT_NO_REVIRTIO`: la factura sigue anulada; puede reintentar |

El SIN permite revertir **una sola vez**: después, la factura ya no se puede anular por servicio web
(sí administrativamente).

### 2.9.2 `POST /facturas/{cuf}/anulacion-administrativa`

Por servicio web solo se anula **hasta el día 9 del mes siguiente** a la emisión (fuera de plazo el SIN
rechaza la anulación y llega `factura.anulacion_rechazada`). Pasado ese plazo se tramita ante el SIN;
cuando la aprueba, regístrela acá:

```json
{ "resolucion": "RA-2026-0042", "motivo": 1 }
```

- **No llama al SIAT**: el SIN ya la anuló de su lado. Queda `CONFIRMADA` al instante y llega el
  webhook `factura.anulada`.
- Vale para facturas `ENVIADA`, `RECIBIDA` o `VALIDADA` sin notas vigentes, aunque ya se haya
  revertido una anulación antes. Otro estado: `409 FACTURA_NO_ANULABLE`.
- No se revierte por servicio web.

### 2.10 `POST /facturas/{cuf}/notas` — nota de crédito-débito

Devolución total o parcial de una factura **`VALIDADA`** (documento sector 24 del SIN). Usted solo
dice **qué** se devuelve; precios, descuentos y crédito fiscal los toma la API de la factura, así la
nota nunca la contradice.

```json
{
  "referencia_externa": "DEVOLUCION-000045",
  "usuario": "caja-01",
  "items": [
    { "linea": 1, "cantidad": 1 },
    { "linea": 3, "cantidad": 0.5 }
  ]
}
```

| Campo | Obligatorio | Descripción |
|---|---|---|
| `items[].linea` | Sí | Posición del item en la factura, **desde 1**, en el orden en que lo mandó en la venta |
| `items[].cantidad` | Sí | Cuánto se devuelve de esa línea. No más de lo que queda por devolver |
| `referencia_externa` | No | Su id de la devolución (idempotencia, igual que la venta). También vale `X-Idempotency-Key` |
| `usuario` | No | Quién la emite. Si falta, el de la factura |

Cómo se calculan los montos:

- Cada línea devuelta va al **precio unitario de la factura**, con la parte proporcional del
  descuento de esa línea.
- `monto_devuelto` = suma de las líneas devueltas.
- `descuento` = la parte del `descuento_global` de la factura que corresponde a lo devuelto.
- `monto_credito_debito` = **13%** de (`monto_devuelto` − `descuento`): el crédito fiscal que se
  revierte.

Respuesta `201 Created`: `{"exito": true, "nota": {...}}` con la misma forma que una factura, más
`tipo_documento: "NOTA_CREDITO_DEBITO"`, `documento_sector: 24`, `factura_origen` (`cuf`,
`numero_factura`), `monto_devuelto`, `descuento` y `monto_credito_debito`. `numero_factura` es el
**número de la nota**: las notas tienen su propio correlativo.

Reglas:

- La nota es un documento más, **con su propio CUF**: se consulta (`GET /facturas/{cuf}`), se
  descarga (`/pdf`, `/xml`, enlaces públicos) y se anula (`POST /facturas/{cuf}/anular`) igual que
  una factura. Su estado llega con los mismos webhooks `factura.*`, con
  `tipo_documento: "NOTA_CREDITO_DEBITO"`.
- Se pueden emitir varias notas sobre la misma factura; entre todas no devuelven más de lo
  vendido. Anular una nota libera lo que devolvía.
- Una factura con notas vigentes **no se puede anular**: anule primero las notas.
- **Solo en línea.** El SIN no recibe notas en paquetes de contingencia: si el punto de venta está
  en contingencia responde `422` (reintente cuando vuelva el SIAT). Si el SIAT falla después de
  emitida, la nota queda `PENDIENTE` y se reenvía sola.
- Todavía no se emiten notas sobre facturas pagadas en parte con gift card.
- Errores de negocio: `422 NOTA_INVALIDA` con `detalles[]`, por ejemplo
  `"Linea 1: se piden devolver 3 y quedan 2 por devolver."`.
- `GET /facturas/{cuf}/notas` lista las notas de una factura. `GET /facturas` lista solo facturas;
  con `?tipo=nota` lista solo notas y con `?tipo=todos`, las dos (útil para buscar una nota por su
  `referencia_externa` tras un corte de red).

### 2.11 `POST /facturas/lote` — varias ventas en una petición

```json
{ "ventas": [ { "referencia_externa": "VENTA-000201", "sucursal": 0, "punto_venta": 0, "...": "..." },
              { "referencia_externa": "VENTA-000202", "sucursal": 0, "punto_venta": 0, "...": "..." } ] }
```

- Hasta **20** ventas, cada una con el formato de `POST /facturas` y **`referencia_externa`
  obligatoria** (sin repetir dentro del lote).
- Cada venta se emite por separado y viaja sola al SIN: una rechazada **no frena** a las demás.
- Responde `200` con `emitidas`, `rechazadas` y `resultados[]` en el mismo orden: cada uno con
  `exito` y la `factura`, o el `error` (`FACTURA_INVALIDA` con `detalles`, `SIAT_NO_DISPONIBLE`,
  `PUNTO_VENTA_NO_ENCONTRADO`). `exito` general es `true` solo si salieron todas.
- Reintentar el lote entero es seguro: las ya emitidas vuelven por su referencia.

No es la emisión masiva del SIN (§2.13): es un atajo para mandar varias ventas juntas.

### 2.12 `GET /dashboard`

Resumen para el tablero de su sistema: `facturas.total`, `facturas.mes` y `facturas.anio`
(`cantidad` y `monto`), `facturas.por_estado`, `facturas.tendencia` (últimos 6 meses) y
`notas_credito_debito.mes`. Los montos cuentan solo facturas **vigentes**; las notas van aparte.
Trae `ambiente` (`PILOTO` = sandbox).

### 2.13 Emisión masiva — `/masiva/{sucursal}/{pv}`

Para alto volumen (servicios básicos, cobranzas): en vez de un viaje al SIN por factura, se mandan
paquetes de hasta 500.

1. `POST /masiva/{sucursal}/{pv}/abrir` → abre un lote en el punto de venta (si ya hay uno abierto,
   devuelve ese).
2. Emita con `POST /facturas` (o `/facturas/lote`) como siempre. Mientras el lote esté abierto, cada
   factura sale con **tipo de emisión masiva** y queda en estado **`MASIVA`**: ya es válida, tiene CUF
   y se puede imprimir, pero todavía no viajó.
3. `POST /masiva/{sucursal}/{pv}/cerrar` → arma los paquetes y los manda en segundo plano. Lo que
   emita después del cierre ya sale en línea.
4. El resultado de cada factura llega por webhook (`factura.validada` / `factura.observada`).
   `GET /masiva/{sucursal}/{pv}` muestra el lote, sus facturas por estado y sus paquetes.

- **Requiere autorización del SIN** para emisión masiva en su NIT; sin ella el SIN rechaza el paquete
  y las facturas quedan `OBSERVADA` con el motivo.
- Si el SIAT cae con el lote abierto, manda la contingencia: lo que se emita sale fuera de línea.
- Las notas de crédito-débito no entran al lote: se emiten en línea siempre.

### 2.14 Autogestión: certificado y puntos de venta

- `GET /certificado` → `vence_el`, `vigente`, `dias_restantes`, `de_prueba`. Nunca devuelve el
  certificado ni su contraseña.
- `POST /certificado` (`multipart/form-data`: `archivo` = el `.p12`, `passphrase`) → lo abre antes
  de guardarlo; si no abre, responde `422` y **el anterior sigue activo**. Renuévelo antes de que
  venza: con el certificado vencido no se puede firmar.
- `POST /puntos-venta/registrar-siat` (`sucursal`, `nombre`, `tipo_punto_venta`) → da de alta un
  punto de venta **nuevo en el SIN** y le pide CUIS y CUFD. **Irreversible**: el SIN crea uno nuevo en
  cada llamada y asigna él el código; no se borra, solo se cierra. Mire antes `GET /puntos-venta`.
  Si responde `502` con "NO reintentes", el SIN pudo haberlo creado igual: avise al proveedor.

---

## 3. Errores

Todas las respuestas de error traen `"exito": false`.

| HTTP | `error` | Cuándo | Qué hacer |
|---|---|---|---|
| 401 | `TOKEN_INVALIDO` | Falta el token, no existe, venció o fue revocado | Pedir un token nuevo al proveedor |
| 403 | `IP_NO_PERMITIDA` | El token está restringido a otras IPs | Llamar desde la IP registrada o pedir que la agreguen |
| 403 | `PERMISO_INSUFICIENTE` | El perfil del token no incluye esa operación | Usar un token con el perfil adecuado |
| 403 | `EMPRESA_NO_HABILITADA` | La empresa no está en `PRODUCCION` | Esperar la habilitación |
| 404 | — | CUF o punto de venta inexistente / inactivo | Revisar códigos |
| 409 | `FACTURA_NO_ANULABLE` | Anular algo que el SIN no conoce, o con notas vigentes | — |
| 409 | `FACTURA_NO_REVERTIBLE` | Revertir algo no anulado, sin confirmar o administrativo | Leer `mensaje` |
| 502 | `SIAT_NO_REVIRTIO` | El SIN rechazó la reversión o no respondió | Reintentar; si persiste, avisar al proveedor |
| 422 | — (formato Laravel `message` + `errors`) | El JSON no cumple la forma (tipos, obligatorios, `moneda`, `anticipo`…) | Corregir el campo indicado |
| 422 | `NOTA_INVALIDA` + `detalles[]` | Devolución imposible: factura no validada, cantidad mayor a la pendiente, línea inexistente, contingencia | Corregir la devolución o reintentar cuando vuelva el SIAT |
| 422 | `FACTURA_INVALIDA` + `detalles[]` | Reglas de negocio: descuentos, gift card, producto no homologado, punto de venta no registrado en el SIN, sin certificado | Corregir la venta |
| 429 | `DEMASIADAS_PETICIONES` | Límite por minuto | Esperar `Retry-After` |
| 409 | `PUNTO_VENTA_NO_REGISTRADO` | Forzar CUFD en un punto de venta que el SIN no conoce | — |
| 422 | `NIT_INVALIDO` | El NIT tiene caracteres que no son dígitos | — |
| 429 | `DEMASIADAS_RENOVACIONES` | Más de 3 CUFD forzados por hora | Esperar `Retry-After` |
| 503 | `SIAT_NO_DISPONIBLE` | El SIN no responde y hacía falta (CUFD vencido, verificación de NIT) | Reintentar más tarde; si persiste, avisar al proveedor |

Ejemplo de `FACTURA_INVALIDA`:

```json
{
  "exito": false,
  "error": "FACTURA_INVALIDA",
  "mensaje": "La venta no cumple las validaciones previas al envio.",
  "detalles": ["Item 1: el descuento (50) supera el importe de la linea (45.5)."]
}
```

---

## 4. Estados de la factura

| Estado | Para el sistema de ventas significa |
|---|---|
| `PENDIENTE` | Emitida, válida, viajando al SIN |
| `RECIBIDA` | El SIN la recibió y la está validando |
| `ENVIADA` | Viajó en un paquete de contingencia |
| `VALIDADA` | Aceptada por el SIN. Estado final normal |
| `OBSERVADA` | Rechazada por el SIN. El porqué viene en `motivo_observacion` (y en el webhook). Requiere revisión con el proveedor |
| `CONTINGENCIA` | Emitida sin conexión con el SIN; válida, se enviará cuando vuelva |
| `ANULADA` | Anulada |
| `MASIVA` | Emitida en un lote de emisión masiva abierto: válida, viaja al cerrar el lote (§2.13) |

---

## 5. Webhooks

Si la empresa tiene `webhook_url` configurado, se envía un `POST` en cada cambio relevante.

### 5.1 Eventos

| Evento | Cuándo |
|---|---|
| `factura.validada` | El SIN validó la factura |
| `factura.observada` | El SIN la rechazó |
| `factura.anulada` | El SIN confirmó la anulación |
| `factura.anulacion_rechazada` | El SIN rechazó la anulación; la factura sigue vigente |

### 5.2 Petición

```http
POST {webhook_url}
Content-Type: application/json
X-Siat-Evento: factura.validada
X-Siat-Signature: sha256=5d41402abc4b2a76b9719d911017c592...

{"evento":"factura.validada","tipo_documento":"FACTURA","cuf":"4A1B...","referencia_externa":"VENTA-000123","estado":"VALIDADA","codigo_recepcion":"1234567","url_publica":"https://{dominio}/factura/4A1B...?signature=...","motivo":null,"emitido_en":"2026-10-11T10:16:03-04:00"}
```

- `tipo_documento` es `FACTURA` o `NOTA_CREDITO_DEBITO`: las notas avisan con los mismos eventos.
- `motivo` trae el porqué en `factura.observada` (ej. `"[1001] HASH INVALIDO"`) y en
  `factura.anulacion_rechazada`; en los demás eventos es `null`.
- Debe responder `2xx`. Si no, se reintenta 5 veces (10, 30, 60, 120 s).
- La URL debe ser **https** y apuntar a una IP pública.
- Puede llegar más de una vez: use `cuf` + `evento` para deduplicar.

### 5.3 Verificar la firma

Cada empresa tiene **su propio secreto**, que el proveedor muestra en la ficha de la empresa
(panel → Empresas → *Secreto webhook*). La firma es HMAC-SHA256 del **cuerpo exacto** recibido.

PHP:

```php
$cuerpo = file_get_contents('php://input');
$esperada = 'sha256='.hash_hmac('sha256', $cuerpo, $secreto);

if (! hash_equals($esperada, $_SERVER['HTTP_X_SIAT_SIGNATURE'] ?? '')) {
    http_response_code(401);
    exit;
}
```

Node.js:

```js
const crypto = require('crypto');
const esperada = 'sha256=' + crypto.createHmac('sha256', secreto).update(cuerpoCrudo).digest('hex');
const valida = crypto.timingSafeEqual(Buffer.from(esperada), Buffer.from(req.get('X-Siat-Signature') || ''));
```

> Verifique sobre el cuerpo **crudo**, antes de parsear el JSON: re-serializarlo puede cambiar
> el orden o el escapado y la firma deja de coincidir.

### 5.4 Varios destinos, por evento, con historial (`/webhooks`)

Además del webhook único que configura el proveedor, su sistema puede registrar **sus propios
destinos** (hasta 10), cada uno suscrito a lo que le interesa y con **su propio secreto**:

```bash
curl -s -X POST "$SIAT_API_URL/webhooks" -H "Authorization: Bearer $SIAT_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"url": "https://erp.miempresa.bo/siat", "eventos": ["factura.*", "siat.*"], "descripcion": "ERP"}'
```

La respuesta trae el `secreto` (`whsec_…`) **una sola vez**. Eventos: `*`, un grupo (`factura.*`,
`siat.*`, `certificado.*`, `cufd.*`) o uno exacto:

| Evento | Cuándo |
|---|---|
| `factura.validada`, `factura.observada`, `factura.anulada`, `factura.anulacion_rechazada`, `factura.anulacion_revertida` | Igual que §5.1 |
| `siat.conexion_perdida` | El SIAT dejó de responder: un punto de venta pasó a contingencia (sigue facturando fuera de línea) |
| `siat.conexion_restablecida` | Volvió: se cierra la contingencia y se envían las facturas pendientes |
| `certificado.por_vencer` | El certificado vence en 30, 15, 7, 3 o 1 días, o ya venció |
| `cufd.renovacion_fallida` | No se pudo renovar el CUFD de un punto de venta |

Cada aviso a estos destinos:

```http
POST {url}
X-Siat-Evento: siat.conexion_perdida
X-Siat-Entrega: 2f6c1d3e-…            (id único: úselo para deduplicar)
X-Siat-Signature: sha256=…            (HMAC-SHA256 del cuerpo con el secreto de ESE destino)

{"id":"2f6c1d3e-…","evento":"siat.conexion_perdida","ambiente":"PRODUCCION","emitido_en":"…","datos":{"sucursal":0,"punto_venta":1,"desde":"…"}}
```

- Los datos de los eventos `factura.*` son los mismos campos que en §5.2 (`cuf`, `estado`,
  `url_publica`, `motivo`…).
- Se reintenta 7 veces (10 s, 30 s, 1 min, 5 min, 15 min, 30 min, 1 h). Agotado, queda `FALLIDA`.
- `GET /webhooks/{id}/entregas` muestra cada aviso con su estado, intentos, código HTTP de su
  servidor y el cuerpo exacto; `POST /webhooks/entregas/{id}/reenviar` lo vuelve a mandar con el
  mismo id. `POST /webhooks/{id}/rotar-secreto` cambia el secreto (el anterior deja de valer en el
  acto). `PUT /webhooks/{id}` con `"activo": false` lo pausa.

---

## 6. Recomendaciones de integración

1. Llame a `GET /estado` al abrir caja; si `puede_facturar` es `false`, avise al proveedor.
   No necesita ocuparse de CUIS ni CUFD: la API los maneja sola.
2. Antes de facturar a un NIT, llame a `GET /nit/{nit}/verificar` y mande su `codigo_excepcion`.
3. Mande siempre `X-Idempotency-Key` con el id de su venta y reintente con la misma clave.
4. Imprima con el CUF que devuelve la emisión; no espere al estado `VALIDADA`.
5. Use los catálogos de la API para unidades de medida, métodos de pago y documentos.
6. Use solo productos homologados (`GET /catalogos/productos`).
7. Ante `OBSERVADA` u `anulacion_rechazada`, contacte al proveedor: el motivo queda registrado.

---

## 6.1 Agentes de IA (servidor MCP)

La misma facturación está disponible como **servidor MCP** para agentes de IA (Claude, Cursor,
VS Code y otros clientes MCP), en `https://{dominio}/mcp` (HTTP), con **el mismo token** en
`Authorization: Bearer …`. Herramientas: `estado-integracion`, `emitir-factura`,
`consultar-factura`, `listar-facturas`, `anular-factura` y `emitir-nota-credito-debito`. Cada una
exige el mismo permiso que su endpoint: con un token de caja, un agente tampoco puede anular. Con un
token `siat_test_` el agente factura en el piloto, sin validez fiscal.

Ejemplo de configuración de cliente:

```json
{ "mcpServers": { "facturacion": { "type": "http", "url": "https://{dominio}/mcp",
    "headers": { "Authorization": "Bearer 12|siat_test_..." } } } }
```

---

## 7. Probar con Postman o Thunder Client

Para probar la API **antes de programar nada**: en 10 minutos va a haber emitido, consultado y
descargado una factura haciendo clic. Necesita:

- los dos archivos de [`postman/`](postman/) — `coleccion.json` (las **18 peticiones**, ordenadas y
  con ejemplos) y `entorno.json` (sus **variables**). También se descargan, ya apuntando al
  servidor, desde `https://{dominio}/docs/api` → **Colección Postman** y **Entorno**;
- los datos que le entregó el proveedor: **URL base**, **token**, **código de sucursal** y
  **código de punto de venta**.

### Paso 1 — Instalar la herramienta (elija una)

| Herramienta | Cómo |
|---|---|
| **Postman** | Descárguelo de <https://www.postman.com/downloads/> e instálelo |
| **Thunder Client** | En Visual Studio Code: *Extensiones* → busque **Thunder Client** → *Instalar*. Aparece un rayo ⚡ en la barra izquierda |

---

### Paso 2 — Importar los dos archivos

**Postman**
1. Botón **Import** (arriba a la izquierda).
2. Arrastre `coleccion.json` **y** `entorno.json` a la ventana → **Import**.
3. Arriba a la derecha, en el selector de entornos, elija **"Facturación SIAT — mi empresa"**.

**Thunder Client**
1. Pestaña **Collections** → menú `≡` → **Import** → elija `coleccion.json`.
2. Pestaña **Env** → menú `≡` → **Import** → elija `entorno.json`.
3. En la colección, menú `…` → **Settings** → **Environment** → elija **"Facturación SIAT — mi empresa"**.

---

### Paso 3 — Completar sus variables (una sola vez)

Abra el entorno y reemplace los valores:

| Variable | Qué poner | Ejemplo |
|---|---|---|
| `base_url` | La URL base que le dio el proveedor, **terminada en `/api/v1`** | `https://facturas.proveedor.bo/api/v1` |
| `token` | El token que le entregó el **sistema de facturación** (su proveedor), **completo, con el número y la barra**. No se obtiene por la API | `12\|siat_live_4f8a...c91e` |
| `sucursal` | Su código de sucursal (0 = casa matriz) | `0` |
| `punto_venta` | Su código de punto de venta | `0` |
| `venta_id` | El id de **su** venta. Cámbielo para cada venta nueva | `VENTA-0001` |
| `cuf` | **Déjelo vacío**: se llena solo al emitir | |
| `cuf_nota` | **Déjelo vacío**: se llena solo al emitir una nota de crédito-débito | |
| `webhook_id`, `entrega_id` | **Déjelos vacíos**: se llenan solos en la carpeta 9 | |

**Guarde** el entorno (Ctrl+S en Postman, botón *Save* en Thunder).

> No hace falta configurar la autenticación en cada petición: la colección ya manda
> `Authorization: Bearer {{token}}` en todas.

---

### Paso 4 — Ejecutar las peticiones en orden

Abra cada petición y presione **Send**. Las carpetas están numeradas en el orden correcto.

#### 1. Probar la conexión
**¿Puedo facturar? (estado)** → debe responder **200** con:

```json
{ "exito": true, "puede_facturar": true }
```

| Si ve… | Significa | Qué hacer |
|---|---|---|
| `401 TOKEN_INVALIDO` | Token mal copiado o revocado | Revise que esté completo (incluye `12\|`) |
| `"puede_facturar": false` | Su empresa aún no está habilitada | Avise al proveedor |
| Error de conexión | `base_url` mal escrita | Revise que termine en `/api/v1` |

#### 2. Datos de referencia
Ejecútelas una por una y **mire las respuestas**: de ahí salen los códigos que va a usar.

- **Sucursales y puntos de venta** → confirme sus códigos y póngalos en `sucursal` y `punto_venta`.
- **Productos homologados de su NIT** → anote un `codigo` de producto: lo necesita para emitir.
- **Unidades de medida**, **Métodos de pago**, **Tipos de documento** → los códigos de los campos de la venta.

#### 3. Verificar NIT (opcional)
Cambie el NIT de la URL por el de un comprador real. La respuesta trae `codigo_excepcion` (0 o 1)
para poner en la venta si el comprador da NIT.

#### 4. Emitir factura
1. Abra **Emitir factura** → pestaña **Body**.
2. Cambie `codigo_producto_sin` por un código de **sus productos** (carpeta 2) y, si hace falta,
   `unidad_medida`.
3. **Send**. Debe responder **201**:

```json
{
  "exito": true,
  "factura": { "cuf": "4A1B9C...F3E2", "numero_factura": 1, "estado": "PENDIENTE", "total": 90.0, ... }
}
```

En la respuesta vienen **`url_publica`**, **`url_publica_pdf`** y **`url_publica_rollo`**:
cópielos al navegador y verá la factura lista, con su PDF y su ticket de 80 mm, **sin token**. Así
es como su sistema la imprime o se la manda al comprador: no tiene que armarla.

El **CUF se guarda solo** en la variable `cuf` (en Postman; en Thunder Client, si no se guarda,
cópielo de la respuesta y péguelo en la variable `cuf`).

**Pruebe la idempotencia:** presione **Send otra vez** sin cambiar nada. Recibe **la misma factura**
(mismo CUF y número): no se creó otra. Para emitir una venta **nueva**, cambie `venta_id` en el
entorno (`VENTA-0002`).

| Si ve… | Significa |
|---|---|
| `422` con `errors` | Un campo con formato inválido: el mensaje dice cuál |
| `422 FACTURA_INVALIDA` + `detalles` | Regla de negocio: producto no homologado, descuento mayor al importe, etc. |
| `403 EMPRESA_NO_HABILITADA` | Su empresa todavía no puede emitir |
| `503 SIAT_NO_DISPONIBLE` | El SIN no responde: reintente en unos minutos |

#### 5. Consultar facturas
- **Estado de la factura emitida** → vuelva a ejecutarla al rato: pasa de `PENDIENTE` a `VALIDADA`.
  Si sale `OBSERVADA`, el motivo está en `motivo_observacion`.
- **Descargar PDF** → en Postman use **Send and Download** (flecha junto a *Send*).
- **Descargar XML firmado** → el documento legal.
- **Buscar por su id de venta** → encuentra la factura con su `venta_id`, útil si se cortó la red.

#### 6. Devolución — nota de crédito-débito (perfil *Administración*)
Con la factura ya `VALIDADA`:

- **Emitir nota de crédito-débito** → devuelve 1 unidad de la línea 1. Responde 201 y guarda el
  CUF de la nota en la variable `cuf_nota`.
- **Notas de la factura** → la lista de notas de esa factura.
- **Anular la nota** → igual que anular una factura, con el CUF de la nota. Hace falta para el paso
  siguiente: **una factura con notas vigentes no se puede anular**.

#### 7. Anular y revertir (solo si su token es de perfil *Administración*)
**Antes**, ejecute **Estado de la factura emitida** y confirme que ya **no** está `PENDIENTE`
(debe decir `RECIBIDA` o `VALIDADA`). Una factura `PENDIENTE` todavía no llegó al SIN y no se puede
anular: responde `409 FACTURA_NO_ANULABLE`. Espere unos segundos y vuelva a mirar.

**Anular la factura emitida** → `{"motivo": 1}`. Responde 200 y la anulación se confirma con el SIN
en segundo plano. Con un token de perfil *Caja / POS* responde `403 PERMISO_INSUFICIENTE`: es lo
esperado.

**Revertir la anulación** → cuando la anulación ya está `CONFIRMADA` (mire `anulacion.estado` en
**Estado de la factura emitida**), la factura vuelve a estar vigente. Solo una vez.

**Anulación administrativa (fuera de plazo)** → registra una anulación que el SIN aprobó por trámite,
con su número de resolución. No llama al SIAT.

#### 8. Códigos del SIN
Solo para mirar: la API maneja el CUIS y el CUFD sola. **Forzar CUFD nuevo** es una emergencia;
no la use en pruebas normales.

#### 9. Webhooks (perfil *Administración*)
**Registrar webhook** guarda el `webhook_id` (y le muestra el secreto una vez). **Historial de
entregas** guarda la última en `entrega_id` para probar **Reenviar una entrega**. Cambie la `url`
del cuerpo por una suya (https y pública; para mirar lo que llega sirve un servicio como
webhook.site).

#### 10. Emisión masiva
**Abrir lote masivo** → emita una o más facturas (carpeta 4) → **Estado del lote** → **Cerrar lote
masivo**. Solo si el SIN autorizó la emisión masiva para su NIT.

---

### Paso 5 — Pasar a su sistema

Cada petición de la colección es exactamente lo que su sistema tiene que hacer:

| En Postman | En su código |
|---|---|
| `{{base_url}}` | La URL base, en su configuración |
| `Authorization: Bearer {{token}}` | El token, en una variable de entorno secreta del **servidor** |
| `X-Idempotency-Key: {{venta_id}}` | El id único de la venta en su sistema |
| El cuerpo de **Emitir factura** | El JSON que arma con los datos de la venta |

En Postman, el botón **`</>`** (*Code*) a la derecha muestra cualquier petición ya escrita en
PHP, JavaScript, Python, C#, Java y más: cópiela como punto de partida.

Referencia completa de cada campo y error: secciones 1 a 6 de esta guía o `https://{dominio}/docs/api`.

---

## Soporte

Ante una factura `OBSERVADA` (rechazada por el SIN), el motivo viene en `motivo_observacion` y en
el webhook. Si necesita ayuda, envíe al proveedor el **CUF** o su **referencia externa** de la venta:
con eso la ubicamos.
