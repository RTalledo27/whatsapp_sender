<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppService;
use App\Services\BotService;
use App\Models\Contact;
use App\Models\Message;
use App\Models\BotConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TestController extends Controller
{
    protected $whatsappService;
    protected $botService;

    public function __construct(WhatsAppService $whatsappService, BotService $botService)
    {
        $this->whatsappService = $whatsappService;
        $this->botService = $botService;
    }

    /**
     * Probar envío de template
     */
    public function testTemplate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string',
            'template_name' => 'required|string',
            'parameters' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $templateData = [
            'name' => $request->template_name,
            'language' => 'es',
            'parameters' => $request->parameters ?? []
        ];

        $result = $this->whatsappService->sendMessage(
            $request->phone,
            null,
            $templateData
        );

        return response()->json($result);
    }

    /**
     * Probar envío de mensaje de texto
     */
    public function testTextMessage(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string',
            'message' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $result = $this->whatsappService->sendMessage(
            $request->phone,
            $request->message
        );

        return response()->json($result);
    }

    /**
     * Probar flujo del bot de calificación de leads
     */
    public function testBotFlow(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string',
            'message' => 'required|string',
            'bot_phone_id' => 'required|string',
            'reset' => 'nullable|boolean'
        ]);

        $phone = $request->phone;
        $text = $request->message;
        $botPhoneId = $request->bot_phone_id;

        // Configurar ID del bot para esta prueba
        $this->botService->setBotChannelId($botPhoneId);

        // Buscar/Crear contacto
        $contact = Contact::firstOrCreate(
            ['phone_number' => $phone],
            ['name' => $phone]
        );

        // Resetear conversación si se solicita
        if ($request->reset) {
            BotConversation::where('contact_id', $contact->id)
                ->where('phone_number_id', $botPhoneId)
                ->delete();
        }

        // Crear mensaje entrante simulado
        $message = Message::create([
            'contact_id' => $contact->id,
            'phone_number_id' => $botPhoneId,
            'phone_number' => $phone,
            'direction' => 'inbound',
            'message' => $text,
            'message_content' => $text,
            'whatsapp_message_id' => 'SIM_' . uniqid(),
            'message_timestamp' => now(),
            'status' => 'delivered',
            'message_type' => 'text'
        ]);

        // Invocar Bot
        $this->botService->handleIncomingMessage($contact, $message);

        // Obtener conversación actual
        $conversation = BotConversation::where('contact_id', $contact->id)
            ->where('phone_number_id', $botPhoneId)
            ->first();

        // Obtener última respuesta del bot
        $response = Message::where('contact_id', $contact->id)
            ->where('phone_number_id', $botPhoneId)
            ->where('direction', 'outbound')
            ->orderBy('created_at', 'desc')
            ->first();

        return response()->json([
            'status' => 'processed',
            'input_message' => $text,
            'bot_state' => $conversation ? [
                'state' => $conversation->state,
                'context' => $conversation->context,
                'last_interaction' => $conversation->last_interaction_at
            ] : null,
            'bot_response' => $response ? $response->message : null
        ]);
    }
}
