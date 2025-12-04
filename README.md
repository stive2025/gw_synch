# GW Synch

Aplicación Laravel para sincronización, ejecutándose en Docker.

## Requisitos

- Docker instalado en tu sistema
- Puerto 8000 disponible

## Instalación y Ejecución

### 1. Construir la imagen Docker

```bash
docker build -t laravel-sync .
```

### 2. Ejecutar el contenedor

```bash
docker run -p 8000:8000 laravel-sync
```

La aplicación estará disponible en: `http://localhost:8000`

## Estructura del Proyecto

```
gw_synch/
├── Dockerfile          # Configuración Docker para la aplicación
└── sync/              # Aplicación Laravel
    ├── app/           # Código de la aplicación
    ├── config/        # Archivos de configuración
    ├── database/      # Migraciones y seeders
    ├── public/        # Archivos públicos
    ├── resources/     # Vistas y assets
    ├── routes/        # Definición de rutas
    └── storage/       # Archivos generados
```

## Desarrollo

### Acceder al contenedor

```bash
docker exec -it <container_id> bash
```

### Ejecutar comandos Artisan

```bash
docker exec -it <container_id> php artisan <comando>
```

## Configuración Docker

El Dockerfile incluye:
- PHP 8.2 CLI
- Extensiones: PDO MySQL, GD, MBString, BCMath, etc.
- Composer para gestión de dependencias
- Servidor integrado de Laravel (`php artisan serve`)
- Puerto expuesto: 8000

## Tecnologías

- **Laravel**: Framework PHP
- **PHP**: 8.2
- **Docker**: Contenedorización
- **Composer**: Gestión de dependencias

## Licencia

Este proyecto utiliza el framework Laravel, licenciado bajo [MIT License](https://opensource.org/licenses/MIT).
