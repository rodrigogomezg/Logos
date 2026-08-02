'use strict';
const fs = require('fs');
const path = require('path');

const DEFAULTS = {
  role:             null,   // 'server' | 'client' — seteado por el instalador NSIS
  serverPort:       8080,   // port PHP listens on
  dbPort:           3306,   // port MariaDB listens on (saved after first start; used in supervisor mode)
  serverIp:         null,   // for client role: 'IP:PORT'
  licenciaApiToken: '',     // Bearer token para el Hub de licencias — completar al instalar
  remoteAccess: {           // Tailscale/Headscale — inactive until explicitly enabled
    enabled:            false,
    headscaleServerUrl: '',
    preAuthKey:         '',
  },
};

class ConfigManager {
  constructor(userDataPath) {
    this._file = path.join(userDataPath, 'logos-config.json');
    this._data = null;
  }

  _load() {
    if (this._data) return;
    try {
      const raw = fs.readFileSync(this._file, 'utf8').replace(/^﻿/, '');
      this._data = { ...DEFAULTS, ...JSON.parse(raw) };
    } catch {
      this._data = { ...DEFAULTS };
    }
  }

  _save() {
    fs.mkdirSync(path.dirname(this._file), { recursive: true });
    fs.writeFileSync(this._file, JSON.stringify(this._data, null, 2), 'utf8');
  }

  get(key) {
    this._load();
    return this._data[key];
  }

  set(key, value) {
    this._load();
    this._data[key] = value;
    this._save();
  }

  getAll() {
    this._load();
    return { ...this._data };
  }
}

module.exports = ConfigManager;
