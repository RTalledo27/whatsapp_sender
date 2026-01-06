<?php

namespace App\Helpers;

class PhoneHelper
{
    /**
     * Normalizar número de teléfono
     * Siempre retorna el número con + al inicio
     * 
     * @param string $phoneNumber
     * @return string
     */
    public static function normalize(string $phoneNumber): string
    {
        // Eliminar espacios, guiones, paréntesis
        $phoneNumber = preg_replace('/[\s\-\(\)]/', '', $phoneNumber);
        
        // Eliminar múltiples + consecutivos
        $phoneNumber = preg_replace('/\++/', '+', $phoneNumber);
        
        // Si no empieza con +, agregarlo
        if (!str_starts_with($phoneNumber, '+')) {
            $phoneNumber = '+' . $phoneNumber;
        }
        
        return $phoneNumber;
    }
    
    /**
     * Verificar si dos números son el mismo (ignorando formato)
     * 
     * @param string $phone1
     * @param string $phone2
     * @return bool
     */
    public static function isSame(string $phone1, string $phone2): bool
    {
        return self::normalize($phone1) === self::normalize($phone2);
    }
}
