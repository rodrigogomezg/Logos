'use strict';
const { app, BrowserWindow, ipcMain, Menu, Tray, net, nativeImage } = require('electron');
const { execFileSync } = require('child_process');
const path = require('path');
const os = require('os');

const ConfigManager = require('./services/config-manager');
const ServerManager = require('./services/server-manager');

// ── Single-instance lock ──────────────────────────────────────────────────────
if (!app.requestSingleInstanceLock()) {
  app.quit();
  process.exit(0);
}

// ── Path resolution (dev vs packaged) ────────────────────────────────────────
const IS_PACKAGED  = app.isPackaged;
const RESOURCES    = IS_PACKAGED ? process.resourcesPath : path.join(__dirname, 'resources');
const WWW_DIR      = IS_PACKAGED ? path.join(process.resourcesPath, 'www') : path.join(__dirname, 'www');
// In dev: app source is the Logos root (one level up from electron/).
// In production: app source is bundled into resources/www/Logos/.
const APP_DIR      = IS_PACKAGED ? path.join(process.resourcesPath, 'www', 'Logos') : path.join(__dirname, '..');

const configManager = new ConfigManager(app.getPath('userData'));
const serverManager = new ServerManager(RESOURCES, WWW_DIR, APP_DIR);

// ── Window helpers ────────────────────────────────────────────────────────────
let mainWindow   = null;
let overlayWin   = null;
let tray         = null;

// ── Client health-check ───────────────────────────────────────────────────────
let healthTimer = null;
let healthFails = 0;

function pingUrl(url) {
  return new Promise(resolve => {
    const timer = setTimeout(() => resolve(false), 4000);
    try {
      const req = net.request({ url, method: 'HEAD' });
      req.on('response', r => { clearTimeout(timer); resolve(r.statusCode < 500); });
      req.on('error',    () => { clearTimeout(timer); resolve(false); });
      req.end();
    } catch { clearTimeout(timer); resolve(false); }
  });
}

function stopHealthCheck() {
  if (healthTimer) { clearInterval(healthTimer); healthTimer = null; }
  healthFails = 0;
}

function startHealthCheck(base, serverIp) {
  stopHealthCheck();
  healthTimer = setInterval(async () => {
    const ok = await pingUrl(`${base}/Logos/ping`);
    if (ok) { healthFails = 0; return; }
    if (++healthFails < 3) return;
    stopHealthCheck();
    showClientDisconnectedError(serverIp);
  }, 8000);
}

function showClientDisconnectedError(serverIp) {
  if (!mainWindow || mainWindow.isDestroyed()) return;
  mainWindow.loadFile(path.join(__dirname, 'renderer', 'error.html'));
  mainWindow.webContents.once('did-finish-load', () => {
    if (mainWindow.isDestroyed()) return;
    mainWindow.webContents.send('show-error', {
      message: `No se puede conectar al servidor en ${serverIp}.\n\nVerificá que la PC servidor esté encendida y conectada a la red.`,
      isServer: false,
      serverIp,
    });
  });
}

const PRELOAD  = path.join(__dirname, 'preload.js');
const APP_ICON = path.join(__dirname, 'build', 'icon.ico');

function openOverlay(file, { width = 520, height = 420, frame = true, title = 'Logos POS' } = {}) {
  if (overlayWin && !overlayWin.isDestroyed()) overlayWin.close();
  overlayWin = new BrowserWindow({
    width, height, resizable: false, center: true, frame,
    title,
    icon: APP_ICON,
    webPreferences: { preload: PRELOAD, contextIsolation: true, nodeIntegration: false },
  });
  overlayWin.loadFile(path.join(__dirname, 'renderer', file));
  const _thisOverlay = overlayWin;
  overlayWin.on('closed', () => { if (overlayWin === _thisOverlay) overlayWin = null; });
  return overlayWin;
}

function closeOverlay() {
  if (overlayWin && !overlayWin.isDestroyed()) { overlayWin.close(); overlayWin = null; }
}

function sendLoading(msg) {
  if (overlayWin && !overlayWin.isDestroyed()) {
    overlayWin.webContents.send('loading-status', msg);
  }
}

function showError(message, isServer = true) {
  const win = openOverlay('error.html', { title: 'Logos POS — Error' });
  win.webContents.once('did-finish-load', () => {
    if (!win.isDestroyed()) win.webContents.send('show-error', { message, isServer });
  });
}

function openMainWindow(url) {
  // Reuse existing window when retrying after a disconnection
  if (mainWindow && !mainWindow.isDestroyed()) {
    mainWindow.loadURL(url);
    mainWindow.webContents.once('did-finish-load', () => {
      closeOverlay();
      if (!mainWindow.isVisible()) mainWindow.show();
      mainWindow.focus();
    });
    return;
  }

  mainWindow = new BrowserWindow({
    width: 1366, height: 768,
    center: true,
    title: 'Logos POS',
    icon: APP_ICON,
    show: false,
    webPreferences: {
      preload: PRELOAD,
      contextIsolation: true,
      nodeIntegration: false,
      webSecurity: true,
    },
  });

  mainWindow.loadURL(url);

  mainWindow.on('ready-to-show', () => {
    closeOverlay();
    mainWindow.show();
    mainWindow.focus();
  });

  // On close: minimize to tray so PHP/MariaDB keep running
  mainWindow.on('close', (e) => {
    if (tray && !app.isQuitting) {
      e.preventDefault();
      mainWindow.hide();
    }
  });

  mainWindow.on('closed', () => { mainWindow = null; });

  // F12 → DevTools, F5/Ctrl+R → reload
  mainWindow.webContents.on('before-input-event', (_, input) => {
    if (input.type !== 'keyDown') return;
    if (input.key === 'F12') mainWindow.webContents.toggleDevTools();
    if (input.key === 'F5' || (input.key === 'r' && input.control)) mainWindow.webContents.reload();
  });

  // Prevent new windows from opening (use same window)
  mainWindow.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));

  // Navigation failure while the app is already running (server went down mid-session)
  mainWindow.webContents.on('did-fail-load', (_, errorCode, _desc, failedUrl) => {
    // -3 = ERR_ABORTED (normal navigation cancellation, e.g. SPA redirect), ignore
    if (errorCode === -3) return;
    // Ignore failures on local renderer files (e.g. error.html itself)
    if (failedUrl && failedUrl.startsWith('file://')) return;
    const serverIp = configManager.get('serverIp');
    if (!serverIp) return;
    stopHealthCheck();
    showClientDisconnectedError(serverIp);
  });
}

// ── Auto-updater ──────────────────────────────────────────────────────────────

// Checks latest.yml on the update server. Returns update info if a newer version
// is available, null otherwise (no update, no internet, any error).
// Never throws — update check is best-effort, never blocks normal startup.
function checkForUpdates() {
  if (!app.isPackaged) return Promise.resolve(null);

  const { autoUpdater } = require('electron-updater');
  autoUpdater.autoDownload         = false;
  autoUpdater.autoInstallOnAppQuit = false;
  autoUpdater.logger               = null; // suppress to avoid polluting logs

  return new Promise((resolve) => {
    let settled = false;
    const finish = (v) => { if (!settled) { settled = true; resolve(v); } };

    // 12-second hard timeout: if the server doesn't respond, keep going normally
    const timer = setTimeout(() => finish(null), 12000);

    autoUpdater.once('update-available',     (info) => { clearTimeout(timer); finish(info); });
    autoUpdater.once('update-not-available', ()     => { clearTimeout(timer); finish(null); });
    autoUpdater.once('error',                ()     => { clearTimeout(timer); finish(null); });

    autoUpdater.checkForUpdates().catch(() => finish(null));
  });
}

// Shows the updating.html screen, downloads the update, and calls quitAndInstall.
// Returns true if quitAndInstall was triggered (app will restart), false on error.
async function performUpdate(updateInfo) {
  const { autoUpdater } = require('electron-updater');
  const fs = require('fs');
  const logFile = path.join(app.getPath('userData'), 'update.log');
  const logUpdate = (msg) => {
    try { fs.appendFileSync(logFile, `[${new Date().toISOString()}] ${msg}\n`); } catch {}
  };

  autoUpdater.logger = { info: logUpdate, warn: logUpdate, error: logUpdate, debug: () => {} };

  const updateWin = new BrowserWindow({
    width: 460, height: 300, frame: false, resizable: false, center: true,
    title: 'Logos POS — Actualizando',
    icon: APP_ICON,
    webPreferences: { preload: PRELOAD, contextIsolation: true, nodeIntegration: false },
  });
  updateWin.loadFile(path.join(__dirname, 'renderer', 'updating.html'));
  await new Promise(r => updateWin.webContents.once('did-finish-load', r));
  updateWin.webContents.send('update-start', updateInfo.version);
  logUpdate(`Descargando v${updateInfo.version}...`);

  return new Promise((resolve) => {
    autoUpdater.on('download-progress', (p) => {
      if (!updateWin.isDestroyed())
        updateWin.webContents.send('update-progress', Math.round(p.percent));
    });

    autoUpdater.once('update-downloaded', async () => {
      if (!updateWin.isDestroyed()) {
        updateWin.webContents.send('update-progress', 100);
        updateWin.webContents.send('update-installing');
      }
      logUpdate('Descarga completa. Instalando...');

      // In server/supervisor mode, stop NSSM services so NSIS can overwrite
      // MariaDB and PHP binaries without file-lock errors.
      // sc stop blocks until the service reaches STOPPED — no extra sleep needed,
      // just a short buffer (300 ms) for OS to release file handles after each stop.
      if (serverManager.serviceMode === 'supervisor') {
        try {
          execFileSync('sc', ['stop', 'LogosPOS-PHP'], { timeout: 8000,  stdio: 'ignore' });
          await new Promise(r => setTimeout(r, 300));
          execFileSync('sc', ['stop', 'LogosPOS-DB'],  { timeout: 10000, stdio: 'ignore' });
          await new Promise(r => setTimeout(r, 300));
        } catch { /* ignore — installer.nsh will start them back */ }
      }

      // isSilent=true skips the NSIS UI; isForceRunAfter=true restarts Electron after install.
      autoUpdater.quitAndInstall(true, true);
      resolve(true);
    });

    const handleError = (err) => {
      const msg = err ? err.message : 'Error desconocido';
      logUpdate(`ERROR en descarga: ${msg}`);
      if (err && err.stack) logUpdate(err.stack);
      // Close the update window and let the app start normally
      if (!updateWin.isDestroyed()) updateWin.close();
      resolve(false);
    };

    autoUpdater.once('error', handleError);
    autoUpdater.downloadUpdate().catch(handleError);
  });
}

// ── Tray icon ─────────────────────────────────────────────────────────────────
function setupTray() {
  const icon = nativeImage.createFromPath(path.join(__dirname, 'build', 'icon.png'))
    .resize({ width: 16, height: 16 });
  tray = new Tray(icon);
  tray.setToolTip('Logos POS');
  tray.setContextMenu(Menu.buildFromTemplate([
    {
      label: 'Abrir Logos POS',
      click: () => { if (mainWindow) { mainWindow.show(); mainWindow.focus(); } },
    },
    { type: 'separator' },
    {
      label: 'Salir',
      click: () => {
        app.isQuitting = true;
        serverManager.stopAll();
        app.quit();
      },
    },
  ]));
  tray.on('double-click', () => { if (mainWindow) mainWindow.show(); });
}

// ── Server connectivity check ─────────────────────────────────────────────────
function waitForUrl(url, timeoutMs = 25000) {
  return new Promise((resolve, reject) => {
    const deadline = Date.now() + timeoutMs;
    const attempt = () => {
      const req = net.request({ url, method: 'HEAD' });
      req.on('response', r => (r.statusCode < 500 ? resolve() : scheduleRetry()));
      req.on('error', scheduleRetry);
      req.end();
    };
    const scheduleRetry = () => {
      if (Date.now() >= deadline) return reject(new Error(`Tiempo agotado conectando a ${url}`));
      setTimeout(attempt, 600);
    };
    attempt();
  });
}

// ── Role startup ─────────────────────────────────────────────────────────────
async function startServerRole() {
  openOverlay('loading.html', { width: 460, height: 290, frame: false });
  const userData = app.getPath('userData');

  try {
    if (serverManager.serviceMode === 'supervisor') {
      // ── SUPERVISOR: NSSM manages the processes — just verify they're alive ──
      sendLoading('Verificando servicios del sistema...');
      const dbPort  = configManager.get('dbPort')     || 3306;
      const webPort = configManager.get('serverPort') || 8080;

      await serverManager.waitForDatabase(dbPort, 15000);

      sendLoading('Verificando servidor web...');
      await waitForUrl(`http://localhost:${webPort}/Logos/ping`, 15000);

      setupTray();
      openMainWindow(`http://localhost:${webPort}/Logos/pos/index.html`);
    } else {
      // ── INITIATOR: Electron starts and owns the processes ──────────────────
      sendLoading('Iniciando base de datos...');
      const { isFirstSetup } = await serverManager.startDatabase(userData);
      configManager.set('dbPort', serverManager.dbPort);

      sendLoading('Iniciando servidor PHP...');
      const port = await serverManager.startPhpServer(
        configManager.get('serverPort') || 8080,
        userData,
      );
      configManager.set('serverPort', port);

      const appUrl = isFirstSetup
        ? `http://localhost:${port}/Logos/pos/instalar.html`
        : `http://localhost:${port}/Logos/pos/index.html`;

      sendLoading('Cargando aplicación...');
      await waitForUrl(`http://localhost:${port}/Logos/ping`, 25000);

      setupTray();
      openMainWindow(appUrl);
    }
  } catch (err) {
    showError(err.message, true);
  }
}

async function startClientRole() {
  const serverIp = configManager.get('serverIp');
  if (!serverIp) {
    openOverlay('client-config.html', { width: 500, height: 380, title: 'Logos POS — Configurar servidor' });
    return;
  }

  openOverlay('loading.html', { width: 460, height: 290, frame: false });

  // serverIp is 'IP:PORT' (from client-config.html) or legacy 'IP' (defaults to 8080)
  const hasPort = /:\d+$/.test(serverIp);
  const base = hasPort ? `http://${serverIp}` : `http://${serverIp}:8080`;
  const url = `${base}/Logos/pos/index.html`;

  try {
    sendLoading(`Conectando a ${serverIp}…`);
    await waitForUrl(url, 15000);
    openMainWindow(url);
    startHealthCheck(base, serverIp);
  } catch {
    closeOverlay();
    openOverlay('error.html', { title: 'Logos POS — Sin conexión' });
    overlayWin.webContents.once('did-finish-load', () => {
      overlayWin.webContents.send('show-error', {
        message: `No se pudo conectar al servidor en ${serverIp}.\n\nVerificá que el servidor esté encendido y la IP sea correcta.`,
        isServer: false,
        serverIp,
      });
    });
  }
}

// ── IPC handlers ──────────────────────────────────────────────────────────────
ipcMain.handle('get-config', () => configManager.getAll());

ipcMain.handle('get-local-ips', () =>
  Object.values(os.networkInterfaces())
    .flat()
    .filter(n => n && n.family === 'IPv4' && !n.internal)
    .map(n => n.address)
);

ipcMain.handle('save-role', async (_, role) => {
  configManager.set('role', role);
  configManager.set('firstRun', false);
  closeOverlay();
  if (role === 'server') await startServerRole();
  else await startClientRole();
});

ipcMain.handle('save-server-ip', async (_, ip) => {
  configManager.set('serverIp', ip.trim());
  closeOverlay();
  await startClientRole();
});

ipcMain.handle('retry-connection', async () => {
  stopHealthCheck();
  closeOverlay();
  await startClientRole();
});

ipcMain.handle('reconfigure-client', () => {
  configManager.set('serverIp', null);
  closeOverlay();
  openOverlay('client-config.html', { width: 500, height: 380, title: 'Logos POS — Configurar servidor' });
});

ipcMain.handle('reset-config', () => {
  configManager.set('role', null);
  configManager.set('firstRun', true);
  configManager.set('serverIp', null);
  closeOverlay();
  openOverlay('setup.html', { width: 620, height: 480, title: 'Logos POS — Configuración inicial' });
});

// ── App lifecycle ─────────────────────────────────────────────────────────────
app.whenReady().then(async () => {
  Menu.setApplicationMenu(null);

  // Check for updates BEFORE showing anything to the user.
  // If an update is found, performUpdate() downloads and installs it (app restarts).
  // On any error (no internet, hash mismatch, etc.) it returns false and we continue normally.
  const updateInfo = await checkForUpdates();
  if (updateInfo) {
    const installed = await performUpdate(updateInfo);
    if (installed) return; // quitAndInstall was called — never actually reaches here
    // Update failed: fall through to normal startup
  }

  const cfg = configManager.getAll();
  if (cfg.firstRun || !cfg.role) {
    openOverlay('setup.html', { width: 620, height: 480, title: 'Logos POS — Configuración inicial' });
    return;
  }

  if (cfg.role === 'server') startServerRole();
  else startClientRole();
});

app.on('second-instance', () => {
  if (mainWindow) {
    if (mainWindow.isMinimized()) mainWindow.restore();
    mainWindow.show();
    mainWindow.focus();
  }
});

app.on('window-all-closed', () => {
  // Stay alive when minimized to tray; quit when no tray
  if (!tray) {
    serverManager.stopAll();
    app.quit();
  }
});

app.on('before-quit', () => {
  stopHealthCheck();
  serverManager.stopAll();
});
