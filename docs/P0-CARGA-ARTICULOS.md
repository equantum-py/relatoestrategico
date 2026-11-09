# Carga inicial de contenido aprobado — preparada, no ejecutada

`data/approved-posts.seed.json` contiene seis entradas extraídas literalmente del arreglo ya existente en `public/blog/index.html` de `e4172ce`.

Se preservan slugs, títulos, categorías, extractos y URLs de imágenes. Son **resúmenes editoriales**, no textos completos: el sitio existente dice que los cuerpos completos aún no están incorporados. `body` se mantiene vacío; no se inventaron párrafos, autores, fechas ni artículos. La candidata mantiene esa presentación de resumen después de importar una entrada sin cuerpo.

El archivo prepara `status: borrador`. No se llamó a la API para importarlo ni se publicó nada. No es un respaldo completo del panel ni debe enviarse a `/backup/restore`.

## Corte explícito y seguro

En `public/js/content-api.js`, `publicationSource` permanece en `approved-static`. Home conserva sus tres tarjetas existentes y Blog sus seis resúmenes. No se elige fuente contando artículos ni consultando un indicador remoto: es una decisión explícita de la versión del frontend.

Cambiar a `api` es un corte posterior que requiere validación y autorización. Después de ese corte, un listado vacío, un 404 o una caída de la API **no resucita** los resúmenes estáticos. Esto permite retirar contenido legítimamente. Un fallo muestra indisponibilidad; no se rellena con textos ficticios.

No se modificó CSS ni la estructura comercial aprobada. Las pruebas comparan las URLs originales de imágenes; en las capturas locales algunas imágenes externas no cargaron. No se sustituyeron por otras ni se certifica su disponibilidad visual en el dominio real.

## Procedimiento preparado para Claude/Dirección — solo base aislada

1. Confirmar el contrato de escritura contra el PHP vigente y el destino de prueba. No usar los contratos del ZIP antiguo como evidencia de esta versión.
2. Consultar cada slug antes de cargar. Si ya existe con idéntico título, categoría, extracto, cuerpo e imagen, omitir su creación. Ante una diferencia, detenerse y revisar; no sobrescribir contenido aprobado ni duplicar registros.
3. Crear únicamente las entradas faltantes como borradores mediante el contrato confirmado. Registrar IDs/slugs creados por esa carga y comprobar sus filas directamente en MariaDB.
4. Exportar las seis filas aisladas a JSON y comparar sin conectar el verificador a ninguna base:

```bash
node scripts/validation/verify_approved_posts.cjs exportacion-aislada.json borrador
```

5. Publicar en la instancia aislada para comprobar listado, detalle y correspondencia de imágenes. Volver a comparar:

```bash
node scripts/validation/verify_approved_posts.cjs exportacion-aislada.json publicado
```

6. Confirmar que los seis slugs aparecen por API y que los títulos, extractos y URLs coinciden. Si se desean cuerpos completos, Dirección debe aportar los originales aprobados; no sustituir resúmenes por redacción nueva.
7. Limpiar solo registros y archivos creados por la prueba, usando el manifiesto de IDs y slugs. Recuperar exclusivamente el estado inicial de la instancia aislada ante fallos; nunca restaurar producción para limpiar.
8. Antes del futuro despliegue, acordar y autorizar expresamente la carga productiva de contenido aprobado. Verificar los seis registros publicados y recién entonces preparar un commit que cambie `publicationSource` a `api`. En este cierre no se realiza la carga, el cambio de fuente, la fusión ni el despliegue.

El verificador PASS local demuestra igualdad de valores exportados; por sí solo no demuestra persistencia, permisos ni atomicidad de MariaDB.
