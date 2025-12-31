#!/usr/bin/env php
<?php
/**
 * Script para obtener el WhatsApp Business Account ID
 * 
 * Uso:
 * php get-business-account-id.php
 */

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  Script para obtener WhatsApp Business Account ID          ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// Cargar .env
if (!file_exists(__DIR__ . '/.env')) {
    echo "❌ Error: No se encuentra el archivo .env\n";
    echo "   Copia .env.example a .env primero\n\n";
    exit(1);
}

// Leer access token del .env
$env = file_get_contents(__DIR__ . '/.env');
preg_match('/WHATSAPP_ACCESS_TOKEN=(.+)/', $env, $matches);

if (empty($matches[1])) {
    echo "❌ Error: WHATSAPP_ACCESS_TOKEN no está configurado en .env\n\n";
    exit(1);
}

$accessToken = trim($matches[1]);

// Obtener Phone Number ID
preg_match('/WHATSAPP_PHONE_NUMBER_ID=(.+)/', $env, $phoneMatches);
if (empty($phoneMatches[1])) {
    echo "❌ Error: WHATSAPP_PHONE_NUMBER_ID no está configurado en .env\n\n";
    exit(1);
}

$phoneNumberId = trim($phoneMatches[1]);

echo "🔍 Buscando Business Account ID...\n";
echo "   Phone Number ID: $phoneNumberId\n\n";

// Hacer request a la API de WhatsApp
$url = "https://graph.facebook.com/v18.0/$phoneNumberId?fields=id,verified_name,display_phone_number&access_token=$accessToken";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo "❌ Error al conectar con WhatsApp API (HTTP $httpCode)\n";
    echo "   Respuesta: $response\n\n";
    echo "💡 Verifica que tu WHATSAPP_ACCESS_TOKEN sea válido\n\n";
    
    // Intentar obtener Business Account ID de otra forma
    echo "🔄 Intentando método alternativo...\n\n";
    
    $debugUrl = "https://graph.facebook.com/debug_token?input_token=$accessToken&access_token=$accessToken";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $debugUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $debugResponse = curl_exec($ch);
    curl_close($ch);
    
    $debugData = json_decode($debugResponse, true);
    
    echo "📖 Información del Access Token:\n";
    echo json_encode($debugData, JSON_PRETTY_PRINT) . "\n\n";
    
    echo "📌 Obtén manualmente tu Business Account ID:\n\n";
    echo "   1. Ve a https://business.facebook.com/\n";
    echo "   2. Selecciona tu cuenta de negocio\n";
    echo "   3. Ve a 'WhatsApp Accounts' en el menú\n";
    echo "   4. El ID está junto a tu número de teléfono\n";
    echo "   5. Agrégalo al .env como WHATSAPP_BUSINESS_ACCOUNT_ID\n\n";
    
    echo "   O prueba este método:\n";
    echo "   1. Ve a https://developers.facebook.com/apps/\n";
    echo "   2. Selecciona tu app\n";
    echo "   3. WhatsApp > API Setup\n";
    echo "   4. Busca 'WhatsApp Business Account ID'\n\n";
    
    exit(1);
}

$data = json_decode($response, true);

if (!isset($data['id'])) {
    echo "❌ Error: No se pudo obtener información de la cuenta\n";
    echo "   Respuesta: $response\n\n";
    exit(1);
}

// Obtener WABA ID (WhatsApp Business Account ID)
$wabaId = null;

// Método 1: Directamente desde el Phone Number endpoint
if (isset($data['waba_id'])) {
    $wabaId = $data['waba_id'];
}

// Método 2: Hacer request directo al Debug Token endpoint
if (!$wabaId) {
    $debugUrl = "https://graph.facebook.com/debug_token?input_token=$accessToken&access_token=$accessToken";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $debugUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $debugResponse = curl_exec($ch);
    curl_close($ch);
    
    $debugData = json_decode($debugResponse, true);
    if (isset($debugData['data']['granular_scopes'])) {
        foreach ($debugData['data']['granular_scopes'] as $scope) {
            if (isset($scope['scope']) && strpos($scope['scope'], 'whatsapp_business_management') !== false) {
                if (isset($scope['target_ids']) && !empty($scope['target_ids'])) {
                    $wabaId = $scope['target_ids'][0];
                    break;
                }
            }
        }
    }
}

echo "✅ Información de tu cuenta WhatsApp Business:\n\n";
echo "┌─────────────────────────────────────────────────────────────┐\n";
echo "│ Phone Number: " . str_pad($data['display_phone_number'] ?? 'N/A', 43) . "│\n";
echo "│ Verified Name: " . str_pad($data['verified_name'] ?? 'N/A', 42) . "│\n";
echo "│ Quality Rating: " . str_pad($data['quality_rating'] ?? 'N/A', 41) . "│\n";
echo "│ Account Mode: " . str_pad($data['account_mode'] ?? 'N/A', 43) . "│\n";

if ($wabaId) {
    echo "│ " . str_repeat("─", 59) . " │\n";
    echo "│ ✨ Business Account ID: " . str_pad($wabaId, 33) . "│\n";
} else {
    echo "│ " . str_repeat("─", 59) . " │\n";
    echo "│ ⚠️  No se pudo obtener automáticamente el WABA ID          │\n";
}
echo "└─────────────────────────────────────────────────────────────┘\n\n";

if ($wabaId) {
    echo "📝 Agrega esta línea a tu archivo .env:\n\n";
    echo "   WHATSAPP_BUSINESS_ACCOUNT_ID=$wabaId\n\n";
    
    // Actualizar .env automáticamente
    echo "¿Deseas actualizar el .env automáticamente? (y/n): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);
    
    if (trim(strtolower($line)) === 'y') {
        $envContent = file_get_contents(__DIR__ . '/.env');
        
        if (strpos($envContent, 'WHATSAPP_BUSINESS_ACCOUNT_ID=') !== false) {
            // Reemplazar valor existente
            $envContent = preg_replace(
                '/WHATSAPP_BUSINESS_ACCOUNT_ID=.*/',
                "WHATSAPP_BUSINESS_ACCOUNT_ID=$wabaId",
                $envContent
            );
        } else {
            // Agregar nueva línea
            $envContent = str_replace(
                'WHATSAPP_PHONE_NUMBER_ID=' . $phoneNumberId,
                "WHATSAPP_PHONE_NUMBER_ID=$phoneNumberId\nWHATSAPP_BUSINESS_ACCOUNT_ID=$wabaId",
                $envContent
            );
        }
        
        file_put_contents(__DIR__ . '/.env', $envContent);
        echo "\n✅ Archivo .env actualizado correctamente!\n";
        echo "🔄 Recuerda reiniciar el servidor Laravel\n\n";
    }
} else {
    echo "📖 Obtén manualmente tu Business Account ID:\n\n";
    echo "   1. Ve a https://business.facebook.com/\n";
    echo "   2. Selecciona tu cuenta de negocio\n";
    echo "   3. Ve a 'WhatsApp Accounts' en el menú\n";
    echo "   4. Copia el ID que aparece junto a tu número\n";
    echo "   5. Agrégalo al .env como WHATSAPP_BUSINESS_ACCOUNT_ID\n\n";
}

echo "🎉 ¡Listo! Ya puedes usar templates en tu sistema\n\n";
