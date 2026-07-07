/**
 * Configuración global del POS
 * Se carga primero en TODOS los HTML antes de otros scripts
 * Detecta automáticamente la ruta base
 */

(function() {
  const scriptPath = document.currentScript?.src || 
                     Array.from(document.scripts).find(s => s.src.includes('config.js'))?.src ||
                     window.location.pathname;
  
  const urlObj = new URL(scriptPath, window.location.origin);
  const pathname = urlObj.pathname;
  
  const match = pathname.match(/^(.+)\/pos\/(.*)$/);
  const basePath = match ? match[1] : '/Logos';
  
  window.APP_CONFIG = {
    BASE_PATH: basePath,
    API_BASE: basePath + '/api',
    POS_BASE: basePath + '/pos',
  };
  
  window.API = window.APP_CONFIG.API_BASE;
  
  console.log('🔧 APP_CONFIG cargado:', window.APP_CONFIG);
})();
