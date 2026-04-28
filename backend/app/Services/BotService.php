<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Message;
use App\Models\BotConversation;
use Illuminate\Support\Facades\Log;

class BotService
{
    private WhatsAppService $whatsappService;
    private LogicWareService $logicwareService;
    private $botPhoneNumberId;
    private $flows = null;

    // Estados posibles del bot
    const STATE_INITIAL  = 'initial';
    const STATE_FINISHED = 'finished';
    const STATE_HANDOFF  = 'handoff';

    // Tipos de acción soportados
    const ACTION_BUTTONS         = 'buttons';
    const ACTION_FREE_TEXT       = 'free_text';
    const ACTION_VALIDATED_INPUT = 'validated_input';

    // Tipos de validación disponibles
    const VALIDATION_DNI    = 'dni';
    const VALIDATION_PHONE  = 'phone';
    const VALIDATION_EMAIL  = 'email';
    const VALIDATION_NUMBER = 'number';
    const VALIDATION_TEXT   = 'text';
    const VALIDATION_REGEX  = 'regex';

    public function __construct(WhatsAppService $whatsappService, LogicWareService $logicwareService)
    {
        $this->whatsappService  = $whatsappService;
        $this->logicwareService = $logicwareService;
        $this->botPhoneNumberId = config('services.whatsapp.leads_bot_id');
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
    private function getActiveFlow()
    {
        if (empty($this->flows)) {
            return null;
        }
        return $this->flows[0] ?? null;
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
        if (!$this->botPhoneNumberId || (string)$message->phone_number_id !== (string)$this->botPhoneNumberId) {
            return;
        }

        $conversation = $this->getOrCreateConversation($contact);
        Log::info("BotService: Conversation retrieved. State: {$conversation->state}, ID: {$conversation->id}");

        // Reiniciar si el usuario escribe hola/reset
        if (strtolower(trim($message->message_content)) === 'hola' || strtolower(trim($message->message_content)) === 'reset') {
            Log::info("BotService: Resetting conversation by user request.");
            $this->updateState($conversation, self::STATE_INITIAL, []);
            $conversation->refresh();
        }

        // El bot no interviene en estado handoff (legacy)
        if ($conversation->state === self::STATE_HANDOFF) {
            Log::info("BotService: Ignoring message because conversation is in HANDOFF state (legacy).");
            return;
        }

        Log::info("BotService: Processing message for state: {$conversation->state}");

        try {
            if ($conversation->state === self::STATE_INITIAL) {
                Log::info("BotService: Starting flow");
                $this->startFlow($conversation);
            } else {
                Log::info("BotService: Processing step");
                $this->processStep($conversation, $message);
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
    private function startFlow(BotConversation $conversation)
    {
        $flow = $this->getActiveFlow();

        if (!$flow || empty($flow['steps'])) {
            $this->sendMessage($conversation->contact, "Lo siento, el servicio no está disponible en este momento.");
            return;
        }

        $firstStep = $flow['steps'][0];
        $this->updateState($conversation, $firstStep['state'], ['retries' => 0]);
        $this->dispatchStep($conversation->contact, $firstStep);
    }

    /**
     * Procesar el paso actual según el estado de la conversación
     */
    private function processStep(BotConversation $conversation, Message $message)
    {
        $flow = $this->getActiveFlow();
        if (!$flow) {
            $this->sendMessage($conversation->contact, "Error: Configuración no disponible.");
            return;
        }

        // Buscar el paso actual por estado
        $currentStep = $this->findStepByState($flow, $conversation->state);

        if (!$currentStep) {
            Log::error('Step not found', ['state' => $conversation->state]);
            $this->sendMessage($conversation->contact, "Error: Paso no encontrado.");
            return;
        }

        // Resolver el tipo de acción (retrocompatibilidad: sin action_type => buttons)
        $actionType = $currentStep['action_type'] ?? self::ACTION_BUTTONS;

        switch ($actionType) {
            case self::ACTION_FREE_TEXT:
                $this->handleFreeTextStep($conversation, $message, $currentStep, $flow);
                break;

            case self::ACTION_VALIDATED_INPUT:
                $this->handleValidatedInputStep($conversation, $message, $currentStep, $flow);
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
        $content = trim($message->message_content);
        $context = $conversation->context ?? [];
        $retries = $context['retries'] ?? 0;

        // Normalizar acciones (retrocompatibilidad buttons → actions)
        $actions = $this->normalizeActions($step);

        // Buscar qué botón seleccionó el usuario
        $selectedAction = null;
        foreach ($actions as $action) {
            if (
                strcasecmp($content, $action['title'] ?? '') === 0 ||
                strcasecmp($content, $action['id'] ?? '') === 0
            ) {
                $selectedAction = $action;
                break;
            }
        }

        if (!$selectedAction) {
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

        // Obtener el siguiente estado
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
     * Redirigir al siguiente estado o finalizar el flujo
     */
    private function routeToNextState(
        BotConversation $conversation,
        Message $message,
        array $currentStep,
        array $context,
        ?string $nextState,
        array $flow
    ) {
        if (!$nextState) {
            Log::error('BotService: nextState is null', ['state' => $currentStep['state']]);
            $this->sendMessage($conversation->contact, "Error de configuración: siguiente paso no definido.");
            return;
        }

        if ($nextState === 'finished') {
            // El flujo determinó que SÍ califica
            $this->updateState($conversation, $currentStep['state'], $context);
            $this->finishFlow($conversation, true, "Cumple los requisitos para acceder al beneficio del club");
            return;
        }

        if ($nextState === 'nofinished') {
            // El flujo determinó que NO califica
            $this->updateState($conversation, $currentStep['state'], $context);
            $this->finishFlow($conversation, false, "No cumple uno o más requisitos para el beneficio del club");
            return;
        }

        // Buscar el siguiente paso
        $nextStep = $this->findStepByState($flow, $nextState);

        if (!$nextStep) {
            Log::error('BotService: Next step not found', ['nextState' => $nextState]);
            $this->sendMessage($conversation->contact, "Error: Siguiente paso no encontrado.");
            return;
        }

        // Avanzar al siguiente estado y enviar la siguiente pregunta
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
     */
    private function finishFlow(BotConversation $conversation, bool $qualified, string $reason)
    {
        $context               = $conversation->context ?? [];
        $context['qualified']  = $qualified;
        $context['reason']     = $reason;

        $this->updateState($conversation, self::STATE_FINISHED, $context);

        if ($qualified) {
            // Enviar lead calificado al CRM
            $this->sendQualifiedLeadToCRM($conversation);

            $msg = "🎉 ¡Felicidades! Según tus respuestas, **SÍ TIENES ACCESO** a los beneficios del club. 🏆\n\n" .
                   "Un asesor revisará tus datos y te contactará pronto. ¡Estate atento!";
        } else {
            $msg = "Gracias por completar las preguntas. 😊\n\n" .
                   "Según tus respuestas, lamentablemente **NO CUMPLES** los requisitos para acceder a los beneficios del club en este momento.\n\n" .
                   "Si necesitas orientación, nuestros asesores están disponibles para ayudarte. 🏆";
        }

        $this->sendMessage($conversation->contact, $msg);
    }

    /**
     * Enviar lead calificado al CRM de LogicWare
     */
    private function sendQualifiedLeadToCRM(BotConversation $conversation): void
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

            $result = $this->logicwareService->createQualifiedLead($contact, $conversation);

            if ($result['success']) {
                Log::info('BotService: Lead sent to CRM successfully', [
                    'contact_id'  => $contact->id,
                    'lead_id'     => $result['lead_id'] ?? null,
                    'assigned_to' => $result['assigned_to'] ?? null,
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
                    'id'         => $a['id'] ?? 'btn_' . uniqid(),
                    'title'      => $a['title'] ?? '',
                    'next_state' => $a['next_state'] ?? $a['nextState'] ?? '',
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

        if ($retries >= 2) {
            Log::info("BotService: Resetting conversation due to too many invalid attempts", ['state' => $conversation->state]);
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

        $this->sendMessage($conversation->contact, "⚠️ No entendí tu respuesta. Por favor, usa los botones de respuesta o escribe **1** (Sí) o **2** (No).");
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
            $ws->sendMessage($contact->phone_number, $text);

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
            Log::info("BotService: Attempting to send interactive message", [
                'to'           => $contact->phone_number,
                'buttons_count' => count($buttons),
            ]);

            $ws     = new WhatsAppService($this->botPhoneNumberId);
            $result = $ws->sendInteractiveButtons($contact->phone_number, $text, $buttons);

            if ($result['success']) {
                Message::create([
                    'contact_id'            => $contact->id,
                    'phone_number_id'       => $this->botPhoneNumberId,
                    'phone_number'          => $contact->phone_number,
                    'message'               => $text,
                    'message_content'       => $text,
                    'direction'             => 'outbound',
                    'status'                => 'sent',
                    'message_timestamp'     => now(),
                    'message_type'          => 'interactive',
                    'whatsapp_message_id'   => $result['message_id'],
                    'metadata'              => ['buttons' => $buttons],
                ]);
                Log::info("BotService: Interactive message sent successfully");
            } else {
                Log::warning("BotService: Interactive buttons failed, using text fallback", ['error' => $result['error']]);
                $this->sendTextFallback($contact, $text, $buttons);
            }
        } catch (\Exception $e) {
            Log::error("BotService: Error sending interactive message", ['error' => $e->getMessage()]);
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
