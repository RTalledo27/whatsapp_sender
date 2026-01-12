# Guía de Deploy - WhatsApp Sender en Digital Ocean

## Requisitos previos
- Droplet Ubuntu 22.04 LTS en Digital Ocean
- Dominios DNS configurados:
  - `sender.casabonita.pe` → IP del droplet
  - `api-sender.casabonita.pe` → IP del droplet

## 1. Conectar al servidor

```bash
ssh root@TU_IP_DROPLET
```

## 2. Instalar dependencias del sistema

```bash
# Actualizar sistema
apt update && apt upgrade -y

# Instalar Nginx
apt install nginx -y

# Instalar PHP 8.2 y extensiones
apt install software-properties-common -y
add-apt-repository ppa:ondrej/php -y
apt update
apt install php8.2 php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip php8.2-bcmath php8.2-gd -y

# Instalar MySQL
apt install mysql-server -y

# Instalar Node.js 20
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt install nodejs -y

# Instalar Composer
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Instalar Certbot para SSL
apt install certbot python3-certbot-nginx -y
```

## 3. Configurar MySQL

```bash
# Iniciar MySQL
systemctl start mysql
systemctl enable mysql

# Configurar MySQL (ejecutar como root)
mysql -u root <<EOF
CREATE DATABASE whatsapp_sender;
CREATE USER 'whatsapp_user'@'localhost' IDENTIFIED BY 'TU_PASSWORD_SEGURA';
GRANT ALL PRIVILEGES ON whatsapp_sender.* TO 'whatsapp_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
EOF
```

## 4. Subir código al servidor

### Opción A: Usando Git (recomendado)

```bash
# En tu computadora, sube el proyecto a GitHub/GitLab
cd c:/Users/rogit/wsp_send
git init
git add .
git commit -m "Initial commit"
git remote add origin TU_REPOSITORIO_GIT
git push -u origin main

# En el servidor
cd /var/www
git clone TU_REPOSITORIO_GIT whatsapp-sender
cd whatsapp-sender
```

### Opción B: Usando SFTP (FileZilla/WinSCP)

Sube toda la carpeta `wsp_send` a `/var/www/whatsapp-sender`

## 5. Configurar Backend Laravel

```bash
cd /var/www/whatsapp-sender/backend

# Instalar dependencias
composer install --optimize-autoloader --no-dev

# Copiar y configurar .env
cp .env.example .env
nano .env
```

**Editar .env con estos valores:**

```env
APP_NAME="WhatsApp Sender"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://api-sender.casabonita.pe

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=whatsapp_sender
DB_USERNAME=whatsapp_user
DB_PASSWORD=TU_PASSWORD_SEGURA

QUEUE_CONNECTION=database

WHATSAPP_API_VERSION=v18.0
WHATSAPP_ACCESS_TOKEN=TU_TOKEN_META
WHATSAPP_PHONE_NUMBER_ID=TU_PHONE_ID
WHATSAPP_BUSINESS_ACCOUNT_ID=TU_BUSINESS_ID
WHATSAPP_API_URL=https://graph.facebook.com
WHATSAPP_DELAY_SECONDS=2
WHATSAPP_DELAY_ON_ERROR=5

CORS_ALLOWED_ORIGINS=https://sender.casabonita.pe
```

**Continuar configuración:**

```bash
# Generar app key
php artisan key:generate

# Ejecutar migraciones
php artisan migrate --force

# Optimizar Laravel
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Configurar permisos
chown -R www-data:www-data /var/www/whatsapp-sender
chmod -R 755 /var/www/whatsapp-sender
chmod -R 775 /var/www/whatsapp-sender/backend/storage
chmod -R 775 /var/www/whatsapp-sender/backend/bootstrap/cache
```

## 6. Compilar Frontend Angular

```bash
cd /var/www/whatsapp-sender/frontend

# Instalar dependencias
npm install

# Compilar para producción
npm run build
```

## 7. Configurar Nginx

### Backend API

```bash
nano /etc/nginx/sites-available/api-sender.casabonita.pe
```

**Contenido:**

```nginx
server {
    listen 80;
    server_name api-sender.casabonita.pe;
    root /var/www/whatsapp-sender/backend/public;
    client_max_body_size 100m;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

### Límite de subida (evitar 413)

Si al enviar archivos ves `413 Request Entity Too Large`, ajusta:

**Nginx (API)**
- En el `server {}` del backend agrega/ajusta: `client_max_body_size 100m;` (arriba).
- Luego:
```bash
nginx -t
systemctl restart nginx
```

**PHP-FPM**
- Edita `/etc/php/8.2/fpm/php.ini` (o tu versión de PHP) y asegúrate de tener:
  - `upload_max_filesize = 100M`
  - `post_max_size = 100M`
- Luego:
```bash
systemctl restart php8.2-fpm
systemctl restart nginx
```

### Notas de voz (grabación desde navegador)

Para enviar notas de voz grabadas desde el navegador, los navegadores suelen generar `webm/opus`. Para que WhatsApp las acepte, el backend convierte a `ogg/opus` usando `ffmpeg` (con `libopus`).

En Ubuntu/Debian:
```bash
apt-get update
apt-get install -y ffmpeg
```

### Frontend Angular

```bash
nano /etc/nginx/sites-available/sender.casabonita.pe
```

**Contenido:**

```nginx
server {
    listen 80;
    server_name sender.casabonita.pe;
    root /var/www/whatsapp-sender/frontend/dist/frontend/browser;

    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

**Activar sitios:**

```bash
ln -s /etc/nginx/sites-available/api-sender.casabonita.pe /etc/nginx/sites-enabled/
ln -s /etc/nginx/sites-available/sender.casabonita.pe /etc/nginx/sites-enabled/

# Probar configuración
nginx -t

# Reiniciar Nginx
systemctl restart nginx
```

## 8. Configurar SSL (HTTPS)

```bash
# Obtener certificados SSL para ambos subdominios
certbot --nginx -d sender.casabonita.pe -d api-sender.casabonita.pe

# Certbot configurará automáticamente HTTPS
# Renovación automática ya está configurada
```

## 9. Configurar Queue Worker como servicio

```bash
nano /etc/systemd/system/whatsapp-queue.service
```

**Contenido:**

```ini
[Unit]
Description=WhatsApp Queue Worker
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
Restart=always
RestartSec=3
ExecStart=/usr/bin/php /var/www/whatsapp-sender/backend/artisan queue:work --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
```

**Activar servicio:**

```bash
systemctl daemon-reload
systemctl enable whatsapp-queue
systemctl start whatsapp-queue

# Ver estado
systemctl status whatsapp-queue

# Ver logs
journalctl -u whatsapp-queue -f
```

## 10. Configurar Firewall

```bash
# Permitir SSH, HTTP y HTTPS
ufw allow 22
ufw allow 80
ufw allow 443
ufw enable
```

## 11. Verificar deployment

### Backend API
```bash
curl https://api-sender.casabonita.pe/api/templates
```

### Frontend
Abre en navegador: `https://sender.casabonita.pe`

## 🔄 Actualizar aplicación (después de cambios)

```bash
# Backend
cd /var/www/whatsapp-sender/backend
git pull
composer install --optimize-autoloader --no-dev
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
systemctl restart whatsapp-queue

# Frontend
cd /var/www/whatsapp-sender/frontend
git pull
npm install
npm run build
systemctl restart nginx
```

## 📊 Monitoreo

### Ver logs de Laravel
```bash
tail -f /var/www/whatsapp-sender/backend/storage/logs/laravel.log
```

### Ver logs de Nginx
```bash
tail -f /var/log/nginx/error.log
tail -f /var/log/nginx/access.log
```

### Ver estado del Queue Worker
```bash
systemctl status whatsapp-queue
journalctl -u whatsapp-queue -f
```

## 🔒 Seguridad adicional

### Actualizar sistema regularmente
```bash
apt update && apt upgrade -y
```

### Backup automático de base de datos
```bash
# Crear script de backup
nano /root/backup-db.sh
```

**Contenido:**

```bash
#!/bin/bash
DATE=$(date +%Y%m%d_%H%M%S)
mysqldump -u whatsapp_user -pTU_PASSWORD whatsapp_sender > /root/backups/db_$DATE.sql
find /root/backups -name "db_*.sql" -mtime +7 -delete
```

```bash
chmod +x /root/backup-db.sh
mkdir -p /root/backups

# Agregar a crontab (diario a las 2 AM)
crontab -e
# Agregar: 0 2 * * * /root/backup-db.sh
```

## ✅ Checklist final

- [ ] Ambos subdominios apuntan a la IP del droplet
- [ ] Backend API responde en `https://api-sender.casabonita.pe`
- [ ] Frontend carga en `https://sender.casabonita.pe`
- [ ] SSL configurado para ambos dominios
- [ ] Queue worker corriendo: `systemctl status whatsapp-queue`
- [ ] Base de datos creada y migraciones ejecutadas
- [ ] Variables de entorno configuradas correctamente
- [ ] Permisos de archivos correctos
- [ ] Firewall configurado
- [ ] Logs sin errores críticos

## 🆘 Troubleshooting

### Error 502 Bad Gateway
```bash
systemctl status php8.2-fpm
systemctl restart php8.2-fpm
```

### Mensajes no se envían
```bash
systemctl status whatsapp-queue
journalctl -u whatsapp-queue -n 50
```

### Error de permisos
```bash
chown -R www-data:www-data /var/www/whatsapp-sender
chmod -R 775 /var/www/whatsapp-sender/backend/storage
```
