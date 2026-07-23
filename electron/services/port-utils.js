'use strict';
const net = require('net');

function isPortFree(port) {
  return new Promise(resolve => {
    const server = net.createServer();
    server.once('error', () => resolve(false));
    // Bind to 0.0.0.0 — same as PHP will bind — so conflicts are detected correctly.
    // On Windows SO_REUSEADDR allows 127.0.0.1 tests to false-positive even when
    // another process owns 0.0.0.0:<port>.
    server.once('listening', () => { server.close(); resolve(true); });
    server.listen(port, '0.0.0.0');
  });
}

async function findFreePort(preferred) {
  // Never try port 80 — requires admin on Windows and Apache typically owns it.
  const safe = preferred === 80 ? 8080 : preferred;
  // Build sensible fallback range: keep near preferred so DB ports stay in 3306+
  // range and web ports stay in 8080+ range.
  const base = safe;
  const candidates = [base, base + 1, base + 2, base + 3, base + 4, base + 5]
    .filter(p => p !== 80);
  for (const port of candidates) {
    if (await isPortFree(port)) return port;
  }
  // OS-assigned free port as last resort
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.listen(0, '127.0.0.1', () => {
      const { port } = server.address();
      server.close(() => resolve(port));
    });
    server.on('error', reject);
  });
}

module.exports = { isPortFree, findFreePort };
