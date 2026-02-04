<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Message;
use App\Models\BotConversation;
use Illuminate\Support\Facades\Log;

class BotService
{
    private WhatsAppService $whatsappService;
    private $botPhoneNumberId;

    // Estados posibles del bot
    const STATE_INITIAL = 'initial';
    const STATE_TERRAIN = 'terrain';
    const STATE_FAMILY = 'family';
    const STATE_INCOME = 'income';
    const STATE_PREVIOUS_SUPPORT = 'previous_support';
    const STATE_FINISHED = 'finished';
    const STATE_HANDOFF = 'handoff';

    public function __construct(WhatsAppService $whatsappService)
    {
        $this->whatsappService = $whatsappService;
        $this->botPhoneNumberId = config('services.whatsapp.leads_bot_id');
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

        // 4. Verificar si el usuario pide hablar con humano explícitamente
        if ($this->isHandoffRequest($message->message_content)) {
            Log::info("BotService: User requested handoff.");
            $this->handoffToAgent($conversation, "El usuario solicitó hablar con un asesor.");
            return;
        }

        // 5. Si ya está en handoff, el bot no interviene
        if ($conversation->state === self::STATE_HANDOFF) {
            Log::info("BotService: Ignoring message because conversation is in HANDOFF state.");
            return;
        }

        // 5. Procesar según el estado actual
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
        $this->updateState($conversation, self::STATE_TERRAIN, ['retries' => 0]);
        
        $text = "¡Hola! 👋 Gracias por tu interés en el Bono Techo Propio. Soy el asistente virtual de Casa Bonita.\n\n" .
                "Para saber si calificas, necesito hacerte 4 preguntas rápidas.\n\n" .
                "1️⃣ ¿Tienes un terreno propio inscrito en Registros Públicos?";

        // Enviar mensaje con botones interactivos
        $this->sendInteractiveMessage($conversation->contact, $text, [
            ['id' => 'btn_terrain_yes', 'title' => 'Sí'],
            ['id' => 'btn_terrain_no', 'title' => 'No']
        ]);
    }

    /**
     * Procesar el paso actual según el estado
     */
    private function processStep(BotConversation $conversation, Message $message)
    {
        $content = trim($message->message_content);
        $context = $conversation->context ?? [];
        $retries = $context['retries'] ?? 0;

        switch ($conversation->state) {
            case self::STATE_TERRAIN:
                // Combinar opciones de texto y button IDs
                $yesOptions = ['1', 'si', 'sí', 'btn_terrain_yes', 'btn_yes'];
                $noOptions = ['2', 'no', 'btn_terrain_no', 'btn_no'];
                
                if ($this->checkOption($content, $yesOptions, $noOptions)) {
                    $hasTerrain = $this->isAffirmative($content);
                    
                    if (!$hasTerrain) {
                        $this->finishFlow($conversation, false, "Sin terreno propio");
                    } else {
                        $context['has_terrain'] = true;
                        $context['retries'] = 0;
                        $this->updateState($conversation, self::STATE_FAMILY, $context);
                        
                        // Enviar siguiente pregunta con botones
                        $text = "¡Genial! ✅\n\n2️⃣ ¿Tienes carga familiar? (Esposa/o, hijos, hermanos menores o padres dependientes)";
                        $this->sendInteractiveMessage($conversation->contact, $text, [
                            ['id' => 'btn_family_yes', 'title' => 'Sí'],
                            ['id' => 'btn_family_no', 'title' => 'No']
                        ]);
                    }
                } else {
                    $this->handleInvalidInput($conversation, $retries);
                }
                break;

            case self::STATE_FAMILY:
                // Combinar opciones de texto y button IDs
                $yesOptions = ['1', 'si', 'sí', 'btn_family_yes', 'btn_yes'];
                $noOptions = ['2', 'no', 'btn_family_no', 'btn_no'];
                
                if ($this->checkOption($content, $yesOptions, $noOptions)) {
                    $hasFamily = $this->isAffirmative($content);
                    
                    if (!$hasFamily) {
                        $this->finishFlow($conversation, false, "Sin carga familiar");
                    } else {
                        $context['has_family'] = true;
                        $context['retries'] = 0;
                        $this->updateState($conversation, self::STATE_INCOME, $context);
                        
                        // Enviar pregunta de ingresos
                        $text = "3️⃣ ¿El ingreso mensual de tu familia es menor a S/ 3,715?";
                        $this->sendInteractiveMessage($conversation->contact, $text, [
                            ['id' => 'btn_income_yes', 'title' => 'Sí'],
                            ['id' => 'btn_income_no', 'title' => 'No']
                        ]);
                    }
                } else {
                    $this->handleInvalidInput($conversation, $retries);
                }
                break;

            case self::STATE_INCOME:
                // Combinar opciones de texto y button IDs
                $yesOptions = ['1', 'si', 'sí', 'btn_income_yes', 'btn_yes'];
                $noOptions = ['2', 'no', 'btn_income_no', 'btn_no'];
                
                if ($this->checkOption($content, $yesOptions, $noOptions)) {
                    $lowIncome = $this->isAffirmative($content);
                    
                    if (!$lowIncome) {
                        $this->finishFlow($conversation, false, "Ingresos superiores al límite");
                    } else {
                        $context['low_income'] = true;
                        $context['retries'] = 0;
                        $this->updateState($conversation, self::STATE_PREVIOUS_SUPPORT, $context);
                        
                        // Enviar pregunta sobre apoyo habitacional previo
                        $text = "4️⃣ ¿Ha recibido anteriormente apoyo de un programa habitacional del Estado?";
                        $this->sendInteractiveMessage($conversation->contact, $text, [
                            ['id' => 'btn_support_yes', 'title' => 'Sí'],
                            ['id' => 'btn_support_no', 'title' => 'No']
                        ]);
                    }
                } else {
                    $this->handleInvalidInput($conversation, $retries);
                }
                break;

            case self::STATE_PREVIOUS_SUPPORT:
                // Combinar opciones de texto y button IDs
                $yesOptions = ['1', 'si', 'sí', 'btn_support_yes', 'btn_yes'];
                $noOptions = ['2', 'no', 'btn_support_no', 'btn_no'];
                
                if ($this->checkOption($content, $yesOptions, $noOptions)) {
                    $hasPreviousSupport = $this->isAffirmative($content);
                    
                    if ($hasPreviousSupport) {
                        // Ya recibió apoyo anteriormente - NO califica
                        $this->finishFlow($conversation, false, "Ya ha recibido apoyo habitacional previo del Estado");
                    } else {
                        // No ha recibido apoyo anteriormente - CALIFICA
                        $context['no_previous_support'] = true;
                        $this->finishFlow($conversation, true, "Apto para Techo Propio");
                    }
                } else {
                    $this->handleInvalidInput($conversation, $retries);
                }
                break;
        }
    }

    /**
     * Manejar entrada inválida del usuario
     */
    private function handleInvalidInput(BotConversation $conversation, int $retries)
    {
        $retries++;
        
        if ($retries >= 2) {
            $this->handoffToAgent($conversation, "Usuario superó intentos fallidos en paso {$conversation->state}");
            return;
        }

        $context = $conversation->context ?? [];
        $context['retries'] = $retries;
        $this->updateState($conversation, $conversation->state, $context);

        $this->sendMessage($conversation->contact, "⚠️ No entendí tu respuesta. Por favor, usa los botones de respuesta o escribe **1** (Sí) o **2** (No).");
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
            $msg = "🎉 ¡Felicidades! Según tus respuestas, **SÍ CALIFICAS** para el Bono Techo Propio. 🏠💰\n\n" .
                   "Un asesor revisará tus datos y te contactará pronto para gestionar tu bono. ¡Estate atento!";
        } else {
            $msg = "Gracias por tus respuestas. Según los requisitos actuales, parece que no calificas para este bono específico ($reason). 😕\n\n" .
                   "Pero no te preocupes, un asesor verificará si hay otras opciones para ti.";
        }

        $this->sendMessage($conversation->contact, $msg);
    }

    /**
     * Transferir a agente humano
     */
    private function handoffToAgent(BotConversation $conversation, string $reason)
    {
        $context = $conversation->context ?? [];
        $context['handoff_reason'] = $reason;
        
        $this->updateState($conversation, self::STATE_HANDOFF, $context);
        
        $this->sendMessage($conversation->contact, "Entiendo que puedas tener dudas. Procederé a cerrar esta sesión automática. Un asesor te contactará pronto. 👋");
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
     * Verificar si la entrada es una opción válida
     * Soporta tanto texto ('1', 'si') como button IDs ('btn_yes')
     */
    private function checkOption($input, array $yesOptions, array $noOptions): bool
    {
        $input = strtolower(trim($input));
        return in_array($input, $yesOptions) || in_array($input, $noOptions);
    }

    /**
     * Verificar si es una respuesta afirmativa
     * Soporta tanto texto ('1', 'si', 'sí') como button IDs ('btn_yes', 'btn_terrain_yes', etc.)
     */
    private function isAffirmative($input): bool
    {
        $input = strtolower(trim($input));
        
        // Opciones de texto tradicionales
        $textOptions = ['1', 'si', 'sí'];
        
        // IDs de botones afirmativos
        $buttonOptions = ['btn_yes', 'btn_si', 'btn_terrain_yes', 'btn_family_yes', 'btn_income_yes', 'btn_support_yes'];
        
        return in_array($input, $textOptions) || in_array($input, $buttonOptions);
    }

    /**
     * Detectar si el usuario pide hablar con un asesor
     */
    private function isHandoffRequest($input): bool
    {
        $input = strtolower(trim($input));
        return in_array($input, ['asesor', 'humano', 'persona', 'ayuda']);
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
