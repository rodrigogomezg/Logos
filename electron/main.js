'use strict';
const { app, BrowserWindow, ipcMain, Menu, Tray, net, nativeImage } = require('electron');
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
      // Allow same-origin requests from localhost to the PHP server
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
app.whenReady().then(() => {
  Menu.setApplicationMenu(null);

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
  serverManager.stopAll();
});
