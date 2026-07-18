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

        $provDesdeProductos = $db->query(
            "SELECT DISTINCT proveedor FROM productos
             WHERE activo = 1 AND proveedor IS NOT NULL AND proveedor != ''
             ORDER BY proveedor"
        )->fetchAll(PDO::FETCH_COLUMN);

        $provDesdeTabla = $db->query(
            "SELECT nombre FROM proveedores WHERE activo = 1 ORDER BY nombre"
        )->fetchAll(PDO::FETCH_COLUMN);

        $proveedores = array_values(array_unique(array_merge($provDesdeTabla, $provDesdeProductos)));
        sort($proveedores);

        $categorias = $db->query(
            "SELECT DISTINCT categoria FROM productos
             WHERE activo = 1 AND categoria IS NOT NULL AND categoria != ''
             ORDER BY categoria"
        )->fetchAll(PDO::FETCH_COLUMN);

        $subcategorias = $db->query(
            "SELECT DISTINCT subcategoria FROM productos
             WHERE activo = 1 AND subcategoria IS NOT NULL AND subcategoria != ''
             ORDER BY subcategoria"
        )->fetchAll(PDO::FETCH_COLUMN);

        json(200, compact('marcas', 'proveedores', 'categorias', 'subcategorias'));
    }
}
