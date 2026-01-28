<?php

namespace App\Http\Controllers;

use App\Models\Note;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NoteController extends Controller
{
    // Listar todas las notas del usuario autenticado
    public function index(Request $request)
    {
        $user = Auth::user();
        $notes = Note::where('user_id', $user->id)->with('client')->orderBy('created_at', 'desc')->get();
        return response()->json($notes);
    }

    // Guardar una nueva nota
    public function store(Request $request)
    {
        $user = Auth::user();
        $validated = $request->validate([
            'client_id' => 'required|exists:contacts,id',
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'tag' => 'nullable|string|max:255',
        ]);
        $note = Note::create([
            'user_id' => $user->id,
            'client_id' => $validated['client_id'],
            'title' => $validated['title'],
            'content' => $validated['content'],
            'tag' => $validated['tag'] ?? null,
        ]);
        return response()->json($note, 201);
    }

    // Mostrar una nota específica
    public function show($id)
    {
        $user = Auth::user();
        $note = Note::where('user_id', $user->id)->where('id', $id)->with('client')->firstOrFail();
        return response()->json($note);
    }
}
