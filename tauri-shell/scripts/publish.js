#!/usr/bin/env node
'use strict';

/**
 * publish.js — Firma el instalador de Logos POS (Tauri), arma latest.json
 * y sube todo a Hostinger via HTTPS. Adaptado de electron/scripts/publish.js
 * — mismo servidor, mismo upload-handler.php, mismo mecanismo de subida.
 *
 * Diferencias frente al de Electron:
 *   - No hay un "dist/latest.yml" generado por el bundler — el instalador
 *     de Tauri no se autofirma en el build, así que este script llama a
 *     `tauri signer sign` él mismo antes de armar el manifiesto.
 *   - El manifiesto que se sube se llama `latest.json` (no `latest.yml`),
 *     para convivir en el mismo dominio sin pisar el canal de Electron
 *     mientras electron/ siga en el repo sin usarse.
 *
 * Uso: node scripts/publish.js
 */

const path  = require('path');
const fs    = require('fs');
const https = require('https');
const http  = require('http');
const { URL } = require('url');
const { execFileSync } = require('child_process');

const ROOT      = path.join(__dirname, '..');
const SRC_TAURI = path.join(ROOT, 'src-tauri');
const BUNDLE_DIR = path.join(SRC_TAURI, 'target', 'release', 'bundle', 'nsis');

// Misma clave que se usó durante todo el desarrollo del shell de Tauri —
// nunca se le entregó nada firmado con ella a ningún cliente real, así que
// no hay necesidad técnica de rotarla en este corte a producción.
const SIGNING_KEY_PATH = path.join(process.env.USERPROFILE || process.env.HOME, '.tauri-keys', 'logos-poc-updater.key');

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

async function main() {
  const envFile = path.join(ROOT, '.env.publish');
  if (!fs.existsSync(envFile)) {
    console.error('\nERROR: tauri-shell/.env.publish no encontrado.');
    console.error('Copiá tauri-shell/.env.publish.example a tauri-shell/.env.publish y completalo\n' +
      '(o copiá directo electron/.env.publish — son las mismas credenciales).\n');
    process.exit(1);
  }
  if (!fs.existsSync(SIGNING_KEY_PATH)) {
    console.error(`\nERROR: no se encontró la clave de firma en ${SIGNING_KEY_PATH}\n`);
    process.exit(1);
  }

  const env    = parseEnvFile(envFile);
  const url    = env.UPLOAD_URL;
  const secret = env.UPLOAD_SECRET;

  if (!url || !secret || secret === 'COMPLETAR_con_secreto_generado_por_upload-handler') {
    console.error('\nERROR: UPLOAD_URL y UPLOAD_SECRET deben estar completos en .env.publish\n');
    process.exit(1);
  }

  // ── Artefactos ──────────────────────────────────────────────────────────────
  const tauriConf = JSON.parse(fs.readFileSync(path.join(SRC_TAURI, 'tauri.conf.json'), 'utf8'));
  const version   = tauriConf.version;
  const productName = tauriConf.productName;

  const exeFile = `${productName}_${version}_x64-setup.exe`;
  const exePath = path.join(BUNDLE_DIR, exeFile);
  if (!fs.existsSync(exePath)) {
    console.error(`\nERROR: Instalador "${exeFile}" no encontrado en ${BUNDLE_DIR}.`);
    console.error('Ejecutá "npx tauri build" primero.\n');
    process.exit(1);
  }
  const exeMb = (fs.statSync(exePath).size / 1024 / 1024).toFixed(1);

  console.log('\n─────────────────────────────────────────');
  console.log(' Logos POS (Tauri) — Publicar actualización');
  console.log('─────────────────────────────────────────');
  console.log(` Installer : ${exeFile} (${exeMb} MB)`);
  console.log(` Upload URL: ${url}`);
  console.log('─────────────────────────────────────────\n');

  // ── Firma ───────────────────────────────────────────────────────────────────
  process.stdout.write('Firmando instalador... ');
  execFileSync('npx', ['tauri', 'signer', 'sign', '-f', SIGNING_KEY_PATH, '-p', '', exePath], {
    cwd: ROOT,
    stdio: ['ignore', 'ignore', 'pipe'],
    shell: true,
  });
  const sigPath = `${exePath}.sig`;
  if (!fs.existsSync(sigPath)) {
    console.log('FALLÓ');
    console.error(`\nERROR: no se generó ${sigPath}\n`);
    process.exit(1);
  }
  const signature = fs.readFileSync(sigPath, 'utf8').trim();
  console.log('OK');

  // ── Manifiesto latest.json ─────────────────────────────────────────────────
  const uploadUrlObj = new URL(url);
  const baseUrl       = `${uploadUrlObj.protocol}//${uploadUrlObj.host}/`;
  const downloadUrl   = baseUrl + encodeURIComponent(exeFile);

  const manifest = {
    version,
    notes: `Logos POS ${version}`,
    pub_date: new Date().toISOString(),
    platforms: {
      'windows-x86_64': {
        signature,
        url: downloadUrl,
      },
    },
  };
  const manifestJson = JSON.stringify(manifest, null, 2);

  try {
    // Non-fatal uploads: infrastructure files that may not need re-uploading
    // (ya están en el servidor desde la publicación de Electron), fallan
    // gracefully si no existen localmente o si el servidor las rechaza.
    for (const [localFile, remoteFile] of [
      ['../electron/scripts/serve-update.php', 'serve-update.php'],
      ['../electron/scripts/htaccess-logos',   '.htaccess'],
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

    process.stdout.write('Subiendo latest.json ... ');
    const r1 = await uploadFile(url, Buffer.from(manifestJson, 'utf8'), 'latest.json', secret);
    console.log(`OK  (${r1})`);

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
