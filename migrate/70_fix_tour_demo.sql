-- Migración 70: corrige instalaciones que quedaron con el tour de Ventas/CC
-- a medias por un bug real en 30_tour_ventas.sql / 31_tour_cc.sql — el
-- INSERT...SELECT hacia venta_items sin sync_uuid explícito fallaba con
-- STRICT_TRANS_TABLES pese al trigger BEFORE INSERT (confirmado
-- reproduciendo el instalador limpio completo). El error quedaba sellado
-- como "migración aplicada" sin haber corrido de verdad, así que nunca se
-- reintentaba solo.
--
-- Esto solo toca las filas de DEMO del tour (marcadas con numero_afip
-- TOUR_DEMO_1/TOUR_DEMO_2/TOUR_CC_CLI) — nunca datos reales de un negocio.
-- Borra los restos a medio insertar (venta sin items) y libera el sello de
-- 30/31 para que se vuelvan a correr solas (ya con el fix) en el próximo
-- arranque. Es un no-op seguro en instalaciones que nunca tuvieron el bug.

DELETE FROM ventas
 WHERE numero_afip IN ('TOUR_DEMO_1', 'TOUR_DEMO_2', 'TOUR_CC_CLI')
   AND NOT EXISTS (SELECT 1 FROM venta_items vi WHERE vi.venta_id = ventas.id);

DELETE FROM _schema_migrations WHERE nombre IN ('30_tour_ventas.sql', '31_tour_cc.sql');
