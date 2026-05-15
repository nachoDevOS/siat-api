# Arquitectura y Setup — siat-api + ventas

## Tabla de contenido

1. [Visión general](#1-visión-general)
2. [Diagrama de componentes](#2-diagrama-de-componentes)
3. [siat-api — requisitos y configuración](#3-siat-api--requisitos-y-configuración)
4. [ventas — requisitos y configuración](#4-ventas--requisitos-y-configuración)
5. [Orden de arranque de servicios](#5-orden-de-arranque-de-servicios)
6. [Bases de datos — esquema resumido](#6-bases-de-datos--esquema-resumido)
7. [Flujos principales end-to-end](#7-flujos-principales-end-to-end)
8. [Sincronización inicial de catálogos](#8-sincronización-inicial-de-catálogos)
9. [Credenciales SIAT — qué configurar y dónde](#9-credenciales-siat--qué-configurar-y-dónde)
10. [Checklist de puesta en marcha](#10-checklist-de-puesta-en-marcha)

---

## 1. Visión general

El sistema está dividido en dos aplicaciones Laravel independientes:

| Proyecto | Ruta local | Puerto sugerido | Rol |
|---|---|---|---|
| **siat-api** | `D:\garoto\projects\siat-api` | `8001` | Proxy SOAP ↔ REST. Habla con el SIN (SIAT). Genera CUF, XML, paquetes. |
| **ventas** | `D:\garoto\projects\ventas` | `8000` | Sistema de ventas. No habla con SIAT directamente. Delega todo a siat-api vía HTTP. |

```
Usuario → ventas (8000) → siat-api (8001) → SIN/SIAT (soap)
```

**ventas** nunca llama SOAP. Toda operación fiscal pasa por **siat-api**.  
**siat-api** no conoce ventas; es una API genérica reutilizable por cualquier sistema.

---

## 2. Diagrama de componentes

```
┌──────────────────────────────────────────────────────────────┐
│                         ventas                               │
│                                                              │
│  SaleController ──► SiatController ──► SiatApiClient        │
│                                              │               │
│  SaleInvoice (local)                         │ HTTP REST     │
│  InvoicePackage (local)                      │               │
│  SiatCuis / SiatCufd (espejo local)          │               │
└──────────────────────────────────────────────┼───────────────┘
                                               │
                                   ┌───────────▼───────────────┐
                                   │        siat-api            │
                                   │                            │
                                   │  CodigosController         │
                                   │  FacturacionController     │
                                   │  PaquetesController        │
                                   │  CatalogosController       │
                                   │          │                 │
                                   │   InvoiceService           │
                                   │   (CUF, XML, emit, pkg)    │
                                   │          │                 │
                                   │  CodigosService   (SOAP)   │
                                   │  FacturacionService (SOAP) │
                                   │  OperacionesService (SOAP) │
                                   │  SincronizacionService(SOAP│
                                   └──────────┼─────────────────┘
                                              │ SOAP / HTTPS
                                   ┌──────────▼─────────────────┐
                                   │   SIN Bolivia (SIAT)        │
                                   │   piloto: puerto 443        │
                                   │   produccion: puerto 443    │
                                   └─────────────────────────────┘
```

---

## 3. siat-api — requisitos y configuración

### 3.1 Requisitos de entorno

| Requisito | Versión mínima | Notas |
|---|---|---|
| PHP | 8.2 | Extensiones requeridas: `soap`, `zlib`, `bcmath`, `openssl`, `pdo_mysql`, `json`, `mbstring` |
| Composer | 2.x | |
| MySQL / MariaDB | 8.0 / 10.6 | Base de datos propia de siat-api (independiente de ventas) |
| Laravel | 13.8 | Ya instalado |
| Acceso a internet | Obligatorio | Necesita llegar a `pilotosiatservicios.impuestos.gob.bo` o `siatservicios.impuestos.gob.bo` por HTTPS |

> **Extensión `soap`**: verificar con `php -m | grep soap`. Si no aparece, habilitar en `php.ini` con `extension=php_soap.dll` (Windows) o `extension=soap` (Linux).
>
> **Extensión `bcmath`**: usada para la conversión base16 del CUF. Igual de crítica que `soap`.

### 3.2 Instalación

```bash
cd D:\garoto\projects\siat-api

composer install

cp .env.example .env
# Editar .env (ver sección 9)

php artisan key:generate

php artisan migrate

php artisan storage:link
# Crea storage/app/public → public/storage (para XMLs de facturas)

php artisan serve --port=8001
```

### 3.3 Variables de entorno (`.env`)

```dotenv
APP_NAME="SIAT API"
APP_ENV=local
APP_KEY=          # generado con key:generate
APP_DEBUG=true
APP_URL=http://localhost:8001

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=siat_api        # BD exclusiva de siat-api
DB_USERNAME=root
DB_PASSWORD=

# ──────────────────────────────────────────────
# Credenciales del emisor ante el SIN
# ──────────────────────────────────────────────
SIAT_TOKEN=           # JWT otorgado por el SIN para autenticar los SOAP
SIAT_NIT=             # NIT del contribuyente emisor (sin puntos ni guiones)
SIAT_CODIGO_SISTEMA=  # Código de sistema asignado por el SIN
SIAT_RAZON_SOCIAL=    # Nombre/razón social del emisor
SIAT_MUNICIPIO=       # Municipio donde opera
SIAT_TELEFONO=        # Teléfono del emisor

# ──────────────────────────────────────────────
# Parámetros de ambiente
# ──────────────────────────────────────────────
SIAT_CODIGO_AMBIENTE=2           # 2=Piloto/Pruebas, 1=Producción
SIAT_CODIGO_MODALIDAD=2          # 2=Computarizada en línea (el más común)
SIAT_CODIGO_DOCUMENTO_SECTOR=1   # 1=Compra/Venta
SIAT_TIPO_FACTURA_DOCUMENTO=1    # 1=Con crédito fiscal

# ──────────────────────────────────────────────
# Modo offline (opcional)
# ──────────────────────────────────────────────
SIAT_MODO_OFFLINE=false  # true = forzar contingencia aunque SIAT esté disponible

# ──────────────────────────────────────────────
# CAFC (solo si se opera en contingencia con código autorizado)
# ──────────────────────────────────────────────
SIAT_CAFC=           # Código CAFC otorgado por el SIN (dejar vacío si no aplica)
SIAT_CAFC_INICIO=1   # Primer número del rango autorizado
SIAT_CAFC_FIN=0      # Último número del rango (0 = sin límite)

# ──────────────────────────────────────────────
# Timeouts SOAP (segundos)
# ──────────────────────────────────────────────
SIAT_TIMEOUT_DEFAULT=5
SIAT_TIMEOUT_PAQUETE=15

# ──────────────────────────────────────────────
# Sanctum — token que usará ventas para autenticarse
# (crear con: php artisan tinker → User::factory()->create() → $user->createToken('ventas')->plainTextToken)
# ──────────────────────────────────────────────
```

### 3.4 Directorios de archivos SIAT

`InvoiceService` guarda los XMLs de facturas en:

```
storage/app/public/siat/
    pendientes/    ← XMLs de facturas 902 (contingencia, no enviadas aún)
    enviados/      ← XMLs de facturas validadas por el SIN (908)
```

Estos directorios se crean automáticamente. Necesitan permisos de escritura (`chmod 775 storage` en Linux).

### 3.5 Crear el token Sanctum para ventas

siat-api usa Laravel Sanctum para autenticar las peticiones que vienen de ventas.

```bash
# Dentro de siat-api
php artisan tinker

# Crear un usuario de sistema (solo una vez)
$user = App\Models\User::create([
    'name'     => 'ventas-sistema',
    'email'    => 'ventas@sistema.local',
    'password' => bcrypt('password-seguro'),
]);

# Generar el token
$token = $user->createToken('ventas')->plainTextToken;
echo $token;
# Copiar este valor → va en SIAT_API_TOKEN del .env de ventas
```

---

## 4. ventas — requisitos y configuración

### 4.1 Requisitos de entorno

| Requisito | Versión mínima | Notas |
|---|---|---|
| PHP | 8.1 | Extensiones: `pdo_mysql`, `openssl`, `mbstring`, `json`, `gd` (para QR/imágenes) |
| Composer | 2.x | |
| MySQL / MariaDB | 8.0 / 10.6 | BD propia de ventas |
| Node.js | 18+ | Para compilar assets con Vite |
| npm | 9+ | |
| Laravel | 10.x | Ya instalado |
| **siat-api corriendo** | — | ventas no arranca si siat-api no responde (solo falla cuando se factura) |

> ventas ya **no necesita** la extensión `soap` ni `bcmath`. Todo el SOAP está en siat-api.

### 4.2 Instalación

```bash
cd D:\garoto\projects\ventas

composer install
npm install

cp .env.example .env
# Editar .env

php artisan key:generate
php artisan migrate
php artisan storage:link
npm run dev    # desarrollo
# npm run build  # producción
php artisan serve --port=8000
```

### 4.3 Variables de entorno relevantes para siat-api (`.env` de ventas)

```dotenv
# ──────────────────────────────────────────────
# Conexión a siat-api
# ──────────────────────────────────────────────
SIAT_API_URL=http://localhost:8001        # URL donde corre siat-api
SIAT_API_TOKEN=<token-generado-arriba>    # Token Sanctum de siat-api

SIAT_API_TIMEOUT=10           # Timeout general (segundos)
SIAT_API_TIMEOUT_FACTURA=10   # Timeout para emitir facturas
SIAT_API_TIMEOUT_PAQUETE=60   # Timeout para enviar paquetes (operación lenta)
```

Estas variables son leídas desde `config/siat_api.php` en ventas.

### 4.4 Migración pendiente

Después del último refactor, ejecutar:

```bash
php artisan migrate
```

Esto agrega:
- `sale_invoices.siat_invoice_id` — FK al `invoices.id` de siat-api
- `invoice_packages.siat_package_id` — FK al `invoice_packages.id` de siat-api

Ambas columnas son `nullable` para compatibilidad con registros anteriores al refactor.

---

## 5. Orden de arranque de servicios

Para que el sistema funcione, los servicios deben estar corriendo en este orden:

```
1. MySQL/MariaDB (siat-api BD)
2. MySQL/MariaDB (ventas BD)     ← puede ser la misma instancia MySQL, bases distintas
3. siat-api   →  php artisan serve --port=8001
4. ventas     →  php artisan serve --port=8000
```

Verificar que siat-api responde antes de usar ventas:

```bash
curl -H "Authorization: Bearer <token>" http://localhost:8001/api/v1/codigos/ping
# Debe devolver: {"ok":true}
```

---

## 6. Bases de datos — esquema resumido

### 6.1 siat-api BD (`siat_api`)

| Tabla | Qué almacena |
|---|---|
| `users` | Usuario(s) de sistema con tokens Sanctum |
| `personal_access_tokens` | Tokens Sanctum generados |
| `siat_cuis` | Registros CUIS por `codigo_sucursal` + `codigo_punto_venta` |
| `siat_cufd` | Registros CUFD por `codigo_sucursal` + `codigo_punto_venta` |
| `siat_cafc_contadores` | Contador de nroFactura para emisión CAFC (contingencia) |
| `invoices` | Facturas emitidas: CUF, XML, estado (902/908), referencia externa |
| `invoice_packages` | Paquetes de contingencia enviados al SIN |
| `siat_actividades` | Catálogo CAEB de actividades económicas |
| `siat_leyendas` | Leyendas obligatorias del XML |
| `siat_motivos_anulacion` | Motivos de anulación de facturas |
| `siat_eventos_significativos` | Tipos de evento para contingencia |
| `siat_tipo_documentos` | Tipos de documento de identidad (CI, NIT, etc.) |
| `siat_metodo_pagos` | Métodos de pago aceptados por el SIN |
| `siat_unidad_medidas` | Unidades de medida (para `<detalle>`) |
| `siat_productos` | Catálogo de productos/servicios del SIN |
| `siat_tipo_monedas` | Tipos de moneda |
| `siat_tipo_punto_ventas` | Tipos de punto de venta |

**Columnas clave de `invoices`:**

```
id               PK auto
external_id      ID de la venta en ventas (ej: "sale-123"), para rastreo
invoice_package_id FK → invoice_packages (null = en línea o pendiente)
nroFactura       Número correlativo
cuf              Código Único de Factura (calculado con mod11 + base16)
codigo_sucursal  Código SIAT de la sucursal
codigo_punto_venta Código SIAT del punto de venta
fechaEmision     Fecha de emisión (Y-m-d\TH:i:s.v)
xml              XML completo de la factura (necesario para paquetes)
codigoEstado     902=Pendiente, 908=Validada
reversed         true si la anulación ya fue revertida (no se puede volver a revertir)
```

### 6.2 ventas BD (su propia base)

Las tablas `siat_*` en ventas son **espejo parcial** de los códigos activos; no replican los catálogos de siat-api.

| Tabla | Rol en ventas |
|---|---|
| `siat_cuis` | Espejo del CUIS activo por `branch_id` + `codigo_punto_venta` |
| `siat_cufd` | Espejo del CUFD activo con `codigoControl`, `direccion`, `fechaVigencia` |
| `sale_invoices` | Resumen de la factura + `siat_invoice_id` (FK al `invoices.id` de siat-api) |
| `invoice_packages` | Paquetes locales + `siat_package_id` (FK al `invoice_packages.id` de siat-api) |
| `siat_motivos_anulacion` | Copia local para mostrar en el select del modal de anulación |
| `siat_eventos_significativos` | Copia local para el select del modal de paquete |
| `siat_actividades` | Copia local para construir el payload hacia siat-api |

> Los catálogos en ventas se sincronizan manualmente desde el panel `/admin/siat`.  
> En siat-api los catálogos se sincronizan vía `POST /api/v1/catalogos/sync/*`.

---

## 7. Flujos principales end-to-end

### 7.1 Emisión de una factura (venta al contado)

```
ventas/SaleController::store()
  └── SiatController::createInvoce($sale, $request)
        ├── getAuthenticatedSiatContext()          ← valida CUIS/CUFD locales; renueva si vencidos
        │     └── SiatApiClient::syncCodes()       → POST /api/v1/codigos/sync
        │                                          → GET  /api/v1/codigos/context
        ├── Construye $cabecera y $detalles (payload)
        └── SiatApiClient::emitirFactura()         → POST /api/v1/facturas

siat-api/FacturacionController::store()
  └── InvoiceService::emit()
        ├── getContext()      ← CUIS/CUFD de siat-api BD
        ├── detectarModoEmision()   ← ping SOAP; 1=online, 2=offline
        ├── nextNroFactura()        ← MAX+1 o contador CAFC
        ├── generarCuf()            ← algoritmo mod11 + base16
        ├── buildXml()              ← XML schema SIN
        ├── saveXmlToDisk()         ← storage/app/public/siat/pendientes/
        ├── FacturacionService::recepcionFactura()   ← SOAP al SIN (si online)
        └── Invoice::create()       ← guarda en BD de siat-api

ventas: SaleInvoice::create() con siat_invoice_id retornado
```

**Resultado en ventas:** `sale_invoices.codigoEstado` = `908` (validada) o `902` (offline/pendiente).

### 7.2 Renovación de CUIS/CUFD

Ocurre automáticamente dentro de `getAuthenticatedSiatContext()` cuando un código vence.

```
ventas/SiatController::getAuthenticatedSiatContext()
  ├── Detecta CUIS o CUFD vencido
  └── SiatController::syncSiatCodesForBranch()
        └── SiatApiClient::syncCodes()           → POST /api/v1/codigos/sync
                                                  → GET  /api/v1/codigos/context
              Respuesta: { cuis, cufd, cufd_vigencia, cufd_control, cufd_direccion }
        ← Escribe nuevos SiatCuis/SiatCufd en BD de ventas
```

### 7.3 Anulación de una factura

```
ventas/SaleController::destroy()
  └── SiatController::anulacionFacturaInvoice($invoice, $codigoMotivo)
        └── SiatApiClient::anularFactura($invoice->siat_invoice_id, $codigoMotivo)
            → POST /api/v1/facturas/{id}/anular

siat-api/FacturacionController::anular()
  └── InvoiceService::anular($invoice, $codigoMotivo)
        ├── getContextFromXml()   ← extrae cufd/cuf/sucursal/PV del XML guardado
        └── FacturacionService::anulacionFactura()   ← SOAP al SIN
```

> La anulación usa el CUFD del XML de la factura (no el CUFD vigente), porque puede ser un CUFD de otro día.

### 7.4 Envío de paquete de contingencia

```
ventas/SaleController::sendSiatPackage()
  ├── 1. SiatApiClient::reconciliarPendientes()      → POST /api/v1/paquetes/reconciliar
  │        siat-api verifica cada factura 902 con el SIN; corrige las que ya están validadas
  ├── 2. Si aún quedan 902s:
  │        SiatApiClient::enviarPaquete()             → POST /api/v1/paquetes/enviar
  │              siat-api:
  │              ├── OperacionesService::registroEventoSignificativo()  ← SOAP
  │              ├── InvoiceService::sendPackage()
  │              │     ├── Construye tar.gz con XMLs de pendientes/
  │              │     ├── FacturacionService::recepcionPaquete()       ← SOAP
  │              │     └── Actualiza Invoice.codigoEstado en BD siat-api
  │              └── Retorna { ok, codigoRecepcion, codigoEstado }
  └── 3. ventas crea InvoicePackage local y actualiza SaleInvoices
```

---

## 8. Sincronización inicial de catálogos

Los catálogos del SIN (actividades, leyendas, motivos, etc.) deben sincronizarse **una vez** antes de emitir facturas. Sin leyendas, el XML falla.

### En siat-api

```bash
# Sincronizar todos los catálogos de una vez
curl -X POST http://localhost:8001/api/v1/catalogos/sync/todos \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"codigo_sucursal": 0, "codigo_punto_venta": 0}'
```

O endpoint por endpoint:
```
POST /api/v1/catalogos/sync/actividades
POST /api/v1/catalogos/sync/leyendas
POST /api/v1/catalogos/sync/motivos-anulacion
POST /api/v1/catalogos/sync/eventos-significativos
POST /api/v1/catalogos/sync/tipos-documento
POST /api/v1/catalogos/sync/metodos-pago
POST /api/v1/catalogos/sync/unidades-medida
POST /api/v1/catalogos/sync/productos-servicios
POST /api/v1/catalogos/sync/tipos-punto-venta
POST /api/v1/catalogos/sync/tipos-moneda
```

Todos requieren CUIS vigente para esa sucursal/PV. Asegurarse de sincronizar CUIS/CUFD primero:

```bash
curl -X POST http://localhost:8001/api/v1/codigos/sync \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"codigo_sucursal": 0, "codigo_punto_venta": 0}'
```

### En ventas

Los catálogos de ventas (`siat_motivos_anulacion`, `siat_eventos_significativos`, `siat_actividades`) se sincronizan desde el panel en `/admin/siat` usando el `SiatController` existente (pendiente de migrar a siat-api; por ahora llama SOAP directamente).

---

## 9. Credenciales SIAT — qué configurar y dónde

| Dato | Dónde obtenerlo | Dónde va |
|---|---|---|
| `SIAT_TOKEN` | JWT otorgado por el SIN al registrar el sistema | `.env` de **siat-api** |
| `SIAT_NIT` | NIT del contribuyente emisor | `.env` de **siat-api** |
| `SIAT_CODIGO_SISTEMA` | Asignado por el SIN al registrar el sistema | `.env` de **siat-api** |
| `SIAT_RAZON_SOCIAL` | Razón social del emisor (aparece en la factura) | `.env` de **siat-api** |
| `SIAT_MUNICIPIO` | Municipio del emisor | `.env` de **siat-api** |
| `SIAT_TELEFONO` | Teléfono del emisor | `.env` de **siat-api** |
| `SIAT_CAFC` | Código CAFC (solo para contingencia con código) | `.env` de **siat-api** |
| `SIAT_API_TOKEN` | Token Sanctum generado en siat-api | `.env` de **ventas** |
| `SIAT_API_URL` | URL de siat-api | `.env` de **ventas** |

> **No modificar** `SIAT_TOKEN`, `SIAT_NIT`, `SIAT_CODIGO_SISTEMA` sin coordinación con el equipo SIAT.  
> El ambiente piloto (`SIAT_CODIGO_AMBIENTE=2`) usa el endpoint `pilotosiatservicios.impuestos.gob.bo`.

---

## 10. Checklist de puesta en marcha

### siat-api

- [ ] PHP 8.2+ con extensiones `soap`, `bcmath`, `zlib`
- [ ] `composer install`
- [ ] `.env` configurado con credenciales SIAT
- [ ] `php artisan migrate` ejecutado
- [ ] `php artisan storage:link` ejecutado (para XMLs)
- [ ] Token Sanctum creado para ventas (`php artisan tinker`)
- [ ] Acceso HTTPS a `pilotosiatservicios.impuestos.gob.bo` verificado
- [ ] `POST /api/v1/codigos/ping` responde `{"ok":true}`
- [ ] `POST /api/v1/codigos/sync` con sucursal/PV crea CUIS y CUFD en BD
- [ ] `POST /api/v1/catalogos/sync/todos` popula catálogos (leyendas, actividades, etc.)

### ventas

- [ ] `composer install && npm install`
- [ ] `.env` configurado con `SIAT_API_URL` y `SIAT_API_TOKEN`
- [ ] `php artisan migrate` ejecutado (incluye migración `2026_05_15_200000_add_siat_ids_to_invoice_tables`)
- [ ] `npm run build` (o `npm run dev`)
- [ ] Usuarios con `branch_id` y `codigo_punto_venta` asignados
- [ ] La sucursal tiene `invoice_enabled = true` si emite facturas
- [ ] La sucursal tiene `codigo_sucursal` configurado (campo SIAT)
- [ ] Catálogos locales sincronizados desde `/admin/siat`
- [ ] Test: crear una venta y verificar que `sale_invoices.codigoEstado` = `908`

---

## Notas importantes

- `codigo_punto_venta = 0` es un valor **válido** en SIAT (punto de venta 0 = casa matriz). Nunca comparar con `!$pv` o `empty($pv)` porque `0` es falsy en PHP.
- El límite de contingencia es **500 facturas** por sucursal/PV. Al llegar a ese límite, siat-api bloquea nuevas facturas offline hasta que se envíe el paquete.
- siat-api guarda el **XML completo** de cada factura en disco (`storage/app/public/siat/pendientes/`). Sin ese archivo no se puede construir el paquete de contingencia.
- Los tokens Sanctum no expiran por defecto. Rotar el token si se compromete: eliminar en `personal_access_tokens` y generar uno nuevo.
- Para producción cambiar `SIAT_CODIGO_AMBIENTE=1` en siat-api. El endpoint SOAP cambia automáticamente a `siatservicios.impuestos.gob.bo`.
