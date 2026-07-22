<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
     * Valida las credenciales e inicia la sesion.
     */
    public function store(Request $request): RedirectResponse
    {
        $credenciales = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Auth::attempt hashea y compara; "remember" mantiene la sesion abierta.
        if (! Auth::attempt($credenciales, $request->boolean('remember'))) {
            // Mismo mensaje para email o contrasena mala: no revelamos cual fallo.
            throw ValidationException::withMessages([
                'email' => 'Las credenciales no son correctas.',
            ]);
        }

        // Se regenera el id de sesion para evitar fijacion de sesion.
        $request->session()->regenerate();

        return redirect()->intended(route('admin.empresas.index'));
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
