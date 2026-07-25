'use strict';
const net = require('net');

function canBind(port, host) {
  return new Promise(resolve => {
    const server = net.createServer();
    server.once('error', () => resolve(false));
    server.once('listening', () => { server.close(); resolve(true); });
    server.listen(port, host);
  });
}

async function isPortFree(port) {
  // On Windows, wildcard and specific-address binds don't conflict with each
  // other: binding 0.0.0.0:<port> succeeds even while another process owns
  // 127.0.0.1:<port>, and vice versa. Both must be tested — PHP binds 0.0.0.0
  // and mysqld binds 127.0.0.1.
  return (await canBind(port, '0.0.0.0')) && (await canBind(port, '127.0.0.1'));
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
