/**
 * Downloads PHP portable + MariaDB portable into electron/resources/.
 * Run once with: npm run setup-deps   (from inside the electron/ folder)
 * Use --force to re-download even if binaries already exist.
 *
 * Versions (pinned — update URLs here when upgrading):
 *   PHP     8.2.32 NTS x64  (Non Thread Safe — built-in server, not Apache/IIS module)
 *   MariaDB 10.11.18 LTS x64
 */
'use strict';
const https    = require('https');
const fs       = require('fs');
const path     = require('path');
const { execSync } = require('child_process');

const FORCE     = process.argv.includes('--force');
const RESOURCES = path.join(__dirname, '..', 'resources');

const DEPS = [
  {
    name:          'PHP 8.2.32 NTS x64',
    url:           'https://downloads.php.net/~windows/releases/php-8.2.32-nts-Win32-vs16-x64.zip',
    dest:          path.join(RESOURCES, 'php'),
    zipName:       'php.zip',
    stripTopLevel: false,
    verify:        ['php.exe'],
  },
  {
    name:          'MariaDB 10.11.18 x64',
    url:           'https://archive.mariadb.org/mariadb-10.11.18/winx64-packages/mariadb-10.11.18-winx64.zip',
    dest:          path.join(RESOURCES, 'mariadb'),
    zipName:       'mariadb.zip',
    stripTopLevel: true,  // zip has top-level folder; strip so bin/mysqld.exe lands at resources/mariadb/bin/
    verify:        ['bin/mysqld.exe', 'bin/mysql.exe', 'bin/mysqladmin.exe', 'bin/mysql_install_db.exe'],
  },
  {
    name:          'NSSM 2.24-101 x64 (Windows service manager)',
    url:           'https://nssm.cc/ci/nssm-2.24-101-g897c7ad.zip',
    dest:          RESOURCES,     // single file extracted directly to resources/
    zipName:       'nssm.zip',
    stripTopLevel: false,
    // Custom extraction: pull only win64/nssm.exe out of the zip
    extractFile:   { zipPath: 'nssm-2.24-101-g897c7ad/win64/nssm.exe', outPath: 'nssm.exe' },
    verify:        ['nssm.exe'],
  },
];

// ── Download ──────────────────────────────────────────────────────────────────
function download(url, dest) {
  return new Promise((resolve, reject) => {
    const file = fs.createWriteStream(dest);

    const request = (u) =>
      https.get(u, { headers: { 'User-Agent': 'logos-pos-setup/1.0' } }, res => {
        if (res.statusCode === 301 || res.statusCode === 302) {
          file.destroy();
          return request(res.headers.location);
        }
        if (res.statusCode !== 200) {
          file.destroy();
          return reject(new Error(`HTTP ${res.statusCode} desde ${u}`));
        }
        const total = parseInt(res.headers['content-length'] || '0', 10);
        let received = 0;
        res.on('data', chunk => {
          received += chunk.length;
          if (total) {
            const pct = Math.round((received / total) * 100);
            process.stdout.write(`\r  ${pct}% (${(received / 1024 / 1024).toFixed(1)} MB / ${(total / 1024 / 1024).toFixed(1)} MB)`);
          }
        });
        res.pipe(file);
        file.on('finish', () => { process.stdout.write('\n'); resolve(); });
        file.on('error', reject);
        res.on('error', reject);
      }).on('error', reject);

    request(url);
  });
}

// ── Extract ───────────────────────────────────────────────────────────────────
function extract(zipPath, dep) {
  const { dest: destDir, stripTopLevel, extractFile } = dep;
  fs.mkdirSync(destDir, { recursive: true });

  if (extractFile) {
    // Pull one specific file out of the zip, rename on the way
    const tmp = destDir + '__nssm_tmp';
    fs.mkdirSync(tmp, { recursive: true });
    execSync(
      `powershell -NoProfile -Command "Expand-Archive -LiteralPath '${zipPath}' -DestinationPath '${tmp}' -Force"`,
      { stdio: 'inherit' }
    );
    const src = path.join(tmp, ...extractFile.zipPath.split('/'));
    const dst = path.join(destDir, extractFile.outPath);
    fs.copyFileSync(src, dst);
    fs.rmSync(tmp, { recursive: true, force: true });
  } else if (stripTopLevel) {
    const tmp = destDir + '__tmp';
    fs.mkdirSync(tmp, { recursive: true });
    execSync(
      `powershell -NoProfile -Command "Expand-Archive -LiteralPath '${zipPath}' -DestinationPath '${tmp}' -Force"`,
      { stdio: 'inherit' }
    );
    const entries = fs.readdirSync(tmp);
    if (entries.length !== 1)
      throw new Error(`Se esperaba 1 carpeta raíz en el zip, se encontraron: ${entries.join(', ')}`);
    const inner = path.join(tmp, entries[0]);
    execSync(`xcopy /E /I /Y /Q "${inner}\\*" "${destDir}\\"`, { stdio: 'inherit' });
    fs.rmSync(tmp, { recursive: true, force: true });
  } else {
    execSync(
      `powershell -NoProfile -Command "Expand-Archive -LiteralPath '${zipPath}' -DestinationPath '${destDir}' -Force"`,
      { stdio: 'inherit' }
    );
  }
}

// ── php.ini (production) ──────────────────────────────────────────────────────
// NOTE: error_log is NOT set here — it is passed as -d error_log=<userData>/logs/php-error.log
// at runtime by server-manager.js so each install writes to its own userData folder.
function writePHPIni(phpDir) {
  const ini = [
    '; Logos POS — php.ini (production portable)',
    '; Generated by npm run setup-deps — do not edit manually.',
    '',
    'extension_dir = "ext"',
    '',
    '; Disable short open tags to avoid <?xml in JS template literals being parsed as PHP',
    'short_open_tag = Off',
    '',
    '; Core extensions required by Logos POS',
    '; Note: xml, dom, xmlwriter, xmlreader are statically compiled in — no DLL needed',
    'extension=pdo_mysql',
    'extension=gd',
    'extension=mbstring',
    'extension=curl',
    'extension=openssl',
    'extension=zip',
    'extension=fileinfo',
    'extension=intl',
    'extension=mysqli',
    '',
    '; Execution limits',
    'max_execution_time = 120',
    'memory_limit = 256M',
    'upload_max_filesize = 20M',
    'post_max_size = 22M',
    '',
    '; Locale',
    'date.timezone = America/Argentina/Buenos_Aires',
    '',
    '; Error handling — display off, log on (path set at runtime via -d error_log)',
    'error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT',
    'display_errors = Off',
    'log_errors = On',
    '',
    '; Session',
    'session.gc_maxlifetime = 28800',
    '',
  ].join('\n');

  fs.writeFileSync(path.join(phpDir, 'php.ini'), ini, 'utf8');
  console.log('  ✓ php.ini escrito');
}

// ── Post-extract validation ───────────────────────────────────────────────────
function verifyBinaries(dep) {
  const missing = dep.verify.filter(rel => !fs.existsSync(path.join(dep.dest, rel)));
  if (missing.length) {
    throw new Error(
      `Validación fallida para ${dep.name}.\n` +
      `Binarios ausentes en ${dep.dest}:\n` +
      missing.map(f => `  ${f}`).join('\n')
    );
  }
  console.log(`  ✓ Binarios verificados: ${dep.verify.join(', ')}`);
}

// ── Main ──────────────────────────────────────────────────────────────────────
async function main() {
  console.log('\nLogos POS — Descarga de dependencias portables\n');
  if (FORCE) console.log('  (modo --force: re-descargando aunque ya existan)\n');

  const tmpDir = path.join(RESOURCES, '_tmp');
  fs.mkdirSync(tmpDir, { recursive: true });

  for (const dep of DEPS) {
    const alreadyDone = !FORCE &&
      fs.existsSync(dep.dest) &&
      dep.verify.every(rel => fs.existsSync(path.join(dep.dest, rel)));

    if (alreadyDone) {
      console.log(`${dep.name}: ya presente, omitiendo. (--force para re-descargar)\n`);
      continue;
    }

    console.log(`Descargando ${dep.name}...`);
    console.log(`  ${dep.url}`);

    if (FORCE && fs.existsSync(dep.dest))
      fs.rmSync(dep.dest, { recursive: true, force: true });

    const zipPath = path.join(tmpDir, dep.zipName);
    try {
      await download(dep.url, zipPath);
      console.log(`  Extrayendo a ${dep.dest}...`);
      extract(zipPath, dep);
      fs.unlinkSync(zipPath);
      verifyBinaries(dep);
      console.log(`  ✓ ${dep.name} listo.\n`);
    } catch (err) {
      console.error(`\n  ✗ Falló: ${err.message}`);
      if (err.message.includes('HTTP')) {
        console.error(`\n  La URL puede estar desactualizada.`);
        console.error(`  Actualizá la URL en scripts/download-deps.js y volvé a intentar.`);
        console.error(`  O descargá manualmente y extraé a: ${dep.dest}`);
      }
      fs.rmSync(tmpDir, { recursive: true, force: true });
      process.exit(1);
    }
  }

  fs.rmSync(tmpDir, { recursive: true, force: true });

  // Write php.ini after PHP binaries are confirmed present
  const phpDir = path.join(RESOURCES, 'php');
  if (fs.existsSync(path.join(phpDir, 'php.exe'))) {
    console.log('Configurando php.ini...');
    writePHPIni(phpDir);
  }

  console.log('\n✓ Dependencias listas.');
  console.log('  Para probar sin XAMPP:  npm start');
  console.log('  Para probar con XAMPP:  npm run start:dev\n');
}

main().catch(err => { console.error(err.message); process.exit(1); });
