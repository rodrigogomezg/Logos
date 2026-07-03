-- Timestamp real de creación de cada venta (la columna `fecha` es DATE, sin hora).
-- Necesario para el gráfico de ventas por hora en caja.html.
ALTER TABLE ventas
  ADD COLUMN IF NOT EXISTS creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER fecha;

-- Backfill: las ventas anteriores a esta migración quedan a la medianoche de su fecha.
UPDATE ventas SET creado_en = fecha WHERE creado_en > NOW() - INTERVAL 1 MINUTE AND fecha < CURDATE();
