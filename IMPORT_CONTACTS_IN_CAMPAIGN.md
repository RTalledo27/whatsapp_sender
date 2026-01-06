# 📁 Importar Contactos en Campañas desde Excel

## 🎯 Funcionalidad

Esta nueva funcionalidad permite importar un archivo Excel para seleccionar automáticamente contactos al crear una nueva campaña, sin necesidad de seleccionar uno por uno cuando tienes muchos contactos.

## 📋 Casos de Uso

Imagina que tienes 700 contactos en tu base de datos, pero necesitas enviar un mensaje masivo solo a 100 contactos específicos. En lugar de buscar y seleccionar manualmente cada uno de los 100 contactos, ahora puedes:

1. Crear un archivo Excel con los teléfonos de esos 100 contactos
2. Importar el Excel durante la creación de la campaña
3. Los contactos se seleccionan automáticamente

## 🚀 Cómo Usar

### Paso 1: Preparar el Archivo Excel

Crea un archivo Excel con la misma estructura que usas para importar contactos:

| Teléfono      | Nombre (opcional) | Email (opcional) |
|---------------|-------------------|------------------|
| +51959348500  | Juan Pérez        | juan@example.com |
| +51987654321  | María García      | maria@example.com|
| +51912345678  | Pedro López       |                  |

**Importante:**
- La primera columna DEBE contener el número de teléfono con código de país
- Solo se seleccionarán los contactos que ya existen en tu base de datos
- Los contactos que no estén registrados se mostrarán en un reporte

### Paso 2: Crear una Nueva Campaña

1. Ve a la sección de **Campañas**
2. Haz clic en **➕ Nueva Campaña**
3. Completa el nombre de la campaña y el mensaje o template

### Paso 3: Importar Contactos desde Excel

En la sección **Seleccionar Contactos**:

1. Haz clic en el botón **📁 Importar desde Excel** (ubicado junto a "Seleccionar todos" y "Deseleccionar todos")
2. Se abrirá un modal de importación
3. Selecciona tu archivo Excel (.xlsx, .xls, o .csv)
4. Haz clic en **Importar y Seleccionar**

### Paso 4: Revisar Resultados

El sistema mostrará:
- ✅ **Contactos encontrados**: Cuántos de los números del Excel existen en tu base de datos
- ⚠️ **Contactos no encontrados**: Números que están en el Excel pero no en tu base de datos
- Lista de números no encontrados (si aplica)

Los contactos encontrados se agregarán automáticamente a la selección de la campaña.

## 📊 Ejemplo de Resultado

```
✅ Importación exitosa:
98 contactos encontrados de 100 en el Excel
2 números no están registrados en tus contactos

Números no encontrados:
• +51999999999
• +51888888888
```

## 🔄 Flujo Completo

1. **Importar Contactos Generales** (opcional)
   - Importa todos tus contactos a la base de datos
   - Esto solo se hace una vez

2. **Crear Campaña con Selección Masiva**
   - Prepara un Excel con los contactos específicos para esta campaña
   - Importa el Excel en el modal de creación de campaña
   - Los contactos se seleccionan automáticamente
   - Envía la campaña

## ⚙️ Implementación Técnica

### Backend

- **Endpoint**: `POST /api/contacts/get-from-excel`
- **Controlador**: `ContactController@getContactsFromExcel`
- **Servicio**: `ExcelImportService@getContactsFromExcel`

### Frontend

- **Componente**: `CampaignsComponent`
- **Servicio**: `ContactService@getContactsFromExcel`
- **Modal**: `showImportContactsModal`

### Respuesta del API

```json
{
  "success": true,
  "contacts": [...],
  "total_in_excel": 100,
  "found": 98,
  "not_found": 2,
  "not_found_numbers": ["+51999999999", "+51888888888"]
}
```

## 🎨 Características

- ✅ Detecta automáticamente si la primera fila es un encabezado
- ✅ Normaliza los números de teléfono
- ✅ Solo selecciona contactos que ya existen en la base de datos
- ✅ Muestra un reporte detallado de la importación
- ✅ No crea contactos nuevos (solo selecciona existentes)
- ✅ Cierra el modal automáticamente después de una importación exitosa
- ✅ Muestra los números no encontrados para que puedas agregarlos manualmente

## 💡 Consejos

1. **Antes de crear una campaña**, asegúrate de que todos los contactos que quieres incluir ya estén importados en la base de datos
2. **Usa la funcionalidad de importación general** primero si tienes contactos nuevos
3. **Revisa el reporte** de números no encontrados para identificar contactos que necesitas agregar
4. **Combina métodos**: Puedes importar desde Excel y luego agregar o quitar contactos manualmente

## 🔍 Diferencias con Importación de Contactos

| Característica | Importar Contactos | Importar en Campaña |
|----------------|-------------------|---------------------|
| **Ubicación** | Sección Contactos | Modal Nueva Campaña |
| **Propósito** | Agregar nuevos contactos a la BD | Seleccionar contactos existentes |
| **Acción** | Crea/actualiza contactos | Solo selecciona contactos |
| **Resultado** | Contactos en la BD | Contactos seleccionados para campaña |

## 📝 Notas

- Los archivos Excel deben ser menores a 10MB
- Formatos soportados: .xlsx, .xls, .csv
- El proceso es rápido incluso con archivos grandes
- Los contactos ya seleccionados no se duplican al importar
