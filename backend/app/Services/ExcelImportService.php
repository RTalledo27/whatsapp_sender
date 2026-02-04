<?php

namespace App\Services;

use App\Models\Contact;
use App\Helpers\PhoneHelper;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\Log;

class ExcelImportService
{
    /**
     * Importar contactos desde un archivo Excel
     */
    public function importContacts(string $filePath, string $contactType = 'client'): array
    {
        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();

            $imported = 0;
            $failed = 0;
            $contacts = [];
            $errors = [];

            // Saltar la primera fila si contiene headers
            $hasHeader = $this->detectHeader($rows[0] ?? []);
            $startRow = $hasHeader ? 1 : 0;

            foreach (array_slice($rows, $startRow) as $index => $row) {
                try {
                    // Esperamos al menos el número de teléfono
                    if (empty($row[0])) {
                        continue;
                    }

                    // Normalizar número de teléfono usando el helper
                    $phoneNumber = PhoneHelper::normalize(trim($row[0]));
                    
                    if (empty($phoneNumber)) {
                        $failed++;
                        $errors[] = "Fila " . ($index + 1) . ": Número de teléfono inválido";
                        continue;
                    }

                    // Verificar si el contacto ya existe
                    $existingContact = Contact::where('phone_number', $phoneNumber)->first();
                    
                    // Regla: No permitir cambiar de 'client' a 'lead'
                    if ($existingContact && $existingContact->contact_type === 'client' && $contactType === 'lead') {
                        $failed++;
                        $errors[] = "Fila " . ($index + 1) . ": No se puede cambiar un cliente a lead (" . $phoneNumber . ")";
                        continue;
                    }

                    // Solo actualizar campos que tienen datos en el Excel
                    // Si están vacíos, mantener los valores existentes
                    $updateData = [
                        'contact_type' => $contactType // Asignar tipo según usuario
                    ];
                    
                    if (!empty($row[1])) {
                        $updateData['name'] = $row[1];
                    }
                    
                    if (!empty($row[2])) {
                        $updateData['email'] = $row[2];
                    }
                    
                    // Solo actualizar metadata si hay al menos un valor
                    $metadata = [];
                    if (!empty($row[3])) $metadata['column_3'] = $row[3];
                    if (!empty($row[4])) $metadata['column_4'] = $row[4];
                    if (!empty($row[5])) $metadata['column_5'] = $row[5];
                    
                    if (!empty($metadata)) {
                        $updateData['metadata'] = $metadata;
                    }

                    $contact = Contact::updateOrCreate(
                        ['phone_number' => $phoneNumber],
                        $updateData
                    );

                    $contacts[] = $contact;
                    $imported++;

                } catch (\Exception $e) {
                    $failed++;
                    $errors[] = "Fila " . ($index + 1) . ": " . $e->getMessage();
                    Log::error('Error importing contact', [
                        'row' => $index + 1,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return [
                'success' => true,
                'imported' => $imported,
                'failed' => $failed,
                'contacts' => $contacts,
                'errors' => $errors,
            ];

        } catch (\Exception $e) {
            Log::error('Error importing Excel file', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Detectar si la primera fila es un header
     */
    private function detectHeader(array $row): bool
    {
        if (empty($row)) {
            return false;
        }

        $headerKeywords = ['telefono', 'phone', 'número', 'nombre', 'name', 'email', 'correo'];
        
        foreach ($row as $cell) {
            $cellLower = strtolower((string)$cell);
            foreach ($headerKeywords as $keyword) {
                if (str_contains($cellLower, $keyword)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Obtener contactos existentes desde un archivo Excel
     * (para selección en campañas sin crear nuevos contactos)
     */
    public function getContactsFromExcel(string $filePath): array
    {
        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();

            $phoneNumbers = [];
            $notFound = [];

            // Saltar la primera fila si contiene headers
            $hasHeader = $this->detectHeader($rows[0] ?? []);
            $startRow = $hasHeader ? 1 : 0;

            foreach (array_slice($rows, $startRow) as $index => $row) {
                if (empty($row[0])) {
                    continue;
                }

                // Normalizar número de teléfono
                $phoneNumber = PhoneHelper::normalize(trim($row[0]));
                
                if (!empty($phoneNumber)) {
                    $phoneNumbers[] = $phoneNumber;
                }
            }

            // Buscar contactos existentes
            $contacts = Contact::whereIn('phone_number', $phoneNumbers)->get();
            
            // Determinar cuáles no fueron encontrados
            $foundNumbers = $contacts->pluck('phone_number')->toArray();
            $notFound = array_diff($phoneNumbers, $foundNumbers);

            return [
                'success' => true,
                'contacts' => $contacts,
                'total_in_excel' => count($phoneNumbers),
                'found' => count($contacts),
                'not_found' => count($notFound),
                'not_found_numbers' => array_values($notFound),
            ];

        } catch (\Exception $e) {
            Log::error('Error getting contacts from Excel file', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
