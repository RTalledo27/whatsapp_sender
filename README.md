# 📱 WhatsApp Sender - Sistema de Envío Masivo

Sistema profesional para envío masivo de mensajes WhatsApp usando Meta WhatsApp Business API.

## 🚀 Características

- ✅ **Frontend Angular** con interfaz moderna y responsive
- ✅ **Backend Laravel** con API RESTful
- ✅ **Importación desde Excel** (.xlsx, .xls, .csv)
- ✅ **Integración con WhatsApp Business API** (Meta)
- ✅ **Sistema de colas** para envío asíncrono
- ✅ **Estadísticas completas** (enviados, fallidos, pendientes)
- ✅ **Gestión de contactos** (CRUD completo)
- ✅ **Gestión de campañas** con tracking en tiempo real
- ✅ **Reintento automático** de mensajes fallidos
- ✅ **Dashboard con métricas** y gráficos

## 📋 Requisitos

### Backend
- PHP >= 8.1
- Composer
- MySQL >= 8.0
- Laravel 10

### Frontend
- Node.js >= 18
- npm >= 9
- Angular CLI

## 🔧 Instalación

### 1. Backend (Laravel)

```bash
cd backend

# Instalar dependencias
composer install

# Copiar archivo de configuración
copy .env.example .env

# Generar key de la aplicación
php artisan key:generate

# Configurar base de datos en .env
# DB_DATABASE=whatsapp_sender
# DB_USERNAME=root
# DB_PASSWORD=

# Configurar WhatsApp Business API en .env
# WHATSAPP_ACCESS_TOKEN=tu_token_aqui
# WHATSAPP_PHONE_NUMBER_ID=tu_phone_id_aqui

# Crear base de datos
mysql -u root -p -e "CREATE DATABASE whatsapp_sender"

# Ejecutar migraciones
php artisan migrate

# Iniciar servidor
php artisan serve
```

### 2. Frontend (Angular)

```bash
cd frontend

# Instalar dependencias
npm install

# Iniciar servidor de desarrollo
npm start
```

La aplicación estará disponible en:
- **Frontend**: http://localhost:4200
- **Backend API**: http://localhost:8000/api

### 3. Configurar Queue Worker (Importante)

Para que los mensajes se envíen, debes ejecutar el queue worker:

```bash
cd backend
php artisan queue:work
```

**Recomendación para producción**: Usa Supervisor o similar para mantener el queue worker ejecutándose.

## 📁 Formato de Excel para Importación

El archivo Excel debe tener las siguientes columnas:

| Teléfono      | Nombre (opcional) | Email (opcional) |
|---------------|-------------------|------------------|
| +1234567890   | Juan Pérez        | juan@example.com |
| +0987654321   | María García      | maria@example.com|

**Notas importantes:**
- La primera columna DEBE ser el número de teléfono con código de país
- El sistema detecta automáticamente si la primera fila es un encabezado
- Las columnas adicionales son opcionales

## 🔑 Configuración de WhatsApp Business API

### Variables necesarias en .env:

```env
WHATSAPP_API_VERSION=v18.0
WHATSAPP_ACCESS_TOKEN=tu_access_token_aqui
WHATSAPP_PHONE_NUMBER_ID=tu_phone_number_id_aqui
WHATSAPP_API_URL=https://graph.facebook.com
```

### Obtener credenciales:

1. Ve a [Meta for Developers](https://developers.facebook.com/)
2. Crea una app de tipo "Business"
3. Activa WhatsApp Business API
4. Obtén tu **Access Token** (User Access Token proporcionado)
5. Obtén tu **Phone Number ID** desde la configuración de WhatsApp

## 📊 Estructura del Proyecto

### Backend (Laravel)
```
backend/
├── app/
│   ├── Http/Controllers/
│   │   ├── ContactController.php      # Gestión de contactos
│   │   ├── CampaignController.php     # Gestión de campañas
│   │   └── StatisticsController.php   # Estadísticas
│   ├── Models/
│   │   ├── Contact.php                # Modelo de contacto
│   │   ├── Campaign.php               # Modelo de campaña
│   │   └── Message.php                # Modelo de mensaje
│   ├── Services/
│   │   ├── WhatsAppService.php        # Integración WhatsApp API
│   │   └── ExcelImportService.php     # Importación Excel
│   └── Jobs/
│       └── SendWhatsAppMessageJob.php # Job para envío asíncrono
├── database/migrations/               # Migraciones de BD
└── routes/api.php                     # Rutas de la API
```

### Frontend (Angular)
```
frontend/
└── src/app/
    ├── components/
    │   ├── dashboard/                 # Dashboard principal
    │   ├── contacts/                  # Gestión de contactos
    │   └── campaigns/                 # Gestión de campañas
    └── services/
        ├── contact.service.ts         # Servicio de contactos
        ├── campaign.service.ts        # Servicio de campañas
        └── statistics.service.ts      # Servicio de estadísticas
```

## 🔄 Flujo de Trabajo

1. **Importar Contactos**: Sube un archivo Excel con los contactos
2. **Crear Campaña**: Define el mensaje y selecciona los contactos
3. **Envío Automático**: Los mensajes se envían automáticamente en segundo plano
4. **Monitoreo**: Ve el estado en tiempo real en el dashboard
5. **Reintentos**: Reintenta mensajes fallidos si es necesario

## 📡 API Endpoints

### Contactos
- `GET /api/contacts` - Listar contactos
- `POST /api/contacts` - Crear contacto
- `PUT /api/contacts/{id}` - Actualizar contacto
- `DELETE /api/contacts/{id}` - Eliminar contacto
- `POST /api/contacts/import-excel` - Importar desde Excel

### Campañas
- `GET /api/campaigns` - Listar campañas
- `POST /api/campaigns` - Crear campaña y enviar mensajes
- `GET /api/campaigns/{id}` - Ver detalles de campaña
- `DELETE /api/campaigns/{id}` - Eliminar campaña
- `GET /api/campaigns/{id}/statistics` - Estadísticas de campaña
- `POST /api/campaigns/{id}/retry-failed` - Reintentar mensajes fallidos

### Estadísticas
- `GET /api/statistics` - Estadísticas generales
- `GET /api/statistics/export` - Exportar estadísticas

## 🔒 Seguridad

- Configura CORS adecuadamente en producción
- Nunca compartas tu Access Token
- Usa HTTPS en producción
- Implementa autenticación (Laravel Sanctum recomendado)

## 🐛 Solución de Problemas

### Los mensajes no se envían
- Verifica que el queue worker esté ejecutándose: `php artisan queue:work`
- Revisa los logs en `storage/logs/laravel.log`

### Error de autenticación con WhatsApp API
- Verifica que tu Access Token sea válido
- Confirma que el Phone Number ID sea correcto
- Asegúrate de estar usando el API version correcto (v18.0)

### Error al importar Excel
- Verifica que el archivo tenga el formato correcto
- Asegúrate de que los números tengan código de país
- Revisa que el archivo no supere 10MB

## 📝 Notas Importantes

- **Rate Limits**: WhatsApp Business API tiene límites de envío. Verifica tu tier.
- **Queue Driver**: Por defecto usa 'database'. Para producción considera Redis.
- **Números de teléfono**: DEBEN incluir código de país con formato internacional (+XX)

## 🎯 Próximas Mejoras

- [ ] Autenticación de usuarios
- [ ] Envío programado de campañas
- [ ] Templates de mensajes
- [ ] Soporte para envío de imágenes/archivos
- [ ] Webhooks para estados de mensajes
- [ ] Exportación de reportes en Excel/PDF

## 👨‍💻 Desarrollo

Creado con ❤️ usando:
- Laravel 10
- Angular 17
- WhatsApp Business API (Meta)
- TailwindCSS-inspired styles

## 📄 Licencia

Este proyecto es de uso libre para fines educativos y comerciales.

---

**¿Necesitas ayuda?** Revisa la documentación oficial:
- [Laravel](https://laravel.com/docs)
- [Angular](https://angular.io/docs)
- [WhatsApp Business API](https://developers.facebook.com/docs/whatsapp)
