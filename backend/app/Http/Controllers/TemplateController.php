<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;

class TemplateController extends Controller
{
    protected $whatsappService;

    public function __construct(WhatsAppService $whatsappService)
    {
        $this->whatsappService = $whatsappService;
    }

    public function index(): JsonResponse
    {
        // Verificar si el Business Account ID está configurado
        $businessAccountId = config('services.whatsapp.business_account_id');
        
        if (empty($businessAccountId)) {
            return response()->json([
                'error' => 'WHATSAPP_BUSINESS_ACCOUNT_ID no está configurado en .env',
                'templates' => [],
                'help' => [
                    'message' => 'Para obtener templates, necesitas configurar tu Business Account ID',
                    'steps' => [
                        '1. Ve a https://business.facebook.com/',
                        '2. Selecciona tu cuenta de negocio',
                        '3. Ve a WhatsApp Accounts en el menú',
                        '4. Copia el Account ID (diferente al Phone Number ID)',
                        '5. Agrégalo al .env como WHATSAPP_BUSINESS_ACCOUNT_ID'
                    ],
                    'alternative' => 'O ejecuta: php get-business-account-id.php'
                ]
            ], 400);
        }

        $result = $this->whatsappService->getTemplates();
        
        if ($result['success']) {
            // Filtrar solo templates aprobados
            $approvedTemplates = array_filter($result['templates'], function($template) {
                return $template['status'] === 'APPROVED';
            });

            return response()->json([
                'templates' => array_values($approvedTemplates)
            ]);
        }

        return response()->json([
            'error' => $result['error'],
            'templates' => [],
            'help' => 'Verifica que tu Access Token tenga permisos para leer templates'
        ], 500);
    }

    public function getAccountInfo(): JsonResponse
    {
        try {
            $phoneNumberId = config('services.whatsapp.phone_number_id');
            $accessToken = config('services.whatsapp.access_token');
            $version = config('services.whatsapp.version');
            $apiUrl = config('services.whatsapp.api_url');

            $url = "{$apiUrl}/{$version}/{$phoneNumberId}?fields=id,verified_name,display_phone_number&access_token={$accessToken}";

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $data = json_decode($response, true);
                return response()->json([
                    'success' => true,
                    'account' => $data,
                    'note' => 'Para obtener el Business Account ID, ve a https://business.facebook.com/ > WhatsApp Accounts'
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => json_decode($response, true),
                'http_code' => $httpCode
            ], $httpCode);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
