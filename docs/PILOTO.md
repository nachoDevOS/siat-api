# Piloto ante el SIN — etapas del portal

> Cómo se corren las pruebas que el SIN exige para autorizar el sistema
> (*Oficina Virtual → Seguimiento de Autorización de Sistemas → Listado de Pruebas*), y en qué
> punto está cada etapa.

---

## 1. Dónde y cómo

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

> ⚠️ Las etapas largas corren en la cola: **`php artisan queue:work` tiene que estar levantado y
> reiniciado después de cada cambio de código.**

Las pruebas viven en `casos_prueba` (sembradas por `CasosPruebaSeeder`, con `etapa` y
`pruebas_esperadas`) y la lógica en `app/Services/Pruebas/EjecutorPruebas.php`.

---

## 2. Etapas

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

## 3. Notas por etapa

### I — CUIS
Si ya existe un CUIS vigente, el SIN responde 980 con el mismo código: **no cuenta** como prueba,
pero el código se guarda. Junto al error aparece **Cerrar operaciones y pedir CUIS nuevo**: en un
solo clic cierra las operaciones del sistema en ese punto de venta (con el CUIS que el SIN tiene
vigente), repite la prueba —ahora el SIN entrega uno nuevo y cuenta— y pide un CUFD atado al
CUIS nuevo, para que las facturas de la etapa IV no vuelvan con 913.

### II — Catálogos
Usa el CUIS del punto de venta de cada prueba. Un rechazo (`transaccion = false`) queda FALLIDO
con su motivo; antes se veía como "sincronizado: 0".

### III — CUFD
Cada solicitud devuelve un CUFD nuevo atado al CUIS con el que se pidió.

### IV y VIII — Emisión
Emite con `EmisorFactura` (mismo código que producción) sin encolar el envío, y envía en el
momento para leer la respuesta del SIN. Es la etapa que confirma si la firma XML-DSig alcanza o
hace falta XAdES-BES (ver [CONTRATO-SIAT.md §6](CONTRATO-SIAT.md#6-firma-digital)).

### V — Eventos
Cada evento lleva el CUFD vigente y un `cufdEvento` distinto; el rango de fechas se calcula a
partir de la emisión del CUFD del evento.

### VI — Paquetes (próxima)
- Los motivos **5, 6 y 7** corresponden a facturación manual y requieren **CAFC**, que ya se
  solicitó al SIN.
- Antes de correrla hay que:
  1. Volver a incorporar el CAFC (tabla, código por punto de venta y `<cafc>` en el XML y en el
     paquete). La migración `2026_09_14_..._eliminar_cafc_fuera_de_alcance_electronica` lo había
     quitado por considerarlo fuera de alcance.
  2. Resolver los problemas de contingencia de producción (ver
     [ESTADO-Y-PENDIENTES.md](ESTADO-Y-PENDIENTES.md)); el generador del piloto
     (`GeneradorPaquetePrueba`) ya emite con `tipo_emision = 2` y CUF coherente.
  3. Contemplar el límite de 500 facturas por paquete.

### VII y XI — Anulación y reversión
Anulan las facturas validadas en la etapa IV y luego revierten esas anulaciones
(`reversionAnulacionFactura`, sin motivo según el WSDL del piloto).

---

## 4. Al terminar

1. Todas las pruebas en sus esperadas → el checklist de la ficha marca el requisito.
2. El SIN aprueba en el portal → en el panel, cambiar el estado a `PILOTO_APROBADO`.
3. Cargar el certificado de la entidad certificadora, pasar el ambiente a 1 y el estado a
   `PRODUCCION` (ver [ALTA-DE-CLIENTE.md](ALTA-DE-CLIENTE.md) paso 7).
