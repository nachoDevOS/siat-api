# Instalación y despliegue

> Para quien instala, levanta, despliega y mantiene el servidor de **siat-api**. Dar de alta un
> cliente: [ALTA-DE-CLIENTE.md](ALTA-DE-CLIENTE.md). Cómo está construido: [TECNICO.md](TECNICO.md).

---

## 1. Requisitos

| Componente | Versión / detalle |
|---|---|
| PHP | 8.3+ con `soap`, `openssl`, `dom`, `bcmath`, `gd`, `zlib`, `pdo_mysql` |
| MySQL | 8.x (base `siat_api`) |
| Composer | 2.x |
| Node / npm | **No hace falta**: el panel y las páginas públicas no tienen build de frontend |
| Zona horaria | **`America/La_Paz`** — en UTC el SIN rechaza por `fechaEnvio` (código 935) y el CUF sale mal |

### Levantar el sistema: los 3 procesos

El sistema **no funciona solo con el servidor web**. Siempre tienen que estar corriendo estos
tres procesos a la vez:

| # | Comando (desarrollo) | Qué es | Qué hace | Si no está corriendo |
|---|---|---|---|---|
| 1 | `php artisan serve` | **Servidor web** | Recibe las peticiones: la API, el panel `/admin`, `/docs/api` y las facturas públicas | No se puede entrar a nada |
| 2 | `php artisan queue:work --queue=facturas,default,piloto` | **Worker** (el trabajador) | Hace en segundo plano lo lento: **envía las facturas al SIN**, verifica si quedaron validadas, anula, manda los paquetes, avisa por webhook, renueva el CUFD y corre el piloto | La API entrega el CUF pero **la factura nunca llega al SIN**: queda en `PENDIENTE` |
| 3 | `php artisan schedule:work` | **Scheduler** (el reloj) | Cada minuto revisa si toca una tarea y la lanza: reintentar pendientes, verificar enviadas, renovar CUFD y CUIS, sincronizar catálogos, avisar vencimientos, limpiar logs | El CUFD vence y nadie lo renueva; nada se reintenta ni se avisa |

En producción no se usan a mano: el **2** lo mantiene vivo **Supervisor** y el **3** lo reemplaza
un **cron** (ver [sección 4](#4-procesos-que-deben-estar-corriendo)).

**¿Por qué `--queue=facturas,default,piloto`?** El trabajo se reparte en tres colas por urgencia y
el worker las atiende **en ese orden**: primero `facturas` (ventas reales), cuando esa está vacía
`default` (webhooks, códigos, catálogos) y por último `piloto` (pruebas, que pueden ser mil). Así
una venta nunca espera detrás de las pruebas del piloto. **Sin `--queue` solo atiende `default`:
las facturas no se enviarían.**

**¿Están corriendo?** El panel lo dice: Dashboard y **Trabajos** muestran 🟢/🔴 para el worker y el
scheduler (cada uno deja un "latido"; si es viejo, está apagado), y cuántos trabajos esperan.

> ⚠️ Después de **cada cambio de código** reinicie el worker (Ctrl+C y volver a lanzarlo, o
> `php artisan queue:restart`): un worker viejo sigue ejecutando el código anterior.

---

## 2. Instalación

```bash
git clone <repo> siat-api && cd siat-api
composer install
cp .env.example .env
php artisan key:generate
# editar .env (ver §3)
php artisan migrate
php artisan db:seed          # crea admin@admin.com / password y los casos del piloto
```

> ⚠️ **`APP_KEY` cifra el token delegado, los `.p12` y sus passphrases.** Si se pierde o se
> cambia, esos datos no se pueden descifrar y hay que volver a cargarlos. Respáldela fuera del
> servidor.

Cambie la contraseña del administrador apenas instale.

---

## 3. Variables de entorno

### 3.1 Propias del sistema

| Variable | Defecto | Descripción |
|---|---|---|
| `APP_TIMEZONE` | `America/La_Paz` | No cambiar |
| `SIAT_PROVEEDOR_NIT` | — | NIT del proveedor (solicitud R-1359) |
| `SIAT_PROVEEDOR_RAZON_SOCIAL` | — | Razón social del proveedor |
| `SIAT_PROVEEDOR_CODIGO_SISTEMA` | — | Código de sistema asignado por el SIN; valor por defecto al crear empresas |
| `SIAT_PROVEEDOR_NOMBRE_SISTEMA` | `SolucionDigital-api` | Nombre del sistema registrado |
| `SIAT_AMBIENTE` | `2` | Ambiente por defecto (1 producción, 2 piloto). Cada empresa tiene el suyo |
| `SIAT_URL_PILOTO` | `https://pilotosiatservicios.impuestos.gob.bo/v2` | Verificada |
| `SIAT_URL_PRODUCCION` | `https://siatrest.impuestos.gob.bo/v2` | **Pendiente de verificar** |
| `SIAT_WEBHOOK_SECRET` | — | Secreto maestro; de él se deriva el secreto de cada empresa. **Sin él los webhooks salen sin firmar.** Cambiarlo cambia el secreto de todos los clientes |
| `SIAT_WEBHOOK_PERMITIR_PRIVADOS` | `false` | `true` solo en desarrollo (permite `http://localhost`) |
| `SIAT_WEBHOOK_TIMEOUT` | `10` | Segundos por intento de webhook |
| `APP_URL` | — | URL pública del servidor. La documentación `/docs/api` la usa como servidor de la API: debe ser la real |
| `TRUSTED_PROXIES` | — | IPs de los proxies de confianza (`*` = cualquiera) |
| `SIAT_RATE_LIMIT_TOKEN` | `60` | Peticiones por minuto por token |
| `SIAT_RATE_LIMIT` | `120` | Peticiones por minuto por empresa |
| `SIAT_API_RETENCION_LOGS_DIAS` | `90` | Retención de `logs_api` (llamadas a la API) |
| `SANCTUM_TOKEN_PREFIX` | `siat_` | Prefijo de los tokens de API |
| `SIAT_TIMEOUT` | `30` | Timeout de lectura SOAP (también al bajar el WSDL) |
| `SIAT_CONNECTION_TIMEOUT` | `15` | Timeout de conexión SOAP |
| `SIAT_WSDL_CACHE` | `0` | `0` en desarrollo, `1` (disco) en producción |
| `SIAT_TRACE` | `true` | Necesario para auditar el XML en `logs_siat` |
| `SIAT_RETENCION_LOGS_DIAS` | `90` | Retención de la auditoría SOAP |
| `SIAT_QR_URL_PRODUCCION` | `https://siat.impuestos.gob.bo/consulta/QR` | Consulta pública que abre el QR (producción) |
| `SIAT_QR_URL_PILOTO` | `https://pilotosiat.impuestos.gob.bo/consulta/QR` | Ídem en piloto |
| `SIAT_QR_TAMANIO` | `2` | Parámetro `t` del QR: 1 rollo, 2 media página / carta |
| `SIAT_OPENSSL_CONF` | `resources/openssl/openssl.cnf` | Para generar autofirmados en Windows |

### 3.2 Laravel relevantes

`DB_*` (MySQL), `QUEUE_CONNECTION=database`, `CACHE_STORE=database` (soporta los locks que usan
los jobs únicos), `SESSION_DRIVER=database`.

Con `php artisan config:cache` en producción **no use `env()` fuera de `config/`**: devuelve null.

> ⚠️ **Detrás de un proxy o balanceador** (nginx en otro equipo, Cloudflare, load balancer),
> ponga sus IPs en `TRUSTED_PROXIES` (separadas por coma, o `*`). Si no, todas las peticiones
> parecen venir de la IP del proxy: la restricción de IPs de los tokens deja de servir y
> `logs_api` registra la IP equivocada.

---

## 4. Procesos que deben estar corriendo

El sistema **no funciona solo con el servidor web**. Hacen falta tres procesos:

| Proceso | Desarrollo | Producción |
|---|---|---|
| Web | `php artisan serve` | nginx/Apache + PHP-FPM |
| Worker de colas | `php artisan queue:work --queue=facturas,default,piloto` | Supervisor (abajo) |
| Scheduler | `php artisan schedule:work` | cron cada minuto |

> ⚠️ **Reinicie el worker después de cada cambio de código** (`php artisan queue:restart` o
> cortarlo y volver a levantarlo). Un worker viejo sigue ejecutando el código anterior: ya
> hizo fallar una etapa del piloto.

Supervisor:

Archivo `/etc/supervisor/conf.d/siat-worker.conf`:

```ini
[program:siat-worker]
; process_name es obligatorio con numprocs > 1: sin el, Supervisor no arranca.
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/siat-api/artisan queue:work --queue=facturas,default,piloto --sleep=3 --tries=1 --max-time=3600
numprocs=2
autostart=true
autorestart=true
user=www-data
stopwaitsecs=120
redirect_stderr=true
stdout_logfile=/var/www/siat-api/storage/logs/worker.log
```

Activarlo y verlo:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start "siat-worker:*"
sudo supervisorctl status          # debe decir RUNNING en los dos procesos
```

`--tries=1` porque cada job declara sus propios intentos y backoff. `--max-time=3600` recicla el
proceso cada hora (evita fugas de memoria); Supervisor lo vuelve a levantar solo.

**Colas por prioridad.** El worker atiende en ese orden y solo pasa a la siguiente cuando la
anterior está vacía:

| Cola | Jobs | Por qué |
|---|---|---|
| `facturas` | envío, verificación, anulación, paquetes | Lo que el SIN tiene que recibir de las ventas reales |
| `default` | webhooks, renovación de CUFD, catálogos | Importante pero puede esperar segundos |
| `piloto` | pruebas del piloto | Una etapa encola hasta mil llamadas: no puede atrasar facturas reales |

> ⚠️ Un `queue:work` **sin `--queue`** solo atiende `default`: las facturas no se enviarían.
> Si querés aislar del todo el piloto, levantá un worker aparte con `--queue=piloto`.

Cron (con `sudo crontab -u www-data -e`, el mismo usuario que el worker):

```cron
* * * * * cd /var/www/siat-api && php artisan schedule:run >> /dev/null 2>&1
```

Verificar que el scheduler ve sus tareas: `php artisan schedule:list`.

### Primera puesta en producción — lista de verificación

**`.env`**

- [ ] `APP_ENV=production` y `APP_DEBUG=false` (con `true` se muestran datos internos en los errores)
- [ ] `APP_URL=https://su-dominio` — con ella se arman los **enlaces públicos de las facturas** y la
      documentación `/docs/api`
- [ ] `APP_KEY` generada **y respaldada fuera del servidor**: cifra token delegado, `.p12` y
      passphrases, y **firma los enlaces públicos** de las facturas. Si cambia, todo eso se pierde
- [ ] `APP_TIMEZONE=America/La_Paz`
- [ ] `DB_*` de la base de producción
- [ ] `SIAT_WEBHOOK_SECRET` con un valor largo y aleatorio (`openssl rand -hex 32`)
- [ ] `SIAT_WSDL_CACHE=1` (cachea el WSDL en disco)
- [ ] `TRUSTED_PROXIES` si hay un proxy o balanceador delante
- [ ] `SIAT_URL_PRODUCCION` y `SIAT_QR_URL_PRODUCCION` confirmadas con el SIN

**Servidor**

- [ ] HTTPS activo (la API y los webhooks lo exigen)
- [ ] `storage/` y `bootstrap/cache/` con escritura para el usuario del servidor web
- [ ] Worker en Supervisor con `--queue=facturas,default,piloto` → `supervisorctl status` en RUNNING
- [ ] Cron del scheduler → `php artisan schedule:list` muestra las tareas
- [ ] `php artisan migrate --force` y `php artisan db:seed --force` (solo la primera vez)
- [ ] **Cambiar la contraseña del administrador** (el seeder la deja en `password`):

```bash
php artisan tinker --execute 'App\Models\User::where("email","admin@admin.com")->first()->update(["email" => "su-correo@dominio.bo", "password" => bcrypt("UNA-CONTRASENA-LARGA")]);'
```

**Comprobación final**

```bash
curl -s https://su-dominio/up                     # 200: la app responde
curl -s https://su-dominio/api/v1/estado          # 401: la API exige token (correcto)
curl -s -o /dev/null -w '%{http_code}' https://su-dominio/docs/api   # 200
```

### Despliegue de una versión nueva

```bash
php artisan down
git pull && composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan up
```

---

## 5. Comandos `siat:*`

| Comando | Programado | Para qué |
|---|---|---|
| `siat:probar {empresa}` | — | Verifica que el WSDL cargue con el token de la empresa. No emite nada |
| `siat:inspeccionar-wsdl {empresa} --servicio=compra_venta --tipos` | — | Lista operaciones y tipos del WSDL real. Solo reporta. Úselo antes de tocar nombres SOAP |
| `siat:renovar-cufds` | cada hora | Renueva CUFD que vencen en < 2 h |
| `siat:revisar-codigos` | diario 02:00 | Pide CUIS nuevo a los puntos de venta sin uno vigente; avisa los que no pudo |
| `siat:avisar-certificados` | diario 07:00 | Avisa certificados **y tokens delegados** vencidos o que vencen en < 30 días; queda en el log |
| `siat:sincronizar-globales` | domingo 03:00 | Catálogos paramétricos globales |
| `siat:recuperar-contingencia` | cada 10 min | Cierra eventos y envía paquetes cuando vuelve el SIAT |
| `siat:purgar-logs` | diario 04:00 | Borra `logs_siat` fuera de retención |

Ver lo programado: `php artisan schedule:list`. Probar una tarea:
`php artisan schedule:test --name=reintentar-anulaciones`.

---

## 6. Monitoreo

| Dónde | Qué mirar |
|---|---|
| **`/factura/{cuf}?signature=…`** (público) | Factura con enlace firmado: página, PDF carta (`/pdf`) y ticket 80 mm (`/rollo`). En el panel, detalle de la factura → *Vista pública* / *Ticket 80 mm*. Sin firma válida: 404 |
| **`/docs/api`** (público) | Documentación de la API para los clientes, con Redoc sobre `docs/integracion/openapi.yaml`. Redoc está guardado en `public/vendor/redoc/` (v2.5.3, MIT): no depende de un CDN |
| Panel → **Trabajos** | **Todo lo que hizo la cola**: pestañas Todos · En cola · En proceso · Exitosos · Reintentando · Fallidos (con contador), filtros por tipo, empresa y fecha (**por defecto, el mes actual**; *Ver todo* quita las fechas), el error de cada fallido, **Reintentar** / **Descartar** / *Reintentar todos*, y el semáforo del worker y del scheduler. Se actualiza cada 10 s. El piloto va oculto salvo *Mostrar piloto*. El menú muestra un globo rojo con los fallidos |
| Panel → **Facturas** | Por defecto muestra **el mes actual** (*Ver todo* quita las fechas; una búsqueda por CUF, referencia o número busca en toda la historia). Buscar por CUF, referencia o número; ver estado, motivo de observación y anulación; descargar PDF/XML; **enviar** una PENDIENTE o **verificar** una RECIBIDA sin esperar a la tarea programada |
| Panel → **Monitor** | Facturas por estado, últimas peticiones SOAP y **últimas llamadas a la API** (filtrables por empresa y solo errores) |
| Ficha de la empresa | Vencimiento del certificado y del **token delegado** (ámbar a 30 días, rojo vencido) |
| Empresa → **Acceso API** | Datos de conexión para el cliente, su token (generar / regenerar / revocar, último uso), webhook y su secreto, últimas llamadas de esa empresa |
| Panel → **Dashboard** | Tasa de éxito SOAP, facturas recientes |
| Panel → **Configuración** | Valores efectivos (sin abrir el `.env`) |
| `logs_siat` | XML enviado/recibido de cada llamada, duración, error |
| `storage/logs/laravel.log` | Rechazos del SIN (`El SIN rechazo...`), PDFs fallidos, webhooks descartados, anulaciones pendientes |
| `failed_jobs` | Mejor desde **Trabajos** (reintentar / descartar). Por consola: `php artisan queue:failed`, `php artisan queue:retry {uuid}` |

Consultas útiles:

```sql
-- facturas que no avanzan
SELECT estado, COUNT(*) FROM facturas GROUP BY estado;
SELECT id, cuf, estado, created_at FROM facturas WHERE estado = 'PENDIENTE' AND created_at < NOW() - INTERVAL 30 MINUTE;
-- anulaciones colgadas
SELECT * FROM facturas_anuladas WHERE estado = 'PENDIENTE';
-- eventos de contingencia abiertos
SELECT * FROM eventos_significativos WHERE estado = 'ABIERTO';
-- últimos errores SOAP
SELECT created_at, servicio, operacion, mensaje_error FROM logs_siat WHERE exitoso = 0 ORDER BY id DESC LIMIT 20;
```

---

## 7. Solución de problemas

| Síntoma | Causa probable | Solución |
|---|---|---|
| **Trabajos** muestra 🔴 *Worker APAGADO* | El worker no corre | `php artisan queue:work --queue=facturas,default,piloto` (o `supervisorctl status`) |
| **Trabajos** muestra 🔴 *Scheduler APAGADO* | Falta `schedule:work` o el cron | Levantarlo; el latido aparece al minuto |
| Las facturas quedan en `PENDIENTE` | Worker apagado, o levantado sin `--queue=facturas,...` | Levantar `queue:work --queue=facturas,default,piloto` |
| `422` "el certificado digital de la empresa venció" | Certificado vencido | Cargar el nuevo `.p12` en la ficha de la empresa |
| Nada se reintenta ni se renueva | Scheduler apagado | Cron / `schedule:work` |
| `503 SIAT_NO_DISPONIBLE` al emitir | No había CUFD y el SIN no entregó uno (o hay contingencia abierta) | Ver `logs_siat`; cuando el SIN responda, la próxima venta lo pide sola (espera 60 s entre intentos) |
| SIN responde 935 | Hora del servidor / zona horaria | `APP_TIMEZONE=America/La_Paz`, NTP activo |
| SIN responde 913 "CUIS INVALIDO" | El SIN ya no reconoce ese CUIS, o el CUFD se pidió con otro | Punto de venta → **Solicitar CUIS** → *Revisar CUIS*; si sigue, *Forzar CUIS nuevo* (cierra operaciones, pide CUIS y CUFD nuevos) |
| "PUNTO DE VENTA INEXISTENTE O INVALIDO" | Punto de venta no registrado en el SIN | Registrar desde el panel o usar el PV 0 |
| `401 TOKEN_INVALIDO` | Token vencido, revocado o mal copiado (incluye el `id|`) | Empresa → *Acceso API* → *Tokens*: revisar vencimiento o crear otro |
| `403 IP_NO_PERMITIDA` con la IP del proxy | Falta `TRUSTED_PROXIES` | Ver §3.2 |
| Todas las llamadas SOAP de un cliente fallan por autenticación | Token delegado vencido | Ficha de la empresa (lo marca en rojo); regenerarlo en la Oficina Virtual |
| `422` "no está homologado por el SIN para este NIT" | Producto nuevo aún no sincronizado | Ficha de la empresa → **Sincronizar ahora** (o esperar la tarea de las 03:30) |
| `422` "no tiene un certificado digital activo" | Falta el `.p12` | Cargarlo en la ficha de la empresa |
| "No se pudo abrir el certificado .p12" | Passphrase incorrecta | Volver a cargar el `.p12` con su passphrase |
| Todo da `429` en producción | `env()` fuera de config con `config:cache` | Ya resuelto; si reaparece, revisar código nuevo |
| Una etapa del piloto falla tras cambiar código | Worker viejo | `php artisan queue:restart` |
| MySQL no arranca en el equipo de desarrollo | Servicio parado | `sudo systemctl start mysql` |

---

## 8. Tests

```bash
php artisan test --compact                       # suite completa
php artisan test --compact --filter=anulacion    # por nombre
vendor/bin/pint --dirty --format agent           # formato antes de cerrar un cambio
```

Los tests usan SQLite en memoria (`phpunit.xml`) y reemplazan el SIN con Mockery a través de
`FabricaServicios`. Ningún test llama al SIN real.

`phpunit.xml` excluye el grupo `mysql` (pruebas de concurrencia contra MySQL real); hoy ningún
test pertenece a ese grupo: los que había se borraron junto con la suite Feature antigua
(commit `d5bc968`, recuperables desde `7ba2acd`).
