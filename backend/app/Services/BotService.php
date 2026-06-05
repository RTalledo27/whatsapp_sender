<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Message;
use App\Models\BotConversation;
use App\Models\Campaign;
use App\Services\ComercioService;
use App\Services\DniValidationService;
use Illuminate\Support\Facades\Log;

class BotService
{
    private WhatsAppService $whatsappService;
    private LogicWareService $logicwareService;
    private ComercioService $comercioService;
    private DniValidationService $dniValidationService;
    private $botPhoneNumberId;
    private $botPhoneNumberIds = [];  // Todos los IDs de bot válidos
    private $flows = null;

    // Estados posibles del bot
    const STATE_INITIAL  = 'initial';
    const STATE_FINISHED = 'finished';
    const STATE_HANDOFF  = 'handoff';

    // Tipos de acción soportados
    const ACTION_BUTTONS         = 'buttons';
    const ACTION_FREE_TEXT       = 'free_text';
    const ACTION_VALIDATED_INPUT = 'validated_input';
    const ACTION_LINK_BUTTON     = 'link_button';
    const ACTION_PLANTILLA       = 'plantilla';
    const ACTION_CRM_LEAD        = 'crm_lead';

    // Tipos de validación disponibles
    const VALIDATION_DNI    = 'dni';
    const VALIDATION_PHONE  = 'phone';
    const VALIDATION_EMAIL  = 'email';
    const VALIDATION_NUMBER = 'number';
    const VALIDATION_TEXT   = 'text';
    const VALIDATION_REGEX  = 'regex';

    public function __construct(
        WhatsAppService $whatsappService,
        LogicWareService $logicwareService,
        ComercioService $comercioService,
        DniValidationService $dniValidationService
    ) {
        $this->whatsappService      = $whatsappService;
        $this->logicwareService     = $logicwareService;
        $this->comercioService      = $comercioService;
        $this->dniValidationService = $dniValidationService;
        $this->botPhoneNumberId     = config('services.whatsapp.leads_bot_id');

        // Registrar todos los bot IDs que deben ser atendidos
        $this->botPhoneNumberIds = array_filter([
            config('services.whatsapp.leads_bot_id'),
            config('services.whatsapp.leads_comunicaciones_bot_id'),
        ]);

        $this->loadFlows();
    }

    /**
     * Cargar flujos desde el archivo JSON con caché
     */
    private function loadFlows()
    {
        try {
            $this->flows = cache()->remember('chatbot_flows', 3600, function () {
                $path = storage_path('app/chatbot/flows.json');
                if (file_exists($path)) {
                    $content = file_get_contents($path);
                    $flows   = json_decode($content, true);
                    Log::info('Chatbot flows loaded from file', ['flows_count' => count($flows)]);
                    return $flows;
                }
                Log::warning('Chatbot flows file not found');
                return [];
            });
        } catch (\Exception $e) {
            Log::error('Error loading chatbot flows', ['error' => $e->getMessage()]);
            $this->flows = [];
        }
    }

    /**
     * Obtener el flujo activo (el primero por ahora)
     */
    /**
     * Obtener el flujo activo.
     * @param string|null $flowId  ID específico del flujo (por comercio)
     * @param string|null $phoneNumberId  ID del número que recibió el mensaje (para filtrar por número de bot)
     */
    private function getActiveFlow(?string $flowId = null, ?string $phoneNumberId = null)
    {
        Log::info('BotService: getActiveFlow called', [
            'requested_flow_id' => $flowId,
            'phone_number_id' => $phoneNumberId,
            'total_flows_loaded' => count($this->flows),
        ]);

        if (empty($this->flows)) {
            Log::error('BotService: No flows available in memory!');
            return null;
        }

        // Si se especifica un flow_id, buscar ese flujo
        if ($flowId) {
            foreach ($this->flows as $flow) {
                if (($flow['id'] ?? '') === $flowId) {
                    Log::info('BotService: Flow matched successfully', ['flow_id' => $flowId, 'flow_name' => $flow['name'] ?? 'Unknown']);
                    return $flow;
                }
            }
            Log::warning('BotService: Flow not found by ID, using default', ['requested_flow_id' => $flowId, 'available_flow_ids' => array_column($this->flows, 'id')]);
        }

        // Filtrar por phone_number_id si se proporcionó
        if ($phoneNumberId) {
            $defaultBotId = config('services.whatsapp.leads_bot_id');
            foreach ($this->flows as $flow) {
                $flowPhoneId = $flow['phone_number_id'] ?? $defaultBotId;
                if ($flowPhoneId === $phoneNumberId) {
                    Log::info('BotService: Flow matched by phone_number_id', [
                        'flow_id' => $flow['id'],
                        'flow_name' => $flow['name'] ?? 'Unknown',
                        'phone_number_id' => $phoneNumberId,
                    ]);
                    return $flow;
                }
            }
            Log::warning('BotService: No flow found for phone_number_id', ['phone_number_id' => $phoneNumberId]);
        }

        // Fallback: el primer flujo
        Log::info('BotService: Using default flow', ['default_flow_id' => $this->flows[0]['id'] ?? 'Unknown']);
        return $this->flows[0] ?? null;
    }

    /**
     * Buscar flujo correspondiente al nombre de la plantilla
     */
    private function findFlowByTemplateName(string $templateName, ?string $phoneNumberId = null): ?array
    {
        if (empty($this->flows)) {
            return null;
        }

        Log::info('BotService: findFlowByTemplateName called', [
            'template_name' => $templateName,
            'phone_number_id' => $phoneNumberId,
        ]);

        foreach ($this->flows as $flow) {
            // Si el primer paso del flujo es de tipo plantilla y su "question" coincide con el templateName
            if (!empty($flow['steps'][0])) {
                $firstStep = $flow['steps'][0];
                $actionType = $firstStep['action_type'] ?? null;
                $templateQuestion = $firstStep['question'] ?? '';

                if ($actionType === self::ACTION_PLANTILLA && strcasecmp($templateQuestion, $templateName) === 0) {
                    // Adicionalmente verificar que coincida con el phone_number_id (si se proporciona)
                    if ($phoneNumberId) {
                        $flowPhoneId = $flow['phone_number_id'] ?? null;
                        if ($flowPhoneId && $flowPhoneId !== $phoneNumberId) {
                            continue;
                        }
                    }
                    
                    Log::info('BotService: Found matching flow by plantilla step question', [
                        'flow_id' => $flow['id'],
                        'flow_name' => $flow['name'] ?? 'Unknown',
                    ]);
                    return $flow;
                }
            }
        }

        return null;
    }

    /**
     * Permite sobreescribir el ID del bot para pruebas
     */
    public function setBotChannelId($id)
    {
        $this->botPhoneNumberId = $id;
    }

    /**
     * Punto de entrada principal para manejar mensajes del bot
     */
    public function handleIncomingMessage(Contact $contact, Message $message)
    {
        $incomingPhoneNumberId = (string)$message->phone_number_id;
        Log::info('[BotFlow] handleIncomingMessage triggered', [
            'contact_phone' => $contact->phone_number,
            'message_id' => $message->id,
            'incoming_phone_number_id' => $incomingPhoneNumberId,
            'valid_bot_ids' => $this->botPhoneNumberIds,
        ]);

        // Verificar si el mensaje proviene de alguno de los números de bot registrados
        if (empty($this->botPhoneNumberIds) || !in_array($incomingPhoneNumberId, array_map('strval', $this->botPhoneNumberIds))) {
            Log::warning('[BotFlow] Incoming phone_number_id is not registered as a bot ID. Ignoring.', [
                'incoming' => $incomingPhoneNumberId
            ]);
            return;
        }

        // Usar el phone_number_id del mensaje entrante como el bot activo para esta conversación
        $this->botPhoneNumberId = $incomingPhoneNumberId;

        $conversation = $this->getOrCreateConversation($contact);
        Log::info("[BotFlow] Conversation loaded", [
            'conversation_id' => $conversation->id,
            'state' => $conversation->state,
            'context' => $conversation->context
        ]);

        // Detectar comercio por el número del remitente (quien escribe)
        $senderPhone = $contact->phone_number;
        Log::info("[BotFlow] Incoming message from phone", ['raw_sender_phone' => $senderPhone]);

        $comercio = $this->comercioService->detectarPorTelefono($senderPhone);
        if ($comercio) {
            Log::info("[BotFlow] Comercio object detected via ComercioService", [
                'comercio_id' => $comercio->id,
                'comercio_nombre' => $comercio->nombre,
                'comercio_flow_id' => $comercio->flow_id
            ]);

            $context = $conversation->context ?? [];
            $needsUpdate = false;
            $needsRestart = false;

            if (empty($context['comercio_id'])) {
                Log::info("[BotFlow] Setting initial comercio_id in context");
                $context['comercio_id']     = $comercio->id;
                $context['comercio_nombre'] = $comercio->nombre;
                $context['tipo_flujo']      = $this->comercioService->getTipoFlujo($senderPhone);
                $needsUpdate = true;
            }

            // Siempre sincronizar flow_id (por si se cambió desde el panel)
            if (($context['flow_id'] ?? null) !== $comercio->flow_id) {
                Log::info("[BotFlow] Syncing flow_id in context", [
                    'old_flow_id' => $context['flow_id'] ?? null,
                    'new_flow_id' => $comercio->flow_id
                ]);
                $context['flow_id'] = $comercio->flow_id;
                $needsUpdate = true;
                $needsRestart = true; // El flujo cambió, hay que reiniciar la conversación
            }

            if ($needsUpdate) {
                $newState = $needsRestart ? self::STATE_INITIAL : $conversation->state;
                Log::info("[BotFlow] Context update required. Updating state and context.", [
                    'old_state' => $conversation->state,
                    'new_state' => $newState,
                    'new_context' => $context
                ]);
                $this->updateState($conversation, $newState, $context);
                $conversation->refresh();
                Log::info('[BotFlow] Context updated successfully in DB', [
                    'final_context' => $conversation->context,
                    'state_restarted' => $needsRestart
                ]);
            }
        } else {
            Log::info("[BotFlow] No comercio detected for this phone number.");
            $context = $conversation->context ?? [];
            // Solo limpiar si el contacto tenía datos de comercio (comercio_id)
            // No limpiar si solo tiene flow_id, que puede ser un flujo resuelto para Leads Comunicaciones
            if (isset($context['comercio_id'])) {
                Log::info("[BotFlow] Phone is no longer a comercio, clearing context and resetting state.");
                
                // Limpiar el contexto de datos de comercio, manteniendo solo lo esencial
                $cleanContext = [
                    'retries' => 0,
                    'last_comercio_id' => $context['comercio_id'] ?? null // Guardar como historial si fuera necesario
                ];

                // Forzar el reinicio al flujo inicial (que será el de cliente por defecto)
                $conversation->state = self::STATE_INITIAL;
                $conversation->context = $cleanContext;
                $conversation->save();
                $conversation->refresh();
                Log::info('[BotFlow] Conversation reset to INITIAL (Client flow) because commerce link was removed.');
            }
        }

        // Reiniciar automáticamente si la conversación ya terminó
        if ($conversation->state === self::STATE_FINISHED) {
            Log::info("[BotFlow] Conversation was FINISHED. Auto-resetting for new incoming message.");
            $this->updateState($conversation, self::STATE_INITIAL, ['retries' => 0]);
            $conversation->refresh();
        }

        // Reiniciar si el usuario escribe hola/reset explícitamente
        if (strtolower(trim($message->message_content)) === 'hola' || strtolower(trim($message->message_content)) === 'reset') {
            Log::info("[BotFlow] Resetting conversation by user request.");
            $this->updateState($conversation, self::STATE_INITIAL, ['retries' => 0]);
            $conversation->refresh();
        }

        // Cuando llega una respuesta a un botón de plantilla (type=button), SIEMPRE
        // resetear al estado inicial para que el flujo empiece limpio desde el paso plantilla.
        // Esto corrige el caso donde la conversación quedó atascada en un paso intermedio
        // (con retries=0), haciendo que el bot no reconociera el botón de plantilla.
        if ($message->message_type === 'button') {
            Log::info("[BotFlow] Template button reply detected. Forcing reset to INITIAL for clean processing.", [
                'previous_state'   => $conversation->state,
                'previous_retries' => $conversation->context['retries'] ?? 0,
            ]);
            $this->updateState($conversation, self::STATE_INITIAL, ['retries' => 0]);
            $conversation->refresh();
        }

        // El bot no interviene en estado handoff (legacy)
        if ($conversation->state === self::STATE_HANDOFF) {
            Log::info("[BotFlow] Ignoring message because conversation is in HANDOFF state (legacy).");
            return;
        }

        // --- Lógica de Resolución para Múltiples Flujos (Solo Leads Comunicaciones) ---
        $leadsComunicacionesBotId = config('services.whatsapp.leads_comunicaciones_bot_id');
        $isLeadsComunicaciones = ($leadsComunicacionesBotId && (string)$this->botPhoneNumberId === (string)$leadsComunicacionesBotId);

        if ($conversation->state === self::STATE_INITIAL && !$comercio && $isLeadsComunicaciones) {
            $resolvedFlowId = null;

            // Buscar el último mensaje saliente de tipo campaña del número de Leads Comunicaciones
            // Filtrar por phone_number_id para no mezclar campañas de otros bots (Club de Beneficios, etc.)
            $outboundMessage = Message::where('contact_id', $contact->id)
                ->where('direction', 'outbound')
                ->where('phone_number_id', $this->botPhoneNumberId)
                ->whereNotNull('campaign_id')
                ->orderBy('created_at', 'desc')
                ->first();

            if ($outboundMessage && $outboundMessage->campaign_id) {
                $campaign = Campaign::find($outboundMessage->campaign_id);
                if ($campaign && $campaign->template_name) {
                    $templateName = $campaign->template_name;
                    $matchedFlow = $this->findFlowByTemplateName($templateName, $incomingPhoneNumberId);
                    if ($matchedFlow) {
                        $resolvedFlowId = $matchedFlow['id'];
                        Log::info("[BotFlow] Resolved flow ID from last sent campaign template", [
                            'template_name' => $templateName,
                            'flow_id' => $resolvedFlowId
                        ]);
                    } else {
                        Log::warning("[BotFlow] No flow found matching template name", ['template_name' => $templateName]);
                    }
                }
            }

            // Actualizar context['flow_id'] (ya sea con el nuevo resolvedFlowId o con null si no se resolvió ninguno)
            $context = $conversation->context ?? [];
            if (($context['flow_id'] ?? null) !== $resolvedFlowId) {
                $context['flow_id'] = $resolvedFlowId;
                $this->updateState($conversation, self::STATE_INITIAL, $context);
                $conversation->refresh();
            }
        }

        Log::info("[BotFlow] Processing message for state: {$conversation->state}");

        // Obtener el flow_id del contexto (asignado por comercio o null)
        $flowId = $conversation->context['flow_id'] ?? null;

        try {
            if ($conversation->state === self::STATE_INITIAL) {
                Log::info("[BotFlow] Starting flow", ['flow_id' => $flowId, 'phone_number_id' => $incomingPhoneNumberId]);
                $this->startFlow($conversation, $message, $flowId, $incomingPhoneNumberId);
            } else {
                Log::info("[BotFlow] Processing step", ['flow_id' => $flowId, 'phone_number_id' => $incomingPhoneNumberId]);
                $this->processStep($conversation, $message, $flowId, $incomingPhoneNumberId);
            }
        } catch (\Exception $e) {
            Log::error('Error in BotService', [
                'error'           => $e->getMessage(),
                'line'            => $e->getLine(),
                'trace'           => $e->getTraceAsString(),
                'contact_id'      => $contact->id,
                'conversation_id' => $conversation->id,
            ]);
        }
    }

    /**
     * Obtener o crear conversación para un contacto
     */
    private function getOrCreateConversation(Contact $contact): BotConversation
    {
        $conversation = BotConversation::where('contact_id', $contact->id)
            ->where('phone_number_id', $this->botPhoneNumberId)
            ->first();

        if (!$conversation) {
            $conversation = BotConversation::create([
                'contact_id'          => $contact->id,
                'phone_number_id'     => $this->botPhoneNumberId,
                'state'               => self::STATE_INITIAL,
                'context'             => ['retries' => 0],
                'last_interaction_at' => now(),
            ]);
        } else {
            $conversation->last_interaction_at = now();
            $conversation->save();
        }

        return $conversation;
    }

    /**
     * Iniciar el flujo del bot con el primer paso
     */
    private function startFlow(BotConversation $conversation, Message $message, ?string $flowId = null, ?string $phoneNumberId = null)
    {
        $flow = $this->getActiveFlow($flowId, $phoneNumberId);

        if (!$flow || empty($flow['steps'])) {
            Log::error('[BotFlow] startFlow: Flow or flow steps are empty!', [
                'flow_id' => $flowId,
                'phone_number_id' => $phoneNumberId,
                'flow_found' => !empty($flow)
            ]);
            $this->sendMessage($conversation->contact, "Lo siento, el servicio no está disponible en este momento.");
            return;
        }

        $firstStep = $flow['steps'][0];
        Log::info('[BotFlow] startFlow: Starting flow with first step', [
            'flow_name' => $flow['name'] ?? 'Unnamed',
            'flow_id' => $flow['id'] ?? 'none',
            'first_step_state' => $firstStep['state'],
            'first_step_action_type' => $firstStep['action_type'] ?? 'buttons'
        ]);
        $this->updateState($conversation, $firstStep['state'], ['retries' => 0, 'flow_id' => $flowId]);
        $conversation->refresh(); // Asegurar que el objeto en memoria refleja el estado y contexto frescos

        $actionType = $firstStep['action_type'] ?? self::ACTION_BUTTONS;
        
        if ($actionType === self::ACTION_PLANTILLA) {
            // El mensaje entrante ES la respuesta al template: procesarlo directamente
            Log::info("[BotFlow] First step is Plantilla. Processing incoming message immediately.");
            $this->processStep($conversation, $message, $flowId, $phoneNumberId);
        } else {
            // Comportamiento normal: enviar el primer mensaje del flujo
            Log::info("[BotFlow] First step is normal. Dispatching first step message.");
            $this->dispatchStep($conversation->contact, $firstStep);
        }
    }

    /**
     * Procesar el paso actual según el estado de la conversación
     */
    private function processStep(BotConversation $conversation, Message $message, ?string $flowId = null, ?string $phoneNumberId = null)
    {
        $flow = $this->getActiveFlow($flowId, $phoneNumberId);
        if (!$flow) {
            Log::error('[BotFlow] processStep: Flow not found!', [
                'flow_id' => $flowId,
                'phone_number_id' => $phoneNumberId
            ]);
            $this->sendMessage($conversation->contact, "Error: Configuración no disponible.");
            return;
        }

        // Buscar el paso actual por estado
        $currentStep = $this->findStepByState($flow, $conversation->state);

        if (!$currentStep) {
            Log::error('[BotFlow] processStep: Step not found in current flow state', [
                'state' => $conversation->state,
                'flow_id' => $flow['id'] ?? 'none'
            ]);
            $this->sendMessage($conversation->contact, "Error: Paso no encontrado.");
            return;
        }

        // Resolver el tipo de acción (retrocompatibilidad: sin action_type => buttons)
        $actionType = $currentStep['action_type'] ?? self::ACTION_BUTTONS;
        Log::info('[BotFlow] processStep: Dispatching step processing', [
            'step_state' => $currentStep['state'],
            'action_type' => $actionType,
            'message_content' => $message->message_content,
            'message_type' => $message->message_type
        ]);

        switch ($actionType) {
            case self::ACTION_FREE_TEXT:
                $this->handleFreeTextStep($conversation, $message, $currentStep, $flow);
                break;

            case self::ACTION_VALIDATED_INPUT:
                $this->handleValidatedInputStep($conversation, $message, $currentStep, $flow);
                break;

            case self::ACTION_LINK_BUTTON:
                // Los link_button no esperan respuesta: avanzan solos al enviar
                // Si el usuario escribe algo, simplemente re-enviamos el link
                $this->handleLinkButtonStep($conversation, $currentStep, $flow);
                break;

            case self::ACTION_PLANTILLA:
                $this->handlePlantillaStep($conversation, $message, $currentStep, $flow);
                break;

            case self::ACTION_CRM_LEAD:
                // crm_lead auto-avanza igual que link_button: no espera respuesta del usuario
                // Si el usuario escribe algo mientras está en este estado, simplemente re-envía CRM y cierra
                $this->handleCrmLeadStep($conversation, $currentStep);
                break;

            case self::ACTION_BUTTONS:
            default:
                $this->handleButtonsStep($conversation, $message, $currentStep, $flow);
                break;
        }
    }

    // ==================== HANDLERS POR TIPO ====================

    /**
     * Manejar paso de tipo BOTONES
     */
    private function handleButtonsStep(BotConversation $conversation, Message $message, array $step, array $flow)
    {
        $content  = trim($message->message_content);
        $context  = $conversation->context ?? [];
        $retries  = $context['retries'] ?? 0;
        // Cuando el usuario presiona un botón interactivo de WhatsApp, el webhook
        // guarda el button_id en message_content y el title en metadata.button_title.
        $metadata    = $message->metadata ?? [];
        $buttonTitle = $metadata['button_title'] ?? null;

        // Normalizar acciones (retrocompatibilidad buttons → actions)
        $actions = $this->normalizeActions($step);
        Log::info('[BotFlow] handleButtonsStep: Starting button matching', [
            'input_content' => $content,
            'input_button_title' => $buttonTitle,
            'configured_actions_count' => count($actions)
        ]);

        // Buscar qué botón seleccionó el usuario
        $selectedAction = null;
        foreach ($actions as $index => $action) {
            $titleMatch = strcasecmp($content, $action['title'] ?? '') === 0;
            $idMatch = strcasecmp($content, $action['id'] ?? '') === 0;
            $metaTitleMatch = ($buttonTitle && strcasecmp($buttonTitle, $action['title'] ?? '') === 0);
            
            Log::info("[BotFlow] handleButtonsStep: Comparing against action #{$index}", [
                'action_id' => $action['id'] ?? 'none',
                'action_title' => $action['title'] ?? 'none',
                'title_match' => $titleMatch,
                'id_match' => $idMatch,
                'meta_title_match' => $metaTitleMatch
            ]);

            if ($titleMatch || $idMatch || $metaTitleMatch) {
                $selectedAction = $action;
                Log::info("[BotFlow] handleButtonsStep: Match found!", ['matched_action' => $action]);
                break;
            }
        }

        if (!$selectedAction) {
            Log::warning('[BotFlow] handleButtonsStep: No match found. Sending to handleInvalidInput.');
            $this->handleInvalidInput($conversation, $retries);
            return;
        }

        $nextState = $selectedAction['next_state'] ?? $selectedAction['nextState'] ?? null;

        // Guardar respuesta en contexto
        $context['retries']                    = 0;
        $context['responses'][$step['state']] = $selectedAction['title'];

        $this->routeToNextState($conversation, $message, $step, $context, $nextState, $flow);
    }

    /**
     * Manejar paso de tipo PLANTILLA
     * Similar a botones, pero con soporte para fallback state si el usuario ingresa un texto no esperado.
     */
    private function handlePlantillaStep(BotConversation $conversation, Message $message, array $step, array $flow)
    {
        $content     = trim($message->message_content);
        $context     = $conversation->context ?? [];
        $actions     = $this->normalizeActions($step);
        $metadata    = $message->metadata ?? [];
        $buttonTitle = $metadata['button_title'] ?? null;

        Log::info('[BotFlow] handlePlantillaStep: Starting template button matching', [
            'input_content' => $content,
            'input_button_title' => $buttonTitle,
            'configured_actions_count' => count($actions)
        ]);

        // Buscar si el usuario seleccionó una de las opciones esperadas
        $selectedAction = null;
        foreach ($actions as $index => $action) {
            $titleMatch = strcasecmp($content, $action['title'] ?? '') === 0;
            $idMatch = strcasecmp($content, $action['id'] ?? '') === 0;
            $metaTitleMatch = ($buttonTitle && strcasecmp($buttonTitle, $action['title'] ?? '') === 0);

            Log::info("[BotFlow] handlePlantillaStep: Comparing against action #{$index}", [
                'action_id' => $action['id'] ?? 'none',
                'action_title' => $action['title'] ?? 'none',
                'title_match' => $titleMatch,
                'id_match' => $idMatch,
                'meta_title_match' => $metaTitleMatch
            ]);

            if ($titleMatch || $idMatch || $metaTitleMatch) {
                $selectedAction = $action;
                Log::info("[BotFlow] handlePlantillaStep: Match found!", ['matched_action' => $action]);
                break;
            }
        }

        if ($selectedAction) {
            $nextState = $selectedAction['next_state'] ?? $selectedAction['nextState'] ?? null;
            $context['retries'] = 0;
            $context['responses'][$step['state']] = $selectedAction['title'];
            $this->routeToNextState($conversation, $message, $step, $context, $nextState, $flow);
            return;
        }

        Log::warning('[BotFlow] handlePlantillaStep: No action matched.');

        // Si no coincidió con ningún botón, verificar si hay fallback state configurado
        $fallbackState = $step['fallback_state'] ?? null;
        if ($fallbackState) {
            Log::info("[BotFlow] handlePlantillaStep: Plantilla input invalid, routing to fallback_state", ['fallback' => $fallbackState]);
            $context['retries'] = 0;
            $context['responses'][$step['state']] = 'INVALID_INPUT';
            $this->routeToNextState($conversation, $message, $step, $context, $fallbackState, $flow);
        } else {
            // Comportamiento normal de error si no hay fallback (reintento)
            $retries = $context['retries'] ?? 0;
            Log::info("[BotFlow] handlePlantillaStep: No fallback_state configured. Calling handleInvalidInput.", ['current_retries' => $retries]);
            $this->handleInvalidInput($conversation, $retries);
        }
    }

    /**
     * Manejar paso de tipo CRM_LEAD (auto-advance)
     * Se envía el lead al CRM con el utm_campaign y el tag_id configurados en el PASO
     * (no en un botón — el usuario no necesita presionar nada).
     * Después cierra el chat silenciosamente.
     * Solo envía si el lead no fue enviado antes (wasAlreadySentToCRM).
     */
    private function handleCrmLeadStep(BotConversation $conversation, array $step): void
    {
        $context     = $conversation->context ?? [];
        $utmCampaign = $step['utm_campaign'] ?? null;
        $tagId       = isset($step['tag_id']) ? (int) $step['tag_id'] : null;

        $context['retries']                    = 0;
        $context['responses'][$step['state']] = 'crm_lead_auto';

        Log::info('[BotFlow] handleCrmLeadStep: Auto-advancing, sending to CRM', [
            'step_state'   => $step['state'],
            'utm_campaign' => $utmCampaign,
            'tag_id'       => $tagId,
        ]);

        // Enviar al CRM con el UTM Campaign y tag configurados en el paso
        $this->sendQualifiedLeadToCRM($conversation, $utmCampaign, $tagId);

        // Cerrar el chat silenciosamente (sin mensaje adicional)
        $this->updateState($conversation, self::STATE_FINISHED, $context);

        Log::info('[BotFlow] handleCrmLeadStep: Conversation closed silently after CRM send.', [
            'contact_id'      => $conversation->contact_id,
            'conversation_id' => $conversation->id,
        ]);
    }

    private function handleLinkButtonStep(BotConversation $conversation, array $step, array $flow)
    {
        $context   = $conversation->context ?? [];
        $actions   = $step['actions'] ?? [];
        $nextState = $actions[0]['next_state'] ?? null;

        // Marcar en el contexto que ya se envió este link
        $context['retries']                    = 0;
        $context['responses'][$step['state']] = 'link_sent';

        // Avanzar inmediatamente al siguiente estado
        $this->routeToNextState($conversation, null, $step, $context, $nextState, $flow);
    }

    /**
     * Manejar paso de tipo TEXTO LIBRE
     * El usuario puede escribir cualquier cosa; se guarda y se avanza al siguiente estado.
     */
    private function handleFreeTextStep(BotConversation $conversation, Message $message, array $step, array $flow)
    {
        $content = trim($message->message_content);
        $context = $conversation->context ?? [];

        // Guardar respuesta en contexto
        $context['retries']                    = 0;
        $context['responses'][$step['state']] = $content;

        // Obtener el siguiente estado (único en acciones de texto libre)
        $actions   = $step['actions'] ?? [];
        $nextState = $actions[0]['next_state'] ?? null;

        if (!$nextState) {
            Log::error('BotService: free_text step has no next_state', ['state' => $step['state']]);
            $this->sendMessage($conversation->contact, "Error de configuración en el flujo.");
            return;
        }

        $this->routeToNextState($conversation, $message, $step, $context, $nextState, $flow);
    }

    /**
     * Manejar paso de tipo ENTRADA VALIDADA (DNI, email, etc.)
     */
    private function handleValidatedInputStep(BotConversation $conversation, Message $message, array $step, array $flow)
    {
        $content    = trim($message->message_content);
        $context    = $conversation->context ?? [];
        $retries    = $context['retries'] ?? 0;
        $validation = $step['validation'] ?? [];
        $type       = $validation['type'] ?? self::VALIDATION_TEXT;

        // Validar la entrada
        $isValid = $this->validateInput($content, $validation);

        if (!$isValid) {
            // Mensaje de error personalizado o genérico
            $errorMsg = $validation['error_message'] ?? "⚠️ Entrada inválida. Por favor verifica el formato e intenta de nuevo.";

            $retries++;
            if ($retries >= 3) {
                // Demasiados intentos fallidos: reiniciar
                Log::info("BotService: Too many invalid attempts on validated_input", ['state' => $step['state']]);
                $this->updateState($conversation, self::STATE_INITIAL, []);
                $this->sendMessage(
                    $conversation->contact,
                    "⚠️ Demasiados intentos fallidos. He reiniciado la conversación.\n\nEscribe **hola** para comenzar. 👋"
                );
                return;
            }

            $context['retries'] = $retries;
            $this->updateState($conversation, $conversation->state, $context);
            $this->sendMessage($conversation->contact, $errorMsg);
            return;
        }

        // Entrada válida: guardar y continuar
        $context['retries']                    = 0;
        $context['responses'][$step['state']] = $content;

        // Si es DNI con validación externa → llamar API
        if ($type === self::VALIDATION_DNI && ($validation['external_validation'] ?? false)) {
            try {
                $apiResult  = $this->dniValidationService->validar($content);
                $comercioId = $context['comercio_id'] ?? null;

                // Registrar evento en tabla consultas
                $tipoEvento = $comercioId ? 'consumo' : 'consulta';
                $this->dniValidationService->registrarEvento([
                    'dni'                 => $content,
                    'tipo_evento'         => $tipoEvento,
                    'resultado'           => $apiResult['resultado'],
                    'telefono_origen'     => $conversation->contact->phone_number,
                    'comercio_id'         => $comercioId,
                    'flujo_tipo'          => $context['tipo_flujo'] ?? 'normal',
                    'status_http'         => $apiResult['status_http'],
                    'tiempo_respuesta_ms' => $apiResult['tiempo_ms'],
                ]);

                // Guardar resultado en contexto
                $context['dni_resultado'] = $apiResult['resultado'];

                // Buscar next_state según resultado del API
                $nextState = $this->resolveNextStateByResultado($step, $apiResult['resultado']);

                if (!$nextState) {
                    Log::error('BotService: No next_state for DNI resultado', [
                        'state'     => $step['state'],
                        'resultado' => $apiResult['resultado'],
                    ]);
                    $this->sendMessage($conversation->contact, "Error de configuración en el flujo.");
                    return;
                }

                // Si apunta directo a finished/nofinished/end_flow, terminar
                if ($nextState === 'finished') {
                    $this->updateState($conversation, $step['state'], $context);
                    $this->finishFlow($conversation, true, 'DNI resultado: ' . $apiResult['resultado']);
                    return;
                }
                if ($nextState === 'nofinished') {
                    $this->updateState($conversation, $step['state'], $context);
                    $this->finishFlow($conversation, false, 'DNI resultado: ' . $apiResult['resultado']);
                    return;
                }
                if ($nextState === 'end_flow') {
                    $this->updateState($conversation, $step['state'], $context);
                    $this->endFlowSilently($conversation, 'DNI resultado: ' . $apiResult['resultado']);
                    return;
                }

                // Si apunta a un paso intermedio (msg_apto, etc.), delegar a routeToNextState
                // con chainAdvance=true para que envíe todos los mensajes informativos en cadena
                // sin esperar input del usuario entre cada uno.
                $this->routeToNextState($conversation, $message, $step, $context, $nextState, $flow, true);
                return;

            } catch (\Exception $e) {
                Log::error('BotService: DNI validation API error', [
                    'dni'   => $content,
                    'error' => $e->getMessage(),
                ]);
                $this->sendMessage(
                    $conversation->contact,
                    "❌ Ocurrió un error al verificar tu DNI. Por favor intenta de nuevo más tarde."
                );
                return;
            }
        }

        // Flujo normal (sin external_validation)
        $actions   = $step['actions'] ?? [];
        $nextState = $actions[0]['next_state'] ?? null;

        if (!$nextState) {
            Log::error('BotService: validated_input step has no next_state', ['state' => $step['state']]);
            $this->sendMessage($conversation->contact, "Error de configuración en el flujo.");
            return;
        }

        $this->routeToNextState($conversation, $message, $step, $context, $nextState, $flow);
    }

    // ==================== ROUTING Y FINALIZACIÓN ====================

    /**
     * Resolver el siguiente estado basado en el resultado de la validación de DNI.
     * Busca en el array 'actions' del paso un entry con 'resultado' que coincida.
     *
     * @param array $step       El paso actual del flujo
     * @param string $resultado El resultado del API ('apto', 'no_apto', 'no_encontrado')
     * @return string|null      El next_state correspondiente
     */
    private function resolveNextStateByResultado(array $step, string $resultado): ?string
    {
        $actions = $step['actions'] ?? [];

        foreach ($actions as $action) {
            if (($action['resultado'] ?? '') === $resultado) {
                return $action['next_state'] ?? null;
            }
        }

        // Fallback: usar el primer next_state disponible
        Log::warning('BotService: No matching resultado in actions, using fallback', [
            'state'     => $step['state'],
            'resultado' => $resultado,
        ]);

        return $actions[0]['next_state'] ?? null;
    }

    /**
     * Redirigir al siguiente estado o finalizar el flujo.
     *
     * @param bool $chainAdvance  Cuando es true (después de validación externa), el bot encadena
     *                            automáticamente pasos informativos de una sola ruta (free_text,
     *                            link_button) sin esperar input del usuario, enviando todos los
     *                            mensajes seguidos hasta llegar a un paso que requiera interacción
     *                            (buttons) o a un estado terminal.
     * @param int  $chainDepth    Profundidad de la cadena para protección contra bucles infinitos.
     */
    private function routeToNextState(
        BotConversation $conversation,
        ?Message $message,
        array $currentStep,
        array $context,
        ?string $nextState,
        array $flow,
        bool $chainAdvance = false,
        int $chainDepth = 0
    ) {
        Log::info('[BotFlow] routeToNextState triggered', [
            'from_state' => $currentStep['state'],
            'to_state' => $nextState,
            'chain_advance' => $chainAdvance,
            'chain_depth' => $chainDepth
        ]);

        if (!$nextState) {
            Log::error('[BotFlow] routeToNextState: nextState is null', ['state' => $currentStep['state']]);
            $this->sendMessage($conversation->contact, "Error de configuración: siguiente paso no definido.");
            return;
        }

        if ($nextState === 'finished') {
            // El flujo determinó que SÍ califica
            Log::info('[BotFlow] routeToNextState: routing to qualified FINISHED state');
            $this->updateState($conversation, $currentStep['state'], $context);
            $this->finishFlow($conversation, true, "Cumple los requisitos para acceder al beneficio del club");
            return;
        }

        if ($nextState === 'nofinished') {
            // El flujo determinó que NO califica
            Log::info('[BotFlow] routeToNextState: routing to unqualified NOFINISHED state');
            $this->updateState($conversation, $currentStep['state'], $context);
            $this->finishFlow($conversation, false, "No cumple uno o más requisitos para el beneficio del club");
            return;
        }

        if ($nextState === 'end_flow') {
            // Terminar chat sin mensaje extra
            Log::info('[BotFlow] routeToNextState: routing to END_FLOW (silent finished) state');
            $this->updateState($conversation, $currentStep['state'], $context);
            $this->endFlowSilently($conversation, 'Finalizado por flujo');
            return;
        }

        // Buscar el siguiente paso
        $nextStep = $this->findStepByState($flow, $nextState);

        if (!$nextStep) {
            Log::error('[BotFlow] routeToNextState: Next step not found in flow', ['nextState' => $nextState]);
            $this->sendMessage($conversation->contact, "Error: Siguiente paso no encontrado.");
            return;
        }

        // Verificar si el siguiente paso es un mensaje "terminal" (informativo que cierra el flujo)
        // Esto permite enviar un mensaje de "Despedida" y cerrar el chat inmediatamente.
        $isAutoAdvance = false;
        $terminalFinalState = null;

        // Normalizamos las acciones del siguiente paso
        $nextActions = $nextStep['actions'] ?? $nextStep['buttons'] ?? [];
        $nextActionType = $nextStep['action_type'] ?? self::ACTION_BUTTONS;

        // ── Caso especial: crm_lead SIEMPRE es auto-advance ──
        // Tiene actions vacío, no hay next_state que detectar.
        // Enviar mensaje + CRM + cerrar, sin esperar respuesta del usuario.
        if ($nextActionType === self::ACTION_CRM_LEAD) {
            Log::info('[BotFlow] routeToNextState: crm_lead step — auto-advancing with CRM send.', [
                'next_state' => $nextState,
            ]);
            $this->sendMessage($conversation->contact, $nextStep['question']);
            $this->updateState($conversation, $nextState, $context);
            $this->handleCrmLeadStep($conversation, $nextStep);
            return;
        }

        // Si tiene exactamente 1 acción (o es de tipo free_text/validated_input)
        if (count($nextActions) === 1 || in_array($nextActionType, [self::ACTION_FREE_TEXT, self::ACTION_VALIDATED_INPUT])) {
            $potentialFinalState = $nextActions[0]['next_state'] ?? null;
            
            // Si esa única opción de ruta apunta a terminar el flujo
            if (in_array($potentialFinalState, ['finished', 'nofinished', 'end_flow'])) {
                $isAutoAdvance = true;
                $terminalFinalState = $potentialFinalState;
            }
        }

        Log::info('[BotFlow] routeToNextState: Step analysis', [
            'next_step_state' => $nextState,
            'next_step_type' => $nextActionType,
            'is_auto_advance' => $isAutoAdvance,
            'terminal_state' => $terminalFinalState
        ]);

        if ($isAutoAdvance) {
            // Es un paso de despedida: enviarlo como texto plano y cerrar inmediatamente
            Log::info("[BotFlow] routeToNextState: Auto-advancing to terminal step", ['nextState' => $nextState, 'final' => $terminalFinalState]);

            // Para crm_lead: enviar el mensaje Y mandar al CRM antes de cerrar
            if ($nextActionType === self::ACTION_CRM_LEAD) {
                $this->sendMessage($conversation->contact, $nextStep['question']);
                $this->updateState($conversation, $nextState, $context);
                $this->handleCrmLeadStep($conversation, $nextStep);
                return;
            }

            $this->sendMessage($conversation->contact, $nextStep['question']); // Enviar directamente como texto
            $this->updateState($conversation, $nextState, $context);
            
            if ($terminalFinalState === 'end_flow') {
                $this->endFlowSilently($conversation, 'Cierre automático después de mensaje final');
            } else {
                $this->finishFlow($conversation, $terminalFinalState === 'finished', 'Cierre automático después de mensaje final', true);
            }
            return;
        }

        // ── Encadenamiento de pasos informativos (después de validación externa) ──
        // Cuando chainAdvance está activo, el bot envía automáticamente los mensajes
        // de pasos de una sola ruta (free_text, link_button) sin esperar que el usuario
        // escriba algo. Solo se detiene cuando llega a un paso con múltiples opciones
        // (buttons) o cuando se alcanza la profundidad máxima de seguridad.
        if ($chainAdvance && $chainDepth < 10) {
            $isSinglePathStep = in_array($nextActionType, [self::ACTION_FREE_TEXT, self::ACTION_LINK_BUTTON])
                                && count($nextActions) >= 1
                                && !empty($nextActions[0]['next_state']);

            if ($isSinglePathStep) {
                $chainNextState = $nextActions[0]['next_state'];
                Log::info('[BotFlow] routeToNextState: Chain-advancing through informational step', [
                    'current_state' => $nextState,
                    'chain_next'    => $chainNextState,
                    'depth'         => $chainDepth,
                ]);

                // Enviar este mensaje como texto plano (sin esperar respuesta)
                $this->sendMessage($conversation->contact, $nextStep['question']);
                $this->updateState($conversation, $nextState, $context);

                // Avanzar recursivamente al siguiente estado
                $this->routeToNextState(
                    $conversation, $message, $nextStep, $context,
                    $chainNextState, $flow, true, $chainDepth + 1
                );
                return;
            }
        }

        // Avanzar al siguiente estado y enviar la siguiente pregunta (comportamiento normal)
        Log::info('[BotFlow] routeToNextState: Routing to normal step. Advancing state and dispatching.', [
            'next_state' => $nextState
        ]);
        $this->updateState($conversation, $nextState, $context);
        $this->dispatchStep($conversation->contact, $nextStep);
    }

    /**
     * Despachar el mensaje correspondiente a un paso según su action_type
     */
    private function dispatchStep(Contact $contact, array $step)
    {
        $actionType = $step['action_type'] ?? self::ACTION_BUTTONS;
        $question   = $step['question'];
        Log::info('[BotFlow] dispatchStep: Dispatching step message to client', [
            'state' => $step['state'],
            'action_type' => $actionType,
            'question_preview' => mb_substr($question, 0, 50) . '...'
        ]);

        switch ($actionType) {
            case self::ACTION_BUTTONS:
                $actions  = $this->normalizeActions($step);
                // Transformar al formato que espera WhatsApp (title + id)
                $buttons  = array_map(fn($a) => [
                    'id'    => $a['id'] ?? 'btn_' . uniqid(),
                    'title' => $a['title'],
                ], $actions);
                $this->sendInteractiveMessage($contact, $question, $buttons);
                break;

            case self::ACTION_CRM_LEAD:
                // crm_lead: solo envía el texto del mensaje (sin botones)
                // El CRM se envía automáticamente al llegar al paso (desde routeToNextState)
                $this->sendMessage($contact, $question);
                break;

            case self::ACTION_PLANTILLA:
                // No se envía ningún mensaje para un paso de tipo plantilla.
                // Se asume que el usuario ya recibió la plantilla externamente.
                Log::info('BotService: Not sending message for Plantilla step as it waits for external template response', ['state' => $step['state']]);
                break;

            case self::ACTION_LINK_BUTTON:
                $actions    = $step['actions'] ?? [];
                $buttonText = $actions[0]['button_text'] ?? 'Ver más';
                $url        = $actions[0]['url'] ?? '';
                $footer     = $step['link_footer'] ?? null;

                if (!$url) {
                    $this->sendMessage($contact, $question);
                    break;
                }

                // Modo testing
                if ($this->botPhoneNumberId && str_starts_with($this->botPhoneNumberId, 'TEST_')) {
                    Log::info("BotService [TEST MODE]: Would send CTA URL message", [
                        'to' => $contact->phone_number, 'url' => $url,
                    ]);
                    $this->sendMessage($contact, "{$question}\n\n🔗 {$buttonText}: {$url}");
                    break;
                }

                // Modo real
                try {
                    $ws     = new WhatsAppService($this->botPhoneNumberId);
                    $result = $ws->sendCtaUrlMessage(
                        $contact->phone_number,
                        $question,
                        $buttonText,
                        $url,
                        null,
                        $footer
                    );
                    if (!$result['success']) {
                        $this->sendMessage($contact, "{$question}\n\n🔗 {$buttonText}: {$url}");
                    }
                } catch (\Exception $e) {
                    Log::error('BotService: Error sending CTA URL', ['error' => $e->getMessage()]);
                    $this->sendMessage($contact, "{$question}\n\n🔗 {$buttonText}: {$url}");
                }
                break;

            case self::ACTION_FREE_TEXT:
            case self::ACTION_VALIDATED_INPUT:
                // Para texto libre y validado: enviar la pregunta como texto plano
                $this->sendMessage($contact, $question);
                break;

            default:
                $this->sendMessage($contact, $question);
        }
    }

    /**
     * Finalizar el flujo con resultado (califica / no califica para el club)
     * @param bool $skipMessage Si true, no envía el mensaje hardcodeado (ya se envió desde el paso)
     */
    private function finishFlow(BotConversation $conversation, bool $qualified, string $reason, bool $skipMessage = false)
    {
        $context               = $conversation->context ?? [];
        $context['qualified']  = $qualified;
        $context['reason']     = $reason;

        $this->updateState($conversation, self::STATE_FINISHED, $context);

        Log::info('BotService: Flow finished', [
            'conversation_id' => $conversation->id,
            'qualified'       => $qualified,
            'skip_message'    => $skipMessage,
            'bot_phone_id'    => $this->botPhoneNumberId,
        ]);

        // Solo enviar al CRM si es el bot de Leads Comunicaciones y el lead califica
        $leadsComunicacionesBotId = config('services.whatsapp.leads_comunicaciones_bot_id');
        if ($qualified && $leadsComunicacionesBotId && (string)$this->botPhoneNumberId === (string)$leadsComunicacionesBotId) {
            $this->sendQualifiedLeadToCRM($conversation);
        }
    }

    /**
     * Terminar el flujo silenciosamente (sin enviar mensaje hardcodeado)
     * Se usa cuando el usuario configura "Terminar chat" como siguiente paso
     */
    private function endFlowSilently(BotConversation $conversation, string $reason): void
    {
        $context           = $conversation->context ?? [];
        $context['reason'] = $reason;

        $this->updateState($conversation, self::STATE_FINISHED, $context);

        Log::info('BotService: Flow ended silently', [
            'conversation_id' => $conversation->id,
            'reason'          => $reason,
        ]);
    }

    /**
     * Enviar lead calificado al CRM de LogicWare
     */
    private function sendQualifiedLeadToCRM(BotConversation $conversation, ?string $utmCampaign = null, ?int $tagId = null): void
    {
        try {
            $contact = $conversation->contact;

            Log::info('BotService: Attempting to send qualified lead to CRM', [
                'contact_id'      => $contact->id,
                'phone'           => $contact->phone_number,
                'conversation_id' => $conversation->id,
            ]);

            if ($this->logicwareService->wasAlreadySentToCRM($contact)) {
                Log::info('BotService: Lead already sent to CRM, skipping', ['contact_id' => $contact->id]);
                return;
            }

            $result = $this->logicwareService->createQualifiedLead($contact, $conversation, $utmCampaign, $tagId);

            if ($result['success']) {
                Log::info('BotService: Lead sent to CRM successfully', [
                    'contact_id'   => $contact->id,
                    'lead_id'      => $result['lead_id'] ?? null,
                    'assigned_to'  => $result['assigned_to'] ?? null,
                    'utm_campaign' => $utmCampaign,
                    'tag_id'       => $tagId,
                ]);
            } else {
                Log::error('BotService: Failed to send lead to CRM', [
                    'contact_id' => $contact->id,
                    'error'      => $result['error'] ?? 'Unknown error',
                    'response'   => $result['response'] ?? null,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('BotService: Exception sending qualified lead to CRM', [
                'contact_id'      => $conversation->contact_id,
                'conversation_id' => $conversation->id,
                'error'           => $e->getMessage(),
                'trace'           => $e->getTraceAsString(),
            ]);
        }
    }

    // ==================== VALIDADORES ====================

    /**
     * Validar una entrada según el tipo de validación configurado en el paso
     */
    private function validateInput(string $value, array $validation): bool
    {
        $type = $validation['type'] ?? self::VALIDATION_TEXT;

        switch ($type) {
            case self::VALIDATION_DNI:
                // DNI peruano: exactamente 8 dígitos numéricos
                return (bool) preg_match('/^\d{8}$/', $value);

            case self::VALIDATION_PHONE:
                // Teléfono: entre 7 y 15 dígitos, con o sin + al inicio
                return (bool) preg_match('/^\+?\d{7,15}$/', preg_replace('/[\s\-()]/', '', $value));

            case self::VALIDATION_EMAIL:
                return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;

            case self::VALIDATION_NUMBER:
                return is_numeric($value);

            case self::VALIDATION_REGEX:
                $pattern = $validation['regex_pattern'] ?? null;
                if (!$pattern) return true;
                try {
                    return (bool) preg_match($pattern, $value);
                } catch (\Exception $e) {
                    Log::warning('BotService: Invalid regex pattern', ['pattern' => $pattern, 'error' => $e->getMessage()]);
                    return true; // Si el regex es inválido, dejar pasar
                }

            case self::VALIDATION_TEXT:
            default:
                return strlen(trim($value)) > 0;
        }
    }

    // ==================== HELPERS ====================

    /**
     * Buscar un paso por su estado dentro de un flujo
     */
    private function findStepByState(array $flow, string $state): ?array
    {
        foreach ($flow['steps'] as $step) {
            if ($step['state'] === $state) {
                return $step;
            }
        }
        return null;
    }

    /**
     * Normalizar acciones: soporta tanto el formato nuevo (actions) como el legacy (buttons)
     * Normaliza además las claves next_state ↔ nextState
     */
    private function normalizeActions(array $step): array
    {
        // Formato nuevo
        if (!empty($step['actions'])) {
            return array_map(function ($a) {
                return [
                    'id'           => $a['id'] ?? 'btn_' . uniqid(),
                    'title'        => $a['title'] ?? '',
                    'next_state'   => $a['next_state'] ?? $a['nextState'] ?? '',
                    // Preservar campos extra (utm_campaign, tag_id) si existen
                    'utm_campaign' => $a['utm_campaign'] ?? null,
                ];
            }, $step['actions']);
        }

        // Formato legacy (buttons)
        if (!empty($step['buttons'])) {
            return array_map(function ($b) {
                return [
                    'id'         => $b['id'] ?? 'btn_' . uniqid(),
                    'title'      => $b['title'] ?? '',
                    'next_state' => $b['nextState'] ?? $b['next_state'] ?? '',
                ];
            }, $step['buttons']);
        }

        return [];
    }

    /**
     * Manejar entrada inválida del usuario en pasos de tipo botones
     */
    private function handleInvalidInput(BotConversation $conversation, int $retries)
    {
        $retries++;
        Log::info('[BotFlow] handleInvalidInput called', [
            'previous_retries' => $retries - 1,
            'new_retries' => $retries,
            'conversation_state' => $conversation->state
        ]);

        if ($retries >= 2) {
            Log::info("[BotFlow] handleInvalidInput: Resetting conversation due to too many invalid attempts", ['state' => $conversation->state]);
            $this->updateState($conversation, self::STATE_INITIAL, []);
            $this->sendMessage(
                $conversation->contact,
                "⚠️ Parece que hay confusión. He reiniciado la conversación para que puedas empezar de nuevo.\n\n" .
                "Por favor, usa los botones de respuesta.\n\n" .
                "Escribe **hola** para comenzar. 👋"
            );
            return;
        }

        $context            = $conversation->context ?? [];
        $context['retries'] = $retries;
        $this->updateState($conversation, $conversation->state, $context);

        $this->sendMessage($conversation->contact, "⚠️ No entendí tu respuesta. Por favor, inténtalo de nuevo.");
    }

    /**
     * Enviar mensaje de texto al contacto
     */
    private function sendMessage(Contact $contact, string $text)
    {
        // MODO TESTING
        if ($this->botPhoneNumberId && str_starts_with($this->botPhoneNumberId, 'TEST_')) {
            Log::info("BotService [TEST MODE]: Sending message to {$contact->phone_number}: {$text}");

            Message::create([
                'contact_id'        => $contact->id,
                'phone_number_id'   => $this->botPhoneNumberId,
                'phone_number'      => $contact->phone_number,
                'message'           => $text,
                'message_content'   => $text,
                'direction'         => 'outbound',
                'status'            => 'sent',
                'message_timestamp' => now(),
                'message_type'      => 'text',
            ]);
            return;
        }

        // MODO REAL
        try {
            Log::info("BotService: Attempting to send real message to {$contact->phone_number}: {$text}");
            $ws = new WhatsAppService($this->botPhoneNumberId);
            $response = $ws->sendMessage($contact->phone_number, $text);
            $wamid = $response['message_id'] ?? null;

            Message::create([
                'contact_id'          => $contact->id,
                'phone_number_id'     => $this->botPhoneNumberId,
                'phone_number'        => $contact->phone_number,
                'message'             => $text,
                'message_content'     => $text,
                'direction'           => 'outbound',
                'status'              => 'sent',
                'message_timestamp'   => now(),
                'message_type'        => 'text',
                'whatsapp_message_id' => $wamid,
            ]);
        } catch (\Exception $e) {
            Log::error("BotService: Error enviando mensaje real: " . $e->getMessage());
        }
    }

    /**
     * Actualizar estado de la conversación
     */
    private function updateState(BotConversation $conversation, string $state, array $context = [])
    {
        $currentContext              = $conversation->context ?? [];
        $conversation->state         = $state;
        $conversation->context       = array_merge($currentContext, $context);
        $conversation->last_interaction_at = now();
        $conversation->save();
    }

    /**
     * Enviar mensaje interactivo con botones (con fallback automático a texto)
     */
    private function sendInteractiveMessage(Contact $contact, string $text, array $buttons)
    {
        // Modo testing
        if ($this->botPhoneNumberId && str_starts_with($this->botPhoneNumberId, 'TEST_')) {
            Log::info("BotService [TEST MODE]: Would send interactive message", [
                'to'      => $contact->phone_number,
                'text'    => $text,
                'buttons' => $buttons,
            ]);

            Message::create([
                'contact_id'        => $contact->id,
                'phone_number_id'   => $this->botPhoneNumberId,
                'phone_number'      => $contact->phone_number,
                'message'           => $text,
                'message_content'   => $text,
                'direction'         => 'outbound',
                'status'            => 'sent',
                'message_timestamp' => now(),
                'message_type'      => 'interactive',
                'metadata'          => ['buttons' => $buttons],
            ]);
            return;
        }

        // Modo real
        try {
            // WhatsApp limita el body de mensajes interactivos a 1024 chars.
            // Si el texto supera ese límite, lo enviamos primero como texto plano
            // y luego enviamos un segundo mensaje corto con los botones.
            $bodyText = $text;
            $prefix   = null;

            if (mb_strlen($text) > 1024) {
                $prefix   = $text;
                $bodyText = 'Selecciona una opción 👇';
                Log::info('BotService: Body too long for interactive, splitting into 2 messages', [
                    'original_length' => mb_strlen($text),
                ]);
            }

            Log::info("BotService: Attempting to send interactive message", [
                'to'            => $contact->phone_number,
                'buttons_count' => count($buttons),
                'body_length'   => mb_strlen($bodyText),
            ]);

            // Enviar el texto largo primero si aplica
            if ($prefix) {
                $this->sendMessage($contact, $prefix);
            }

            $ws     = new WhatsAppService($this->botPhoneNumberId);
            $result = $ws->sendInteractiveButtons($contact->phone_number, $bodyText, $buttons);

            if ($result['success']) {
                Message::create([
                    'contact_id'            => $contact->id,
                    'phone_number_id'       => $this->botPhoneNumberId,
                    'phone_number'          => $contact->phone_number,
                    'message'               => $bodyText,
                    'message_content'       => $bodyText,
                    'direction'             => 'outbound',
                    'status'                => 'sent',
                    'message_timestamp'     => now(),
                    'message_type'          => 'interactive',
                    'whatsapp_message_id'   => $result['message_id'],
                    'metadata'              => ['buttons' => $buttons],
                ]);
                Log::info("BotService: Interactive message sent successfully");
            } else {
                Log::warning("BotService: Interactive buttons failed, using text fallback", [
                    'error' => $result['error'] ?? 'Unknown error',
                    'buttons' => $buttons,
                    'text_length' => mb_strlen($bodyText, 'UTF-8')
                ]);
                $this->sendTextFallback($contact, $bodyText, $buttons);
            }
        } catch (\Exception $e) {
            Log::error("BotService: Error sending interactive message", [
                'error' => $e->getMessage(),
                'buttons' => $buttons
            ]);
            $this->sendTextFallback($contact, $text, $buttons);
        }
    }

    /**
     * Enviar mensaje de texto simple como fallback cuando los botones fallan
     */
    private function sendTextFallback(Contact $contact, string $text, array $buttons)
    {
        $textWithOptions = $text . "\n\n" . implode("\n", array_map(
            fn($btn, $idx) => ($idx + 1) . ". " . ($btn['title'] ?? ''),
            $buttons,
            array_keys($buttons)
        ));

        $this->sendMessage($contact, $textWithOptions);
    }
}
