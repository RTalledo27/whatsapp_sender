<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Simple hardcoded user for now - you can change this to use database
        $validEmail = env('ADMIN_EMAIL', 'admin@whatsapp.com');
        $validPassword = env('ADMIN_PASSWORD', 'admin123');

        if ($request->email !== $validEmail || $request->password !== $validPassword) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales son incorrectas.'],
            ]);
        }

        // Generate a simple token (in production, use Laravel Sanctum)
        $token = base64_encode($validEmail . ':' . now()->timestamp);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => 1,
                'name' => 'Administrador',
                'email' => $validEmail,
            ]
        ]);
    }

    public function logout(Request $request)
    {
        return response()->json(['message' => 'Logged out successfully']);
    }
}
