# Sistema de Templates de WhatsApp 📱✨

## ¿Qué se implementó?

### 🎯 Características Principales

1. **Selección de Templates de Meta**
   - El sistema ahora puede obtener tus templates aprobados desde Meta
   - Los templates se muestran en un dropdown al crear una campaña
   - Puedes ver una vista previa del template antes de enviar

2. **Parámetros Dinámicos**
   - Si tu template tiene parámetros ({{1}}, {{2}}, etc.), el sistema los detecta automáticamente
   - Te muestra campos para llenar cada parámetro
   - Los valores se envían correctamente a WhatsApp

3. **Modo Dual: Template o Texto**
   - **Modo Template**: Recomendado para nuevos contactos o campañas masivas
   - **Modo Texto**: Para contactos que ya han conversado contigo en las últimas 24 horas

### ⚠️ Importante: Business Account ID

Para que el sistema pueda obtener tus templates, necesitas agregar tu **WhatsApp Business Account ID** al archivo `.env`:

1. Ve a [Meta Business Manager](https://business.facebook.com/)
2. Selecciona tu cuenta de negocio
3. Ve a **WhatsApp Accounts** en el menú lateral
4. Copia el **Account ID** (no es lo mismo que Phone Number ID)
5. Agrégalo al archivo `backend/.env`:

```env
WHATSAPP_BUSINESS_ACCOUNT_ID=tu_business_account_id_aqui
```

### 🚀 Cómo Usar Templates

#### Paso 1: Crear Templates en Meta
1. Ve a [Meta Business Manager](https://business.facebook.com/)
2. Selecciona **WhatsApp Manager**
3. Ve a **Message Templates**
4. Crea tus templates y espera la aprobación de Meta

**Ejemplo de Template:**
```
Nombre: promocion_navidad
Categoría: MARKETING
Idioma: Spanish (es)

Contenido:
Hola! 🎄 Tenemos una promoción especial para ti.
Descuento del {{1}}% en {{2}}.
Válido hasta {{3}}.
```

#### Paso 2: Usar el Template en el Sistema
1. Abre la interfaz web
2. Ve a **Campañas** → **Nueva Campaña**
3. Marca el checkbox **"Usar Template de WhatsApp"**
4. Selecciona tu template del dropdown
5. Llena los parámetros:
   - Parámetro 1: 30
   - Parámetro 2: todos los productos
   - Parámetro 3: 31 de diciembre
6. Selecciona los contactos
7. ¡Envía!

### 🔧 Cambios Técnicos Realizados

#### Backend (Laravel)
- ✅ Migración para agregar `template_name` y `template_parameters` a la tabla `campaigns`
- ✅ Actualizado `Campaign` model con nuevos campos
- ✅ Método `getTemplates()` en `WhatsAppService` para obtener templates de Meta
- ✅ Método `sendMessage()` actualizado para soportar templates
- ✅ `TemplateController` para exponer endpoint `/api/templates`
- ✅ `SendWhatsAppMessageJob` actualizado para enviar con templates
- ✅ `CampaignController` validación flexible para templates o texto

#### Frontend (Angular)
- ✅ Nuevo `TemplateService` para obtener templates
- ✅ Componente de campañas actualizado con:
  - Toggle para elegir entre template o texto
  - Dropdown de templates
  - Vista previa del template
  - Campos dinámicos para parámetros
  - Alertas informativas sobre limitaciones de WhatsApp

### 📊 Estructura de Datos

**Template en Meta:**
```json
{
  "name": "promocion_navidad",
  "language": "es",
  "status": "APPROVED",
  "category": "MARKETING",
  "components": [
    {
      "type": "BODY",
      "text": "Hola! Tenemos {{1}} con {{2}}% de descuento."
    }
  ]
}
```

**Campaña con Template:**
```json
{
  "name": "Campaña Navidad 2024",
  "template_name": "promocion_navidad",
  "template_parameters": ["productos seleccionados", "30"],
  "contact_ids": [1, 2, 3]
}
```

### 🎨 Interfaz de Usuario

La interfaz ahora muestra:
- ✅ Checkbox para activar modo template
- ✅ Dropdown con templates disponibles (solo APPROVED)
- ✅ Vista previa del contenido del template
- ✅ Campos dinámicos para cada parámetro {{1}}, {{2}}, etc.
- ✅ Alerta de advertencia cuando se usa texto simple
- ✅ Validación: no permite enviar si faltan parámetros

### 🔒 Reglas de WhatsApp Business API

**Mensajes de Texto Simple:**
- ✅ Solo para contactos que te escribieron en las últimas 24 horas
- ❌ NO funcionan para nuevos contactos
- ❌ NO funcionan para campañas masivas a contactos fríos

**Templates Aprobados:**
- ✅ Funcionan para TODOS los contactos
- ✅ No requieren conversación previa
- ✅ Ideales para campañas masivas
- ⚠️ Requieren aprobación de Meta (24-48 horas)
- ⚠️ Categorías: UTILITY, MARKETING, AUTHENTICATION

### 📝 Endpoints Nuevos

**GET /api/templates**
- Obtiene todos los templates aprobados de tu cuenta
- Respuesta:
```json
{
  "templates": [
    {
      "id": "123456789",
      "name": "welcome_message",
      "language": "es",
      "status": "APPROVED",
      "category": "UTILITY",
      "components": [...]
    }
  ]
}
```

### 🛠️ Testing

1. **Verificar que el servidor esté corriendo:**
   ```bash
   cd backend
   php artisan serve
   ```

2. **Probar endpoint de templates:**
   ```bash
   curl http://localhost:8000/api/templates
   ```

3. **Abrir frontend:**
   ```bash
   cd frontend
   ng serve
   ```
   Visita: http://localhost:4200

### 📋 Checklist de Configuración

- [ ] Agregar `WHATSAPP_BUSINESS_ACCOUNT_ID` al `.env`
- [ ] Reiniciar el servidor Laravel
- [ ] Crear al menos un template en Meta Business Manager
- [ ] Esperar aprobación del template (APPROVED status)
- [ ] Probar creando una campaña con template
- [ ] Verificar que los parámetros se llenen correctamente

### 💡 Tips

1. **Templates deben estar APROBADOS** - No aparecerán en el sistema si están PENDING o REJECTED
2. **Business Account ID ≠ Phone Number ID** - Son diferentes, asegúrate de usar el correcto
3. **Los parámetros se numeran desde 1** - {{1}}, {{2}}, {{3}}, etc.
4. **Usa templates para campañas masivas** - Es la forma correcta según políticas de WhatsApp
5. **Los templates tienen categorías** - MARKETING tiene restricciones de frecuencia

### 🐛 Troubleshooting

**"No templates available"**
- Verifica que `WHATSAPP_BUSINESS_ACCOUNT_ID` esté configurado
- Verifica que tengas templates con status APPROVED
- Revisa los logs en `storage/logs/laravel.log`

**"Template not found" al enviar**
- El template puede haber sido rechazado o eliminado
- Verifica el nombre exacto en Meta Business Manager

**Mensajes no se envían**
- Inicia el queue worker: `php artisan queue:work`
- Revisa `messages` table para ver errores
- Verifica logs de Laravel

### 🚀 Siguiente Paso

¡Todo está listo! Solo necesitas:
1. Agregar tu `WHATSAPP_BUSINESS_ACCOUNT_ID` al `.env`
2. Reiniciar el servidor
3. Crear tu primera campaña con templates

¡A enviar masivamente con templates de WhatsApp! 🎉
