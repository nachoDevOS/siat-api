<!DOCTYPE html>
{{-- Pantalla de acceso al panel. Standalone: no muestra la navegacion del panel. --}}
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acceso · Panel SIAT</title>
    <style>
        body { margin: 0; font-family: system-ui, sans-serif; background: #111827; color: #1f2937;
               display: flex; min-height: 100vh; align-items: center; justify-content: center; }
        .caja { background: #fff; border-radius: 10px; padding: 28px; width: 320px; box-shadow: 0 10px 30px rgba(0,0,0,.3); }
        h1 { font-size: 18px; margin: 0 0 4px; } .sub { color: #6b7280; font-size: 13px; margin-bottom: 18px; }
        label { display: block; font-size: 13px; margin: 12px 0 4px; color: #374151; }
        input[type=email], input[type=password] { width: 100%; padding: 9px; border: 1px solid #d1d5db;
               border-radius: 6px; font-size: 14px; box-sizing: border-box; }
        .btn { width: 100%; margin-top: 18px; background: #2563eb; color: #fff; padding: 10px; border: 0;
               border-radius: 6px; font-size: 15px; cursor: pointer; }
        .error { color: #dc2626; font-size: 13px; margin-top: 10px; }
        .recordar { display: flex; align-items: center; gap: 6px; margin-top: 12px; font-size: 13px; color: #374151; }
    </style>
</head>
<body>
    <form class="caja" method="POST" action="{{ route('login.store') }}">
        @csrf
        <h1>Panel SIAT</h1>
        <div class="sub">Ingrese con su cuenta de administrador.</div>

        <label for="email">Correo</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>

        <label for="password">Contrasena</label>
        <input id="password" type="password" name="password" required>

        <label class="recordar"><input type="checkbox" name="remember"> Recordarme</label>

        @error('email')
            <div class="error">{{ $message }}</div>
        @enderror

        <button class="btn" type="submit">Ingresar</button>
    </form>
</body>
</html>
