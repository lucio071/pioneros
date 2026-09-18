<?php

namespace App\Http\Middleware;

use App\Models\DispositivoCronometro;
use Closure;
use Illuminate\Http\Request;

class AuthDispositivo
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['message' => 'Token requerido'], 401);
        }

        $dispositivo = DispositivoCronometro::where('api_token', $token)->first();

        if (!$dispositivo) {
            return response()->json(['message' => 'Dispositivo no encontrado'], 401);
        }

        if (!$dispositivo->activo) {
            return response()->json(['message' => 'Dispositivo inactivo'], 403);
        }

        $request->merge(['dispositivo' => $dispositivo]);

        return $next($request);
    }
}
