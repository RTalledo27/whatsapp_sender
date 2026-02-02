<?php

namespace App\Services;

use App\Models\BotConversation;
use App\Models\Contact;
use Illuminate\Support\Facades\Log;

class BotService
{
    /**
     * Procesar mensaje entrante y decidir si activar el bot
     */
    public static function handleIncomingMessage(Contact $contact, string $message, string $phoneNumberId): ?string
    {
        // SAFEGUARD CRÍTICO: Solo activar para número de leads
        if ($phoneNumberId !== env('WHATSAPP_LEADS_BOT_ID')) {
            Log::warning("Bot activation attempted for unauthorized number", [
                'phone_number_id' => $phoneNumberId,
                'contact_id' => $contact->id
            ]);
            return null; // No enviar respuesta automática
        }

        // Obtener o crear conversación
        $conversation = BotConversation::firstOrCreate(
            [
                'contact_id' => $contact->id,
                'phone_number_id' => $phoneNumberId,
            ],
            [
                'state' => BotConversation::STATE_INITIAL,
                'context' => [],
                'last_interaction_at' => now(),
            ]
        );

        // Si el usuario escribe "hola" o "inicio", reiniciar flujo
        $messageLower = strtolower(trim($message));
        if (in_array($messageLower, ['hola', 'inicio', 'empezar', 'start'])) {
            $conversation->update([
                'state' => BotConversation::STATE_INITIAL,
                'context' => [],
                'last_interaction_at' => now(),
            ]);
        }

        // Procesar según el estado actual
        return self::processMessage($conversation, $message);
    }

    /**
     * Procesar mensaje según el estado de la conversación
     */
    private static function processMessage(BotConversation $conversation, string $userMessage): string
    {
        $userMessage = trim($userMessage);

        switch ($conversation->state) {
            case BotConversation::STATE_INITIAL:
                return self::handleInitialState($conversation);

            case BotConversation::STATE_WAITING_INTEREST:
                return self::handleInterestResponse($conversation, $userMessage);

            case BotConversation::STATE_WAITING_AGE:
                return self::handleAgeResponse($conversation, $userMessage);

            case BotConversation::STATE_WAITING_EMPLOYMENT:
                return self::handleEmploymentResponse($conversation, $userMessage);

            case BotConversation::STATE_WAITING_INCOME:
                return self::handleIncomeResponse($conversation, $userMessage);

            case BotConversation::STATE_WAITING_FAMILY_GROUP:
                return self::handleFamilyGroupResponse($conversation, $userMessage);

            case BotConversation::STATE_WAITING_FIRST_HOME:
                return self::handleFirstHomeResponse($conversation, $userMessage);

            default:
                // Si está en estado completado, reiniciar
                if (!$conversation->isActive()) {
                    $conversation->update([
                        'state' => BotConversation::STATE_INITIAL,
                        'context' => [],
                    ]);
                    return self::handleInitialState($conversation);
                }
                return "Lo siento, hubo un error. Escribe 'hola' para empezar de nuevo.";
        }
    }

    /**
     * Estado inicial: Mensaje de bienvenida
     */
    private static function handleInitialState(BotConversation $conversation): string
    {
        $conversation->update([
            'state' => BotConversation::STATE_WAITING_INTEREST,
            'last_interaction_at' => now(),
        ]);

        return "Hola! 👋 Me gustaría poder evaluarte para ver si accedes a tu casa lote+módulo.\n\n"
            . "Por favor selecciona:\n"
            . "1️⃣ Me interesa\n"
            . "2️⃣ No me interesa";
    }

    /**
     * Manejar respuesta de interés
     */
    private static function handleInterestResponse(BotConversation $conversation, string $response): string
    {
        $responseLower = strtolower(trim($response));

        // Opción 2: No me interesa
        if ($responseLower === '2' || str_contains($responseLower, 'no me interesa') || $responseLower === 'no') {
            $conversation->update([
                'state' => BotConversation::STATE_NOT_INTERESTED,
                'last_interaction_at' => now(),
            ]);
            $conversation->saveResponse('interested', false);
            return "Entiendo, gracias por tu tiempo. Que tengas un excelente día! 👋";
        }

        // Opción 1: Me interesa
        if ($responseLower === '1' || str_contains($responseLower, 'me interesa') || str_contains($responseLower, 'si')) {
            $conversation->update([
                'state' => BotConversation::STATE_WAITING_AGE,
                'last_interaction_at' => now(),
            ]);
            $conversation->saveResponse('interested', true);
            return "Perfecto! Vamos a ver si calificas para el Bono Techo Propio 🏠\n\n¿Cuál es tu edad?";
        }

        // Respuesta no válida
        return "Por favor selecciona una opción válida:\n1️⃣ Me interesa\n2️⃣ No me interesa";
    }

    /**
     * Manejar respuesta de edad
     */
    private static function handleAgeResponse(BotConversation $conversation, string $response): string
    {
        // Extraer número de la respuesta
        preg_match('/\d+/', $response, $matches);
        
        if (empty($matches)) {
            return "Por favor ingresa tu edad en números. Ejemplo: 25";
        }

        $age = (int) $matches[0];

        if ($age < 18 || $age > 100) {
            return "Por favor ingresa una edad válida (entre 18 y 100 años).";
        }

        $conversation->saveResponse('age', $age);
        $conversation->update([
            'state' => BotConversation::STATE_WAITING_EMPLOYMENT,
            'last_interaction_at' => now(),
        ]);

        return "Gracias! Ahora dime, ¿cuál es tu situación laboral actual?\n\n"
            . "1️⃣ Trabajo dependiente\n"
            . "2️⃣ Trabajo independiente\n"
            . "3️⃣ Desempleado";
    }

    /**
     * Manejar respuesta de situación laboral
     */
    private static function handleEmploymentResponse(BotConversation $conversation, string $response): string
    {
        $responseLower = strtolower(trim($response));
        $employment = null;

        if ($responseLower === '1' || str_contains($responseLower, 'dependiente')) {
            $employment = 'dependiente';
        } elseif ($responseLower === '2' || str_contains($responseLower, 'independiente')) {
            $employment = 'independiente';
        } elseif ($responseLower === '3' || str_contains($responseLower, 'desempleado')) {
            $employment = 'desempleado';
        }

        if (!$employment) {
            return "Por favor selecciona una opción válida:\n"
                . "1️⃣ Trabajo dependiente\n"
                . "2️⃣ Trabajo independiente\n"
                . "3️⃣ Desempleado";
        }

        $conversation->saveResponse('employment', $employment);
        $conversation->update([
            'state' => BotConversation::STATE_WAITING_INCOME,
            'last_interaction_at' => now(),
        ]);

        return "Perfecto! ¿Cuál es tu ingreso mensual aproximado en soles (S/)?\n\nEjemplo: 1500";
    }

    /**
     * Manejar respuesta de ingresos
     */
    private static function handleIncomeResponse(BotConversation $conversation, string $response): string
    {
        // Extraer número de la respuesta (puede incluir comas o puntos)
        $cleanResponse = str_replace([',', ' '], '', $response);
        preg_match('/\d+/', $cleanResponse, $matches);

        if (empty($matches)) {
            return "Por favor ingresa tu ingreso mensual en números. Ejemplo: 1500";
        }

        $income = (int) $matches[0];

        if ($income < 0 || $income > 100000) {
            return "Por favor ingresa un ingreso válido (entre 0 y 100,000 soles).";
        }

        $conversation->saveResponse('income', $income);
        $conversation->update([
            'state' => BotConversation::STATE_WAITING_FAMILY_GROUP,
            'last_interaction_at' => now(),
        ]);

        return "Gracias! ¿Ya tienes un Grupo Familiar formado?\n\n"
            . "1️⃣ Sí\n"
            . "2️⃣ No";
    }

    /**
     * Manejar respuesta de grupo familiar
     */
    private static function handleFamilyGroupResponse(BotConversation $conversation, string $response): string
    {
        $responseLower = strtolower(trim($response));
        $hasFamilyGroup = null;

        if ($responseLower === '1' || $responseLower === 'si' || $responseLower === 'sí') {
            $hasFamilyGroup = true;
        } elseif ($responseLower === '2' || $responseLower === 'no') {
            $hasFamilyGroup = false;
        }

        if ($hasFamilyGroup === null) {
            return "Por favor selecciona una opción válida:\n1️⃣ Sí\n2️⃣ No";
        }

        $conversation->saveResponse('has_family_group', $hasFamilyGroup);
        $conversation->update([
            'state' => BotConversation::STATE_WAITING_FIRST_HOME,
            'last_interaction_at' => now(),
        ]);

        return "Última pregunta! ¿Esta sería tu primera vivienda propia?\n\n"
            . "1️⃣ Sí\n"
            . "2️⃣ No";
    }

    /**
     * Manejar respuesta de primera vivienda y evaluar calificación
     */
    private static function handleFirstHomeResponse(BotConversation $conversation, string $response): string
    {
        $responseLower = strtolower(trim($response));
        $isFirstHome = null;

        if ($responseLower === '1' || $responseLower === 'si' || $responseLower === 'sí') {
            $isFirstHome = true;
        } elseif ($responseLower === '2' || $responseLower === 'no') {
            $isFirstHome = false;
        }

        if ($isFirstHome === null) {
            return "Por favor selecciona una opción válida:\n1️⃣ Sí\n2️⃣ No";
        }

        $conversation->saveResponse('is_first_home', $isFirstHome);

        // Evaluar calificación
        $qualified = self::evaluateQualification($conversation->context);

        if ($qualified) {
            $conversation->update([
                'state' => BotConversation::STATE_QUALIFIED,
                'last_interaction_at' => now(),
            ]);

            return "✅ *¡Excelente noticia!*\n\n"
                . "Según tus respuestas, podrías calificar para el Bono Techo Propio.\n\n"
                . "Un asesor se comunicará contigo pronto para ayudarte con los siguientes pasos.\n\n"
                . "¡Gracias por tu tiempo! 😊";
        } else {
            $conversation->update([
                'state' => BotConversation::STATE_NOT_QUALIFIED,
                'last_interaction_at' => now(),
            ]);

            $reason = self::getNotQualifiedReason($conversation->context);

            return "❌ Lo sentimos, pero según tus respuestas, en este momento no calificas para el Bono Techo Propio.\n\n"
                . $reason . "\n\n"
                . "Te recomendamos revisar los requisitos y volver a intentarlo más adelante.\n\n"
                . "¡Gracias por tu interés! 🏠";
        }
    }

    /**
     * Evaluar si el usuario califica para el bono
     */
    private static function evaluateQualification(array $context): bool
    {
        // Criterios de calificación
        $age = $context['age'] ?? 0;
        $income = $context['income'] ?? 0;
        $isFirstHome = $context['is_first_home'] ?? false;
        $employment = $context['employment'] ?? '';

        // Validaciones
        if ($age < 18 || $age > 65) {
            return false; // Edad fuera de rango
        }

        if ($income > 2689) {
            return false; // Ingresos muy altos
        }

        if (!$isFirstHome) {
            return false; // Debe ser primera vivienda
        }

        if ($employment === 'desempleado' && $income < 500) {
            return false; // Desempleado sin ingresos suficientes
        }

        return true; // Califica!
    }

    /**
     * Obtener razón de no calificación
     */
    private static function getNotQualifiedReason(array $context): string
    {
        $age = $context['age'] ?? 0;
        $income = $context['income'] ?? 0;
        $isFirstHome = $context['is_first_home'] ?? false;

        if ($age < 18 || $age > 65) {
            return "Motivo: La edad debe estar entre 18 y 65 años.";
        }

        if ($income > 2689) {
            return "Motivo: Los ingresos mensuales deben ser menores a S/ 2,689.";
        }

        if (!$isFirstHome) {
            return "Motivo: El bono es solo para primera vivienda.";
        }

        return "Motivo: No cumples con todos los requisitos en este momento.";
    }
}
