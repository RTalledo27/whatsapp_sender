# 🔗 INTEGRACIÓN CON LOGICWARE CRM

## Configuración Completa

### ✅ Archivos Implementados

1. **LogicWareService.php** - Servicio principal para conectar con LogicWare
2. **BotService.php** - Modificado para enviar leads calificados al CRM
3. **SendLeadToCRMJob.php** - Job para procesamiento en cola (opcional)
4. **CRMController.php** - Endpoints para monitoreo y gestión
5. **.env.example** - Variables de entorno actualizadas
6. **routes/api.php** - Rutas de la API del CRM

---

## 📋 Configuración Paso a Paso

### 1. Configurar Variables de Entorno

Edita tu archivo `.env` y agrega:

```bash
# ==========================================
# LOGICWARE CRM INTEGRATION
# ==========================================
LOGICWARE_API_URL=https://gw.logicwareperu.com
LOGICWARE_API_KEY=tu_api_key_aqui
LOGICWARE_SUBDOMAIN=casabonita
LOGICWARE_VERSION=v1.0
LOGICWARE_PORTAL_CODE=WHATSAPP_BOT
LOGICWARE_PROJECT_CODE=CASABONITA
```

### 2. Registrar Servicio en config/services.php

Ya está configurado. Verifica que exista:

```php
'logicware' => [
    'api_url' => env('LOGICWARE_API_URL', 'https://gw.logicwareperu.com'),
    'api_key' => env('LOGICWARE_API_KEY'),
    'subdomain' => env('LOGICWARE_SUBDOMAIN', 'casabonita'),
    'version' => env('LOGICWARE_VERSION', 'v1.0'),
    'portal_code' => env('LOGICWARE_PORTAL_CODE', 'WHATSAPP_BOT'),
    'project_code' => env('LOGICWARE_PROJECT_CODE', 'CASABONITA'),
],
```

### 3. Limpiar Cache de Configuración

```bash
php artisan config:clear
php artisan cache:clear
```

---

## 🚀 Funcionamiento

### Flujo Automático

1. **Usuario inicia conversación** con el bot de WhatsApp
2. **Bot realiza preguntas** de calificación (flows.json)
3. **Si califica:**
   - ✅ Marca conversación como `finished` con `qualified: true`
   - ✅ **Envía automáticamente al CRM de LogicWare**
   - ✅ Guarda `crm_lead_id` en metadata del contacto
   - ✅ Notifica al usuario que será contactado
4. **Si NO califica:**
   - ❌ Marca como `finished` con `qualified: false`
   - ❌ NO se envía al CRM
   - ❌ Mensaje de agradecimiento

### Datos Enviados al CRM

#### Campos Obligatorios:
- `portalCode`: "WHATSAPP_BOT"
- `projectCode`: "CASABONITA"
- `documentType`: 1 (DNI)
- `firstName`: Nombre del contacto
- `phoneNumber`: Teléfono con formato +51XXXXXXXXX

#### Campos Opcionales (si existen):
- `paternalLastname`: Apellido paterno
- `maternalLastname`: Apellido materno
- `email`: Email del contacto
- `comment`: Texto con respuestas del bot

#### Ejemplo de Payload:

```json
{
  "portalCode": "WHATSAPP_BOT",
  "projectCode": "CASABONITA",
  "documentType": 1,
  "firstName": "Juan",
  "paternalLastname": "Pérez",
  "maternalLastname": "García",
  "phoneNumber": "+51946552086",
  "email": "juan@example.com",
  "comment": "🤖 LEAD REACTIVADO - BOT WHATSAPP\n✅ Cliente calificado para Bono Techo Propio\n\n📝 Respuestas del cliente:\n✅ ¿Tiene terreno propio inscrito en Registros Públicos? → Sí\n✅ ¿Tiene carga familiar? → Sí\n✅ ¿Ingreso familiar menor a S/3,715? → Sí\n❌ ¿Recibió apoyo previo del Estado? → No\n\n📅 Fecha de calificación: 07/02/2026 15:30\n📱 Canal: WhatsApp Business API\n🔄 Estado: Lead reactivado automáticamente"
}
```

---

## 📊 Endpoints de Monitoreo

### 1. Estadísticas del CRM

```http
GET /api/crm/stats
```

**Respuesta:**
```json
{
  "success": true,
  "stats": {
    "sent_to_crm": 45,
    "qualified_not_sent": 2,
    "failed_to_send": 1,
    "success_rate": 97.83
  },
  "recently_sent": [
    {
      "id": 123,
      "name": "Juan Pérez García",
      "phone": "+51946552086",
      "email": "juan@example.com",
      "crm_lead_id": "12345",
      "crm_assigned_to": "Asesor A",
      "sent_at": "2026-02-07T15:30:00Z"
    }
  ]
}
```

### 2. Listar Leads con Error

```http
GET /api/crm/failed-leads?per_page=20
```

### 3. Reenviar Lead Manualmente

```http
POST /api/crm/resend/{contact_id}
```

**Respuesta:**
```json
{
  "success": true,
  "message": "Lead encolado para reenvío al CRM",
  "contact": {
    "id": 123,
    "name": "Juan Pérez",
    "phone": "+51946552086"
  }
}
```

### 4. Limpiar Cache del Token (Testing)

```http
POST /api/crm/clear-token-cache
```

---

## 🔍 Verificación de Logs

### Ver logs del servicio:

```bash
tail -f storage/logs/laravel.log | grep -i logicware
```

### Logs importantes:

```
LogicWare: Obtaining new token
LogicWare: Token obtained successfully
LogicWare: Sending qualified lead (contact_id: 123)
LogicWare: Lead created/reactivated successfully (lead_id: 12345)
```

---

## 🐛 Troubleshooting

### Problema 1: "Failed to obtain access token"

**Causa:** Credenciales incorrectas en `.env`

**Solución:**
```bash
# Verifica las variables
php artisan config:show services.logicware

# Limpia cache
php artisan config:clear
php artisan cache:clear
```

### Problema 2: Lead no se envía al CRM

**Causa:** Verificar si ya fue enviado

**Solución:**
```php
// En tinker
$contact = Contact::find(123);
$contact->metadata; // Ver si tiene 'crm_sent' = true

// Forzar reenvío
unset($contact->metadata['crm_sent']);
$contact->save();
```

### Problema 3: Token cache no funciona

**Solución:**
```bash
# Verifica que Redis/Database cache esté funcionando
php artisan cache:clear

# O usa POST /api/crm/clear-token-cache
```

---

## ⚡ Modo Cola (Recomendado para Producción)

Para no bloquear el bot mientras envía al CRM, usa el Job:

### 1. Modificar BotService.php

Reemplaza la línea:
```php
$this->sendQualifiedLeadToCRM($conversation);
```

Por:
```php
dispatch(new \App\Jobs\SendLeadToCRMJob($contact, $conversation));
```

### 2. Configurar Cola

```bash
# .env
QUEUE_CONNECTION=database

# Ejecutar worker
php artisan queue:work --queue=default --tries=3
```

### 3. Monitorear Cola

```bash
# Ver trabajos fallidos
php artisan queue:failed

# Reintentar trabajos fallidos
php artisan queue:retry all
```

---

## 📈 Métricas Recomendadas

### Dashboard de Conversiones

```sql
-- Tasa de calificación
SELECT 
  COUNT(CASE WHEN JSON_EXTRACT(context, '$.qualified') = true THEN 1 END) as qualified,
  COUNT(CASE WHEN JSON_EXTRACT(context, '$.qualified') = false THEN 1 END) as not_qualified,
  COUNT(*) as total
FROM bot_conversations
WHERE state = 'finished';

-- Tasa de envío exitoso al CRM
SELECT 
  COUNT(CASE WHEN JSON_EXTRACT(metadata, '$.crm_sent') = true THEN 1 END) as sent,
  COUNT(CASE WHEN JSON_EXTRACT(metadata, '$.crm_sent') = false OR JSON_EXTRACT(metadata, '$.crm_sent') IS NULL THEN 1 END) as not_sent,
  COUNT(*) as total
FROM contacts
WHERE contact_type = 'lead';
```

---

## 🔒 Seguridad

### 1. Proteger Endpoints del CRM

Agrega middleware de autenticación en `routes/api.php`:

```php
Route::middleware('auth:api')->prefix('crm')->group(function () {
    Route::get('/stats', [CRMController::class, 'stats']);
    // ...
});
```

### 2. Rate Limiting

```php
Route::middleware(['auth:api', 'throttle:60,1'])->prefix('crm')->group(function () {
    // 60 requests por minuto
});
```

---

## ✅ Checklist de Producción

- [ ] Variables de entorno configuradas en `.env`
- [ ] Token de LogicWare válido
- [ ] Config cache limpiada (`php artisan config:clear`)
- [ ] Logs monitoreados
- [ ] Endpoints del CRM protegidos con auth
- [ ] Cola configurada (opcional pero recomendado)
- [ ] Worker ejecutándose (`php artisan queue:work`)
- [ ] Alertas configuradas para fallos
- [ ] Backup de base de datos
- [ ] Pruebas end-to-end realizadas

---

## 📞 Soporte

Si tienes problemas, revisa:

1. `storage/logs/laravel.log` - Logs de la aplicación
2. Endpoint `/api/crm/stats` - Estado del sistema
3. Endpoint `/api/crm/failed-leads` - Leads con error
4. Base de datos: Tabla `bot_conversations` columna `context`

---

## 🎯 Próximos Pasos Sugeridos

1. **Implementar notificaciones**: Email/Slack cuando falla un envío
2. **Dashboard en frontend**: Visualizar estadísticas del CRM
3. **Exportar reportes**: CSV de leads enviados
4. **Webhook inverso**: LogicWare notifica cuando un asesor atiende el lead
5. **A/B Testing**: Diferentes flujos de calificación