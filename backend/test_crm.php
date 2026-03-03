<?php
/**
 * Script rápido para probar integración con LogicWare CRM
 * Uso: php test_crm.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\LogicWareService;
use App\Models\Contact;
use App\Models\BotConversation;

echo "🧪 Probando integración LogicWare CRM\n";
echo "=====================================\n\n";

// Test 1: Configuración
echo "📋 Test 1: Verificando configuración...\n";
$config = config('services.logicware');

if (empty($config['api_key'])) {
    echo "❌ ERROR: LOGICWARE_API_KEY no configurada\n";
    exit(1);
}

echo "   ✅ API Key: " . substr($config['api_key'], 0, 20) . "...\n";
echo "   ✅ Subdomain: {$config['subdomain']}\n";
echo "   ✅ Portal Code: {$config['portal_code']}\n";
echo "   ✅ Project Code: {$config['project_code']}\n\n";

// Test 2: Obtener token
echo "🔑 Test 2: Obteniendo token de acceso...\n";
$service = app(LogicWareService::class);

try {
    $token = $service->getValidToken();
    echo "   ✅ Token obtenido: " . substr($token, 0, 30) . "...\n\n";
} catch (\Exception $e) {
    echo "   ❌ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 3: Buscar un contacto con conversación calificada
echo "🔍 Test 3: Buscando contacto con conversación calificada...\n";

$conversation = BotConversation::where('state', 'finished')
    ->whereJsonContains('context->qualified', true)
    ->with('contact')
    ->orderBy('created_at', 'desc')
    ->first();

if (!$conversation) {
    echo "   ⚠️  No se encontró ninguna conversación calificada\n";
    echo "   💡 Puedes crear una de prueba:\n\n";
    echo "   php artisan tinker\n";
    echo "   >>> \$contact = \\App\\Models\\Contact::create(['phone_number' => '+51946552086', 'name' => 'Test Usuario', 'contact_type' => 'lead']);\n";
    echo "   >>> \$conv = \$contact->botConversations()->create(['flow_name' => 'bono_techo_propio', 'state' => 'finished', 'context' => ['qualified' => true, 'responses' => ['terrain' => 'si', 'family' => 'si', 'income' => 'si', 'previous_support' => 'no']]]);\n\n";
    exit(0);
}

$contact = $conversation->contact;
echo "   ✅ Contacto encontrado:\n";
echo "      - ID: {$contact->id}\n";
echo "      - Nombre: {$contact->name}\n";
echo "      - Teléfono: {$contact->phone_number}\n";
echo "      - Email: {$contact->email}\n\n";

// Verificar si ya fue enviado
if ($service->wasAlreadySentToCRM($contact)) {
    echo "   ⚠️  Este contacto ya fue enviado al CRM anteriormente\n";
    echo "   💡 Para reenviar, limpia el metadata:\n\n";
    echo "   php artisan tinker\n";
    echo "   >>> \$contact = \\App\\Models\\Contact::find({$contact->id});\n";
    echo "   >>> \$metadata = \$contact->metadata ?? [];\n";
    echo "   >>> unset(\$metadata['crm_sent']);\n";
    echo "   >>> \$contact->metadata = \$metadata;\n";
    echo "   >>> \$contact->save();\n\n";
    
    echo "¿Deseas enviarlo de todas formas? (y/n): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    if (trim(strtolower($line)) !== 'y') {
        echo "   Cancelado.\n";
        exit(0);
    }
}

// Test 4: Enviar lead al CRM
echo "📤 Test 4: Enviando lead al CRM...\n";

try {
    $result = $service->createQualifiedLead($contact, $conversation);
    
    if ($result['success']) {
        echo "   ✅ Lead enviado exitosamente!\n";
        echo "      - Lead ID: " . ($result['lead_id'] ?? 'N/A') . "\n";
        echo "      - Asignado a: " . ($result['assigned_to'] ?? 'N/A') . "\n\n";
        
        // Mostrar metadata actualizado
        $contact->refresh();
        echo "   📊 Metadata actualizado:\n";
        $metadata = $contact->metadata ?? [];
        echo "      - crm_sent: " . ($metadata['crm_sent'] ? 'true' : 'false') . "\n";
        echo "      - crm_lead_id: " . ($metadata['crm_lead_id'] ?? 'N/A') . "\n";
        echo "      - crm_sent_at: " . ($metadata['crm_sent_at'] ?? 'N/A') . "\n";
    } else {
        echo "   ❌ ERROR al enviar lead:\n";
        echo "      " . ($result['error'] ?? 'Error desconocido') . "\n";
        exit(1);
    }
} catch (\Exception $e) {
    echo "   ❌ EXCEPCIÓN: " . $e->getMessage() . "\n";
    echo "   Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n🎉 Todos los tests pasaron exitosamente!\n";
