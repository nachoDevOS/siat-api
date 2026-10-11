# Integración con la API de facturación electrónica

Esta carpeta es todo lo que necesita su sistema de ventas (POS, ERP, tienda online) para emitir
facturas electrónicas ante el SIN de Bolivia a través de nuestra API.

**Su sistema solo envía la venta en JSON.** Nosotros calculamos el CUF, armamos y firmamos el XML,
lo enviamos al SIN, verificamos el resultado y manejamos los códigos CUIS y CUFD. Usted no necesita
SOAP, certificados ni códigos del SIN.

## Contenido

| Archivo | Qué es |
|---|---|
| [API.md](API.md) | **Guía de integración.** Empiece por la sección 0, *Integración paso a paso*: configuración, primera llamada, emisión, webhooks y lista de verificación antes de producción. Después, referencia completa de cada endpoint, errores y estados |
| [openapi.yaml](openapi.yaml) | Especificación OpenAPI 3. Impórtela en Postman, Insomnia o un generador de clientes para tener todos los endpoints listos para probar |

## Lo que le entregamos por separado

Estos datos son **privados** de su empresa y no están en esta carpeta:

| Dato | Para qué |
|---|---|
| URL base de la API | Todas las llamadas |
| El **token** de su empresa (uno solo, para todas sus sucursales y cajas) | Cabecera `Authorization: Bearer ...` |
| Secreto de webhook (si usa webhooks) | Verificar que los avisos vienen de nosotros |
| Sus códigos de sucursal y punto de venta | Van en cada venta |

Guarde el token y el secreto como secretos (variables de entorno o almacén de secretos): nunca
en el código fuente, en un repositorio ni en el navegador.

## Empezar en 5 minutos

```bash
export SIAT_API_URL=https://{dominio}/api/v1
export SIAT_API_TOKEN='12|siat_...'

# ¿Funciona la conexión y puedo facturar?
curl -s "$SIAT_API_URL/estado" -H "Authorization: Bearer $SIAT_API_TOKEN" -H "Accept: application/json"
```

Si responde `"puede_facturar": true`, siga con la emisión en [API.md](API.md), sección 0, paso 5.

## Soporte

Ante una factura `OBSERVADA` (rechazada por el SIN), el motivo viene en `motivo_observacion` y en
el webhook. Si necesita ayuda, envíenos el **CUF** o su **referencia externa** de la venta: con
eso la ubicamos.
