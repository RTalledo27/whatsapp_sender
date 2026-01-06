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

        $query = Contact::query();

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
        $result = $this->excelImportService->importContacts($file->getPathname());

        return response()->json($result);
    }

    /**
     * Crear contacto manualmente
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string',
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Normalizar el número de teléfono antes de crear
        $data = $request->only(['phone_number', 'name', 'email']);
        $data['phone_number'] = PhoneHelper::normalize($data['phone_number']);

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
        $validator = Validator::make($request->all(), [
            'phone_number' => 'sometimes|required|string',
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Normalizar el número de teléfono si se está actualizando
        $data = $request->only(['phone_number', 'name', 'email']);
        if (isset($data['phone_number'])) {
            $data['phone_number'] = PhoneHelper::normalize($data['phone_number']);
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
