-- Migración 43: permisos granulares por usuario
-- Matriz de permisos configurable (JSON) para usuarios rol 'user'.
-- Los admin quedan con NULL: bypasean todos los checks (Auth::puede).
-- A los users existentes se les asigna la plantilla "legacy", equivalente
-- exacto a lo que un 'user' podía hacer hasta hoy (ve reportes, costos y
-- cuentas corrientes; no puede comprar, importar, editar productos, cobrar
-- CC, anular sin clave, ver el log ni operar otras cajas). Así el deploy
-- no cambia el comportamiento de nadie hasta que el admin ajuste permisos.

ALTER TABLE usuarios
  ADD COLUMN permisos JSON NULL AFTER rol;

UPDATE usuarios
SET permisos = '{"compras":false,"importar":false,"productos_editar":false,"costos":true,"reportes":true,"cc_ver":true,"cc_cobrar":false,"anular":false,"log":false,"cajas_todas":false}'
WHERE rol = 'user';
