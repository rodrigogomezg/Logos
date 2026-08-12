-- Migración 74: corrige el admin "de fábrica" que quedó filtrado a producción.
--
-- 12_configuracion.sql y 13_usuarios.sql tenían INSERT hardcodeados con datos
-- de una instancia de prueba (negocio "BULFON GUILLERMO JESUS", cajas
-- "Ferretería/Sanitarios/Compras", usuario "Admin" con PIN 1234) que corrían
-- en TODA instalación nueva, no solo en desarrollo. Como el wizard de
-- instalación crea el admin real con POST /instalacion/admin y ese endpoint
-- rechaza con 403 si YA existe un admin, el admin sembrado hacía que el
-- wizard reusara su ID en vez de crear el admin que el cliente tipeó — si
-- algún paso posterior del commit fallaba (ej. AFIP), el negocio y las cajas
-- de prueba quedaban como datos reales y el PIN de fábrica 1234 como único
-- acceso. Ver CLAUDE.md.
--
-- Esos dos INSERT ya se sacaron para instalaciones nuevas de acá en adelante.
-- Esta migración no toca instalaciones nuevas (no van a tener ninguna fila
-- con ese pin_hash exacto). Para instalaciones que ya corrieron con el bug,
-- NO podemos simplemente borrar o desactivar esa cuenta — puede ser el único
-- acceso real que el cliente usa hoy (ver regla de "nunca cortar acceso a una
-- instalación que ya funciona" en CLAUDE.md). En cambio, se marca para forzar
-- un cambio de PIN en el próximo login (ver UsuariosController::login() y
-- app/src/routes/login/+page.svelte) — no bloquea el acceso, solo obliga a
-- reemplazar el PIN público antes de entrar.
--
-- El match es por el hash bcrypt EXACTO del PIN sembrado (incluye salt
-- aleatorio único de esa generación) — si un cliente cambió el PIN en algún
-- momento, aunque haya vuelto a poner "1234", el hash sería distinto y esta
-- migración no lo toca. Solo alcanza a cuentas que nunca se tocaron desde
-- la instalación.

ALTER TABLE usuarios ADD COLUMN debe_cambiar_pin TINYINT(1) NOT NULL DEFAULT 0;

UPDATE usuarios
   SET debe_cambiar_pin = 1
 WHERE pin_hash = '$2y$10$wW.dB/yyAQ2oKfnfPBGHgeDEvZWdxPrstxcNWUAZkfhr/tlNBs4nu';
