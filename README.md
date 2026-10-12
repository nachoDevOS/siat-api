# siat-api

Proveedor de **facturación electrónica en línea** ante el SIAT (Servicio de Impuestos Nacionales
de Bolivia), multi-cliente, para el **documento sector 1 — Factura Compra Venta**.

Los sistemas de venta de los clientes mandan la venta en JSON a una API REST (con el token de su
empresa) y reciben el CUF al instante. `siat-api` hace todo lo demás: CUIS y CUFD, CUF, XML, firma
digital, envío y verificación ante el SIN, anulación, contingencia, catálogos, avisos por webhook y
la factura lista para imprimir (hoja o ticket de 80 mm).

| | |
|---|---|
| Stack | PHP 8.3 · Laravel 13 · Sanctum · MySQL · colas en base de datos · Pest 4 · sin Node |
| Modalidad | Electrónica en línea (1) · Sector 1, compra-venta con crédito fiscal |
| Ambientes | 1 producción · 2 piloto (por empresa) |

---

## Levantar en desarrollo

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed            # admin@admin.com / password
```

Y estos **tres procesos a la vez**, cada uno en su terminal:

```bash
php artisan serve                                        # 1. web: API, panel /admin, /docs/api
php artisan queue:work --queue=facturas,default,piloto   # 2. worker: envía las facturas al SIN
php artisan schedule:work                                # 3. reloj: renovaciones y reintentos
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
| `/docs/api` en el servidor | **Los clientes**: la misma documentación, navegable |

**Pendiente** (depende de terceros): firma XAdES y Etapas IV/VIII (passphrase del `.p12`), Etapa
VI y contingencia (CAFC), correo al comprador, URL de producción. Detalle en
[TECNICO.md → Pendientes](docs/TECNICO.md#pendientes).

---

## Reglas del proyecto

- Nada de SOAP fuera de `app/Services/Siat/`; todo pasa por `FabricaServicios`.
- No inventar nombres del SIN: verificar con `php artisan siat:inspeccionar-wsdl` antes de tocar
  una operación o un campo.
- Comentarios en español explicando el **porqué**.
- Todo cambio lleva su test; formatear con `vendor/bin/pint --dirty --format agent`.
