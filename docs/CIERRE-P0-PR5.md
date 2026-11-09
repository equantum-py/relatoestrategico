# Cierre P0 de PR #5 — frontend corregido, integración pendiente

**Dictamen: NO GO para fusión y despliegue.** Revisión de `e4172ce`, exclusivamente en `integration/frontend-php-api`. No se creó otra rama ni se modificó el backend productivo.

## Estado de los dos defectos

| Punto | Evidencia PASS | Pendiente / resultado final |
| --- | --- | --- |
| P1: identidad estable | El frontend usa únicamente `block_key` semántica. 39 vínculos conservados con IDs nuevos, títulos editados y orden invertido; IDs reutilizados no cambian el vínculo. Claves ausentes, duplicadas o de otra página rechazan la respuesta completa antes de modificar el DOM. | **Bloqueado de extremo a extremo.** El GET real de Home sigue devolviendo siete bloques sin `block_key` ni `identity_schema`. El consumidor del contrato propuesto está corregido; falta contrastar/adoptar ese contrato en el PHP vigente y demostrarlo mediante restauración en MariaDB aislada. |
| P2: transición del blog | Preparados los seis resúmenes existentes con título, categoría, extracto e imagen exactos. Home conserva sus tres tarjetas y Blog sus seis entradas hasta un corte explícito. Una vez seleccionada la API, cero artículos, 404 o indisponibilidad no resucitan el contenido estático. | La carga inicial **no se ejecutó**. Hay que validar escritura, filas y publicación en aislamiento antes de autorizar la futura carga/corte. No se inventaron cuerpos completos: la fuente solo contiene resúmenes. |

## Cambios correctivos

- `public/js/content-api.js`: elimina la identidad por ID/título/posición, incluyendo el antiguo atajo `template_key`. Exige `identity_schema: relato.block-key.v1` y una `block_key` persistente. El catálogo asocia claves literales a la plantilla aprobada; su orden numérico solo controla presentación. No se infiere la identidad del contenido recibido.
- `public/js/public-content.js`: vincula por esas claves y conserva el HTML aprobado ante contratos legados/incompletos. Mantiene el orden de diseño para la semilla sin reordenamientos explícitos.
- `data/block-identity-bootstrap.json`: 39 claves propuestas y precondiciones de asignación inicial para que Claude las contraste con cPanel. Los IDs/títulos observados son comprobaciones de una migración única, nunca identidad permanente. No se ejecuta automáticamente.
- `data/approved-posts.seed.json`: seis cargas de **borrador**, extraídas de la fuente existente. No es un backup ni se debe enviar a `/backup/restore`.
- `publicationSource = "approved-static"`: decisión de esta versión, sin depender de red, caché ni cantidad de artículos. Se mantiene así. Cambiar a `api` requiere otra decisión posterior al cierre de las verificaciones; aquí no se cambió la fuente pública ni se desplegó.
- Un artículo importado sin cuerpo conserva la presentación de resumen editorial existente. No se añadieron textos completos, autores, fechas ni imágenes nuevas.

No se cambió CSS ni la estructura visual comercial. No hay nuevas funciones de negocio ni servicios.

## Coordinación necesaria con Claude — sin afirmar acceso que no existe

No hay conexión disponible a cPanel/SSH/MariaDB ni una copia nueva del PHP desplegado en GitHub. La rama backend continúa en `e1b3b83`; no se la copió sobre producción. No se ha transmitido este trabajo a una sesión externa de Claude: el contrato queda versionado para su contraste.

[Contrato técnico y prueba exigida](P0-CONTRATO-BLOQUES.md): Claude debe inspeccionar `/home/cre116835/relato-app` y `/home/cre116835/public_html/relato-api`, confirmar campos reales, revisar la asignación semántica de los 39 bloques y aportar copia sin secretos/hashes. Luego debe demostrar, en una base aislada, que las claves persisten al editar, exportar y restaurar, incluso con IDs distintos o reutilizados. La versión del contrato no debe anunciarse hasta completar esa adopción.

Si el PHP vigente ya tiene otro identificador inmutable verificable, contrastar su semántica y adaptar explícitamente el consumidor en esta PR. No añadir un alias por título o posición para compatibilizar una respuesta antigua.

## Carga inicial preparada

[Procedimiento de carga, comparación y corte](P0-CARGA-ARTICULOS.md).

Los seis registros conservan los valores literales de `fallbackPosts` en `e4172ce`. `body` permanece vacío porque no hay cuerpos completos en esa fuente. Si Dirección requiere el artículo completo, debe aportar el original aprobado; la carga preparada no lo sustituye por redacción nueva.

El verificador local detecta omisiones, duplicaciones y cambios de título, extracto, cuerpo o URL de imagen:

```bash
node scripts/validation/verify_approved_posts.cjs exportacion-aislada.json borrador
node scripts/validation/verify_approved_posts.cjs exportacion-aislada.json publicado
```

El PASS contra el propio archivo preparado solo valida formato/valores locales; no demuestra filas ni persistencia MariaDB. El procedimiento indica consultar slugs antes de cargar, omitir únicamente coincidencias exactas y detenerse ante conflictos. No se creó ni publicó ningún artículo.

## Validaciones indispensables

| Validación | Resultado |
| --- | --- |
| Regresiones frontend afectadas | **PASS:** 11 pruebas focalizadas. No se repitieron las pruebas unitarias no afectadas. |
| Identidad con claves persistentes | **PASS contractual:** 39/39 con IDs nuevos/títulos editados/orden invertido; IDs reutilizados y claves inválidas cubiertos. No es restauración SQL. |
| Transición y fidelidad de carga | **PASS local:** seis entradas exactas, tres tarjetas de Home preservadas, fuente explícita y verificador de diferencias. |
| Desktop/mobile afectados | **PASS local:** 18 comprobaciones de los flujos corregidos a 390 y 1440 px; cero errores JS ni overflow horizontal. Revisión de capturas de la presentación existente. |
| Imágenes | **PASS de valores:** URLs originales idénticas. En las capturas del navegador de pruebas algunas imágenes externas no cargaron; no se sustituyeron ni se declara aceptación visual de sus píxeles en producción. Las seis URLs respondieron HEAD 200 con `image/jpeg`; esa evidencia HTTP no sustituye la comprobación visual en el dominio real. |
| Contrato estable de API real | **BLOQUEADO:** lectura real 200 sin claves/versionado. Sin acceso al PHP efectivo para contrastar las escrituras. |
| Login/logout real | **BLOQUEADO:** sin sesión interactiva autorizada ni acceso a una candidata existente admitida por CORS. El panel productivo observado sigue siendo DEMO. El script seguro preparado previamente sigue disponible; no se pidieron ni almacenaron contraseñas. No se repitió el intento no interactivo. |
| Escrituras, persistencia MariaDB, restauración | **BLOQUEADO:** no hay instancia aislada/código vigente disponibles. Cero restauraciones SQL y cero escrituras productivas. |
| Medios, ajustes y permisos autenticados | **BLOQUEADO:** falta la sesión/instancia aislada. No se repitieron los GET 401 previamente aprobados ni se crearon usuarios en producción. |

Las 18 comprobaciones de navegador usan respuestas aisladas del contrato propuesto. No se presentan como pruebas de la API real, autenticación o base de datos. Las evidencias anteriores de `e4172ce` permanecen históricas y no sustituyen el cierre pendiente.

## Reproducción de lo afectado

```bash
node --test --test-name-pattern='P1|transición|artículos aprobados|binding semántico|contrato legado|semilla con claves' tests/content.test.cjs tests/restore-risk.test.cjs tests/transition.test.cjs
node tests/p0-browser.cjs
```

`CHROMIUM_EXECUTABLE` es opcional si se usa un Chromium instalado. Las pruebas aíslan toda la API y no ejecutan login ni escrituras reales. Evidencia: `docs/evidence/pr5-p0-frontend-20261109.json`.

## Acción exacta para completar el cierre

1. Claude/Dirección: entregar el código PHP vigente sin secretos y el contraste del contrato de claves. No copiar el paquete antiguo ni modificar producción.
2. Identificar una base/servicio de prueba **aislados y existentes o preparados por Dirección**, con código vigente y un origen admitido. No se autoriza aquí su despliegue ni cambios de credenciales/CORS.
3. Ejecutar allí sesión interactiva segura, carga de borradores, publicación/retiro de prueba, SELECT de verificación, restauración, medios/ajustes y roles. Aplicar la limpieza y recuperación exclusivamente aisladas ya definidas en `ACEPTACION-PR5.md`.
4. Aportar evidencias de identidad, filas y lectura pública. Hasta entonces, el consumidor permanece protegido frente al contrato legado y el blog conserva el contenido estático aprobado.

**No GO integrado:** las correcciones y preparativos frontend están probados; no se da por adoptado el contrato real ni por ejecutadas la carga o las validaciones SQL. Sin fusión, despliegue, cambios de credenciales, nuevos usuarios productivos ni contratación de servicios.
