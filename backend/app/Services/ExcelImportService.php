<?php

namespace App\Services;

use App\Models\Contact;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\Log;

class ExcelImportService
{
    /**
     * Importar contactos desde un archivo Excel
     */
    public function importContacts(string $filePath): array
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

                    $phoneNumber = $this->cleanPhoneNumber($row[0]);
                    
                    if (empty($phoneNumber)) {
                        $failed++;
                        $errors[] = "Fila " . ($index + 1) . ": Número de teléfono inválido";
                        continue;
                    }

                    $contact = Contact::updateOrCreate(
                        ['phone_number' => $phoneNumber],
                        [
                            'name' => $row[1] ?? null,
                            'email' => $row[2] ?? null,
                            'metadata' => [
                                'column_3' => $row[3] ?? null,
                                'column_4' => $row[4] ?? null,
                                'column_5' => $row[5] ?? null,
                            ],
                        ]
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
     * Limpiar número de teléfono
     */
    private function cleanPhoneNumber(string $phoneNumber): string
    {
        // Remover espacios, guiones, paréntesis
        $cleaned = preg_replace('/[^0-9+]/', '', $phoneNumber);

        // Si no empieza con +, agregar + 
        if (!str_starts_with($cleaned, '+')) {
            $cleaned = '+' . $cleaned;
        }

        return $cleaned;
    }

    /**
     * Obtener ejemplo de formato de Excel
     */
    public function getExampleFormat(): array
    {
        return [
            'headers' => ['Teléfono', 'Nombre', 'Email'],
            'example_rows' => [
                ['+1234567890', 'Juan Pérez', 'juan@example.com'],
                ['+0987654321', 'María García', 'maria@example.com'],
            ],
            'notes' => [
                'La primera columna debe contener el número de teléfono con código de país',
                'Las columnas adicionales son opcionales',
                'El sistema detecta automáticamente si la primera fila es un encabezado',
            ],
        ];
    }
}
