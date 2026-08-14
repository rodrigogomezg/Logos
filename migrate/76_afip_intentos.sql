-- Migración 76: contador de intentos automáticos de facturación AFIP.
--
-- Soporta el job de reintento en segundo plano (VentasController::procesarPendientesAfip(),
-- llamado por un timer del shell Tauri cada 1-2 min) — necesita un tope para
-- no seguir golpeando a ARCA sin límite ante un certificado roto o un
-- comprobante con datos inválidos. Los reintentos MANUALES (botón "Reintentar
-- AFIP"/"Reintentar facturación" en Ventas, POST /ventas/{id}/facturar) NO
-- tocan esta columna a propósito — el tope solo aplica al camino automático,
-- un cajero/staff que reintenta a mano siempre puede.

ALTER TABLE ventas ADD COLUMN afip_intentos INT NOT NULL DEFAULT 0;
