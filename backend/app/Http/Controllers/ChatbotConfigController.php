<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ChatbotConfigController extends Controller
{
    private $storageFile = 'chatbot/flows.json';

    /**
     * Cargar todos los flujos desde el archivo JSON
     */
    private function loadFlows()
    {
        if (!Storage::exists($this->storageFile)) {
            // Crear estructura inicial con flujo por defecto
            $defaultFlow = [
                'id' => 'flow_' . time(),
                'name' => 'Bono Techo Propio',
                'steps' => [
                    [
                        'state' => 'terrain',
                        'question' => '¿Tienes un terreno propio o de un familiar directo?',
                        'buttons' => [
                            ['id' => 'btn_1', 'title' => 'Sí', 'nextState' => 'family'],
                            ['id' => 'btn_2', 'title' => 'No', 'nextState' => 'finished']
                        ],
                        'order' => 1
                    ],
                    [
                        'state' => 'family',
                        'question' => '¿Tienes carga familiar? (esposa/hijos)',
                        'buttons' => [
                            ['id' => 'btn_3', 'title' => 'Sí', 'nextState' => 'income'],
                            ['id' => 'btn_4', 'title' => 'No', 'nextState' => 'finished']
                        ],
                        'order' => 2
                    ],
                    [
                        'state' => 'income',
                        'question' => '¿Tus ingresos mensuales son menores a S/. 3,000?',
                        'buttons' => [
                            ['id' => 'btn_5', 'title' => 'Sí', 'nextState' => 'previous_support'],
                            ['id' => 'btn_6', 'title' => 'No', 'nextState' => 'finished']
                        ],
                        'order' => 3
                    ],
                    [
                        'state' => 'previous_support',
                        'question' => '¿Has recibido anteriormente algún bono del Estado?',
                        'buttons' => [
                            ['id' => 'btn_7', 'title' => 'Sí', 'nextState' => 'finished'],
                            ['id' => 'btn_8', 'title' => 'No', 'nextState' => 'finished']
                        ],
                        'order' => 4
                    ]
                ],
                'created_at' => now()->toISOString(),
                'updated_at' => now()->toISOString()
            ];

            $this->saveFlows([$defaultFlow]);
            return [$defaultFlow];
        }

        $content = Storage::get($this->storageFile);
        return json_decode($content, true);
    }

    /**
     * Guardar flujos en el archivo JSON y limpiar caché
     */
    private function saveFlows($flows)
    {
        $path = storage_path('app/chatbot/flows.json');

        try {
            // Verificar que el directorio existe, crearlo si no
            $dir = dirname($path);  
            if (!file_exists($dir)) {
                if (!mkdir($dir, 0775, true)) {
                    throw new \RuntimeException("No se pudo crear el directorio: {$dir}");
                }
                Log::info('Created chatbot directory', ['dir' => $dir]);
            }

            // Verificar que el archivo (si existe) tiene permisos de escritura
            if (file_exists($path) && !is_writable($path)) {
                throw new \RuntimeException(
                    "Sin permisos de escritura sobre el archivo: {$path}. " .
                    "Ejecuta en el servidor: chmod 664 {$path}"
                );
            }

            // Guardar el JSON
            $json   = json_encode($flows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $result = Storage::put($this->storageFile, $json);

            if (!$result) {
                throw new \RuntimeException(
                    "Storage::put() devolvió false. Revisa los permisos del archivo o directorio: {$path}"
                );
            }

            // Verificar tamaño real para confirmar escritura
            clearstatcache(true, $path);
            $fileSize = file_exists($path) ? filesize($path) : 0;

            Log::info('Chatbot flows saved successfully', [
                'path'        => $path,
                'flows_count' => count($flows),
                'file_size'   => $fileSize,
                'timestamp'   => date('Y-m-d H:i:s'),
            ]);

        } catch (\Exception $e) {
            Log::error('Exception saving chatbot flows', [
                'error' => $e->getMessage(),
                'path'  => $path,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e; // Propaga el error → los endpoints retornarán 500 en vez de 200 falso
        }

        // Limpiar caché
        Cache::forget('chatbot_flows');
        cache()->forget('chatbot_flows');

        if (config('cache.default') === 'file') {
            $cacheFile = storage_path('framework/cache/data') . '/' . sha1('chatbot_flows');
            if (file_exists($cacheFile)) {
                @unlink($cacheFile);
                Log::info('Deleted cache file', ['file' => $cacheFile]);
            }
        }

        Log::info('Chatbot cache cleared successfully');
    }

    /**
     * GET /api/chatbot/flows
     * Obtener todos los flujos
     */
    public function getFlows()
    {
        $flows = $this->loadFlows();
        return response()->json($flows);
    }

    /**
     * GET /api/chatbot/flows/{id}
     * Obtener un flujo específico
     */
    public function getFlow($id)
    {
        $flows = $this->loadFlows();
        $flow = collect($flows)->firstWhere('id', $id);

        if (!$flow) {
            return response()->json(['error' => 'Flujo no encontrado'], 404);
        }

        return response()->json($flow);
    }

    /**
     * POST /api/chatbot/flows
     * Crear un nuevo flujo
     */
    public function createFlow(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255'
        ]);

        $flows = $this->loadFlows();

        $newFlow = [
            'id' => 'flow_' . time(),
            'name' => $request->name,
            'steps' => [],
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString()
        ];

        $flows[] = $newFlow;
        $this->saveFlows($flows);

        return response()->json($newFlow, 201);
    }

    /**
     * PUT /api/chatbot/flows/{id}
     * Actualizar un flujo
     */
    public function updateFlow(Request $request, $id)
    {
        $request->validate([
            'name' => 'sometimes|string|max:255'
        ]);

        $flows = $this->loadFlows();
        $index = collect($flows)->search(function ($flow) use ($id) {
            return $flow['id'] === $id;
        });

        if ($index === false) {
            return response()->json(['error' => 'Flujo no encontrado'], 404);
        }

        $flows[$index]['name'] = $request->name ?? $flows[$index]['name'];
        $flows[$index]['updated_at'] = now()->toISOString();

        $this->saveFlows($flows);

        return response()->json($flows[$index]);
    }

    /**
     * DELETE /api/chatbot/flows/{id}
     * Eliminar un flujo
     */
    public function deleteFlow($id)
    {
        $flows = $this->loadFlows();
        $flows = array_values(array_filter($flows, function ($flow) use ($id) {
            return $flow['id'] !== $id;
        }));

        $this->saveFlows($flows);

        return response()->json(['message' => 'Flujo eliminado correctamente']);
    }

    /**
     * POST /api/chatbot/flows/{id}/steps
     * Agregar un paso al flujo
     */
    public function addStep(Request $request, $id)
    {
        $request->validate([
            'state' => 'required|string',
            'question' => 'required|string',
            'buttons' => 'required|array',
            'buttons.*.id' => 'sometimes|string',
            'buttons.*.title' => 'required|string',
            'buttons.*.nextState' => 'required|string',
            'order' => 'sometimes|integer'
        ]);

        $flows = $this->loadFlows();
        $index = collect($flows)->search(function ($flow) use ($id) {
            return $flow['id'] === $id;
        });

        if ($index === false) {
            return response()->json(['error' => 'Flujo no encontrado'], 404);
        }

        // Generar IDs para botones si no vienen
        $buttons = collect($request->buttons)->map(function ($btn) {
            return [
                'id' => $btn['id'] ?? 'btn_' . uniqid(),
                'title' => $btn['title'],
                'nextState' => $btn['nextState']
            ];
        })->toArray();

        $newStep = [
            'state' => $request->state,
            'question' => $request->question,
            'buttons' => $buttons,
            'order' => $request->order ?? count($flows[$index]['steps']) + 1
        ];

        $flows[$index]['steps'][] = $newStep;
        $flows[$index]['updated_at'] = now()->toISOString();

        $this->saveFlows($flows);

        return response()->json($newStep, 201);
    }

    /**
     * PUT /api/chatbot/flows/{id}/steps/{state}
     * Actualizar un paso del flujo
     */
    public function updateStep(Request $request, $id, $state)
    {
        $request->validate([
            'question' => 'sometimes|string',
            'buttons' => 'sometimes|array',
            'buttons.*.id' => 'sometimes|string',
            'buttons.*.title' => 'required|string',
            'buttons.*.nextState' => 'required|string',
            'order' => 'sometimes|integer'
        ]);

        $flows = $this->loadFlows();
        $flowIndex = collect($flows)->search(function ($flow) use ($id) {
            return $flow['id'] === $id;
        });

        if ($flowIndex === false) {
            return response()->json(['error' => 'Flujo no encontrado'], 404);
        }

        $stepIndex = collect($flows[$flowIndex]['steps'])->search(function ($step) use ($state) {
            return $step['state'] === $state;
        });

        if ($stepIndex === false) {
            return response()->json(['error' => 'Paso no encontrado'], 404);
        }

        // Actualizar campos
        if ($request->has('question')) {
            $flows[$flowIndex]['steps'][$stepIndex]['question'] = $request->question;
        }

        if ($request->has('buttons')) {
            $buttons = collect($request->buttons)->map(function ($btn) {
                return [
                    'id' => $btn['id'] ?? 'btn_' . uniqid(),
                    'title' => $btn['title'],
                    'nextState' => $btn['nextState']
                ];
            })->toArray();

            $flows[$flowIndex]['steps'][$stepIndex]['buttons'] = $buttons;
        }

        if ($request->has('order')) {
            $flows[$flowIndex]['steps'][$stepIndex]['order'] = $request->order;
        }

        $flows[$flowIndex]['updated_at'] = now()->toISOString();

        $this->saveFlows($flows);

        return response()->json($flows[$flowIndex]['steps'][$stepIndex]);
    }

    /**
     * DELETE /api/chatbot/flows/{id}/steps/{state}
     * Eliminar un paso del flujo
     */
    public function deleteStep($id, $state)
    {
        $flows = $this->loadFlows();
        $flowIndex = collect($flows)->search(function ($flow) use ($id) {
            return $flow['id'] === $id;
        });

        if ($flowIndex === false) {
            return response()->json(['error' => 'Flujo no encontrado'], 404);
        }

        $flows[$flowIndex]['steps'] = array_values(array_filter(
            $flows[$flowIndex]['steps'],
            function ($step) use ($state) {
                return $step['state'] !== $state;
            }
        ));

        $flows[$flowIndex]['updated_at'] = now()->toISOString();

        $this->saveFlows($flows);

        return response()->json(['message' => 'Paso eliminado correctamente']);
    }

    /**
     * GET /api/chatbot/debug
     * Endpoint de diagnóstico para verificar estado del sistema
     */
    public function debug()
    {
        $path = storage_path('app/chatbot/flows.json');
        $dir = dirname($path);
        
        $debug = [
            'file_path' => $path,
            'file_exists' => file_exists($path),
            'file_readable' => is_readable($path),
            'file_writable' => is_writable($path),
            'dir_exists' => file_exists($dir),
            'dir_writable' => is_writable($dir),
            'file_size' => file_exists($path) ? filesize($path) : 0,
            'file_modified' => file_exists($path) ? date('Y-m-d H:i:s', filemtime($path)) : null,
            'cache_exists' => cache()->has('chatbot_flows'),
            'flows_count' => 0,
            'first_question' => null,
        ];
        
        if (file_exists($path)) {
            try {
                $content = file_get_contents($path);
                $flows = json_decode($content, true);
                $debug['flows_count'] = count($flows);
                if (!empty($flows[0]['steps'][0]['question'])) {
                    $debug['first_question'] = substr($flows[0]['steps'][0]['question'], 0, 100);
                }
            } catch (\Exception $e) {
                $debug['error'] = $e->getMessage();
            }
        }
        
        return response()->json($debug);
    }
}
