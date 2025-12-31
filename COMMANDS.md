# Comandos Rápidos - WhatsApp Sender

## Iniciar Proyecto Completo

### Backend
```bash
cd backend
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

### Queue Worker (Terminal separada)
```bash
cd backend
php artisan queue:work
```

### Frontend
```bash
cd frontend
npm install
npm start
```

## Comandos Útiles

### Backend
```bash
# Limpiar cache
php artisan cache:clear
php artisan config:clear

# Ver jobs en cola
php artisan queue:failed

# Reintentar jobs fallidos
php artisan queue:retry all

# Crear nueva migración
php artisan make:migration nombre_migracion

# Rollback migraciones
php artisan migrate:rollback
```

### Frontend
```bash
# Build para producción
npm run build

# Linter
ng lint

# Actualizar dependencias
npm update
```

## Troubleshooting

### Reiniciar todo
```bash
# Backend
cd backend
php artisan config:clear
php artisan cache:clear
php artisan queue:restart

# Frontend
cd frontend
rm -rf node_modules package-lock.json
npm install
```
