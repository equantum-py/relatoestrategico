# Integración frontend + API — revisión

> Estado vigente de este cierre: [CIERRE-P0-PR5.md](CIERRE-P0-PR5.md). Las descripciones de identidad por IDs/títulos de este documento corresponden a la revisión anterior y quedaron sustituidas por el contrato estable propuesto.

**Cierre de aceptación:** [Informe PR #5](ACEPTACION-PR5.md). Dictamen vigente: **NO GO para fusión y despliegue**; ver defectos, resultados y accesos pendientes en ese informe.

Base: `main` `bb6607755c30659e7d2521dd4a6ce1a619a509ed`.
Rama única: `integration/frontend-php-api`. No fusionar ni desplegar con este trabajo.

## Alcance

El panel real se incorpora desde `feat/admin-real-auth-api` (`e1b3b83`), conservando sus formularios y controles por rol. Usa cookies de sesión HttpOnly emitidas por PHP y CSRF en memoria; no guarda sesiones ni contenido administrativo en localStorage/sessionStorage. Las escrituras se confirman con la respuesta de la API. Logout no muestra éxito cuando el servidor falla.

El sitio consume exclusivamente `/v1/public/*`, sin cookies. Mantiene estilos, imágenes, estructura y copy aprobados como respaldo ante una API inaccesible. Los valores históricos de la semilla no reemplazan automáticamente el copy comercial aprobado. Una respuesta pública válida con cero publicaciones muestra el estado vacío: no resucita artículos retirados. El cuerpo del artículo se obtiene por slug y se muestra como texto, conforme al editor existente.

Se conectan páginas, artículos, medios, ajustes, invitaciones/roles/estado de usuarios y exportación/restauración de respaldos. Se conserva el control de acceso de la API; los controles de UI solo reflejan los roles.

**No se incluye ni se reemplaza el backend PHP.** El ZIP de referencia y la rama anterior no contienen las rutas públicas que sí existen en el servidor actual. Copiarlos al hosting revertiría cambios ajenos. No se modificaron credenciales, DNS, configuración remota, MariaDB ni servicios.

## Contratos verificados

Lecturas HTTP directas efectuadas el 9 de noviembre de 2026, sin autenticación ni mutaciones:

| Ruta | Resultado observado |
| --- | --- |
| `GET /v1/health` | 200, `{ok:true,status:"up"}` |
| `GET /v1/auth/me` | 401, `unauthenticated` |
| `GET /v1/posts`, `/v1/pages`, `/v1/settings` | 401 sin sesión |
| `GET /v1/public/posts` | 200, `posts:[]`, `pagination:{limit:10,offset:0,count:0}` |
| `GET /v1/public/posts/articulo-inexistente` | 404, `not_found`, «La publicación no existe.» |
| `GET /v1/public/pages/{key}` | 200 en home, soluciones, servicios, resultados, nosotros, preguntas y contacto |
| `GET /v1/public/pages` | 404; no existe listado público |
| `GET /v1/public/settings` | 200; siteName, whatsapp, calendar, linkedin, instagram, location, seoTitle, seoDescription, ogImage; sin email |

Los bloques públicos tienen `id`, `sort_order`, `title`, `subtitle`, `description`, `button`, `url`, `image_url`. No incluyen `visible`: el frontend considera la lista pública como autoritativa. La fixture guarda las respuestas públicas observadas, sin datos de sesión.

CORS: `Origin: https://www.relatoestrategico.cl` recibe Allow-Origin exacto y Allow-Credentials. `Origin: https://relatoestrategico.cl` no recibió Allow-Origin. Usar el origen con **www** y HTTPS para comprobar sesiones. Un preview vercel.app o localhost no es prueba de CORS/cookies reales. No cambiar SameSite ni ampliar CORS para sortearlo.

Los contratos administrativos de escritura se contrastaron con el código PHP de la rama previa y el paquete existente: JSON `{ok:true,...}`, errores `{ok:false,error:{code,message}}`, `credentials:include`, `X-CSRF-Token`, multipart `file` para medios. **No se verificó aún su ejecución autenticada contra la versión desplegada.** La respuesta de detalle público existente (con un post publicado real) también requiere confirmación; se espera `{ok:true,post:{...body...}}`.

## Identidad de bloques y límites conocidos

`content-api.js` fija el baseline público observado (39 bloques) por página e ID. Renombrar y reordenar conserva ese vínculo. Una semilla recreada puede reconocerse por su título original. Los bloques nuevos se agregan con la presentación de tarjetas existente. La ocultación o eliminación de bloques conocidos se refleja cuando dejan de aparecer en la respuesta pública.

**Bloqueante de producción para restauraciones:** si el backend restaura bloques editados asignando IDs nuevos, ya no existe una identidad inequívoca con la plantilla. Verificar que preserve los IDs o que publique una clave semántica inmutable antes de aceptar ese flujo. El adaptador admite `template_key` + `content` + `disabled_keys` como contrato futuro, pero no presupone que exista actualmente. Con IDs desconocidos trata el bloque como contenido nuevo; no adivina su sección por orden.

El orden se aplica entre nodos hermanos administrados. La portada de Home está fuera de `main` y conserva esa posición estructural. Tampoco se mueve una sección a través de otro contenedor del diseño. Este límite debe validarse con Dirección al revisar la gestión de páginas.

Las etiquetas históricas de la semilla (por ejemplo «Perfil Estratégico») siguen en el panel si así las devuelve el servidor; el sitio conserva «LinkedIn Start» hasta una edición explícita. No se ha migrado contenido real.

## Pruebas reproducibles

```bash
npm ci
npm test
npx playwright install chromium
npm run test:e2e
```

El runner E2E inicia su servidor local. `CHROMIUM_EXECUTABLE` permite usar un Chromium ya instalado; `BASE_URL` es opcional. **Siempre intercepta la API con fixtures**, incluso si se suministra BASE_URL: no usarlo como evidencia de pruebas reales. No incluir credenciales en comandos, repositorio ni capturas.

Resultados locales: 8 pruebas de contrato/unidad aprobadas y 15 comprobaciones E2E aprobadas. Cubren login/CSRF, rutas protegidas, creación/publicación/edición, recarga, detalle público escapado, retiro a borrador, edición de bloques, carga de medios/ajustes/usuarios/respaldos, desconexión, logout fallido y exitoso, rol lector y expiración. Comprueban nueve páginas públicas y siete vistas administrativas a 390 y 1440 px, con capturas. No equivalen a persistencia MariaDB.

Se revisaron visualmente portada mobile, servicios desktop y panel mobile. Las capturas son evidencia local temporal, no contienen usuarios reales. No se agregaron funcionalidades ni pruebas de la lógica interna PHP.

## Pendientes reales — no GO de producción

1. Con sesión autorizada en navegador sobre `https://www.relatoestrategico.cl`, verificar login correcto/incorrecto, recarga, cookie HttpOnly/Secure/SameSite y logout efectivo. Confirmar redirección del dominio sin www al canónico; no fue modificada.
2. Obtener acceso **de lectura al código PHP actualmente desplegado en cPanel** para confirmar los contratos de escritura y de detalle público. El paquete anterior no representa íntegramente esa versión. No enviar contraseñas al chat.
3. En entorno de prueba autorizado, crear un borrador sintético, editarlo, recargar y confirmar filas en MariaDB con acceso de lectura a la base. Publicarlo y abrirlo en sesión anónima; retirarlo y comprobar que listado/detalle dejan de exponerlo. Acordar su limpieza; no se crearon registros reales aquí.
4. Editar título/texto/imagen, reordenar y ocultar bloques. Confirmar respuesta pública y efecto en Home y páginas internas, incluidos carruseles y lightbox. Resolver el vínculo de IDs después de restaurar contenido editado.
5. Subir y eliminar una imagen de prueba; comprobar URL pública, límites y permisos reales. Editar ajustes de prueba y comprobar contactos/metadatos, considerando la caché pública observada de 60 segundos.
6. Con cuentas admin/editor/reader de prueba, comprobar permisos efectivos de escritura, usuarios y respaldos en el servidor. Validar invitación y entrega de correo sin modificar cuentas reales.
7. Exportar respaldo, revisar que no contenga secretos, y restaurarlo **solo en base aislada autorizada**, comprobando transacción, contenido e identidad de bloques. El restablecimiento también se verifica allí; nunca en producción durante revisión.
8. Comparación visual final desktop/mobile en el dominio canónico con contenido real. El origen de pruebas locales no demuestra CORS, cookies ni caché del hosting.

No se ejecutó PHP/MariaDB en este entorno ni se usó una cuenta administrativa real. La aprobación de producción queda pendiente de estas verificaciones. Vercel tiene deshabilitado el despliegue automático únicamente para esta rama en `vercel.json`; no se lanzó despliegue ni se fusionó main.
