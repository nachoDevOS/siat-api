<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Login del panel /admin. Autenticacion por sesion contra el modelo User.
 *
 * Se usa un controlador propio y minimo (sin paquetes de scaffolding) porque
 * el panel solo necesita entrar, salir y proteger rutas: nada de registro
 * publico ni recuperacion de contrasena por ahora.
 */
class LoginController extends Controller
{
    /**
     * Muestra el formulario de acceso.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Intentos fallidos permitidos antes de bloquear, y por cuanto tiempo.
     */
    private const INTENTOS_MAXIMOS = 5;

    private const BLOQUEO_SEGUNDOS = 60;

    /**
     * Valida las credenciales e inicia la sesion.
     */
    public function store(Request $request): RedirectResponse
    {
        $credenciales = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $clave = $this->claveIntentos($request);

        // Sin limite, el panel queda abierto a fuerza bruta contra el admin.
        if (RateLimiter::tooManyAttempts($clave, self::INTENTOS_MAXIMOS)) {
            $segundos = RateLimiter::availableIn($clave);

            throw ValidationException::withMessages([
                'email' => "Demasiados intentos. Reintente en {$segundos} segundos.",
            ]);
        }

        // Auth::attempt hashea y compara; "remember" mantiene la sesion abierta.
        if (! Auth::attempt($credenciales, $request->boolean('remember'))) {
            RateLimiter::hit($clave, self::BLOQUEO_SEGUNDOS);

            // Mismo mensaje para email o contrasena mala: no revelamos cual fallo.
            throw ValidationException::withMessages([
                'email' => 'Las credenciales no son correctas.',
            ]);
        }

        RateLimiter::clear($clave);

        // Se regenera el id de sesion para evitar fijacion de sesion.
        $request->session()->regenerate();

        return redirect()->intended(route('admin.empresas.index'));
    }

    /**
     * Los intentos se cuentan por email + IP: asi un atacante desde una IP no
     * puede bloquear la cuenta de un usuario legitimo que entra desde otra.
     */
    private function claveIntentos(Request $request): string
    {
        return 'login:'.Str::transliterate(Str::lower($request->string('email')).'|'.$request->ip());
    }

    /**
     * Cierra la sesion y limpia el token.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
