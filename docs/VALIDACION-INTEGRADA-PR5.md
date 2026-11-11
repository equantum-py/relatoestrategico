# Validación integrada final — PR #5

Fecha: 2026-11-09. Referencia frontend: `a6d87a3`; correcciones y evidencia en el commit que incorpora este informe, exclusivamente en `integration/frontend-php-api`.

**GO técnico de compatibilidad para el frontend corregido y el paquete PHP recibido. NO GO de liberación productiva hasta verificar la secuencia coordinada indicada abajo.** No se fusionó ni desplegó; producción no fue modificada. `a6d87a3` sin estas correcciones no recibe GO.

Backend: `relato-app-p0-block-key.tar.gz`, SHA-256 `85aa4113fccf1d42d8d83cc6ff7e3b38f1b5412ec861e6ce4a9dbd7b1f213d7a`. Los 27 archivos PHP/SQL/.htaccess extraídos coinciden con el paquete original. El informe de Claude declara 50 PASS y cero FAIL; se conserva como evidencia suministrada, sin repetir íntegramente esa suite.

## Resultado por requisito

| Requisito | Resultado | Evidencia integrada |
| --- | --- | --- |
| Contratos PHP/frontend | PASS corregido | Versión `relato.block-key.v1`, lecturas administrativas/públicas, escrituras y errores reales. Se corrigió la incompatibilidad UUID/hex16 de bloques nuevos. |
| Identidad independiente de `id` | PASS | La identidad de contenido y el vínculo DOM usan `block_key`; `id` solo direcciona recursos administrativos. No se deriva identidad de título, posición ni orden. |
| 39 bloques y restauración | PASS | Las 39 claves coinciden con el catálogo; exportación y restauración real con IDs cambiados conservan claves, texto editado, orden y visibilidad. También se conservó un bloque adicional con clave generada por PHP. |
| Navegación y edición del panel | PASS | Reordenar, editar, ocultar, exportar, restaurar y volver a editar desde navegador; comprobación directa SQL y respuesta pública. |
| Artículos | PASS | Creación y edición desde panel; publicación visible en API pública y retiro con respuesta 404; persistencia SQL. Solo se publicó en aislamiento un resumen aprobado, sin cuerpo inventado. |
| Seis resúmenes aprobados | PASS | Comparación exacta de títulos, extractos e imágenes contra la fuente aprobada. Seis imágenes originales descargadas y decodificadas en ambas vistas; no se sustituyeron imágenes. |
| Sesiones y permisos | PASS | Login inválido/válido, cookie segura, logout e invalidación real; sesión vencida en SQL expulsa el panel. Lector sin escritura/exportación; editor puede editar bloques y recibe 403 en ajustes. |
| Medios y ajustes | PASS corregido | Miniatura privada autenticada; URL pública 404 antes de publicar; sincronizador entregado publica una imagen referenciada. Borrado en uso recibe 409; desvinculada se elimina de SQL y ambos directorios. Ajustes persisten y respetan whitelist pública. |
| Respaldos antiguos | PASS de rechazo seguro | Respaldo sin esquema/claves rechazado por PHP sin alterar MariaDB. Procedimiento de recuperación abajo; no se afirma recuperación automática de un respaldo histórico concreto no entregado. |
| Desktop/mobile | PASS | Navegador a 390 y 1440 px, navegación móvil y panel; cero errores JS y desbordamiento. Blog con las seis imágenes originales decodificadas. |

## Correcciones necesarias encontradas

1. PHP genera `<page>.custom.<16 hex>`; el consumidor de `a6d87a3` exigía UUID. Se reproduce el rechazo anterior con una respuesta real y se admite el contrato entregado.
2. Los archivos recién subidos son privados. El panel usaba la URL pública y mostraba miniaturas rotas. Biblioteca y vistas previas ahora usan `/v1/media/<archivo>/file` con sesión; copiar enlace conserva la URL pública.
3. Los bloques nuevos ahora crean su título con `h3`, conservando la semántica y estilo de los títulos. No se modificó CSS ni el diseño aprobado.

No quedaron defectos funcionales abiertos dentro del alcance integrado ejecutado. El PHP admite algunas claves semánticas bien formadas que el consumidor no reconoce: respaldos modificados manualmente o ajenos al productor deben pasar el preflight local antes de restaurar. No asumir que PHP valida el catálogo completo del frontend.

## Método y evidencia

Se ejecutaron PHP 8.3.6 y MariaDB 10.11.14 reales, en loopback y una base/usuario únicos de prueba. Tres cuentas sintéticas se crearon únicamente en esa base. Contraseñas aleatorias en memoria, configuración temporal con permisos restringidos; no se usó ni guardó contraseña administrativa real. La base, usuario SQL y configuración efímera fueron eliminados al terminar.

Playwright conservó los orígenes lógicos de frontend/API y redirigió las solicitudes a PHP local. Los cuerpos API no fueron simulados y toda otra red se bloqueó. Esto prueba sesiones y permisos de la aplicación; no certifica TLS, Apache, cookies entre dominios reales ni instalación de cPanel. El router local reproduce el despacho de archivos/API, no sustituye esa comprobación final.

Resultados separados, sin sumarlos a los 50 declarados por Claude:

- 26 comprobaciones integradas PASS, cero FAIL.
- Dos vistas del blog PASS, seis imágenes originales decodificadas por vista.
- Cuatro regresiones unitarias P1 PASS, afectadas por el ajuste de claves.
- Tres casos del preflight local PASS: respaldo compatible aceptado; legado y clave desconocida rechazados.

Evidencia estructurada: [pr5-integrated-20261109.json](evidence/pr5-integrated-20261109.json). No contiene contraseñas, cookies ni respaldos con datos productivos. `publicationSource` permanece `approved-static`; el corte a API solo se simuló en memoria durante la prueba. Cinco resúmenes se cargaron como fixture y el sexto se creó/editó desde el panel; los seis se verificaron contra SQL. No se habilitó un blog vacío.

## Recuperación segura de respaldos antiguos

1. Conservar el original inmutable y su hash. Obtener y verificar respaldo completo de la base y de los archivos privados de medios. El JSON de aplicación no incluye usuarios, contraseñas, sesiones ni los bytes de medios.
2. Restaurar el legado exclusivamente en una base aislada compatible con su esquema. Si hace falta código anterior para leerlo, usarlo solo allí; nunca copiarlo sobre producción.
3. Reconstruir la correspondencia con un catálogo explícito confiable y revisión del propietario. No asignar identidad permanente por título/orden ni agregar simplemente `identity_schema` al JSON. Si hay ambigüedad, detener la conversión.
4. Revisar el preflight y las etapas de la migración 002 del paquete: agregar columna, vincular las 39 filas verificadas y aplicar restricciones. Los IDs/títulos del catálogo son guardas de una migración única, no identidad de runtime. DDL puede confirmar implícitamente: disponer de recuperación completa, no confiar en rollback transaccional.
5. Exportar un respaldo nuevo y ejecutar `node scripts/validation/check_backup_identity.cjs /ruta/respaldo.json` desde la raíz del repositorio. Es una comprobación local de identidad; no escribe ni certifica archivos/SQL. Claves desconocidas requieren reconciliación antes de continuar.
6. Restaurar en una segunda base aislada con IDs distintos. Comparar claves, contenido, orden y visibilidad de los 39 bloques y cualquier bloque adicional. Recuperar medios originales verificando hashes y referencias; una fila JSON no recupera un archivo perdido. Comprobar imágenes antes de cualquier autorización posterior.

## Reproducción aislada

`tests/integrated-php.cjs` requiere Playwright/Chromium, PHP con PDO MySQL, mysqlnd, GD, mbstring, fileinfo y ctype; MariaDB 10.11 y un datadir previamente inicializado y exclusivo. Descomprimir el paquete en una carpeta independiente. Crear dentro del estado privado `db`, `run`, `logs`, `private-media` y `mail`; inicializar solo ese `db` con `mariadb-install-db`. No apuntar a un datadir ni configuración existentes.

Configurar `RELATO_TEST_BACKEND_DIR`, `RELATO_TEST_STATE`, `RELATO_TEST_PHP`, `RELATO_TEST_MARIADBD`, `RELATO_TEST_DB_BASEDIR` y, si corresponde, `RELATO_TEST_PHP_INI`/`CHROMIUM_EXECUTABLE`; ejecutar `node tests/integrated-php.cjs` desde la raíz. El runner crea y limpia su propia base con prefijo `relato_pr5_test_`; rechaza una fixture fuera del alcance. `tests/approved-assets-visual.cjs` usa las imágenes originales capturadas por `scripts/validation/capture_approved_images.py`, sin sustituir los recursos aprobados.

## Verificaciones operativas pendientes para Dirección/cPanel

Antes de autorizar fusión/despliegue, acordar y revisar una secuencia que evite frontend nuevo con PHP anterior:

1. Verificar que el candidato PHP a instalar corresponde al hash validado y contrastar configuración, permisos de carpetas y respaldo recuperable. Esta revisión no accedió al PHP instalado en cPanel.
2. Ejecutar la migración 002 y comprobar las 39 claves con el catálogo revisado en el entorno autorizado, con recuperación preparada. No ejecutar restauraciones de prueba en producción.
3. Verificar la tarea programada del sincronizador `sync_media.php` entregado (intervalo documentado de 10–15 minutos), rutas y permisos. Las escrituras no sincronizan los archivos públicos inmediatamente por sí solas. Aquí se ejecutó manualmente solo en aislamiento; no se verificó un cron productivo.
4. Preparar carga de los seis resúmenes aprobados y comprobar textos, imágenes y publicación antes del corte explícito `approved-static` → `api`; conservar la vista aprobada durante la transición. No se autoriza inventar cuerpos completos ni dejar vacío el blog.
5. En el entorno final autorizado, entrada interactiva del propietario para login/logout y comprobación de cookies/CORS/TLS/Apache, medios públicos y diseño. No compartir ni almacenar su contraseña.

Estas acciones no se ejecutaron. El GO técnico aislado no constituye autorización de fusión ni despliegue; la liberación permanece NO GO hasta cerrar estas comprobaciones y la decisión de Dirección.
