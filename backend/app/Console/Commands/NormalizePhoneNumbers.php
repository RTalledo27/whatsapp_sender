<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\Message;
use App\Helpers\PhoneHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NormalizePhoneNumbers extends Command
{
    protected $signature = 'contacts:normalize-phones';
    protected $description = 'Normalizar números de teléfono de contactos (agregar + si falta) y fusionar duplicados';

    public function handle()
    {
        $this->info('Iniciando normalización de números de teléfono...');

        DB::beginTransaction();

        try {
            // Obtener todos los contactos
            $contacts = Contact::all();
            $normalized = 0;
            $merged = 0;

            foreach ($contacts as $contact) {
                $originalPhone = $contact->phone_number;
                $normalizedPhone = PhoneHelper::normalize($originalPhone);

                // Si el número cambió después de normalizar
                if ($originalPhone !== $normalizedPhone) {
                    // Buscar si ya existe un contacto con el número normalizado
                    $existing = Contact::where('phone_number', $normalizedPhone)
                        ->where('id', '!=', $contact->id)
                        ->first();

                    if ($existing) {
                        // Fusionar: mover todos los mensajes del duplicado al contacto existente
                        Message::where('contact_id', $contact->id)
                            ->update(['contact_id' => $existing->id]);

                        // Si el duplicado tiene nombre y el existente no, copiar el nombre
                        if (!empty($contact->name) && empty($existing->name)) {
                            $existing->update(['name' => $contact->name]);
                        }

                        // Eliminar el duplicado
                        $contact->delete();
                        $merged++;

                        $this->info("Fusionado: {$originalPhone} → {$normalizedPhone} (contacto #{$existing->id})");
                    } else {
                        // Solo normalizar el número
                        $contact->update(['phone_number' => $normalizedPhone]);
                        $normalized++;

                        $this->info("Normalizado: {$originalPhone} → {$normalizedPhone}");
                    }
                }
            }

            DB::commit();

            $this->info("\n✅ Proceso completado:");
            $this->info("   - {$normalized} números normalizados");
            $this->info("   - {$merged} contactos duplicados fusionados");

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Error: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
