#!/usr/bin/env node
'use strict';
/**
 * patch-server.js — Sube .htaccess + serve-update.php + latest.yml al servidor
 * sin resubir el instalador (157 MB). Útil para corregir la configuración sin
 * necesidad de un rebuild completo.
 *
 * Uso: node scripts/patch-server.js
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
    if (isBuffer) req.end(filePathOrBuffer);
    else fs.createReadStream(filePathOrBuffer).pipe(req);
  });
}

function patchLatestYml(ymlContent, exeFilename) {
  const encoded = encodeURIComponent(exeFilename);
  return ymlContent
    .replace(/^(\s*- url:\s*)(.+\.exe\s*)$/m, `$1${encoded}`);
}

async function main() {
  const envFile = path.join(ROOT, '.env.publish');
  if (!fs.existsSync(envFile)) {
    console.error('\nERROR: electron/.env.publish no encontrado.\n');
    process.exit(1);
  }
  const env    = parseEnvFile(envFile);
  const url    = env.UPLOAD_URL;
  const secret = env.UPLOAD_SECRET;

  const latestYmlPath = path.join(DIST, 'latest.yml');
  if (!fs.existsSync(latestYmlPath)) {
    console.error('\nERROR: dist/latest.yml no encontrado. Ejecutá npm run build primero.\n');
    process.exit(1);
  }

  const originalYml  = fs.readFileSync(latestYmlPath, 'utf8');
  const ymlPathMatch = originalYml.match(/^path:\s*(.+\.exe)\s*$/m);
  const exeFile      = ymlPathMatch ? ymlPathMatch[1].trim() : 'Logos POS Setup.exe';
  const patchedYml   = patchLatestYml(originalYml, exeFile);

  console.log('\n─────────────────────────────────────────');
  console.log(' Logos POS — Patch servidor (sin exe)');
  console.log('─────────────────────────────────────────\n');

  try {
    // Infrastructure files (non-fatal if server doesn't allow them yet)
    for (const [localFile, remoteFile] of [
      ['serve-update.php', 'serve-update.php'],
      ['htaccess-logos',   '.htaccess'],
    ]) {
      const localPath = path.join(__dirname, localFile);
      if (!fs.existsSync(localPath)) { console.log(`  ${remoteFile}: archivo local no encontrado, salteando`); continue; }
      process.stdout.write(`  ${remoteFile} ... `);
      try {
        await uploadFile(url, localPath, remoteFile, secret);
        console.log('OK');
      } catch (e) {
        console.log(`SKIP (${e.message})`);
      }
    }

    // latest.yml
    process.stdout.write('  latest.yml ... ');
    await uploadFile(url, Buffer.from(patchedYml, 'utf8'), 'latest.yml', secret);
    console.log('OK');

    console.log('\n✓ Listo. latest.yml actualizado con URL directa al .exe (.htaccess hace el proxy).\n');
    console.log('  URL nueva: ' + encodeURIComponent(exeFile));
  } catch (err) {
    console.error(`\nERROR: ${err.message}\n`);
    process.exit(1);
  }
}

main();
