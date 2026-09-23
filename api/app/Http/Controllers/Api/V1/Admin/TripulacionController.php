<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\FechaCategoria;
use App\Models\Tripulacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TripulacionController extends Controller
{
    public function store(Request $request, FechaCategoria $fechaCategoria): JsonResponse
    {
        if ($fechaCategoria->fecha->estado === 'finalizada') {
            return response()->json(['message' => 'Fecha finalizada, no se puede inscribir'], 422);
        }

        $data = $request->validate([
            'numero' => 'required|string|max:10',
            'nombre' => 'required|string|max:255',
            'piloto' => 'required|string|max:255',
            'copiloto' => 'nullable|string|max:255',
            'orden_largada' => 'nullable|integer|min:1',
        ]);

        $data['fecha_categoria_id'] = $fechaCategoria->id;
        $trip = Tripulacion::create($data);
        $fechaCategoria->fecha->incrementVersion();

        return response()->json(['data' => $trip->load('vueltas.tramos')], 201);
    }

    public function update(Request $request, Tripulacion $tripulacion): JsonResponse
    {
        if ($tripulacion->fechaCategoria->fecha->estado === 'finalizada') {
            return response()->json(['message' => 'Fecha finalizada, no se puede modificar'], 422);
        }

        $data = $request->validate([
            'numero' => 'sometimes|string|max:10',
            'nombre' => 'sometimes|string|max:255',
            'piloto' => 'sometimes|string|max:255',
            'copiloto' => 'nullable|string|max:255',
            'orden_largada' => 'nullable|integer|min:1',
        ]);

        $tripulacion->update($data);
        $tripulacion->fechaCategoria->fecha->incrementVersion();

        return response()->json(['data' => $tripulacion->load('vueltas.tramos')]);
    }

    public function destroy(Tripulacion $tripulacion): JsonResponse
    {
        if ($tripulacion->fechaCategoria->fecha->estado === 'finalizada') {
            return response()->json(['message' => 'Fecha finalizada, no se puede eliminar'], 422);
        }

        $fecha = $tripulacion->fechaCategoria->fecha;
        $tripulacion->delete();
        $fecha->incrementVersion();

        return response()->json(null, 204);
    }

    /**
     * Solo admin puede modificar puntos en fechas finalizadas.
     */
    public function asignarPuntos(Request $request, Tripulacion $tripulacion): JsonResponse
    {
        $user = $request->user();
        $fecha = $tripulacion->fechaCategoria->fecha;

        if ($fecha->estado === 'finalizada' && ($user->rol ?? 'cronometrista') !== 'admin') {
            return response()->json(['message' => 'Solo el administrador puede modificar puntos en fechas finalizadas'], 403);
        }

        $data = $request->validate([
            'puntos' => 'required|integer|min:0',
        ]);

        $tripulacion->update($data);
        $fecha->incrementVersion();

        return response()->json(['data' => $tripulacion]);
    }

    public function salioAPista(Tripulacion $tripulacion): JsonResponse
    {
        if ($tripulacion->fechaCategoria->fecha->estado === 'finalizada') {
            return response()->json(['message' => 'Fecha finalizada'], 422);
        }

        $tripulacion->update(['estado' => 'en_pista']);
        $tripulacion->fechaCategoria->fecha->incrementVersion();

        return response()->json(['data' => $tripulacion->load('vueltas.tramos')]);
    }

    /**
     * Confirmar manualmente que una tripulacion largo una tanda especifica.
     * Se usa cuando el sensor de largada fallo pero el auto si largo.
     */
    public function confirmarLargoTanda(Request $request, Tripulacion $tripulacion): JsonResponse
    {
        if ($tripulacion->fechaCategoria->fecha->estado === 'finalizada') {
            return response()->json(['message' => 'Fecha finalizada, no se puede modificar'], 422);
        }

        $data = $request->validate([
            'vuelta_numero' => 'required|integer|min:1',
        ]);

        $vuelta = $tripulacion->vueltas()->firstOrCreate(
            ['numero_vuelta' => $data['vuelta_numero']],
            ['fase' => 'clasificacion', 'nula' => false, 'confirmada' => false]
        );
        $vuelta->update(['largo' => true]);

        $tripulacion->fechaCategoria->fecha->incrementVersion();

        return response()->json(['data' => [
            'vuelta_numero' => $data['vuelta_numero'],
            'largo' => true,
        ]]);
    }

    /**
     * Marcar tripulacion como abandono mecanico.
     * Entra al bucket DNF del ranking; el cronometrista le asigna 1 punto manualmente.
     */
    public function reincorporar(Tripulacion $tripulacion): JsonResponse
    {
        if ($tripulacion->fechaCategoria->fecha->estado === 'finalizada') {
            return response()->json(['message' => 'Fecha finalizada, no se puede modificar'], 422);
        }

        $tripulacion->update(['estado' => 'en_pista']);
        $tripulacion->actualizarEstado();
        $tripulacion->fechaCategoria->fecha->incrementVersion();

        return response()->json(['data' => $tripulacion->load('vueltas.tramos')]);
    }

    public function abandonar(Tripulacion $tripulacion): JsonResponse
    {
        if ($tripulacion->fechaCategoria->fecha->estado === 'finalizada') {
            return response()->json(['message' => 'Fecha finalizada, no se puede modificar'], 422);
        }

        $tripulacion->update(['estado' => 'abandonado']);
        $tripulacion->fechaCategoria->fecha->incrementVersion();

        return response()->json(['data' => $tripulacion->load('vueltas.tramos')]);
    }
}
