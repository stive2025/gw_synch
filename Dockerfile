FROM php:8.2-cli

# Instalar dependencias del sistema
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    supervisor
# Limpiar cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Instalar extensiones de PHP
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Establecer directorio de trabajo
WORKDIR /var/www/html

# Copiar archivos de la aplicación
COPY ./sync /var/www/html

# Copiar configuración de supervisor
COPY ./supervisord.conf /etc/supervisord.conf

# Instalar dependencias de PHP
RUN composer install --no-dev --optimize-autoloader

# Crear directorio de storage y darle permisos
RUN mkdir -p storage/framework/{sessions,views,cache} \
    && mkdir -p storage/logs \
    && chmod -R 775 storage \
    && chmod -R 775 bootstrap/cache

# Exponer el puerto 8000
EXPOSE 8000

# Iniciar supervisord (maneja webserver + queue worker)
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
