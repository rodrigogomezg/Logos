<?php

declare(strict_types=1);

/**
 * Lista fija (no configurable por request) de tablas que forman el
 * "backup liviano": productos/clientes/proveedores + todo lo que
 * ConfiguracionController::resetFabrica() ya trata como dato operativo del
 * negocio, más las tablas de documentos/movimientos agregadas al esquema
 * después de que resetFabrica() se escribió (devoluciones, notas de envío,
 * cheques, cierres de caja, stock por depósito/reserva, escalas de precio).
 *
 * Deliberadamente afuera: usuarios/configuracion/licencia_estado (backup
 * liviano no debe tocarlos — ver CLAUDE.md), productos_import_* (historial
 * de corridas de importación, no dato de negocio en sí), reglas_precio/
 * listas_precio/rubros/marcas (configuración de catálogo, no movimiento),
 * sucursales/depositos (infraestructura — un restore liviano es siempre
 * sobre la MISMA instalación, esas filas ya están ahí con los mismos IDs).
 */
final class BackupTablas {
    public const LIVIANO = [
        'productos', 'clientes', 'proveedores', 'vendedores',
        'cajas', 'caja_turnos', 'caja_movimientos', 'caja_cierres',
        'ventas', 'venta_items', 'venta_pagos',
        'compras', 'compra_items', 'compra_pagos',
        'cuenta_corriente_movimientos', 'cc_asignaciones',
        'movimientos_stock', 'stock_depositos', 'stock_reservas',
        'devoluciones', 'devolucion_items',
        'notas_envio', 'nota_envio_items',
        'cheques', 'mp_pagos', 'escalas_precio',
    ];

    /**
     * Tablas excluidas del backup completo:
     * - licencia_estado: no debe viajar el estado de licencia/Hub de una
     *   instalación a otra (ver CLAUDE.md).
     * - _schema_migrations: si viajara, quedaría marcada la migración que
     *   crea licencia_estado (66_licencia_estado.sql) como "ya aplicada"
     *   en la instalación nueva sin que la tabla exista de verdad (porque
     *   licencia_estado se excluye del dump) — el motor de migraciones
     *   (server_manager.rs::run_migrations()) nunca la volvería a crear.
     *   Excluir _schema_migrations entera es la forma simple y robusta de
     *   evitar esto: la instalación nueva arranca con cero migraciones
     *   selladas, así que TODAS se reintentan solas en el próximo arranque
     *   — las que ya existen fallan con "already exists" y se sellan igual
     *   (comportamiento tolerante ya existente del runner), y licencia_estado
     *   se crea de cero con su fila semilla (INSERT IGNORE de la 66). Mismo
     *   principio que ya usa Configuracion::restaurarDesde() para el
     *   restore total: dejar que las migraciones se reapliquen solas.
     */
    public const COMPLETO_TABLAS_EXCLUIDAS = ['licencia_estado', '_schema_migrations'];
}
