<?php

namespace App\Http\Controllers;

use App\Models\Comercio;
use App\Models\ComercioTelefono;
use App\Services\ComercioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ComercioController extends Controller
{
    private ComercioService $comercioService;

    public function __construct(ComercioService $comercioService)
    {
        $this->comercioService = $comercioService;
    }

    /**
     * Listar todos los comercios con sus teléfonos
     */
    public function index()
    {
        $comercios = Comercio::with('telefonos')->orderBy('nombre')->get();

        return response()->json($comercios);
    }

    /**
     * Crear un nuevo comercio
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nombre'  => 'required|string|max:255',
            'estado'  => 'sometimes|in:activo,inactivo',
            'flow_id' => 'sometimes|nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $comercio = Comercio::create([
            'nombre'  => $request->nombre,
            'estado'  => $request->estado ?? 'activo',
            'flow_id' => $request->flow_id,
        ]);

        Log::info('ComercioController: Comercio creado', [
            'id'     => $comercio->id,
            'nombre' => $comercio->nombre,
        ]);

        return response()->json($comercio->load('telefonos'), 201);
    }

    /**
     * Mostrar un comercio con sus teléfonos
     */
    public function show($id)
    {
        $comercio = Comercio::with('telefonos')->findOrFail($id);

        return response()->json($comercio);
    }

    /**
     * Actualizar un comercio
     */
    public function update(Request $request, $id)
    {
        $comercio = Comercio::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'nombre'  => 'sometimes|string|max:255',
            'estado'  => 'sometimes|in:activo,inactivo',
            'flow_id' => 'sometimes|nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $comercio->update($request->only(['nombre', 'estado', 'flow_id']));

        // Limpiar caché de los teléfonos asociados
        $this->comercioService->limpiarCacheComercio($comercio);

        Log::info('ComercioController: Comercio actualizado', [
            'id'     => $comercio->id,
            'nombre' => $comercio->nombre,
            'estado' => $comercio->estado,
        ]);

        return response()->json($comercio->load('telefonos'));
    }

    /**
     * Eliminar un comercio
     */
    public function destroy($id)
    {
        $comercio = Comercio::findOrFail($id);

        // Limpiar caché antes de eliminar
        $this->comercioService->limpiarCacheComercio($comercio);

        $nombre = $comercio->nombre;
        $comercio->delete();

        Log::info('ComercioController: Comercio eliminado', [
            'id'     => $id,
            'nombre' => $nombre,
        ]);

        return response()->json(['message' => 'Comercio eliminado correctamente']);
    }

    /**
     * Agregar un teléfono a un comercio
     */
    public function addTelefono(Request $request, $id)
    {
        $comercio = Comercio::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'telefono'   => 'required|string|max:255',
            'tipo_flujo' => 'sometimes|string|max:100',
            'activo'     => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Normalizar el teléfono antes de guardar y buscar
        $telefonoNormalizado = \App\Helpers\PhoneHelper::normalize($request->telefono);

        // Verificar que el teléfono no esté ya asignado a otro comercio
        $existente = ComercioTelefono::where('telefono', $telefonoNormalizado)->first();
        if ($existente) {
            return response()->json([
                'error' => 'Este número ya está asignado al comercio: ' . $existente->comercio->nombre,
            ], 409);
        }

        $telefono = $comercio->telefonos()->create([
            'telefono'   => $telefonoNormalizado,
            'tipo_flujo' => $request->tipo_flujo ?? 'normal',
            'activo'     => $request->activo ?? true,
        ]);

        // IMPORTANTE: Limpiar caché para que el bot reconozca este número inmediatamente
        $this->comercioService->limpiarCache($telefonoNormalizado);

        Log::info('ComercioController: Teléfono agregado', [
            'comercio_id' => $comercio->id,
            'telefono'    => $telefono->telefono,
            'tipo_flujo'  => $telefono->tipo_flujo,
        ]);

        return response()->json($telefono, 201);
    }

    /**
     * Eliminar un teléfono de un comercio
     */
    public function removeTelefono($id, $telefonoId)
    {
        $comercio = Comercio::findOrFail($id);
        $telefono = $comercio->telefonos()->findOrFail($telefonoId);

        // Limpiar caché del teléfono
        $this->comercioService->limpiarCache($telefono->telefono);

        $telefono->delete();

        Log::info('ComercioController: Teléfono eliminado', [
            'comercio_id' => $id,
            'telefono_id' => $telefonoId,
        ]);

        return response()->json(['message' => 'Teléfono eliminado correctamente']);
    }
}
