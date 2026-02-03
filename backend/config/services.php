<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'whatsapp' => [
        'api_url' => env('WHATSAPP_API_URL', 'https://graph.facebook.com'),
        'version' => env('WHATSAPP_API_VERSION', 'v18.0'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        'delay_between_messages' => env('WHATSAPP_DELAY_SECONDS', 2),
        'delay_on_error' => env('WHATSAPP_DELAY_ON_ERROR', 5),
        
        'leads_bot_id' => env('WHATSAPP_LEADS_PHONE_NUMBER_ID'),
        
        'available_numbers' => [
            [
                'id' => env('WHATSAPP_PHONE_NUMBER_ID'),
                'name' => 'Cobranza',
                'phone' => env('WHATSAPP_PHONE_NUMBER', '51 922 902 212'),
                'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
                'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
            ],
            [
                'id' => env('WHATSAPP_ALT_PHONE_NUMBER_ID'),
                'name' => 'Atención al Cliente',
                'phone' => env('WHATSAPP_ALT_PHONE_NUMBER', '51 922 902 154'),
                'access_token' => env('WHATSAPP_ALT_ACCESS_TOKEN') ?: env('WHATSAPP_ACCESS_TOKEN'),
                'business_account_id' => env('WHATSAPP_ALT_BUSINESS_ACCOUNT_ID'),
            ],
            [
                'id' => env('WHATSAPP_LEADS_PHONE_NUMBER_ID'),
                'name' => 'Bot Leads - Techo Propio',
                'phone' => env('WHATSAPP_LEADS_PHONE_NUMBER', '51 922 902 122'),
                'access_token' => env('WHATSAPP_LEADS_ACCESS_TOKEN') ?: env('WHATSAPP_ACCESS_TOKEN'),
                'business_account_id' => env('WHATSAPP_LEADS_BUSINESS_ACCOUNT_ID'),
            ],
        ],
    ],

];
