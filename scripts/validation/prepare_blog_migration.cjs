#!/usr/bin/env node
"use strict";
/**
 * Preparación OFFLINE de seis artículos históricos. NO conecta a MariaDB.
 * Uso: node scripts/validation/prepare_blog_migration.cjs [directorio-salida]
 * Los SQL generados requieren aprobación y validación del esquema antes de ejecutarse.
 */
const fs = require("node:fs");
const path = require("node:path");
const seed = require("../../data/approved-posts.seed.json");
const output = path.resolve(process.argv[2] || "migration-review");
const expected = ["title","slug","category","excerpt","body","image_url","status"];
function sql(value) {
  if (typeof value !== "string") throw Error("Campo no textual");
  return "'" + value.replace(/\\/g,"\\\\").replace(/'/g,"''") + "'";
}
function main() {
  if (seed.posts.length !== 6 || new Set(seed.posts.map(p=>p.slug)).size !== 6) throw Error("Se requieren seis slugs únicos");
  for (const p of seed.posts) {
    if (expected.some(k=>typeof p[k]!=="string") || p.status!=="borrador" || p.body!=="") throw Error("Semilla inesperada: revisar antes de preparar");
    if (!/^[a-z0-9-]+$/.test(p.slug)) throw Error("Slug inválido");
  }
  fs.mkdirSync(output,{recursive:true});
  const lines = [
    "-- SOLO REVISIÓN. NO EJECUTAR EN PRODUCCIÓN NI EN BASE COMPARTIDA.",
    "-- Requiere esquema posts(id, title, slug, category, excerpt, body, image_url, status, created_at, updated_at).",
    "-- Probar primero en una copia AISLADA de MariaDB y verificar respaldos.",
    "-- No se inventan cuerpos: los seis artículos históricos solo tienen extractos.",
    "-- Se insertan como BORRADOR, sin modificar ni publicar artículos existentes.",
    "START TRANSACTION;"
  ];
  for (const p of seed.posts) {
    const fields = ["title","slug","category","excerpt","body","image_url","status"];
    const vals = fields.map(k=>sql(p[k]));
    lines.push(
      "INSERT INTO posts (title, slug, category, excerpt, body, image_url, status, created_at, updated_at)",
      "SELECT "+vals.join(", ")+", NOW(), NOW()",
      "WHERE NOT EXISTS (SELECT 1 FROM posts WHERE slug = "+sql(p.slug)+");"
    );
  }
  lines.push("SELECT slug, status, title, category, image_url FROM posts WHERE slug IN ("+seed.posts.map(p=>sql(p.slug)).join(", ")+") ORDER BY slug;");
  lines.push("-- Si el resultado es correcto, confirmar manualmente en el entorno aislado.");
  lines.push("COMMIT;");
  fs.writeFileSync(path.join(output,"blog-six-drafts.REVIEW-ONLY.sql"),lines.join("\n")+"\n");
  fs.writeFileSync(path.join(output,"blog-six-drafts.manifest.json"),JSON.stringify({
    scope:"offline-review-only",source:"data/approved-posts.seed.json",count:6,
    note:"Cuerpos completos ausentes. Publicación y ejecución NO autorizadas.",
    posts:seed.posts.map(p=>({slug:p.slug,title:p.title,status:p.status,body_complete:false}))
  },null,2)+"\n");
  process.stdout.write("PASS: seis borradores preparados sin acceso a base de datos. Salida: "+output+"\n");
}
main();
