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
    const STATE_INITIAL = 'initial';
    const STATE_FINISHED = 'finished';
    const STATE_HANDOFF = 'handoff';

    public function __construct(WhatsAppService $whatsappService, LogicWareService $logicwareService)
    {
        $this->whatsappService = $whatsappService;
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
            // Intentar obtener del caché primero
            $this->flows = cache()->remember('chatbot_flows', 3600, function () {
                $path = storage_path('app/chatbot/flows.json');
                if (file_exists($path)) {
                    $content = file_get_contents($path);
                    $flows = json_decode($content, true);
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
        // 1. Verificar si este mensaje fue recibido por el número del bot
        // Comparamos como string para evitar problemas de tipos (int vs string)
        if (!$this->botPhoneNumberId || (string)$message->phone_number_id !== (string)$this->botPhoneNumberId) {
            return;
        }

        // 2. Obtener o crear conversación
        $conversation = $this->getOrCreateConversation($contact);
        Log::info("BotService: Conversation retrieved. State: {$conversation->state}, ID: {$conversation->id}");

        // 3. Verificar si el usuario pide reiniciar (útil si se atoró)
        if (strtolower(trim($message->message_content)) === 'hola' || strtolower(trim($message->message_content)) === 'reset') {
             Log::info("BotService: Resetting conversation by user request.");
             $this->updateState($conversation, self::STATE_INITIAL, []);
             $conversation->refresh();
        }

        // 4. Si ya está en estado handoff (legacy), el bot no interviene
        if ($conversation->state === self::STATE_HANDOFF) {
            Log::info("BotService: Ignoring message because conversation is in HANDOFF state (legacy).");
            return;
        }

        // 5. Procesar según el estado actual
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
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(), // Trace completo
                'contact_id' => $contact->id,
                'conversation_id' => $conversation->id
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
                'contact_id' => $contact->id,
                'phone_number_id' => $this->botPhoneNumberId,
                'state' => self::STATE_INITIAL,
                'context' => ['retries' => 0],
                'last_interaction_at' => now(),
            ]);
        } else {
            // Actualizar última interacción
            $conversation->last_interaction_at = now();
            $conversation->save();
        }

        return $conversation;
    }

    /**
     * Iniciar el flujo del bot
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
        
        // Enviar mensaje con botones del primer paso
        $this->sendInteractiveMessage($conversation->contact, $firstStep['question'], $firstStep['buttons']);
    }

    /**
     * Procesar el paso actual según el estado
     */
    private function processStep(BotConversation $conversation, Message $message)
    {
        $content = trim($message->message_content);
        $context = $conversation->context ?? [];
        $retries = $context['retries'] ?? 0;

        $flow = $this->getActiveFlow();
        if (!$flow) {
            $this->sendMessage($conversation->contact, "Error: Configuración no disponible.");
            return;
        }

        // Buscar el paso actual
        $currentStep = null;
        foreach ($flow['steps'] as $step) {
            if ($step['state'] === $conversation->state) {
                $currentStep = $step;
                break;
            }
        }

        if (!$currentStep) {
            Log::error('Step not found', ['state' => $conversation->state]);
            $this->sendMessage($conversation->contact, "Error: Paso no encontrado.");
            return;
        }

        // Buscar qué botón seleccionó el usuario
        $selectedButton = null;
        foreach ($currentStep['buttons'] as $button) {
            // Comparar con el título del botón o el ID
            if (strcasecmp($content, $button['title']) === 0 || 
                strcasecmp($content, $button['id']) === 0) {
                $selectedButton = $button;
                break;
            }
        }

        if (!$selectedButton) {
            // Respuesta inválida
            $this->handleInvalidInput($conversation, $retries);
            return;
        }

        // Procesar según el siguiente estado
        $nextState = $selectedButton['nextState'];
        
        if ($nextState === 'finished' || $nextState === 'nofinished') {
            // Verificar si el usuario califica basado en sus respuestas
            $qualified = $this->checkIfQualified($conversation, $selectedButton['title']);
            $reason = $qualified ? "Cumple todos los requisitos" : "No cumple uno o más requisitos";
            $this->finishFlow($conversation, $qualified, $reason);
        } else {
            // Buscar el siguiente paso
            $nextStep = null;
            foreach ($flow['steps'] as $step) {
                if ($step['state'] === $nextState) {
                    $nextStep = $step;
                    break;
                }
            }

            if ($nextStep) {
                // Guardar respuesta actual en contexto
                $context['retries'] = 0;
                $context['responses'][$conversation->state] = $selectedButton['title'];
                $this->updateState($conversation, $nextState, $context);
                
                // Enviar siguiente pregunta
                $this->sendInteractiveMessage($conversation->contact, $nextStep['question'], $nextStep['buttons']);
            } else {
                Log::error('Next step not found', ['nextState' => $nextState]);
                $this->sendMessage($conversation->contact, "Error: Siguiente paso no encontrado.");
            }
        }
    }

    /**
     * Manejar entrada inválida del usuario
     */
    private function handleInvalidInput(BotConversation $conversation, int $retries)
    {
        $retries++;
        
        if ($retries >= 2) {
            // Reiniciar la conversación después de 3 intentos fallidos
            Log::info("BotService: Resetting conversation due to too many invalid attempts", ['state' => $conversation->state]);
            $this->updateState($conversation, self::STATE_INITIAL, []);
            $this->sendMessage(
                $conversation->contact, 
                "⚠️ Parece que hay confusión. He reiniciado la conversación para que puedas empezar de nuevo.\n\n" .
                "Por favor, usa los botones de **Sí** o **No** para responder las preguntas correctamente.\n\n" .
                "Escribe **hola** para comenzar. 👋"
            );
            return;
        }

        $context = $conversation->context ?? [];
        $context['retries'] = $retries;
        $this->updateState($conversation, $conversation->state, $context);

        $this->sendMessage($conversation->contact, "⚠️ No entendí tu respuesta. Por favor, usa los botones de respuesta o escribe **1** (Sí) o **2** (No).");
    }

    /**
     * Verificar si el usuario califica basado en sus respuestas
     */
    private function checkIfQualified(BotConversation $conversation, string $lastResponse): bool
    {
        $context = $conversation->context ?? [];
        $responses = $context['responses'] ?? [];
        $currentState = $conversation->state;
        
        // Agregar la respuesta actual
        $responses[$currentState] = $lastResponse;
        
        // Para calificar para el Bono Techo Propio:
        // terrain: No (NO debe tener terreno propio)
        // family: Sí (debe tener carga familiar)
        // income: Sí (ingreso familiar menor a S/3,715)
        // previous_support: No (no debe haber recibido apoyo previo del Estado)
        $terrainOk = isset($responses['terrain']) && $responses['terrain'] === 'No';
        $familyOk = isset($responses['family']) && $responses['family'] === 'Sí';
        $incomeOk = isset($responses['income']) && $responses['income'] === 'Sí';
        $supportOk = isset($responses['previous_support']) && $responses['previous_support'] === 'No';
        
        return $terrainOk && $familyOk && $incomeOk && $supportOk;
    }

    /**
     * Finalizar el flujo con resultado
     */
    private function finishFlow(BotConversation $conversation, bool $qualified, string $reason)
    {
        $context = $conversation->context ?? [];
        $context['qualified'] = $qualified;
        $context['reason'] = $reason;
        
        $this->updateState($conversation, self::STATE_FINISHED, $context);

        if ($qualified) {
            // ==========================================
            // 🎯 ENVIAR LEAD CALIFICADO AL CRM
            // ==========================================
            $this->sendQualifiedLeadToCRM($conversation);
            
            $msg = "🎉 ¡Felicidades! Según tus respuestas, **SÍ CALIFICAS** para el Bono Techo Propio. 🏠💰\n\n" .
                   "Un asesor revisará tus datos y te contactará pronto para gestionar tu bono. ¡Estate atento!";
        } else {
            $msg = "Gracias por completar las preguntas. 😊\n\n" .
                   "Según tus respuestas, lamentablemente **NO CALIFICAS** para el Bono Techo Propio en este momento.\n\n" .
                   "Te invitamos a trabajar en cumplir los requisitos necesarios. Si necesitas orientación, nuestros asesores están disponibles para ayudarte. 🏠";
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
                'contact_id' => $contact->id,
                'phone' => $contact->phone_number,
                'conversation_id' => $conversation->id
            ]);
            
            // Verificar si ya fue enviado (evitar duplicados)
            if ($this->logicwareService->wasAlreadySentToCRM($contact)) {
                Log::info('BotService: Lead already sent to CRM, skipping', [
                    'contact_id' => $contact->id
                ]);
                return;
            }
            
            // Enviar al CRM
            $result = $this->logicwareService->createQualifiedLead($contact, $conversation);
            
            if ($result['success']) {
                Log::info('BotService: Lead sent to CRM successfully', [
                    'contact_id' => $contact->id,
                    'lead_id' => $result['lead_id'] ?? null,
                    'assigned_to' => $result['assigned_to'] ?? null
                ]);
                
                // Opcional: Enviar confirmación adicional al usuario
                // $this->sendMessage(
                //     $contact,
                //     "✅ Tus datos han sido registrados exitosamente en nuestro sistema de gestión."
                // );
            } else {
                Log::error('BotService: Failed to send lead to CRM', [
                    'contact_id' => $contact->id,
                    'error' => $result['error'] ?? 'Unknown error',
                    'response' => $result['response'] ?? null
                ]);
                
                // No notificar al usuario del error técnico
                // El asesor podrá ver el lead en el sistema igualmente
            }
            
        } catch (\Exception $e) {
            Log::error('BotService: Exception sending qualified lead to CRM', [
                'contact_id' => $conversation->contact_id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    // ==================== HELPERS ====================

    /**
     * Enviar mensaje al contacto
     */
    private function sendMessage(Contact $contact, string $text)
    {
        // MODO TESTING: Si el ID comienza con TEST_, no usar la API real
        if ($this->botPhoneNumberId && str_starts_with($this->botPhoneNumberId, 'TEST_')) {
            Log::info("BotService [TEST MODE]: Sending message to {$contact->phone_number}: {$text}");
            
            Message::create([
                'contact_id' => $contact->id,
                'phone_number_id' => $this->botPhoneNumberId,
                'phone_number' => $contact->phone_number,
                'message' => $text,
                'message_content' => $text,
                'direction' => 'outbound',
                'status' => 'sent', 
                'message_timestamp' => now(),
                'message_type' => 'text'
            ]);
            return;
        }

        // MODO REAL
        try {
            Log::info("BotService: Attempting to send real message to {$contact->phone_number}: {$text}");
            $ws = new WhatsAppService($this->botPhoneNumberId);
            $ws->sendMessage($contact->phone_number, $text);
            
            Message::create([
                'contact_id' => $contact->id,
                'phone_number_id' => $this->botPhoneNumberId,
                'phone_number' => $contact->phone_number,
                'message' => $text,
                'message_content' => $text,
                'direction' => 'outbound',
                'status' => 'sent',
                'message_timestamp' => now(),
                'message_type' => 'text'
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
        $currentContext = $conversation->context ?? [];
        $conversation->state = $state;
        $conversation->context = array_merge($currentContext, $context);
        $conversation->last_interaction_at = now();
        $conversation->save();
    }

    /**
     * Enviar mensaje interactivo con botones
     * Con fallback automático a texto si falla el envío de botones
     */
    private function sendInteractiveMessage(Contact $contact, string $text, array $buttons)
    {
        // Modo testing: registrar sin enviar
        if ($this->botPhoneNumberId && str_starts_with($this->botPhoneNumberId, 'TEST_')) {
            Log::info("BotService [TEST MODE]: Would send interactive message", [
                'to' => $contact->phone_number,
                'text' => $text,
                'buttons' => $buttons
            ]);
            
            Message::create([
                'contact_id' => $contact->id,
                'phone_number_id' => $this->botPhoneNumberId,
                'phone_number' => $contact->phone_number,
                'message' => $text,
                'message_content' => $text,
                'direction' => 'outbound',
                'status' => 'sent',
                'message_timestamp' => now(),
                'message_type' => 'interactive',
                'metadata' => ['buttons' => $buttons]
            ]);
            return;
        }

        // Modo real: intentar enviar botones con fallback a texto
        try {
            Log::info("BotService: Attempting to send interactive message", [
                'to' => $contact->phone_number,
                'buttons_count' => count($buttons)
            ]);
            
            $ws = new WhatsAppService($this->botPhoneNumberId);
            $result = $ws->sendInteractiveButtons($contact->phone_number, $text, $buttons);
            
            if ($result['success']) {
                Message::create([
                    'contact_id' => $contact->id,
                    'phone_number_id' => $this->botPhoneNumberId,
                    'phone_number' => $contact->phone_number,
                    'message' => $text,
                    'message_content' => $text,
                    'direction' => 'outbound',
                    'status' => 'sent',
                    'message_timestamp' => now(),
                    'message_type' => 'interactive',
                    'whatsapp_message_id' => $result['message_id'],
                    'metadata' => ['buttons' => $buttons]
                ]);
                Log::info("BotService: Interactive message sent successfully");
            } else {
                // Fallback a texto simple
                Log::warning("BotService: Interactive buttons failed, using text fallback", [
                    'error' => $result['error']
                ]);
                $this->sendTextFallback($contact, $text, $buttons);
            }
        } catch (\Exception $e) {
            Log::error("BotService: Error sending interactive message", [
                'error' => $e->getMessage()
            ]);
            
            // Fallback a texto simple
            $this->sendTextFallback($contact, $text, $buttons);
        }
    }

    /**
     * Enviar mensaje de texto simple como fallback cuando los botones fallan
     */
    private function sendTextFallback(Contact $contact, string $text, array $buttons)
    {
        $textWithOptions = $text . "\n\n" . implode("\n", array_map(
            fn($btn, $idx) => ($idx + 1) . ". " . $btn['title'],
            $buttons,
            array_keys($buttons)
        ));
        
        $this->sendMessage($contact, $textWithOptions);
    }
}
