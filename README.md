# siat-api

Proveedor de **facturación electrónica en línea** ante el SIAT (Servicio de Impuestos Nacionales
de Bolivia), multi-cliente, para el **documento sector 1 — Factura Compra Venta** y sus
devoluciones con **notas de crédito-débito (sector 24)**.

Los sistemas de venta de los clientes mandan la venta en JSON a una API REST (con el token de su
empresa) y reciben el CUF al instante. `siat-api` hace todo lo demás: CUIS y CUFD, CUF, XML, firma
digital, envío y verificación ante el SIN, anulación (también revertir y administrativa),
contingencia, catálogos, avisos por webhook y la factura lista para imprimir (hoja, ticket de 80 mm,
mini ticket o ZIP).

También: lotes de hasta 20 ventas, **emisión masiva** (paquetes de 500), dashboard, webhooks propios
del cliente por evento con historial, autogestión de certificado y puntos de venta, un **sandbox**
por cliente contra el SIAT piloto (tokens `siat_test_` / `siat_live_`) y un **servidor MCP** para
agentes de IA.

| | |
|---|---|
| Stack | PHP 8.3 · Laravel 13 · Sanctum · MySQL · colas en base de datos · Pest 4 · React 18 + Vite para el sitio público y la documentación (el panel es Blade) |
| Modalidad | Electrónica en línea (1) · Sector 1, compra-venta con crédito fiscal · Sector 24, nota de crédito-débito |
| Ambientes | 1 producción · 2 piloto (por empresa) |

---

## Levantar en desarrollo

```bash
composer install
npm install && npm run build          # sitio público (/) y documentación (/docs/api)
cp .env.example .env && php artisan key:generate
php artisan migrate --seed            # admin@admin.com / password
```

Para trabajar, **un solo comando** levanta todo junto (web, worker, reloj y Vite):

```bash
composer run dev
```

El worker va con `queue:listen --timeout=0`: recarga el código en cada trabajo (en desarrollo no
hace falta reiniciarlo después de un cambio) y no tiene el tope de 60 s por trabajo que, superado,
tiraba abajo los cuatro procesos. Ctrl+C los cierra a todos. Por separado, son estos procesos, cada uno en su terminal:

```bash
php artisan serve                                        # 1. web: sitio /, API, panel /admin, /docs/api
php artisan queue:work --queue=facturas,default,piloto   # 2. worker: envía las facturas al SIN
php artisan schedule:work                                # 3. reloj: renovaciones y reintentos
npm run dev                                              # 4. solo si editás el sitio o la documentación (React)
```

Qué hace cada uno, por qué el worker lleva `--queue=...` y cómo se deja en producción:
[docs/INSTALACION-Y-DESPLIEGUE.md](docs/INSTALACION-Y-DESPLIEGUE.md).

Tests: `php artisan test --compact`.

---

## Documentación

| Documento | Para quién |
|---|---|
| [docs/INSTALACION-Y-DESPLIEGUE.md](docs/INSTALACION-Y-DESPLIEGUE.md) | Quien instala, levanta, despliega y mantiene el servidor |
| [docs/ALTA-DE-CLIENTE.md](docs/ALTA-DE-CLIENTE.md) | Quien opera el panel: dar de alta un cliente y correr el piloto |
| [docs/TECNICO.md](docs/TECNICO.md) | Desarrolladores: arquitectura, contrato con el SIN, base de datos y pendientes |
| [docs/integracion/](docs/integracion/) | **Los clientes** (se les entrega esta carpeta): `API.md`, OpenAPI y colección de Postman |
| `/docs/api` en el servidor | **Los clientes**: la misma guía como portal (React): una página por endpoint con ejemplos en 4 lenguajes, buscador y descargas de Postman/OpenAPI |
| `/` en el servidor | **Clientes nuevos**: sitio público con la marca, sin precios, y formulario de contacto (llega a panel → *Solicitudes*) |

**Pendiente** (depende de terceros): firma XAdES y Etapas IV/VIII (passphrase del `.p12`), Etapa
VI y contingencia (CAFC), URL de producción. **Por verificar en el piloto:** certificación de la nota
de crédito-débito (sector 24), emisión masiva (requiere autorización del SIN), sandbox de un cliente
ya en producción y anulación administrativa. El correo al comprador lo hace otro servicio. Detalle
en [TECNICO.md → Pendientes](docs/TECNICO.md#pendientes).

---

## Reglas del proyecto

- Nada de SOAP fuera de `app/Services/Siat/`; todo pasa por `FabricaServicios`.
- No inventar nombres del SIN: verificar con `php artisan siat:inspeccionar-wsdl` antes de tocar
  una operación o un campo.
- Comentarios en español explicando el **porqué**.
- Todo cambio lleva su test; formatear con `vendor/bin/pint --dirty --format agent`.
