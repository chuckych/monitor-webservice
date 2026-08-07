# WebService Ping Monitor Agent

Script PHP 7.4 para monitorear la disponibilidad de un webservice mediante ping HTTP y notificar por email en caso de fallo. Pensado para ejecutarse como tarea programada en Windows Server.

## Requisitos

- PHP 7.4.33 (CLI)
- Extensión cURL habilitada
- Extensión mbstring habilitada (para normalización UTF-8)
- Windows Server (o cualquier sistema con PHP CLI)

## Instalación

1. Copiar el proyecto a una carpeta (ej: `C:\monitor\webservice-monitor`).
2. Editar `config.ini` con los valores reales del webservice y del API de correo.
3. Crear la tarea programada (ver más abajo).
4. Verificar los logs en la carpeta configurada.

## Uso

```bash
# Ejecución normal
php ping-monitor.php

# Con archivo de configuración específico
php ping-monitor.php --config=custom_config.ini

# Modo debug (verbose en consola)
php ping-monitor.php --debug

# Ayuda
php ping-monitor.php --help
```

## Códigos de retorno

| Código | Descripción |
|--------|-------------|
| 0 | Éxito - Ping OK |
| 1 | Error de configuración |
| 2 | Ping fallido - Email enviado |
| 3 | Error crítico - Script abortado |
| 4 | Ping fallido - Email no enviado (intervalo) |

## Tarea programada (Windows)

```batch
schtasks /create /tn "WebServicePingMonitor" /tr "C:\php\php.exe C:\path\to\ping-monitor.php" /sc minute /mo 5 /ru SYSTEM
```

## Estructura

```
webservice-monitor/
├── config.example.ini         # Configuración de ejemplo (copiar a config.ini)
├── ping-monitor.php          # Script principal
├── email-template.example.txt # Plantilla de ejemplo (copiar a email-template.txt)
├── functions/
│   └── mail-functions.php    # Funciones de email (API HTTP) + normalización UTF-8
├── logs/
│   └── monitor_YYYY-MM-DD.log  # Logs diarios (se crea automáticamente)
└── README.md                 # Documentación
```

> `config.ini` y `email-template.txt` no se versionan (son personalizables). Al clonar, copiar los archivos `.example` a su nombre real y ajustar los valores.

## Configuración

Ver `config.ini`. Los valores críticos son:

- `[WEBSERVICE] urlPing`: URL del endpoint a monitorear (debe responder HTTP 201). Algunos servicios requieren el signo `?` al final del path para responder.
- `[WEBSERVICE] pathLogWebservice`: carpeta donde se encuentra el log del webservice. El script concatena `LogWebServiceAAAAMMDD.txt` (fecha en formato `Ymd`), lee las últimas 5 líneas y las adjunta al email de fallo y al log del monitor.
- `[EMAIL] apiMailUrl / apiToken / recipient / replyTo`: credenciales del API de correo (el token se envía en el header `Token` junto con `X-Request-Id`).
- `[EMAIL] logFolder`: carpeta donde se escriben los logs diarios.
- `[EMAIL] emailTemplate`: ruta de la plantilla del cuerpo del email (relativa al `config.ini` o absoluta). Copiar `email-template.example.txt` a `email-template.txt` y personalizarla. Placeholders disponibles: `{timestamp}`, `{url}`, `{attempts}`, `{httpCode}`, `{error}`, `{wslog}`, `{agentName}`, `{agentVersion}`.
- `[SETTINGS] maxRetries / retryDelay`: cantidad de reintentos y espera entre ellos.
- `[SETTINGS] minEmailInterval`: segundos mínimos entre emails de notificación (previene spam).
- `[SETTINGS] logRetentionDays`: retención de logs en días (default 7). Los `monitor_*.log` más antiguos que ese valor se eliminan al ejecutar el script.

## Versionado

La versión se declara en `ping-monitor.php` como `SCRIPT_VERSION`. Por cada cambio relevante:

1. Actualizar `SCRIPT_VERSION` (ej: `'1.1.0'`).
2. Commitear los cambios.
3. Crear el tag con el mismo valor: `git tag v1.1.0`.
4. Pushear: `git push origin main --tags`.

Los tags usan el prefijo `v` seguido de `SCRIPT_VERSION` (ej: `v1.0.0`).
