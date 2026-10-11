# Operación — instalación, configuración y mantenimiento

> Para quien instala, despliega y mantiene el servidor. Para dar de alta un cliente ver
> [ALTA-DE-CLIENTE.md](ALTA-DE-CLIENTE.md).

---

## 1. Requisitos

| Componente | Versión / detalle |
|---|---|
| PHP | 8.3+ con `soap`, `openssl`, `dom`, `bcmath`, `gd`, `zlib`, `pdo_mysql` |
| MySQL | 8.x (base `siat_api`) |
| Composer | 2.x |
| Zona horaria | **`America/La_Paz`** — en UTC el SIN rechaza por `fechaEnvio` (código 935) y el CUF sale mal |

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

```ini
[program:siat-worker]
command=php /var/www/siat-api/artisan queue:work --queue=facturas,default,piloto --sleep=3 --tries=1 --max-time=3600
numprocs=2
autostart=true
autorestart=true
user=www-data
stopwaitsecs=120
redirect_stderr=true
stdout_logfile=/var/www/siat-api/storage/logs/worker.log
```

`--tries=1` porque cada job declara sus propios intentos y backoff.

**Colas por prioridad.** El worker atiende en ese orden y solo pasa a la siguiente cuando la
anterior está vacía:

| Cola | Jobs | Por qué |
|---|---|---|
| `facturas` | envío, verificación, anulación, paquetes | Lo que el SIN tiene que recibir de las ventas reales |
| `default` | webhooks, renovación de CUFD, catálogos | Importante pero puede esperar segundos |
| `piloto` | pruebas del piloto | Una etapa encola hasta mil llamadas: no puede atrasar facturas reales |

> ⚠️ Un `queue:work` **sin `--queue`** solo atiende `default`: las facturas no se enviarían.
> Si querés aislar del todo el piloto, levantá un worker aparte con `--queue=piloto`.

Cron:

```cron
* * * * * cd /var/www/siat-api && php artisan schedule:run >> /dev/null 2>&1
```

Despliegue típico:

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
| Panel → **Facturas** | Buscar por CUF, referencia o número; ver estado, motivo de observación y anulación; descargar PDF/XML; **enviar** una PENDIENTE o **verificar** una RECIBIDA sin esperar a la tarea programada |
| Panel → **Monitor** | Facturas por estado, últimas peticiones SOAP y **últimas llamadas a la API** (filtrables por empresa y solo errores) |
| Ficha de la empresa | Vencimiento del certificado y del **token delegado** (ámbar a 30 días, rojo vencido) |
| Empresa → **Acceso API** | Datos de conexión para el cliente, su token (generar / regenerar / revocar, último uso), webhook y su secreto, últimas llamadas de esa empresa |
| Panel → **Dashboard** | Tasa de éxito SOAP, facturas recientes |
| Panel → **Configuración** | Valores efectivos (sin abrir el `.env`) |
| `logs_siat` | XML enviado/recibido de cada llamada, duración, error |
| `storage/logs/laravel.log` | Rechazos del SIN (`El SIN rechazo...`), PDFs fallidos, webhooks descartados, anulaciones pendientes |
| `failed_jobs` | `php artisan queue:failed` · reintentar con `php artisan queue:retry {id}` |

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
