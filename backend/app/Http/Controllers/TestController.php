<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TestController extends Controller
{
    protected $whatsappService;

    public function __construct(WhatsAppService $whatsappService)
    {
        $this->whatsappService = $whatsappService;
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
}
