<?php

namespace App\Http\Controllers;

use App\Models\User;
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

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales son incorrectas.'],
            ]);
        }

        // Solo generar y guardar api_token si no existe
        if (!$user->api_token) {
            $user->api_token = bin2hex(random_bytes(32));
            $user->save();
        }

        return response()->json([
            'access_token' => $user->api_token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'phone_number_id' => $user->phone_number_id,
                'phone_number_name' => $user->phone_number_name,
            ]
        ]);
    }

    public function logout(Request $request)
    {
        return response()->json(['message' => 'Logged out successfully']);
    }
    
    public function me(Request $request)
    {
        // Get user from token (simplified - in production use middleware)
        $token = $request->bearerToken();
        if (!$token) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $decoded = base64_decode($token);
        $email = explode(':', $decoded)[0] ?? null;
        
        if (!$email) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $user = User::where('email', $email)->first();
        
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'phone_number_id' => $user->phone_number_id,
                'phone_number_name' => $user->phone_number_name,
            ]
        ]);
    }
}
