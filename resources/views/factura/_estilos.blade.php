{{-- Estilos de la representacion grafica (PDF carta). Siguen el diseno de
     la representacion que genera el propio SIN. dompdf solo entiende CSS
     basico. --}}
<style>
    /* dompdf solo soporta CSS basico: tablas y bloques, nada de flex.
       Medidas tomadas de la representacion del SIN en hoja carta. */
    @page { margin: 30px 37px 30px 40px; }
    body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #000; }
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; }
    .factura { position: relative; }

    /* Marca de agua del ambiente de pruebas (piloto). */
    .marca-agua { position: absolute; z-index: -1; top: 62px; left: -7px; width: 100%; text-align: center;
                  font-size: 73px; font-weight: bold; font-style: italic; color: #d0d0d0; white-space: nowrap; }

    .encabezado td { padding: 0; }
    .emisor { width: 31%; text-align: center; font-size: 11px; line-height: 15px; }
    .emisor .nombre { font-size: 12.5px; font-weight: bold; line-height: 17px; }
    .emisor .casa { font-weight: bold; }
    .identificacion td { padding: 0; font-size: 11px; line-height: 15px; }
    .identificacion .etiqueta { font-weight: bold; width: 56%; }
    .identificacion .cuf { font-size: 10.5px; line-height: 12px; }

    .titulo { text-align: center; margin-top: 40px; }
    .titulo h1 { font-size: 18px; margin: 0; font-weight: bold; line-height: 20px; }
    .titulo .sub { font-size: 12px; margin-top: 4px; }

    .comprador { margin-top: 27px; }
    .comprador td { padding: 2px 0 3px; font-size: 12px; }
    .comprador .etiqueta { font-weight: bold; }

    .detalle { margin-top: 18px; }
    .detalle th { border: 1pt solid #000; padding: 6px 4px; font-size: 11px; font-weight: bold; text-align: center;
                  vertical-align: middle; text-transform: uppercase; line-height: 12.5px;
                  background: #f7f7f7; }
    .detalle td { border: 1pt solid #000; padding: 6px; font-size: 11px; vertical-align: middle; }
    .detalle td.texto { vertical-align: top; padding: 2px 3px; }
    .detalle .derecha { text-align: right; }
    .detalle .total-etiqueta { text-align: right; font-size: 9px; padding: 2px 3px 1px; }
    .detalle .total-valor { text-align: right; font-size: 10.5px; padding: 1px 6px; }
    .detalle .fuerte { font-weight: bold; background: #f7f7f7; }
    .detalle .literal { border: none; vertical-align: middle; font-weight: bold; font-size: 11px; padding-left: 11px; }

    .pie { margin-top: 30px; }
    .pie .leyendas { text-align: center; font-size: 10px; padding: 10px 0 0; }
    .pie .leyendas p { margin: 0 0 11px; }
    /* Todo en mayusculas: como en el SIN, ocupa el mismo ancho que la linea de abajo. */
    .pie .leyendas .mayusculas { font-size: 9.35px; }
    .pie .codigo-qr { width: 88px; padding-right: 4px; text-align: right; }
</style>
