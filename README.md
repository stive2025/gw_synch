# GW Synch

Aplicación Laravel v2.0.0 para sincronización de créditos y pagos vía WEB SERVICE, ejecutándose en Docker.

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

## Endpoints de Sincronización

### Sincronizar créditos
```
GET /api/syncs/credits
```
Sincroniza créditos desde FACES, actualiza bandejas y despacha los jobs de contactos en background.

### Sincronizar pagos
```
GET /api/syncs/pays
```

### Re-sincronizar solo contactos (método de respaldo)
```
GET /api/syncs/credits/contacts
```
Re-sincroniza contactos, teléfonos y direcciones de los créditos que ya están en la base de datos **sin volver a correr el sync de créditos desde cero**. Útil cuando el sync de créditos completó correctamente pero los jobs de contactos fallaron o no se procesaron.

**Modos de ejecución:**

| Modo | URL | Descripción |
|------|-----|-------------|
| Asíncrono (default) | `/api/syncs/credits/contacts` | Despacha jobs a la cola — requiere worker activo |
| Síncrono (respaldo) | `/api/syncs/credits/contacts?async=false` | Ejecuta en línea — no necesita worker |

**Cuándo usar `?async=false`:**
- El worker de la cola no está corriendo
- Se necesita verificar el resultado inmediatamente
- Se está debuggeando el proceso de contactos

**Verificar el worker:**
```bash
docker exec -it <container_id> supervisorctl status
```

**Ver logs del worker:**
```bash
docker exec -it <container_id> tail -f /var/www/html/storage/logs/worker.log
```

**Ver jobs fallidos:**
```bash
docker exec -it <container_id> php artisan queue:failed
```

**Reintentar jobs fallidos:**
```bash
docker exec -it <container_id> php artisan queue:retry all
```

## Configuración Docker

El Dockerfile incluye:
- PHP 8.2 CLI
- Extensiones: PDO MySQL, GD, MBString, BCMath, etc.
- Composer para gestión de dependencias
- Supervisor: ejecuta el servidor web y el worker de colas simultáneamente
- Puerto expuesto: 8000

## Tecnologías

- **Laravel**: Framework PHP
- **PHP**: 8.2
- **Docker**: Contenedorización
- **Composer**: Gestión de dependencias

## Licencia

Este proyecto utiliza el framework Laravel, licenciado bajo [MIT License](https://opensource.org/licenses/MIT).
