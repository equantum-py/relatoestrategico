# PR #5 — Informe de aceptación técnica

> Informe vigente: [VALIDACION-INTEGRADA-PR5.md](VALIDACION-INTEGRADA-PR5.md). El contenido inferior conserva el estado de la revisión anterior.

> Estado vigente de este cierre: [CIERRE-P0-PR5.md](CIERRE-P0-PR5.md). Las descripciones de identidad por IDs/títulos de este documento corresponden a la revisión anterior y quedaron sustituidas por el contrato estable propuesto.

**Dictamen: NO GO para autorizar fusión y despliegue.**

Revisión solicitada sobre `40058fe3319f09543e02c343b9cc2448c0efa012`, exclusivamente en `integration/frontend-php-api`. Se agregó una corrección mínima para conservar el orden del diseño, pruebas diagnósticas y este informe. Sin otra rama, funcionalidades nuevas, fusión, despliegue, cambios de credenciales, creación de usuarios ni mutaciones de contenido productivo.

Fecha: 9 de noviembre de 2026. Lecturas reales: 14:57, America/Asuncion (17:57 UTC). La evidencia JSON conserva la hora precisa.

## PASS/FAIL por prioridad

`FAIL — bloqueado` significa que el requisito de aceptación no pudo verificarse; no significa que se haya observado un fallo del servidor. Una prueba contractual o un render local no se presenta como una prueba real de MariaDB.

| # | Requisito | Resultado de aceptación | Evidencia y acción pendiente |
| --- | --- | --- | --- |
| 1 | Contratos de escritura del PHP desplegado | **FAIL — bloqueado** | No hay conexión a cPanel/SSH ni copia verificable del código actualmente desplegado. El paquete anterior carece de rutas públicas que el servidor sí responde; no sirve para certificar esta versión. Dirección debe facilitar el código vigente sin configuración ni secretos, identificado por hash/fecha. |
| 2 | Sesión real sin compartir/guardar contraseña | **FAIL — bloqueado** | Se abrió el panel productivo: sigue mostrando «PROTOTIPO SIN AUTENTICACIÓN REAL» y «Entrar a la demo». No se introdujeron credenciales. `/v1/auth/me` devuelve 401 sin sesión. Se preparó `scripts/validation/session_check.py`, con entrada oculta interactiva, cookies en RAM y cierre de sesión; no se ejecutó un login real. |
| 3 | Crear, editar, publicar y retirar artículo; MariaDB | **FAIL — bloqueado** | Sin sesión autorizada ni acceso de lectura a MariaDB. La API pública devuelve 0 publicaciones. No se crearon registros ni se publicó contenido ficticio en producción. Falta ejecutar el procedimiento aislado indicado abajo y verificar filas directamente. |
| 4 | Editar bloques y verificar visibilidad pública conservando diseño | **FAIL — parcial** | **PASS de lectura:** 7 páginas, 39 bloques, mismos IDs y contenido que el baseline. **PASS local:** render de la PR con respuestas reales capturadas por GET. Se corrigió el reordenamiento involuntario de la semilla. Edición/ocultación real y persistencia siguen bloqueadas por acceso. |
| 5 | Descartar pérdida de vínculo tras restauración | **FAIL — defecto P1 reproducido** | Con IDs nuevos y títulos editados se pierden 39/39 vínculos. Con IDs reutilizados en otro orden se puede enlazar contenido a una plantilla equivocada. No se conoce aún el comportamiento de la restauración desplegada; no se puede excluir ese escenario. |
| 6 | Restauración en base aislada | **FAIL — bloqueado** | No se recibió una base aislada ni su esquema/código vigente. No se ejecutó restauración en ninguna base. **PASS de restricción:** cero restauraciones o restablecimientos sobre producción. La simulación de payloads no se cuenta como restauración SQL. |
| 7 | Medios, configuración y permisos reales | **FAIL — parcial** | **PASS real sin sesión:** GET de medios, ajustes, usuarios y respaldo devuelve 401. CORS admite el origen www. No se verificaron cargas, modificaciones, permisos admin/editor/reader ni correo con sesión real. Ajustes públicos omiten `email`; no se puede aceptar todavía la sincronización de ese campo desde ajustes. |
| 8 | Visual desktop/mobile con contenido real | **FAIL — parcial** | **PASS local:** 18 vistas (9 páginas × 390/1440 px), 50 respuestas públicas reales reproducidas, cero errores JS y cero desbordamientos horizontales. Se inspeccionaron capturas. No es la PR desplegada ni una sesión autenticada real. La API vacía deja sin artículos al blog: falta cerrar la carga inicial del contenido aprobado. |

## Defectos concretos

### D1 — P1 abierto: identidad de bloques después de restaurar

Ubicación: `public/js/content-api.js`, `normalizePage`; `public/js/public-content.js`, `applyPage`.

El vínculo usa primero el ID fijado en el baseline y, si no existe, el título original. El orden no identifica de forma estable un bloque. Las respuestas públicas actuales no incluyen `template_key` ni una identidad semántica equivalente.

Reproducción aislada, sin base de datos ni red:

```bash
node --test tests/restore-risk.test.cjs
```

| Escenario ficticio | Resultado observado |
| --- | --- |
| 39 títulos editados, IDs conservados, orden invertido | 39 vínculos conservados |
| 39 títulos editados, IDs incrementados en 1000 | 39 vínculos nulos; 39 plantillas marcadas como deshabilitadas |
| Servicios invertidos y reasignados a los IDs iniciales | El contenido de `servicios-3` queda vinculado a `servicios-0` |
| IDs nuevos, títulos originales de semilla intactos | Se recuperan los 39 vínculos por título |

**Los diagnósticos pasan porque reproducen el riesgo; no certifican que las restauraciones sean seguras.** Tampoco prueban que el backend desplegado regenere IDs: esa condición requiere inspección de su código y prueba SQL aislada.

Cierre exigido: demostrar en el backend vigente que la exportación/restauración conserva la identidad sin colisiones y que el contenido público mantiene el vínculo, o corregir el contrato con una clave inmutable preservada en creación, edición, exportación y restauración. No copiar el PHP anterior ni sustituir el vínculo por título/posición para ocultar el problema.

### D2 — P2 abierto: blog aprobado aún no está en la API

`GET /v1/public/posts?limit=50&offset=0` devuelve una lista vacía. El frontend de la PR considera esa respuesta válida y muestra cero artículos, mientras el sitio previo conserva resúmenes estáticos. Se reprodujo con la respuesta pública real: desaparecen las tarjetas de artículos de Home y el listado del blog queda vacío.

Esto impide aceptar un despliegue que deba mantener el contenido aprobado. Antes de liberar, preparar y validar la carga inicial de los artículos aprobados en un entorno aislado; aplicar cualquier carga real solo con autorización específica. No activar un fallback ciego al recibir cero artículos, porque volvería a mostrar artículos retirados legítimamente.

### D3 — P2 corregido en esta misma PR: orden de la semilla

La respuesta inicial de contacto ordena WhatsApp antes de correo, y la semilla de nosotros ordena biografía antes de fotografía. Aplicar ese orden automáticamente cambiaba el diseño aprobado sin edición administrativa.

Corrección: `normalizePage` identifica si cambió el orden de los bloques respecto del baseline; `applyPage` conserva la posición original de la plantilla cuando es la semilla sin cambios. Un reordenamiento explícito sigue aplicándose entre hermanos administrados. Prueba nueva: «semilla de contacto no reordena el diseño aprobado; orden explícito sí».

No se modificó CSS, copy, imágenes ni HTML público para esta corrección.

## Acciones exactas necesarias de Dirección

1. **Código de cPanel:** obtener una copia de solo código del frontend de API y `src`/migraciones efectivamente en uso. Ruta documentada previamente, a confirmar en cPanel: `/home/cre116835/public_html/relato-api` y `/home/cre116835/relato-app`. Incluir controlador de rutas y clases de Auth/Session/Content/Pages/Media/Users/Backup/Http/Validate, versión PHP, DDL de tablas y hashes SHA-256. Excluir `config.php`, `.env`, contraseñas, tokens, cookies, logs, respaldos y datos de usuarios. No sobrescribir archivos del servidor.
2. **Sesión real sin revelar la contraseña:** Dirección puede ejecutar en su propia terminal `python3 scripts/validation/session_check.py`. Pide correo y contraseña actual con entrada oculta; no acepta contraseña por argumento/archivo/env y no guarda cookies en disco. Hace un solo intento de login, `me`, logout y comprobación de 401 posterior. Compartir únicamente el resultado JSON saneado. Esto comprueba sesión HTTP, no atributos/cookies del navegador ni el frontend DEMO. No introducir la contraseña real en el panel DEMO publicado.
3. **Entorno aislado existente o preparado por Dirección:** identificar base/host de prueba y confirmar que es distinta de producción, con el esquema y código vigentes. Facilitar acceso de lectura al código y resultados de consultas; cualquier autenticación se realiza por canal seguro o en la propia sesión de Dirección, nunca pegando secretos en el chat. No se autoriza aquí un despliegue ni cambiar CORS/cookies/credenciales para sortear el bloqueo.
4. **Frontend real en navegador:** el URL actual es DEMO. Para la prueba integrada, Dirección debe indicar un acceso existente a la candidata sin modificar producción, con origen HTTPS admitido por la API y base aislada. Si no existe, hace falta una autorización posterior y concreta para preparar ese entorno; este trabajo no lo desplegó. Un localhost o preview no autorizado por CORS no equivale a una prueba válida.

El script de sesión fue comprobado sintácticamente; en ejecución no interactiva se negó a pedir credenciales y salió con código 2. No se reporta un PASS de autenticación real.

## Procedimiento preparado: prueba aislada, limpieza y recuperación

**Preflight obligatorio:** identificar por separado origen de prueba, host/base aislada, versión/hashes de PHP y esquema. Confirmar aislamiento de sesiones, tablas, medios y correo frente a producción. No ejecutar si no se puede distinguir el destino. Conservar un estado inicial de la base aislada y de sus medios, con hashes; no exportar credenciales ni sesiones a evidencias.

Usar un identificador único `PR5-TEST-20261109-<sufijo>` y registrar solo IDs/slugs/rutas creados por la prueba. Las cuentas de rol serán cuentas autorizadas de prueba en la instancia aislada; no crear usuarios adicionales en producción ni cambiar contraseñas.

1. Artículo ficticio: crear borrador, consultar fila y contenido; editar título/cuerpo y verificar por SELECT y recarga; publicar y abrir sin sesión; retirar a borrador y comprobar ausencia en listado y 404 en detalle. Conservar únicamente valores sintéticos y resultados PASS/FAIL.
2. Bloques: guardar estado inicial de los 39 bloques; asignar marcadores ficticios únicos por bloque, manteniendo una tabla `identidad → ID → marcador`. Editar títulos, descripciones e imágenes; reordenar y ocultar una muestra; comprobar GET administrativo/público y las plantillas en ambos tamaños. No usar la base productiva para estas ediciones.
3. Respaldo: exportar desde la instancia aislada, comprobar que no incluye secretos; restaurar allí, comparar la tabla de identidades antes/después, valores completos, orden y visibilidad. Repetir contra IDs/autoincrement distintos para detectar regeneración o colisiones. Un archivo inválido debe rechazarse sin cambios parciales. Verificar transacciones en MariaDB.
4. Medios/ajustes/permisos: subir una imagen ficticia, comprobar URL y filas; modificar un ajuste sintético y observar la respuesta pública y su caché (páginas/posts 60 s; ajustes 300 s observados). Con roles de prueba verificar lecturas, escrituras permitidas y 403 en acciones denegadas. No usar envío de invitaciones a destinatarios reales.
5. Limpieza: retirar el artículo; borrar exclusivamente los IDs/slugs/rutas registrados como creados por esta prueba en el entorno aislado. Recuperar los bloques y ajustes del estado inicial aislado; verificar hashes/contenido y ausencia de marcadores públicos. Si un paso falla, detener la prueba y recuperar solo esa instancia a partir de su estado inicial. Nunca usar reset/restore productivo como mecanismo de limpieza.

No se ejecutó este procedimiento contra ninguna base. No hay registros productivos de prueba que limpiar.

## Evidencia y reproducción

- `docs/evidence/pr5-readonly-20261109.json`: 17 lecturas HTTP reales; estados, CORS, campos e IDs, sin cookies ni credenciales.
- `docs/evidence/pr5-public-snapshot-20261109.json`: resultados locales con respuestas públicas reales capturadas. No es una prueba en producción.
- `tests/restore-risk.test.cjs`: cuatro diagnósticos aislados de identidad.
- `tests/content.test.cjs`: nueve pruebas de contrato/regresión, incluida la corrección D3.
- `tests/public-snapshot.cjs`: render de la PR con un directorio de respuestas GET públicas; rechaza métodos distintos de GET hacia la API interceptada. Capturas temporales locales; no se versionan datos de sesión.

```bash
npm ci
npm test
# Después de capturar exclusivamente respuestas GET públicas:
PUBLIC_SNAPSHOT_DIR=/ruta/a/captura-publica node tests/public-snapshot.cjs
# Solo Dirección en su terminal interactiva, sin contraseña en argumentos:
python3 scripts/validation/session_check.py
```

**13 pruebas locales aprobadas incluyen los diagnósticos de defectos conocidos.** No son 13 requisitos de producción aprobados. Tras la corrección D3, las 15 comprobaciones contractuales de navegador volvieron a aprobarse (114 llamadas interceptadas), y el render con respuestas públicas reales aprobó las 18 vistas (50 respuestas interceptadas, cero errores JS). Estas comprobaciones son locales y no demuestran sesión real ni persistencia MariaDB.

Se mantienen bloqueados el acceso a PHP vigente, sesión real, persistencia MariaDB, restauración aislada y permisos autenticados. D1 y D2 impiden autorizar la fusión/despliegue hasta resolverlos o aportar evidencia concluyente de cierre.
