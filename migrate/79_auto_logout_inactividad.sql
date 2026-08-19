-- Migración 79: toggle en Configuración para el auto-logout por inactividad
-- (15 min, agregado 15/08/2026 en app/src/routes/(app)/+layout.svelte).
--
-- Pedido de Rodrigo (19/08/2026): hay clientes a los que no les conviene
-- que el sistema cierre sesión solo tras 15 minutos sin uso — se agrega la
-- posibilidad de desactivarlo. Default en 1 (activado) para no cambiar el
-- comportamiento de las instalaciones que ya lo tienen andando.

ALTER TABLE configuracion
  ADD COLUMN auto_logout_inactividad TINYINT(1) NOT NULL DEFAULT 1;
