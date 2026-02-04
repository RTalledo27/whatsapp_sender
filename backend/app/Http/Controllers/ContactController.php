<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Services\ExcelImportService;
use App\Helpers\PhoneHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ContactController extends Controller
{
    public function __construct(
        private ExcelImportService $excelImportService
    ) {}

    /**
     * Listar todos los contactos
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 50);
        $search = $request->input('search', '');
        $contactType = $request->input('contact_type'); // 'lead', 'client', o null (todos)
        $user = $request->user();

        $query = Contact::query();

        // Aplicar filtros de permisos según rol y usuario (solo si está autenticado)
        if ($user) {
            if ($user->role === 'admin') {
                // Admin puede ver todos, pero puede filtrar por tipo
                if ($contactType) {
                    $query->where('contact_type', $contactType);
                }
            } elseif ($user->phone_number_id === config('services.whatsapp.leads_bot_id')) {
                // Usuario Leads: solo ve leads
                $query->where('contact_type', 'lead');
            } else {
                // Usuarios normales: solo ven clientes
                $query->where('contact_type', 'client');
            }
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('phone_number', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $contacts = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($contacts);
    }

    /**
     * Importar contactos desde Excel
     */
    public function importExcel(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
            'contact_type' => 'nullable|in:lead,client',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Determinar tipo de contacto
        $contactType = $request->input('contact_type', 'client');
        
        // Si no es admin, forzar tipo según usuario
        if ($user && $user->role !== 'admin') {
            if ($user->phone_number_id === config('services.whatsapp.leads_bot_id')) {
                $contactType = 'lead';
            } else {
                $contactType = 'client';
            }
        }

        $file = $request->file('file');
        $result = $this->excelImportService->importContacts($file->getPathname(), $contactType);

        return response()->json($result);
    }

    /**
     * Crear contacto manualmente
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string',
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'contact_type' => 'nullable|in:lead,client',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Normalizar el número de teléfono antes de crear
        $data = $request->only(['phone_number', 'name', 'email', 'contact_type']);
        $data['phone_number'] = PhoneHelper::normalize($data['phone_number']);

        // Determinar tipo de contacto según usuario si no se especificó
        if (!isset($data['contact_type'])) {
            if ($user && $user->role === 'admin') {
                // Admin debe especificar el tipo, por defecto 'client'
                $data['contact_type'] = 'client';
            } elseif ($user && $user->phone_number_id === config('services.whatsapp.leads_bot_id')) {
                // Usuario Leads siempre crea leads
                $data['contact_type'] = 'lead';
            } else {
                // Usuarios normales crean clientes
                $data['contact_type'] = 'client';
            }
        } else {
            // Si se especificó, validar permisos
            if ($user && $user->role !== 'admin') {
                // Solo admin puede elegir libremente el tipo
                if ($user->phone_number_id === config('services.whatsapp.leads_bot_id')) {
                    $data['contact_type'] = 'lead';
                } else {
                    $data['contact_type'] = 'client';
                }
            }
        }

        // Verificar si el contacto ya existe
        $existingContact = Contact::where('phone_number', $data['phone_number'])->first();
        
        if ($existingContact) {
            // Regla: No permitir cambiar de 'client' a 'lead'
            if ($existingContact->contact_type === 'client' && $data['contact_type'] === 'lead') {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede cambiar un cliente existente a lead',
                ], 422);
            }
            
            // Si es de lead a client o mismo tipo, actualizar
            $existingContact->update($data);
            return response()->json([
                'success' => true,
                'contact' => $existingContact,
            ], 200);
        }

        $contact = Contact::create($data);

        return response()->json([
            'success' => true,
            'contact' => $contact,
        ], 201);
    }

    /**
     * Actualizar contacto
     */
    public function update(Request $request, Contact $contact): JsonResponse
    {
        $user = $request->user();
        
        $validator = Validator::make($request->all(), [
            'phone_number' => 'sometimes|required|string',
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'contact_type' => 'nullable|in:lead,client',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Normalizar el número de teléfono si se está actualizando
        $data = $request->only(['phone_number', 'name', 'email', 'contact_type']);
        if (isset($data['phone_number'])) {
            $data['phone_number'] = PhoneHelper::normalize($data['phone_number']);
        }

        // Solo admin puede cambiar el tipo de contacto
        if (isset($data['contact_type'])) {
            if (!$user || $user->role !== 'admin') {
                unset($data['contact_type']);
            } else {
                // Regla: No permitir cambiar de 'client' a 'lead'
                if ($contact->contact_type === 'client' && $data['contact_type'] === 'lead') {
                    return response()->json([
                        'success' => false,
                        'message' => 'No se puede cambiar un cliente a lead',
                    ], 422);
                }
            }
        }

        $contact->update($data);

        return response()->json([
            'success' => true,
            'contact' => $contact,
        ]);
    }

    /**
     * Eliminar contacto
     */
    public function destroy(Contact $contact): JsonResponse
    {
        $contact->delete();

        return response()->json([
            'success' => true,
            'message' => 'Contacto eliminado exitosamente',
        ]);
    }

    /**
     * Obtener formato de ejemplo de Excel
     */
    public function getExcelFormat(): JsonResponse
    {
        $format = $this->excelImportService->getExampleFormat();
        return response()->json($format);
    }

    /**
     * Obtener contactos desde Excel para selección en campaña
     */
    public function getContactsFromExcel(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $file = $request->file('file');
        $result = $this->excelImportService->getContactsFromExcel($file->getPathname());

        return response()->json($result);
    }
}
