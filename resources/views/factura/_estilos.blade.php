{{-- Estilos de la representacion grafica (carta). Los comparten el PDF y la
     vista publica de la factura. dompdf solo entiende CSS basico. --}}
<style>
    /* dompdf solo soporta CSS basico: tablas y bloques, nada de flex. */
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
    h1 { font-size: 15px; margin: 0; text-align: center; }
    .sub { text-align: center; font-size: 10px; margin: 2px 0 10px; }
    table { width: 100%; border-collapse: collapse; }
    .datos td { padding: 2px 4px; vertical-align: top; border: none; }
    .detalle { margin-top: 8px; }
    .detalle th, .detalle td { border: 1px solid #999; padding: 4px; text-align: left; }
    .detalle th { background: #eee; }
    .detalle .derecha { text-align: right; }
    .derecha { text-align: right; }
    .totales { width: 50%; margin-left: 50%; margin-top: 6px; }
    .totales th, .totales td { border: 1px solid #999; padding: 3px 4px; }
    .totales th { text-align: left; font-weight: normal; }
    .total-pagar th, .total-pagar td { font-weight: bold; }
    .literal { margin-top: 6px; font-weight: bold; }
    .pie { margin-top: 12px; text-align: center; font-size: 9px; }
    .pie p { margin: 4px 0; }
    .qr { text-align: center; margin-top: 8px; }
</style>
