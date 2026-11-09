# Despliegue de la API administrativa (cPanel)

Backend PHP 8.2 + MySQL/MariaDB para `https://api.relatoestrategico.cl`. El panel en Vercel
(`https://relatoestrategico.cl/admin`) consume esta API. No requiere variables de entorno en Vercel.

> Estado: esta rama **no está desplegada**. Nada de lo descrito aquí se ha aplicado a producción.

## Estructura

| Repositorio | Servidor (cPanel) | Visible por URL |
|---|---|---|
| `backend/public-api/index.php`, `.htaccess` | `/home/cre116835/public_html/relato-api/` | Sí (único punto de entrada) |
| `backend/public-api/media/.htaccess` | `/home/cre116835/public_html/relato-api/media/` | Solo imágenes con nombre generado |
| `backend/app/src/`, `migrations/`, `scripts/`, `tests/` | `/home/cre116835/relato-app/` | No (fuera del docroot) |
| `backend/app/config/config.example.php` (plantilla) | `/home/cre116835/relato-config/config.php` | No (fuera de public_html) |

## Requisitos de PHP

- PHP 8.2 con las extensiones `pdo_mysql`, `fileinfo` y `gd` (WebP requiere GD compilado con WebP).
  Sin `fileinfo` o `gd`, la subida de imágenes responde 503 y el resto de la API sigue funcionando.
- `display_errors` debe quedar desactivado en producción (la API ya lo fuerza, pero el servidor no debe mostrar errores fuera de ella).

## Base de datos: dos usuarios

1. **Usuario de migración** (con DDL): solo se usa una vez por cambio de esquema, desde phpMyAdmin o SSH.
   Ejecutar `migrations/001_schema.sql` sobre `cre116835_relato_admin`. El script es idempotente.
   > Nota: esta versión modifica `001_schema.sql` directamente porque la primera versión nunca se desplegó.
   > Si en el futuro hay una base ya creada con el esquema anterior, hay que escribir una migración nueva; no volver a ejecutar este archivo sobre datos existentes sin revisarlo.
2. **Usuario de la aplicación** (el que está en `config.php`): solo DML, sin DDL.

```sql
-- Sustituir <CONTRASEÑA_FUERTE> por una contraseña generada; no se guarda en el repositorio.
CREATE USER 'relato_app'@'localhost' IDENTIFIED BY '<CONTRASEÑA_FUERTE>';
GRANT SELECT, INSERT, UPDATE, DELETE ON cre116835_relato_admin.* TO 'relato_app'@'localhost';
```

La aplicación nunca necesita `CREATE`, `ALTER`, `DROP` ni `GRANT`.

## Pasos

1. **Versión de PHP**: en MultiPHP Manager, asignar PHP 8.2 al dominio `api.relatoestrategico.cl`.
2. **Aplicación fuera del docroot**: subir `backend/app/` a `/home/cre116835/relato-app/` (sin `tests/` si no se necesita en producción).
3. **Configuración real**: crear `/home/cre116835/relato-config/config.php` a partir de `config.example.php`, con permisos `600`.
   - `app.secret`: `php -r "echo bin2hex(random_bytes(32));"`.
   - `db`: usuario de la aplicación (sección anterior). La contraseña se escribe **solo en el servidor**.
   - `media_dir`: `/home/cre116835/public_html/relato-api/media`.
   - `media_base_url`: `https://api.relatoestrategico.cl/media`.
   - `media_max_bytes`: 5242880 (5 MB).
   - `trusted_proxies`: dejar `[]`. Solo si delante de la aplicación hay un proxy inverso propio, indicar su IP aquí; de lo contrario la API ignora `X-Forwarded-For`.
4. **Entrada pública**: subir `backend/public-api/index.php` y `backend/public-api/.htaccess` a `/home/cre116835/public_html/relato-api/`.
5. **Carpeta de medios**: crear `/home/cre116835/public_html/relato-api/media/` y subir dentro `backend/public-api/media/.htaccess`.
   Ese archivo deniega todo y solo permite nombres de 32 caracteres hexadecimales con extensión `.jpg`, `.png` o `.webp`.
   Sin ese `.htaccess`, cualquier archivo subido podría listarse; la API lo impide, pero la carpeta debe estar protegida igualmente.
6. **Esquema**: ver "Base de datos: dos usuarios".
7. **Primer administrador**: por SSH o Terminal de cPanel:
   `php /home/cre116835/relato-app/scripts/create_admin.php correo@dominio.cl`
   La contraseña se pide por teclado, sin mostrarse. No se acepta como argumento.
8. **Verificación**: `https://api.relatoestrategico.cl/v1/health` debe responder `{"ok":true,"status":"up"}`.
9. **Panel**: al fusionar el PR, Vercel publica `public/admin/` y aplica `vercel.json` (cabeceras de seguridad para `/admin/*`).

## Usuarios y invitaciones

- Un administrador crea cuentas desde **Usuarios y roles**. La cuenta nueva no recibe contraseña elegida por el administrador:
  se envía un enlace de un solo uso (30 min) para que la persona cree su propia contraseña.
- Límite: 3 enlaces por cuenta por hora (`reset_per_account_per_hour`) y 5 solicitudes de recuperación por IP por hora (`reset_per_ip_per_hour`). Si se supera, la respuesta es la misma que para una cuenta válida y no se envía correo.
- Cambiar el rol o desactivar una cuenta cierra todas sus sesiones abiertas.
- Siempre debe quedar al menos un administrador activo.

## Correo (invitaciones y recuperación)

- Producción usa `mail()` de PHP (`app.mail_transport = 'mail'`). El servidor debe poder enviar desde `app.mail_from` (`no-reply@relatoestrategico.cl`).
- Antes de habilitar recuperación, verificar en cPanel: SPF y DKIM del dominio, DMARC si se usa, y entrega a una cuenta de prueba **real y propia**.
- Si `mail()` falla, el envío devuelve error al registro del servidor y el panel no lo muestra al usuario. Revisar el registro tras la primera prueba.
- `app.mail_transport = 'file'` existe **solo para pruebas**: guarda cada mensaje como archivo `.eml` (0600) en `app.mail_test_dir`, sin enviarlo.
  - Solo se acepta con `app.environment = 'test'`. Con cualquier otro valor la configuración se rechaza al cargar.
  - `mail_test_dir` debe ser una ruta absoluta, fuera de cualquier directorio público (`public_html`, `public-api`) y con permisos 0700.
  - Nunca debe activarse en `config.php` de producción.
- Los tokens nunca se escriben en el registro del servidor, en la auditoría ni en los intentos de acceso (verificado en la suite).

## Respaldo y restauración

- **Respaldo**: páginas, bloques, ajustes y artículos, en JSON (`format: relato-backup`, versión 1).
  No incluye usuarios, contraseñas, sesiones ni auditoría. **No incluye los archivos de imagen**.
- **Restauración**: exige escribir `RESTAURAR`. Antes de restaurar, el panel descarga automáticamente un respaldo del estado actual.
  Toda la validación ocurre antes de tocar la base de datos, y la restauración es una sola transacción.
  Las imágenes solo se recuperan si el archivo sigue en `media_dir` y su SHA-256 coincide.
- **Restablecer valores iniciales**: exige escribir `RESTABLECER`. Restaura páginas y ajustes. **No borra artículos ni imágenes.**

## Pruebas

Requiere una base de datos **de pruebas** (el nombre debe contener `test`; la suite se niega a ejecutarse en otro caso) con el esquema aplicado:

```
RELATO_TEST_DB_NAME=... RELATO_TEST_DB_USER=... RELATO_TEST_DB_PASS=... php backend/app/tests/run.php
```

La suite levanta dos servidores PHP locales (puertos 8099 y 8100) con configuraciones temporales.
Verifica: inicialización del administrador, cabeceras y CORS, autenticación, bloqueos por par IP+cuenta, IP y cuenta
(con proxy de confianza), sesiones, CSRF, permisos por rol, artículos, páginas y bloques (orden, visibilidad, marcado),
multimedia (tipo real, re-codificación, nombres aleatorios, tamaño, uso en contenidos), usuarios e invitaciones,
respaldo y restauración, recuperación de contraseña, CSP del panel y ausencia de secretos en respuestas y auditoría.

## Seguridad aplicada

- Contraseñas con `password_hash()` / `password_verify()`; hash de referencia para igualar tiempos de respuesta.
- Cookie de sesión `__Host-rs_session`: HttpOnly, Secure, SameSite=Strict, sin Domain. Solo guarda un token aleatorio; en base de datos se guarda su SHA-256.
- Expiración por inactividad (30 min) y por tiempo máximo (8 h). Cierre de sesión real en el servidor.
- Límites de inicio de sesión en tres niveles, todos en `login_attempts` (guardados como HMAC):
  - par IP + cuenta: 5 fallos en 15 min (solo bloquea a quien intenta esa cuenta desde esa conexión);
  - IP: 30 fallos en 15 min (configurable);
  - cuenta: 50 fallos en 1 h (umbral alto, frena ataques distribuidos).
- Recuperación de contraseña: token de 256 bits, un solo uso, vence en 30 min, guardado como hash;
  límite por IP (5/h) y por cuenta (3/h); respuesta idéntica exista o no la cuenta.
- Contraseñas de al menos 12 caracteres con letras y números.
- CSRF: token HMAC para formularios anónimos y token por sesión para escrituras. Origen exacto obligatorio para métodos que modifican datos.
- CORS solo para `https://relatoestrategico.cl`.
- Consultas PDO preparadas, sin emulación; validación de longitud, formato y lista blanca de campos.
  Los campos de texto rechazan marcado HTML; las URL aceptan solo `https://` o rutas internas que no empiecen con `//`.
- Multimedia: tipo verificado por contenido (no por extensión), re-codificación con GD (se eliminan metadatos y datos añadidos),
  nombre aleatorio, `.htaccess` que impide ejecutar o listar archivos, límite de tamaño y de dimensiones.
- Roles: lector solo consulta; editor gestiona artículos, páginas, bloques y multimedia; administrador además gestiona ajustes, usuarios y respaldos.
  Los permisos se validan en la API. El panel solo oculta o deshabilita controles.
- Auditoría en `audit_log` sin contraseñas, tokens ni contenido de formularios.

## Limitaciones conocidas

- Los artículos, páginas y ajustes se guardan en MySQL. Las páginas públicas del sitio siguen siendo HTML estático:
  un artículo publicado en el panel **no aparece todavía en el blog público**. Hacerlo requiere un endpoint público de lectura y cambiar las páginas del blog, lo que queda fuera de esta fase.
- Los enlaces de invitación y recuperación contienen el token en la URL. El token es de un solo uso y vence en 30 minutos,
  pero puede quedar en registros del servidor web. El panel borra el token de la barra de direcciones en cuanto lo lee,
  y las peticiones a la API se envían con `referrerPolicy: no-referrer`. Esto reduce la exposición, no la elimina.
- El respaldo no incluye los archivos de imagen. Hay que copiar `media_dir` por separado.
- El panel usa `style-src 'unsafe-inline'` por los estilos en línea del documento. Los scripts sí están restringidos a `'self'`.
- Las contraseñas de bcrypt solo consideran los primeros 72 bytes; el límite de 200 caracteres de la aplicación no cambia ese comportamiento.
- La suite se ejecutó con PHP 8.3.6 y MariaDB 10.11. **No se ejecutó con PHP 8.2** en el entorno de desarrollo.
- No se verificó contra el servidor LiteSpeed real de cPanel ni contra el certificado HTTPS de `api.relatoestrategico.cl`,
  porque ese dominio no era accesible desde el entorno de desarrollo. Tampoco se verificó la entrega de correo de invitaciones y recuperación.
- La entrega real de correo en cPanel no se ha probado: la suite usa el transporte de archivos, no `mail()`.
- Las cabeceras de `vercel.json` deben comprobarse en el panel publicado tras el primer despliegue.
