# Despliegue de la API administrativa (cPanel)

Backend PHP 8.2 + MySQL para `https://api.relatoestrategico.cl`. El panel en Vercel
(`https://relatoestrategico.cl/admin`) consume esta API. No requiere variables de entorno en Vercel.

## Estructura

| Repositorio | Servidor (cPanel) | Visible por URL |
|---|---|---|
| `backend/public-api/index.php`, `.htaccess` | `/home/cre116835/public_html/relato-api/` | Sí (único punto de entrada) |
| `backend/app/src/`, `migrations/`, `scripts/`, `tests/` | `/home/cre116835/relato-app/` | No (fuera del docroot) |
| `backend/app/config/config.example.php` (plantilla) | `/home/cre116835/relato-config/config.php` | No (fuera de public_html) |

## Pasos

1. **Versión de PHP**: en MultiPHP Manager, asignar PHP 8.2 al dominio `api.relatoestrategico.cl`.
2. **Aplicación fuera del docroot**: subir `backend/app/` a `/home/cre116835/relato-app/` (sin `tests/` si no se necesita en producción).
3. **Configuración real**: crear `/home/cre116835/relato-config/config.php` a partir de `config.example.php`, con permisos `600`.
   Generar la clave con `php -r "echo bin2hex(random_bytes(32));"` y pegarla en `app.secret`.
   La contraseña de base de datos se escribe **solo en el servidor**. Nunca se sube al repositorio ni se pega en el chat.
4. **Entrada pública**: subir `backend/public-api/index.php` y `backend/public-api/.htaccess` a `/home/cre116835/public_html/relato-api/`.
5. **Esquema**: en phpMyAdmin, seleccionar `cre116835_relato_admin` y ejecutar `migrations/001_schema.sql`. Es idempotente.
6. **Primer administrador**: por SSH o Terminal de cPanel:
   `php /home/cre116835/relato-app/scripts/create_admin.php correo@dominio.cl`
   La contraseña se pide por teclado, sin mostrarse. No se acepta como argumento.
7. **Verificación**: `https://api.relatoestrategico.cl/v1/health` debe responder `{"ok":true,"status":"up"}`.
8. **Panel**: al fusionar el PR, Vercel publica `public/admin/index.html` automáticamente.

## Pruebas

Requiere una base de datos **de pruebas** (nunca la de producción) con el esquema aplicado:

```
RELATO_TEST_DB_NAME=... RELATO_TEST_DB_USER=... RELATO_TEST_DB_PASS=... php backend/app/tests/run.php
```

La suite levanta un servidor PHP local, crea un administrador de prueba y verifica login, bloqueos,
sesiones, CSRF, CORS, permisos por rol, SQL injection, XSS, recuperación de contraseña y ausencia de secretos.

## Seguridad aplicada

- Contraseñas con `password_hash()` / `password_verify()`; hash de referencia para igualar tiempos.
- Cookie de sesión `__Host-rs_session`: HttpOnly, Secure, SameSite=Strict, sin Domain. Solo guarda un token aleatorio; en base de datos se guarda su SHA-256.
- Expiración por inactividad (30 min) y por tiempo máximo (8 h). Cierre de sesión real en el servidor.
- Bloqueo de cuenta tras 5 intentos fallidos (15 min) y límite por IP (30 intentos / 15 min).
- Recuperación de contraseña: token de un solo uso, vence en 30 min, guardado como hash, respuesta idéntica exista o no la cuenta.
- CSRF: token HMAC para formularios anónimos y token por sesión para escrituras. Origen exacto obligatorio para métodos que modifican datos.
- CORS solo para `https://relatoestrategico.cl`.
- Consultas PDO preparadas, sin emulación; validación de longitud, formato y lista blanca de campos.
- Auditoría en `audit_log` sin contraseñas, tokens ni contenido de formularios.

## Limitaciones conocidas

- Los artículos y ajustes se guardan en MySQL. Las páginas públicas del sitio siguen siendo HTML estático:
  un artículo publicado en el panel **no aparece todavía en el blog público**. Hacerlo requiere un endpoint público de lectura y cambiar las páginas del blog, lo que queda fuera de esta fase.
- La rama no se verificó contra el servidor LiteSpeed real ni contra el certificado HTTPS de `api.relatoestrategico.cl`, porque ese dominio no era accesible desde el entorno de desarrollo.
