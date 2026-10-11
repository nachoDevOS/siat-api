# siat-api

Proveedor de **facturación electrónica en línea** ante el SIAT (Servicio de Impuestos Nacionales
de Bolivia), multi-cliente, para el **documento sector 1 — Factura Compra Venta**.

Los sistemas de venta de los clientes mandan un JSON a una API REST (autenticada con tokens
Bearer de Sanctum, uno por sistema y con permisos) y reciben el CUF al instante.
`siat-api` resuelve todo lo demás: CUIS y CUFD, CUF, XML, firma digital, envío y verificación ante
el SIN, anulación, contingencia, catálogos y avisos por webhook.

| | |
|---|---|
| Stack | PHP 8.3 · Laravel 13 · Sanctum · MySQL · colas en base de datos · Pest 4 · panel Blade con CSS propio (sin Node ni build de frontend) |
| Modalidad | Electrónica en línea (1) |
| Documento | Sector 1, compra-venta con crédito fiscal |
| Ambientes | 1 producción · 2 piloto (por empresa) |

---

## Inicio rápido (desarrollo)

```bash
composer install
cp .env.example .env && php artisan key:generate
# completar SIAT_PROVEEDOR_* y SIAT_WEBHOOK_SECRET en .env
php artisan migrate --seed          # admin@admin.com / password
php artisan serve                   # panel en http://localhost:8000/admin
php artisan queue:work --queue=facturas,default,piloto   # worker (obligatorio; reiniciar tras cada cambio de código)
php artisan schedule:work           # tareas programadas
php artisan test --compact          # tests
```

---

## Documentación

| Documento | Para quién | Contenido |
|---|---|---|
| [docs/ARQUITECTURA.md](docs/ARQUITECTURA.md) | Desarrolladores | Componentes, flujo de emisión, estados, jobs, tareas programadas, contingencia, seguridad |
| [docs/integracion/](docs/integracion/) | **Sistemas externos (se les entrega esta carpeta)** | Guía de integración paso a paso, endpoints, errores, webhooks y especificación OpenAPI. No contiene nada interno |
| [docs/OPERACION.md](docs/OPERACION.md) | Quien instala y mantiene | Instalación, variables de entorno, worker, cron, comandos `siat:*`, monitoreo, solución de problemas |
| [docs/ALTA-DE-CLIENTE.md](docs/ALTA-DE-CLIENTE.md) | Operador del panel | Paso a paso para dar de alta un contribuyente |
| [docs/PILOTO.md](docs/PILOTO.md) | Operador / desarrolladores | Etapas del portal del SIN y su estado |
| [docs/CONTRATO-SIAT.md](docs/CONTRATO-SIAT.md) | Desarrolladores | Lo verificado y lo pendiente del contrato SOAP, CUF, XML y firma |
| [docs/BASE-DE-DATOS.md](docs/BASE-DE-DATOS.md) | Desarrolladores | Tablas, columnas y relaciones |
| [docs/ESTADO-Y-PENDIENTES.md](docs/ESTADO-Y-PENDIENTES.md) | Todos | Qué falta para producción e historial de cambios |
| [docs/ANALISIS-SISTEMA.md](docs/ANALISIS-SISTEMA.md) | Referencia | Auditorías anteriores (histórico) |
| [docs/PROMPT-SISTEMA-FACTURACION-SIAT.md](docs/PROMPT-SISTEMA-FACTURACION-SIAT.md) | Referencia | Especificación original del sistema |

---

## Reglas del proyecto

- Nada de SOAP fuera de `app/Services/Siat/`; todo pasa por `FabricaServicios`.
- No inventar nombres del SIN: verificar con `php artisan siat:inspeccionar-wsdl` o con el WSDL
  vigente antes de tocar una operación o un campo.
- Comentarios en español explicando el **porqué**.
- Todo cambio lleva su test; formatear con `vendor/bin/pint --dirty --format agent`.
