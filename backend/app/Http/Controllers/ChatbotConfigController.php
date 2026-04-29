<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ChatbotConfigController extends Controller
{
    private $storageFile = 'chatbot/flows.json';

    // Tipos de acción válidos
    private const VALID_ACTION_TYPES = ['buttons', 'free_text', 'validated_input', 'link_button'];

    // Tipos de validación válidos
    private const VALID_VALIDATION_TYPES = ['dni', 'phone', 'email', 'number', 'text', 'regex'];

    /**
     * Cargar todos los flujos desde el archivo JSON
     */
    private function loadFlows()
    {
        if (!Storage::exists($this->storageFile)) {
            $defaultFlow = $this->buildDefaultFlow();
            $this->saveFlows([$defaultFlow]);
            return [$defaultFlow];
        }

        $content = Storage::get($this->storageFile);
        return json_decode($content, true);
    }

    /**
     * Construir el flujo por defecto con el nuevo schema de acciones
     */
    private function buildDefaultFlow(): array
    {
        return [
            'id'         => 'flow_' . time(),
            'name'       => 'Beneficios del Club',
            'steps'      => [
                [
                    'state'       => 'club_member',
                    'question'    => '¿Eres socio activo del club?',
                    'action_type' => 'buttons',
                    'actions'     => [
                        ['id' => 'btn_1', 'title' => 'Sí', 'next_state' => 'get_dni'],
                        ['id' => 'btn_2', 'title' => 'No', 'next_state' => 'nofinished'],
                    ],
                    'order' => 1,
                ],
                [
                    'state'       => 'get_dni',
                    'question'    => 'Por favor ingresa tu número de DNI (8 dígitos):',
                    'action_type' => 'validated_input',
                    'validation'  => [
                        'type'          => 'dni',
                        'error_message' => '⚠️ DNI inválido. Debe tener exactamente 8 dígitos numéricos. Intenta de nuevo:',
                    ],
                    'actions' => [
                        ['next_state' => 'finished'],
                    ],
                    'order' => 2,
                ],
            ],
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ];
    }

    /**
     * Guardar flujos en el archivo JSON y limpiar caché
     */
    private function saveFlows($flows)
    {
        $path = storage_path('app/chatbot/flows.json');

        try {
            $dir = dirname($path);
            if (!file_exists($dir)) {
                if (!mkdir($dir, 0775, true)) {
                    throw new \RuntimeException("No se pudo crear el directorio: {$dir}");
                }
                Log::info('Created chatbot directory', ['dir' => $dir]);
            }

            if (file_exists($path) && !is_writable($path)) {
                throw new \RuntimeException(
                    "Sin permisos de escritura sobre el archivo: {$path}. " .
                    "Ejecuta en el servidor: chmod 664 {$path}"
                );
            }

            $json   = json_encode($flows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $result = Storage::put($this->storageFile, $json);

            if (!$result) {
                throw new \RuntimeException(
                    "Storage::put() devolvió false. Revisa los permisos del archivo o directorio: {$path}"
                );
            }

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
            throw $e;
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

    // ==================== ENDPOINTS ====================

    /**
     * GET /api/chatbot/flows
     */
    public function getFlows()
    {
        $flows = $this->loadFlows();
        return response()->json($flows);
    }

    /**
     * GET /api/chatbot/flows/{id}
     */
    public function getFlow($id)
    {
        $flows = $this->loadFlows();
        $flow  = collect($flows)->firstWhere('id', $id);

        if (!$flow) {
            return response()->json(['error' => 'Flujo no encontrado'], 404);
        }

        return response()->json($flow);
    }

    /**
     * POST /api/chatbot/flows
     */
    public function createFlow(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $flows = $this->loadFlows();

        $newFlow = [
            'id'         => 'flow_' . time(),
            'name'       => $request->name,
            'steps'      => [],
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ];

        $flows[] = $newFlow;
        $this->saveFlows($flows);

        return response()->json($newFlow, 201);
    }

    /**
     * PUT /api/chatbot/flows/{id}
     */
    public function updateFlow(Request $request, $id)
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
        ]);

        $flows = $this->loadFlows();
        $index = collect($flows)->search(fn($flow) => $flow['id'] === $id);

        if ($index === false) {
            return response()->json(['error' => 'Flujo no encontrado'], 404);
        }

        $flows[$index]['name']       = $request->name ?? $flows[$index]['name'];
        $flows[$index]['updated_at'] = now()->toISOString();

        $this->saveFlows($flows);
        return response()->json($flows[$index]);
    }

    /**
     * DELETE /api/chatbot/flows/{id}
     */
    public function deleteFlow($id)
    {
        $flows = array_values(array_filter($this->loadFlows(), fn($flow) => $flow['id'] !== $id));
        $this->saveFlows($flows);
        return response()->json(['message' => 'Flujo eliminado correctamente']);
    }

    /**
     * POST /api/chatbot/flows/{id}/steps
     */
    public function addStep(Request $request, $id)
    {
        $request->validate([
            'state'       => 'required|string',
            'question'    => 'required|string',
            'action_type' => 'sometimes|string|in:buttons,free_text,validated_input,link_button',
            'order'       => 'sometimes|integer',

            // Para action_type = buttons (y retrocompatibilidad con buttons legacy)
            'actions'               => 'sometimes|array',
            'actions.*.id'          => 'sometimes|string',
            'actions.*.title'       => 'required_if_accepted:actions|string',
            'actions.*.next_state'  => 'required_if_accepted:actions|string',

            // Para action_type = validated_input
            'validation'                  => 'sometimes|array',
            'validation.type'             => 'required_with:validation|string|in:dni,phone,email,number,text,regex',
            'validation.error_message'    => 'sometimes|string',
            'validation.regex_pattern'    => 'sometimes|nullable|string',

            // Retrocompatibilidad legacy
            'buttons'              => 'sometimes|array',
            'buttons.*.id'         => 'sometimes|string',
            'buttons.*.title'      => 'required_if_accepted:buttons|string',
            'buttons.*.nextState'  => 'required_if_accepted:buttons|string',
        ]);

        $flows     = $this->loadFlows();
        $flowIndex = collect($flows)->search(fn($flow) => $flow['id'] === $id);

        if ($flowIndex === false) {
            return response()->json(['error' => 'Flujo no encontrado'], 404);
        }

        $actionType = $request->action_type ?? 'buttons';
        $newStep    = $this->buildStep($request, $actionType, count($flows[$flowIndex]['steps']));

        $flows[$flowIndex]['steps'][]     = $newStep;
        $flows[$flowIndex]['updated_at']  = now()->toISOString();

        $this->saveFlows($flows);
        return response()->json($newStep, 201);
    }

    /**
     * PUT /api/chatbot/flows/{id}/steps/{state}
     */
    public function updateStep(Request $request, $id, $state)
    {
        $request->validate([
            'question'    => 'sometimes|string',
            'action_type' => 'sometimes|string|in:buttons,free_text,validated_input,link_button',
            'order'       => 'sometimes|integer',

            'actions'              => 'sometimes|array',
            'actions.*.id'         => 'sometimes|string',
            'actions.*.title'      => 'sometimes|string',
            'actions.*.next_state' => 'sometimes|string',

            'validation'               => 'sometimes|array',
            'validation.type'          => 'sometimes|string|in:dni,phone,email,number,text,regex',
            'validation.error_message' => 'sometimes|string',
            'validation.regex_pattern' => 'sometimes|nullable|string',

            // Retrocompatibilidad legacy
            'buttons'             => 'sometimes|array',
            'buttons.*.id'        => 'sometimes|string',
            'buttons.*.title'     => 'sometimes|string',
            'buttons.*.nextState' => 'sometimes|string',
        ]);

        $flows     = $this->loadFlows();
        $flowIndex = collect($flows)->search(fn($flow) => $flow['id'] === $id);

        if ($flowIndex === false) {
            return response()->json(['error' => 'Flujo no encontrado'], 404);
        }

        $stepIndex = collect($flows[$flowIndex]['steps'])->search(fn($s) => $s['state'] === $state);

        if ($stepIndex === false) {
            return response()->json(['error' => 'Paso no encontrado'], 404);
        }

        $step       = $flows[$flowIndex]['steps'][$stepIndex];
        $actionType = $request->action_type ?? $step['action_type'] ?? 'buttons';

        // Actualizar campos
        if ($request->has('question')) {
            $step['question'] = $request->question;
        }

        $step['action_type'] = $actionType;

        // Actualizar acciones según el tipo
        if ($actionType === 'buttons') {
            if ($request->has('actions')) {
                $step['actions'] = $this->normalizeActionsInput($request->actions);
                // Limpiar campos de otros tipos
                unset($step['validation'], $step['buttons']);
            } elseif ($request->has('buttons')) {
                // Retrocompatibilidad: convertir buttons al nuevo formato
                $step['actions'] = $this->convertLegacyButtons($request->buttons);
                unset($step['buttons'], $step['validation']);
            }
        } elseif (in_array($actionType, ['free_text', 'validated_input'])) {
            if ($request->has('actions')) {
                // Solo guardar next_state para estos tipos
                $step['actions'] = [['next_state' => $request->actions[0]['next_state'] ?? '']];
            }
            if ($actionType === 'validated_input' && $request->has('validation')) {
                $step['validation'] = $request->validation;
            }
            // Limpiar campos de botones
            unset($step['buttons']);
        } elseif ($actionType === 'link_button') {
            if ($request->has('actions')) {
                $action = $request->actions[0] ?? [];
                $step['actions'] = [[
                    'button_text' => $action['button_text'] ?? 'Ver más',
                    'url'         => $action['url'] ?? '',
                    'next_state'  => $action['next_state'] ?? '',
                ]];
            }
            unset($step['buttons'], $step['validation']);
        }

        if ($request->has('order')) {
            $step['order'] = $request->order;
        }

        $flows[$flowIndex]['steps'][$stepIndex] = $step;
        $flows[$flowIndex]['updated_at']         = now()->toISOString();

        $this->saveFlows($flows);
        return response()->json($flows[$flowIndex]['steps'][$stepIndex]);
    }

    /**
     * DELETE /api/chatbot/flows/{id}/steps/{state}
     */
    public function deleteStep($id, $state)
    {
        $flows     = $this->loadFlows();
        $flowIndex = collect($flows)->search(fn($flow) => $flow['id'] === $id);

        if ($flowIndex === false) {
            return response()->json(['error' => 'Flujo no encontrado'], 404);
        }

        $flows[$flowIndex]['steps'] = array_values(array_filter(
            $flows[$flowIndex]['steps'],
            fn($step) => $step['state'] !== $state
        ));

        $flows[$flowIndex]['updated_at'] = now()->toISOString();

        $this->saveFlows($flows);
        return response()->json(['message' => 'Paso eliminado correctamente']);
    }

    /**
     * GET /api/chatbot/debug
     */
    public function debug()
    {
        $path = storage_path('app/chatbot/flows.json');
        $dir  = dirname($path);

        $debug = [
            'file_path'     => $path,
            'file_exists'   => file_exists($path),
            'file_readable' => is_readable($path),
            'file_writable' => is_writable($path),
            'dir_exists'    => file_exists($dir),
            'dir_writable'  => is_writable($dir),
            'file_size'     => file_exists($path) ? filesize($path) : 0,
            'file_modified' => file_exists($path) ? date('Y-m-d H:i:s', filemtime($path)) : null,
            'cache_exists'  => cache()->has('chatbot_flows'),
            'flows_count'   => 0,
            'first_step'    => null,
            'schema_version' => 'v2_actions',
        ];

        if (file_exists($path)) {
            try {
                $content              = file_get_contents($path);
                $flows                = json_decode($content, true);
                $debug['flows_count'] = count($flows);
                if (!empty($flows[0]['steps'][0])) {
                    $step = $flows[0]['steps'][0];
                    $debug['first_step'] = [
                        'state'       => $step['state'] ?? null,
                        'action_type' => $step['action_type'] ?? 'buttons (legacy)',
                        'question'    => substr($step['question'] ?? '', 0, 100),
                    ];
                }
            } catch (\Exception $e) {
                $debug['error'] = $e->getMessage();
            }
        }

        return response()->json($debug);
    }

    // ==================== HELPERS PRIVADOS ====================

    /**
     * Construir un objeto paso a partir del request
     */
    private function buildStep(Request $request, string $actionType, int $currentCount): array
    {
        $step = [
            'state'       => $request->state,
            'question'    => $request->question,
            'action_type' => $actionType,
            'order'       => $request->order ?? $currentCount + 1,
        ];

        if ($actionType === 'buttons') {
            if ($request->has('actions')) {
                $step['actions'] = $this->normalizeActionsInput($request->actions);
            } elseif ($request->has('buttons')) {
                // Retrocompatibilidad
                $step['actions'] = $this->convertLegacyButtons($request->buttons);
            } else {
                $step['actions'] = [];
            }
        } elseif (in_array($actionType, ['free_text', 'validated_input'])) {
            $nextState       = $request->actions[0]['next_state'] ?? '';
            $step['actions'] = [['next_state' => $nextState]];

            if ($actionType === 'validated_input') {
                $step['validation'] = $request->validation ?? ['type' => 'text'];
            }
        } elseif ($actionType === 'link_button') {
            $action = $request->actions[0] ?? [];
            $step['actions'] = [[
                'button_text' => $action['button_text'] ?? 'Ver más',
                'url'         => $action['url'] ?? '',
                'next_state'  => $action['next_state'] ?? '',
            ]];
        }

        return $step;
    }

    /**
     * Normalizar el input de acciones del request
     */
    private function normalizeActionsInput(array $actions): array
    {
        return collect($actions)->map(fn($a) => [
            'id'         => $a['id'] ?? 'btn_' . uniqid(),
            'title'      => $a['title'],
            'next_state' => $a['next_state'] ?? $a['nextState'] ?? '',
        ])->toArray();
    }

    /**
     * Convertir formato legacy de buttons al nuevo formato de actions
     */
    private function convertLegacyButtons(array $buttons): array
    {
        return collect($buttons)->map(fn($b) => [
            'id'         => $b['id'] ?? 'btn_' . uniqid(),
            'title'      => $b['title'],
            'next_state' => $b['nextState'] ?? $b['next_state'] ?? '',
        ])->toArray();
    }
}
