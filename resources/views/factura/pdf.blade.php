<!DOCTYPE html>
{{-- Representacion grafica de la factura (sector 1, compra-venta, electronica
     en linea). El documento LEGAL es el XML firmado; esto es lo que el
     comprador imprime, asi que lleva todo lo que el SIN espera ver: titulo
     con credito fiscal, datos del emisor y de la sucursal, monto en literal,
     leyenda de la actividad y la de documento fiscal digital, y el QR. --}}
<html lang="es">
<head>
    <meta charset="utf-8">
    @include('factura._estilos')
</head>
<body>
    @include('factura._cuerpo')
</body>
</html>
