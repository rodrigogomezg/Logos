<?php

require_once __DIR__ . '/../config/db.php';

class FiltrosController {

    public function get(): void {
        $db = DB::get();

        $marcas = $db->query(
            "SELECT DISTINCT marca FROM productos
             WHERE activo = 1 AND marca IS NOT NULL AND marca != ''
             ORDER BY marca"
        )->fetchAll(PDO::FETCH_COLUMN);

        $proveedores = $db->query(
            "SELECT DISTINCT proveedor FROM productos
             WHERE activo = 1 AND proveedor IS NOT NULL AND proveedor != ''
             ORDER BY proveedor"
        )->fetchAll(PDO::FETCH_COLUMN);

        $categorias = $db->query(
            "SELECT DISTINCT categoria FROM productos
             WHERE activo = 1 AND categoria IS NOT NULL AND categoria != ''
             ORDER BY categoria"
        )->fetchAll(PDO::FETCH_COLUMN);

        json(200, compact('marcas', 'proveedores', 'categorias'));
    }
}
