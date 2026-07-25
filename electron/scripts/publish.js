#!/usr/bin/env node
'use strict';

/**
 * publish.js — Sube el instalador y latest.yml a Hostinger via HTTPS.
 *
 * Requiere que upload-handler.php y serve-update.php estén en el servidor.
 * Uso: npm run publish
 */

const path  = require('path');
const fs    = require('fs');
const https = require('https');
const http  = require('http');
const { URL } = require('url');

const ROOT = path.join(__dirname, '..');
const DIST = path.join(ROOT, 'dist');

function parseEnvFile(filePath) {
  const result = {};
  const lines  = fs.readFileSync(filePath, 'utf8').split(/\r?\n/);
  for (const line of lines) {
    const t = line.trim();
    if (!t || t.startsWith('#')) continue;
    const idx = t.indexOf('=');
    if (idx < 0) continue;
    result[t.slice(0, idx).trim()] = t.slice(idx + 1).trim();
  }
  return result;
}

function progressBar(pct, width = 28) {
  const filled = Math.round(pct / 100 * width);
  return '█'.repeat(filled) + '░'.repeat(width - filled);
}

function uploadFile(uploadUrl, filePathOrBuffer, filename, secret) {
  return new Promise((resolve, reject) => {
    const isBuffer  = Buffer.isBuffer(filePathOrBuffer);
    const fileSize  = isBuffer ? filePathOrBuffer.length : fs.statSync(filePathOrBuffer).size;
    const parsed    = new URL(uploadUrl);
    const lib       = parsed.protocol === 'https:' ? https : http;

    const req = lib.request({
      hostname: parsed.hostname,
      port:     parsed.port || (parsed.protocol === 'https:' ? 443 : 80),
      path:     parsed.pathname + parsed.search,
      method:   'POST',
      headers: {
        'X-Upload-Secret': secret,
        'X-Filename':      filename,
        'Content-Length':  fileSize,
        'Content-Type':    'application/octet-stream',
      },
    }, (res) => {
      let body = '';
      res.on('data', d => (body += d.toString()));
      res.on('end', () => {
        if (res.statusCode === 200) resolve(body.trim());
        else reject(new Error(`HTTP ${res.statusCode}: ${body.trim()}`));
      });
    });

    req.on('error', reject);

    if (isBuffer) {
      req.end(filePathOrBuffer);
    } else {
      const stream = fs.createReadStream(filePathOrBuffer);
      let uploaded = 0;
      stream.on('data', (chunk) => {
        uploaded += chunk.length;
        const pct = Math.round((uploaded / fileSize) * 100);
        process.stdout.write(`\r  ${String(pct).padStart(3)}%  [${progressBar(pct)}]  `);
      });
      stream.on('error', reject);
      stream.pipe(req);
    }
  });
}

// Rewrite latest.yml so the exe URL is just the encoded filename.
// .htaccess on Hostinger routes these requests to serve-update.php (since
// LiteSpeed blocks direct .exe serving). The url: field MUST end in .exe so
// electron-updater's getCacheUpdateFileName() picks the correct branch and
// creates a valid Windows temp filename (the else branch uses path.basename
// of the raw url string which includes '?' — invalid in Windows filenames).
function patchLatestYml(ymlContent, exeFilename) {
  const encoded = encodeURIComponent(exeFilename);
  return ymlContent
    .replace(/^(\s*- url:\s*)(.+\.exe\s*)$/m, `$1${encoded}`);
}

async function main() {
  const envFile = path.join(ROOT, '.env.publish');
  if (!fs.existsSync(envFile)) {
    console.error('\nERROR: electron/.env.publish no encontrado.');
    console.error('Copiá electron/.env.publish.example a electron/.env.publish y completalo.\n');
    process.exit(1);
  }

  const env    = parseEnvFile(envFile);
  const url    = env.UPLOAD_URL;
  const secret = env.UPLOAD_SECRET;

  if (!url || !secret || secret === 'COMPLETAR') {
    console.error('\nERROR: UPLOAD_URL y UPLOAD_SECRET deben estar completos en .env.publish\n');
    process.exit(1);
  }

  // ── Artefactos ──────────────────────────────────────────────────────────────
  const latestYmlPath = path.join(DIST, 'latest.yml');
  if (!fs.existsSync(latestYmlPath)) {
    console.error('\nERROR: dist/latest.yml no encontrado. Ejecutá npm run build primero.\n');
    process.exit(1);
  }

  // Read yml first — it's the source of truth for which exe to upload
  const originalYml   = fs.readFileSync(latestYmlPath, 'utf8');
  const ymlPathMatch  = originalYml.match(/^path:\s*(.+\.exe)\s*$/m);
  const exeFile       = ymlPathMatch ? ymlPathMatch[1].trim() : null;
  if (!exeFile || !fs.existsSync(path.join(DIST, exeFile))) {
    console.error(`\nERROR: Instalador "${exeFile}" no encontrado en dist/.`);
    console.error('Ejecutá npm run build primero.\n');
    process.exit(1);
  }
  const exePath    = path.join(DIST, exeFile);
  const exeMb      = (fs.statSync(exePath).size / 1024 / 1024).toFixed(1);
  const patchedYml = patchLatestYml(originalYml, exeFile);

  console.log('\n─────────────────────────────────────────');
  console.log(' Logos POS — Publicar actualización');
  console.log('─────────────────────────────────────────');
  console.log(` Installer : ${exeFile} (${exeMb} MB)`);
  console.log(` Upload URL: ${url}`);
  console.log('─────────────────────────────────────────\n');

  try {
    // Non-fatal uploads: these are infrastructure files that may not be allowed
    // on the server yet. They fail gracefully and only need uploading once.
    for (const [localFile, remoteFile] of [
      ['serve-update.php', 'serve-update.php'],
      ['htaccess-logos',   '.htaccess'],
    ]) {
      const localPath = path.join(__dirname, localFile);
      if (!fs.existsSync(localPath)) continue;
      process.stdout.write(`Subiendo ${remoteFile} ... `);
      try {
        const r = await uploadFile(url, localPath, remoteFile, secret);
        console.log(`OK  (${r})`);
      } catch (e) {
        console.log(`SKIP  (${e.message})`);
      }
    }

    // Upload patched latest.yml (in-memory, no temp file)
    process.stdout.write('Subiendo latest.yml ... ');
    const r1 = await uploadFile(url, Buffer.from(patchedYml, 'utf8'), 'latest.yml', secret);
    console.log(`OK  (${r1})`);

    // Upload the exe
    console.log(`Subiendo ${exeFile}:`);
    const r2 = await uploadFile(url, exePath, exeFile, secret);
    console.log(`\n  OK  (${r2})\n`);

    console.log('✓ Publicado. La próxima vez que abran la app con una versión');
    console.log('  anterior, recibirán la actualización automáticamente.\n');
  } catch (err) {
    console.error(`\nERROR al publicar: ${err.message}\n`);
    process.exit(1);
  }
}

main();
