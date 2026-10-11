# Contrato con el SIAT — lo verificado y lo pendiente

> Referencia técnica de cómo habla este sistema con el SIN. Cada dato lleva su nivel de
> confianza. **Antes de cambiar un nombre de operación o de campo, contrástelo con el WSDL real**
> (`php artisan siat:inspeccionar-wsdl {empresa} --tipos`): ext-soap corta sin enviar si falta un
> campo de la struct.

Niveles:

- ✅ **Verificado**: contrastado con el WSDL del piloto, con respuestas reales del SIN o con
  `ventas` (sistema del mismo autor que factura en producción, modalidad computarizada).
- ⚠️ **Pendiente**: sale de documentación o deducción; confirmar antes de producción.

---

## 1. Identidad del proveedor

Solicitud R-1359 nº 9484 (09/08/2026): NIT 7633685015 · sistema *SolucionDigital-api* ·
código de sistema `22848EC6F66C9401C16F7` · tipo PROVEEDOR · modalidad electrónica en línea.

---

## 2. Transporte

| Tema | Valor | Nivel |
|---|---|---|
| URL piloto | `https://pilotosiatservicios.impuestos.gob.bo/v2/{Servicio}?wsdl` | ✅ |
| URL producción | `https://siatrest.impuestos.gob.bo/v2/{Servicio}?wsdl` | ⚠️ |
| Servicios | `FacturacionCodigos`, `FacturacionSincronizacion`, `FacturacionOperaciones`, `ServicioFacturacionCompraVenta` | ✅ |
| Token | Cabecera HTTP **`apikey: TokenApi {token_delegado}`** (no `Authorization`) | ✅ |
| Rechazos | HTTP 200 con `transaccion = false` y `mensajesList`, **no** SoapFault | ✅ |
| Fechas | Hora de Bolivia, formato `Y-m-d\TH:i:s.v` | ✅ |

Cabecera común de casi toda solicitud: `codigoAmbiente`, `codigoSistema`, `codigoModalidad`,
`nit`.

---

## 3. Códigos: CUIS y CUFD

| Tema | Detalle | Nivel |
|---|---|---|
| CUIS | Operación `cuis`. Dura ~1 año. Si ya hay uno vigente el SIN responde `transaccion=false` con **código 980** pero **devuelve el mismo CUIS**: no es error | ✅ |
| Vigencia | Se usa la `fechaVigencia` que declara el SIN, no "ahora + 1 año": un CUIS existente vence antes | ✅ |
| CUFD | Operación `cufd`. Dura 24 h. **Cada solicitud devuelve uno nuevo**. Trae `codigo`, `codigoControl`, `direccion`, `fechaVigencia` | ✅ |
| CUFD ↔ CUIS | Cada CUFD queda atado al CUIS con que se pidió. Mandar un CUFD con otro CUIS da **913 "CUIS INVALIDO"**. Por eso, cuando el SIN entrega un CUIS **nuevo**, el sistema pide también un CUFD nuevo; si devuelve el mismo (980), no | ✅ |
| CUFD vigente | El último insertado (`latest('id')`) no vencido; no por fecha, que se rompió al corregir la zona horaria | ✅ |
| Punto de venta 0 | Existe en el SIN sin registrarlo; tiene su propio CUIS y CUFD | ✅ |
| Registrar PV | `registroPuntoVenta` crea uno **nuevo cada vez**; un PV no se borra, solo se cierra, y cerrado no se reabre | ✅ |

---

## 4. CUF

```
nit(13) + fecha AAAAMMDDHHIISSmmm(17) + sucursal(4) + modalidad(1) + tipoEmision(1)
+ tipoFactura(1) + documentoSector(2) + numeroFactura(10) + puntoVenta(4)   = 53 dígitos
→ + dígito verificador módulo 11                                             = 54 dígitos
→ a base 16 (mayúsculas)
→ + codigoControl del CUFD
```

| Tema | Detalle | Nivel |
|---|---|---|
| Anchos | `tipoFactura` ocupa **1** dígito, `documentoSector` 2 | ✅ |
| Módulo 11 | Pesos 2..9 ciclando de derecha a izquierda; dígito = **`suma % 11`** (no `11 − resto`); 10 → 1, 11 → 0 | ✅ |
| Fecha | La del CUF debe ser idéntica a `<fechaEmision>`; por eso se emite con milisegundos en `000` | ✅ |
| Tipo de emisión | 1 en línea, 2 fuera de línea; entra al CUF | ✅ |

Implementación: `app/Services/Factura/GeneradorCuf.php`.

---

## 5. XML de la factura (sector 1, electrónica)

| Tema | Detalle | Nivel |
|---|---|---|
| Raíz | `facturaElectronicaCompraVenta` (la computarizada es `facturaComputarizadaCompraVenta`) | ✅ |
| Atributos raíz | `xmlns:xsi` + `xsi:noNamespaceSchemaLocation="facturaElectronicaCompraVenta.xsd"` | ✅ |
| Vacíos | Siempre presentes con `xsi:nil="true"`, nunca omitidos (el XSD es una secuencia) | ✅ |
| Orden de campos | El de `ConstructorXml` | ⚠️ contrastar con el XSD |
| `codigoExcepcion` | `0` normal; `1` = NIT no validado (solo documento 5) | ✅ (`ventas`) |
| `cafc` | Hoy siempre nil (ver Etapa VI) | ⚠️ |
| `montoTotalSujetoIva` | `montoTotal − montoGiftCard` | ✅ (`ventas`, con gift card 0) |
| `montoTotalMoneda` / `tipoCambio` | Hoy solo bolivianos: igual a `montoTotal`, cambio 1 | ✅ |
| `actividadEconomica` | Del catálogo de productos homologados del NIT | ✅ |
| `leyenda` | Del catálogo de leyendas de la actividad, al azar | ✅ |

Cabecera, en orden: `nitEmisor`, `razonSocialEmisor`, `municipio`, `telefono`, `numeroFactura`,
`cuf`, `cufd`, `codigoSucursal`, `direccion`, `codigoPuntoVenta`, `fechaEmision`,
`nombreRazonSocial`, `codigoTipoDocumentoIdentidad`, `numeroDocumento`, `complemento`,
`codigoCliente`, `codigoMetodoPago`, `numeroTarjeta`, `montoTotal`, `montoTotalSujetoIva`,
`codigoMoneda`, `tipoCambio`, `montoTotalMoneda`, `montoGiftCard`, `descuentoAdicional`,
`codigoExcepcion`, `cafc`, `leyenda`, `usuario`, `codigoDocumentoSector`.

Detalle, en orden: `actividadEconomica`, `codigoProductoSin`, `codigoProducto`, `descripcion`,
`cantidad`, `unidadMedida`, `precioUnitario`, `montoDescuento`, `subTotal`, `numeroSerie`,
`numeroImei`.

---

## 6. Firma digital

| Tema | Detalle | Nivel |
|---|---|---|
| Tipo | XML-DSig enveloped, RSA-SHA256, C14N, digest SHA-256, `X509Certificate` en `KeyInfo` | Implementado |
| Canonicalización | El `SignedInfo` se canonicaliza **en su lugar** (hereda `xmlns:xsi` de la raíz); suelto no verifica | ✅ (openssl_verify) |
| Perfil XAdES-BES | `QualifyingProperties` armado pero **no enchufado** (`FirmadorXml::construirQualifyingProperties`) | ⚠️ **bloqueante** |
| Dudas XAdES | Versión del namespace (1.3.2 vs 1.4.1), `SignedDataObjectProperties`, digest del certificado, formato de `X509IssuerName`, patrón de los `Id` | ⚠️ |

Se confirma en la Etapa IV/VIII del piloto con el `.p12` real. Si el SIN acepta la firma XML-DSig
simple, el bloque XAdES no hace falta.

---

## 7. Recepción, estado y anulación

| Tema | Detalle | Nivel |
|---|---|---|
| Struct base `solicitudRecepcion` | `codigoAmbiente`, `codigoDocumentoSector`, `codigoEmision`, `codigoModalidad`, `codigoPuntoVenta`, `codigoSistema`, `codigoSucursal`, `cufd`, `cuis`, `nit`, `tipoFacturaDocumento` | ✅ WSDL piloto |
| `recepcionFactura` | + `archivo` (XML firmado en **gzip**), `fechaEnvio`, `hashArchivo` | ✅ |
| `hashArchivo` | SHA-256 **del gzip**, no del XML | ✅ |
| `verificacionEstadoFactura` | + `cuf` | ✅ |
| `anulacionFactura` | + `cuf`, `codigoMotivo`; con el **CUFD vigente**, no el de la emisión | ✅ |
| `reversionAnulacionFactura` | + `cuf` (sin motivo) | ✅ WSDL piloto |
| Códigos | **908 y 690 = validada**; **902 = pendiente** (no es rechazo) | ✅ |
| 935 | `fechaEnvio` fuera de tolerancia (zona horaria) | ✅ |

---

## 8. Eventos y paquetes (contingencia)

| Tema | Detalle | Nivel |
|---|---|---|
| `registroEventoSignificativo` | `codigoMotivoEvento`, `descripcion`, `cuis`, `cufd` (vigente), `cufdEvento` (el de la caída, **distinto** del vigente), `fechaHoraInicioEvento`, `fechaHoraFinEvento`, sucursal y PV. Respuesta bajo `RespuestaListaEventos`, código en `codigoRecepcionEventoSignificativo` | ✅ WSDL piloto |
| Motivos | 1 corte de internet · 2 inaccesibilidad del servicio web · 3 zonas sin internet · 4 venta sin internet · 5 virus/falla de software · 6 cambio de infraestructura/hardware · 7 corte de energía | ✅ portal |
| `recepcionPaqueteFactura` | Struct base + `cafc`, `cantidadFacturas`, `codigoEvento`, `archivo`, `fechaEnvio`, `hashArchivo` | ✅ WSDL piloto |
| Archivo | **TAR** con un XML firmado por factura, comprimido en gzip | ✅ |
| `validacionRecepcionPaqueteFactura` | Confirma el procesamiento del paquete | ✅ nombre |
| Máximo por paquete | 500 facturas (el portal pide "igual a 500" y "menor a 500") | ✅ portal |
| CAFC | Necesario para los motivos 5–7 (facturación manual) | ⚠️ en trámite con el SIN |

---

## 9. Sincronización de catálogos

Operaciones: `sincronizarFechaHora`, `sincronizarActividades`,
`sincronizarListaProductosServicios`, `sincronizarListaLeyendasFactura`,
`sincronizarListaMensajesServicios`, `sincronizarListaActividadesDocumentoSector` y las
paramétricas `sincronizarParametrica*` (unidad de medida, tipo de moneda, documento de
identidad, método de pago, motivo de anulación, documento sector, país de origen, tipo de punto
de venta, entre otras). Todas requieren `cuis`, `codigoSucursal`, `codigoPuntoVenta`. Un rechazo
viene con `transaccion=false` dentro del envoltorio de cada operación (`RespuestaSiat::rechazoDeCatalogo`).

---

## 10. QR de verificación

| Tema | Valor | Nivel |
|---|---|---|
| Piloto | `https://pilotosiat.impuestos.gob.bo/consulta/QR` | ✅ (`ventas`) |
| Producción | `https://siat.impuestos.gob.bo/consulta/QR` | ⚠️ mismo camino en el dominio principal; confirmar |
| Parámetros | `nit`, `cuf`, `numero`, `t` | ✅ (`ventas`) |
| `t` | `2` (media página / carta; `1` = rollo), configurable con `SIAT_QR_TAMANIO` | ✅ valor de `ventas`; significado a confirmar |
