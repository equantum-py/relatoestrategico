# Contrato de identidad de bloques — propuesta para contraste con cPanel

**Estado: implementado y probado en frontend; pendiente de confirmación/adaptación del PHP vigente por Claude.** No es un contrato observado en producción. No se autoriza modificar el backend productivo.

Comprobación pública de esta revisión: `GET /v1/public/pages/home` respondió 200, siete bloques, sin `identity_schema` y sin `block_key`. GitHub no contiene una copia nueva del PHP efectivamente desplegado. Por eso la candidata conserva el HTML aprobado para esas respuestas; no intenta adivinar vínculos.

## Forma mínima de lectura pública

```json
{
  "ok": true,
  "page": {
    "key": "home",
    "identity_schema": "relato.block-key.v1",
    "blocks": [
      {
        "block_key": "home.hero",
        "id": 1042,
        "sort_order": 1,
        "title": "Título guardado",
        "subtitle": "",
        "description": "Texto guardado",
        "button": "",
        "url": "",
        "image_url": ""
      }
    ]
  }
}
```

`block_key` es la única identidad usada por el frontend. `id` puede cambiar; se mantiene solo como dirección del recurso administrativo. Título, orden y posición visual nunca se usan para reconstruir una identidad. `sort_order` sigue siendo presentación.

El catálogo explícito de 39 claves está en `data/block-identity-bootstrap.json`. Los identificadores observados y títulos que contiene son precondiciones para revisar una **migración inicial única**, no son identidad de runtime. Claude debe contrastar cada fila con la base y la plantilla aprobada. Hay etiquetas históricas del panel distintas del copy actual: no derivar claves del título ni asignarlas automáticamente por orden. Si algún vínculo no corresponde o un dato cambió, detener la asignación y corregir el catálogo explícito antes de la prueba.

## Obligaciones del backend a demostrar en aislamiento

1. Persistir una clave única e inmutable para cada bloque. Las claves conocidas son las del catálogo revisado. Para bloques nuevos, generar una clave persistente `<page>.custom.<uuid>` en el servidor; no regenerarla al exportar/restaurar ni derivarla del contenido.
2. Incluir la misma clave en lecturas administrativas, exportación y restauración. Una edición normal no debe poder cambiarla. El campo no se expone como entrada editable en el panel.
3. Validar unicidad y pertenencia a la página. Rechazar claves duplicadas, de otra página, desconocidas o ausentes antes de comenzar una restauración; usar transacción y restricciones consistentes en MariaDB. Definir tratamiento explícito y aislado de respaldos antiguos sin claves: no inferir silenciosamente por título/posición.
4. Conservar las claves aunque se reasignen IDs/autoincrement. No permitir que un ID reutilizado cambie la asociación. La importación no debe convertir un bloque editado en un bloque nuevo solo porque cambió su título.
5. En rutas públicas, devolver solo bloques visibles. La lista es completa para esa página. No filtrar bloques ocultos en el navegador ni exponer su contenido. El cliente deriva las plantillas ausentes únicamente después de validar todo el contrato.
6. Emitir `identity_schema` solo después de adoptar y verificar el contrato para la página completa. El frontend rechaza toda respuesta sin versión, con claves faltantes/duplicadas o campos incompletos, sin aplicar ocultaciones o cambios parciales.

Las claves conocidas son semánticas y se asignan explícitamente a nodos de la plantilla aprobada. La selección de nodos DOM y el orden de presentación no generan identidades de contenido.

## Prueba mínima de cierre con PHP/MariaDB reales

En base aislada: capturar 39 claves y contenido; editar todos los títulos con marcadores ficticios; exportar; cambiar el rango de IDs de destino y restaurar. Comprobar por SELECT y por API que las mismas claves señalan los mismos marcadores, orden y visibilidad. Repetir con IDs reutilizados en distinto orden. La restauración inválida/duplicada debe dejar el estado anterior intacto.

Las pruebas Node de PR #5 cubren el consumidor frontend del contrato propuesto, no prueban que estas obligaciones ya estén implementadas en el PHP desplegado. Mantener NO GO hasta recibir el contraste y la evidencia SQL de Claude.
