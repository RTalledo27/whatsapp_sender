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

        // 3. Verificar si el usuario pide hablar con humano explícitamente
        if ($this->isHandoffRequest($message->message_content)) {
            $this->handoffToAgent($conversation, "El usuario solicitó hablar con un asesor.");
            return;
        }

        // 4. Si ya está en handoff, el bot no interviene
        if ($conversation->state === self::STATE_HANDOFF) {
            return;
        }

        // 5. Procesar según el estado actual
        try {
            if ($conversation->state === self::STATE_INITIAL) {
                $this->startFlow($conversation);
            } else {
                $this->processStep($conversation, $message);
            }
        } catch (\Exception $e) {
            Log::error('Error in BotService', [
                'error' => $e->getMessage(),
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
                "Para saber si calificas, necesito hacerte 3 preguntas rápidas.\n\n" .
                "1️⃣ ¿Tienes un terreno propio inscrito en Registros Públicos?\n" .
                "responde con el número de tu opción:\n" .
                "1. Sí\n" .
                "2. No";

        $this->sendMessage($conversation->contact, $text);
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
                if ($this->checkOption($content, ['1', 'si', 'sí'], ['2', 'no'])) {
                    $hasTerrain = $this->isAffirmative($content);
                    
                    if (!$hasTerrain) {
                        $this->finishFlow($conversation, false, "Sin terreno propio");
                    } else {
                        $context['has_terrain'] = true;
                        $context['retries'] = 0;
                        $this->updateState($conversation, self::STATE_FAMILY, $context);
                        $this->sendMessage($conversation->contact, "¡Genial! ✅\n\n2️⃣ ¿Tienes carga familiar? (Esposa/o, hijos, hermanos menores o padres dependientes)\n\n1. Sí\n2. No");
                    }
                } else {
                    $this->handleInvalidInput($conversation, $retries);
                }
                break;

            case self::STATE_FAMILY:
                if ($this->checkOption($content, ['1', 'si', 'sí'], ['2', 'no'])) {
                    $hasFamily = $this->isAffirmative($content);
                    
                    if (!$hasFamily) {
                        $this->finishFlow($conversation, false, "Sin carga familiar");
                    } else {
                        $context['has_family'] = true;
                        $context['retries'] = 0;
                        $this->updateState($conversation, self::STATE_INCOME, $context);
                        $this->sendMessage($conversation->contact, "¡Perfecto! Vamos con la última.\n\n3️⃣ ¿El ingreso mensual de tu familia es menor a S/ 3,715?\n\n1. Sí\n2. No");
                    }
                } else {
                    $this->handleInvalidInput($conversation, $retries);
                }
                break;

            case self::STATE_INCOME:
                if ($this->checkOption($content, ['1', 'si', 'sí'], ['2', 'no'])) {
                    $lowIncome = $this->isAffirmative($content);
                    
                    if (!$lowIncome) {
                        $this->finishFlow($conversation, false, "Ingresos superiores al límite");
                    } else {
                        $context['low_income'] = true;
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

        $this->sendMessage($conversation->contact, "⚠️ No entendí tu respuesta. Por favor, responde solo con el número **1** (Sí) o **2** (No).");
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
     */
    private function checkOption($input, array $yesOptions, array $noOptions): bool
    {
        $input = strtolower(trim($input));
        return in_array($input, $yesOptions) || in_array($input, $noOptions);
    }

    /**
     * Verificar si es una respuesta afirmativa
     */
    private function isAffirmative($input): bool
    {
        $input = strtolower(trim($input));
        return in_array($input, ['1', 'si', 'sí']);
    }

    /**
     * Detectar si el usuario pide hablar con un asesor
     */
    private function isHandoffRequest($input): bool
    {
        $input = strtolower(trim($input));
        return in_array($input, ['asesor', 'humano', 'persona', 'ayuda']);
    }
}
