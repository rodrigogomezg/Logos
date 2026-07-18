-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: logos
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `afip_tokens`
--

DROP TABLE IF EXISTS `afip_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `afip_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `servicio` varchar(20) NOT NULL,
  `entorno` varchar(20) NOT NULL,
  `token` text NOT NULL,
  `sign` text NOT NULL,
  `expira` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_servicio_entorno` (`servicio`,`entorno`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `caja_cierres`
--

DROP TABLE IF EXISTS `caja_cierres`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caja_cierres` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `turno_id` int(11) NOT NULL,
  `tipo` enum('parcial','total') NOT NULL DEFAULT 'parcial',
  `registrado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `usuario_id` int(11) DEFAULT NULL,
  `periodo_desde` datetime NOT NULL,
  `total_efectivo` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_tarjeta` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_transferencia` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_cheque` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_mercado_pago` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_cc` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_ingresos` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_retiros` decimal(14,2) NOT NULL DEFAULT 0.00,
  `fondo_periodo` decimal(14,2) NOT NULL DEFAULT 0.00,
  `efectivo_esperado` decimal(14,2) DEFAULT NULL,
  `efectivo_contado` decimal(14,2) DEFAULT NULL,
  `cheques_recibidos` decimal(14,2) DEFAULT NULL,
  `depositos_recibidos` decimal(14,2) DEFAULT NULL,
  `posnet_cierres` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`posnet_cierres`)),
  `mercado_pago_contado` decimal(12,2) DEFAULT NULL,
  `fondo_siguiente` decimal(12,2) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `diferencia_efectivo` decimal(14,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_turno` (`turno_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `caja_movimientos`
--

DROP TABLE IF EXISTS `caja_movimientos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caja_movimientos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `turno_id` int(11) NOT NULL,
  `tipo` enum('ingreso','retiro','transferencia') NOT NULL,
  `medio_pago` enum('efectivo','transferencia','tarjeta','mercado_pago') DEFAULT NULL,
  `medio_pago_destino` enum('efectivo','transferencia','tarjeta') DEFAULT NULL,
  `monto` decimal(12,2) NOT NULL,
  `motivo` varchar(255) DEFAULT NULL,
  `usuario_id` int(11) NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `caja_turnos`
--

DROP TABLE IF EXISTS `caja_turnos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caja_turnos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `caja_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `device_name` varchar(100) DEFAULT NULL,
  `fondo_inicial` decimal(12,2) NOT NULL DEFAULT 0.00,
  `abierto_en` datetime NOT NULL,
  `cerrado_en` datetime DEFAULT NULL,
  `estado` enum('abierto','cerrado') NOT NULL DEFAULT 'abierto',
  `total_efectivo` decimal(12,2) DEFAULT NULL,
  `total_tarjeta` decimal(12,2) DEFAULT NULL,
  `total_transferencia` decimal(12,2) DEFAULT NULL,
  `total_cheque` decimal(12,2) DEFAULT NULL,
  `total_cc` decimal(12,2) DEFAULT NULL,
  `total_mercado_pago` decimal(12,2) DEFAULT NULL,
  `total_ingresos` decimal(12,2) DEFAULT NULL,
  `total_retiros` decimal(12,2) DEFAULT NULL,
  `efectivo_esperado` decimal(12,2) DEFAULT NULL,
  `efectivo_contado` decimal(12,2) DEFAULT NULL,
  `diferencia` decimal(12,2) DEFAULT NULL,
  `cheques_recibidos` decimal(12,2) DEFAULT NULL,
  `depositos_recibidos` decimal(12,2) DEFAULT NULL,
  `posnet_cierres` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`posnet_cierres`)),
  `mercado_pago_contado` decimal(12,2) DEFAULT NULL,
  `fondo_siguiente` decimal(12,2) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cajas`
--

DROP TABLE IF EXISTS `cajas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cajas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sucursal_id` int(11) NOT NULL DEFAULT 1,
  `nombre` varchar(100) NOT NULL,
  `tipo` enum('venta','compra') NOT NULL DEFAULT 'venta',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `orden` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `fk_cajas_sucursal` (`sucursal_id`),
  CONSTRAINT `fk_cajas_sucursal` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cc_asignaciones`
--

DROP TABLE IF EXISTS `cc_asignaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cc_asignaciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `movimiento_id` int(11) NOT NULL,
  `venta_id` int(11) DEFAULT NULL,
  `monto` decimal(14,4) NOT NULL,
  `compra_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mov` (`movimiento_id`),
  KEY `idx_ven` (`venta_id`),
  KEY `fk_cca_com` (`compra_id`),
  CONSTRAINT `fk_cca_com` FOREIGN KEY (`compra_id`) REFERENCES `compras` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cca_mov` FOREIGN KEY (`movimiento_id`) REFERENCES `cuenta_corriente_movimientos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cca_ven` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `clientes`
--

DROP TABLE IF EXISTS `clientes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clientes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) NOT NULL,
  `cuit` varchar(20) DEFAULT NULL,
  `condicion_iva` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `telefono` varchar(50) DEFAULT NULL,
  `domicilio` varchar(255) DEFAULT NULL,
  `localidad` varchar(100) DEFAULT NULL,
  `provincia` varchar(100) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `domicilios_envio` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`domicilios_envio`)),
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `cc_habilitada` tinyint(1) NOT NULL DEFAULT 0,
  `descuento_extra` decimal(5,2) NOT NULL DEFAULT 0.00,
  `solo_remito` tinyint(1) NOT NULL DEFAULT 0,
  `lista_precio_id` int(11) DEFAULT NULL,
  `creado_en` datetime DEFAULT current_timestamp(),
  `limite_credito` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `plazo_pago_dias` int(11) DEFAULT NULL,
  `saldo_cuenta_corriente` decimal(14,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY (`id`),
  KEY `idx_nombre` (`nombre`),
  KEY `idx_cuit` (`cuit`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `compra_items`
--

DROP TABLE IF EXISTS `compra_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `compra_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `compra_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `cantidad` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `costo_unitario` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `iva_porcentaje` decimal(5,2) NOT NULL DEFAULT 21.00,
  `iva_monto` decimal(14,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY (`id`),
  KEY `idx_compra` (`compra_id`),
  KEY `idx_producto` (`producto_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `compra_pagos`
--

DROP TABLE IF EXISTS `compra_pagos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `compra_pagos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `compra_id` int(11) NOT NULL,
  `tipo_pago` varchar(20) NOT NULL,
  `monto` decimal(14,4) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_compra` (`compra_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `compras`
--

DROP TABLE IF EXISTS `compras`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `compras` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sucursal_id` int(11) NOT NULL DEFAULT 1,
  `fecha` date NOT NULL,
  `proveedor_id` int(11) DEFAULT NULL,
  `total` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `estado` varchar(20) NOT NULL DEFAULT 'completado',
  `caja_id` int(11) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `tipo_comprobante` varchar(20) DEFAULT NULL,
  `numero_comprobante` varchar(50) DEFAULT NULL,
  `subtotal` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `iva_monto` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `percepcion_iibb_porcentaje` decimal(5,2) DEFAULT NULL,
  `percepcion_iibb_monto` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `tipo_pago` varchar(20) NOT NULL DEFAULT 'efectivo',
  PRIMARY KEY (`id`),
  KEY `idx_fecha` (`fecha`),
  KEY `idx_proveedor` (`proveedor_id`),
  KEY `fk_compras_sucursal` (`sucursal_id`),
  CONSTRAINT `fk_compras_sucursal` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `configuracion`
--

DROP TABLE IF EXISTS `configuracion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `configuracion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `razon_social` varchar(255) NOT NULL DEFAULT '',
  `nombre_fantasia` varchar(255) DEFAULT NULL,
  `cuit` varchar(20) NOT NULL DEFAULT '',
  `condicion_iva` varchar(50) NOT NULL DEFAULT 'Responsable Inscripto',
  `domicilio` varchar(255) DEFAULT NULL,
  `iibb` varchar(50) DEFAULT NULL,
  `telefono` varchar(50) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `punto_venta` int(11) NOT NULL DEFAULT 1,
  `iva_porcentaje` decimal(5,2) NOT NULL DEFAULT 21.00,
  `impresora_nombre` varchar(255) DEFAULT NULL,
  `posnet_terminales` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`posnet_terminales`)),
  `carpeta_comprobantes` varchar(500) DEFAULT NULL,
  `carpeta_backups` varchar(500) DEFAULT NULL,
  `carpeta_backups_secundaria` varchar(500) DEFAULT NULL,
  `afip_cert` text DEFAULT NULL,
  `afip_key` text DEFAULT NULL,
  `afip_entorno` enum('homologacion','produccion') NOT NULL DEFAULT 'homologacion',
  `clave_autorizacion_hash` varchar(255) DEFAULT NULL,
  `color_tema` varchar(30) NOT NULL DEFAULT 'bordo',
  `tipos_habilitados` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tipos_habilitados`)),
  `mp_access_token` varchar(500) DEFAULT NULL,
  `mp_webhook_secret` varchar(255) DEFAULT NULL,
  `wa_phone_id` varchar(100) DEFAULT NULL,
  `wa_token` varchar(500) DEFAULT NULL,
  `wa_template_name` varchar(100) DEFAULT 'envio_comprobante',
  `actualizado_en` datetime DEFAULT NULL,
  `backup_auto_cierre` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cuenta_corriente_movimientos`
--

DROP TABLE IF EXISTS `cuenta_corriente_movimientos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cuenta_corriente_movimientos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entidad_tipo` enum('cliente','proveedor') NOT NULL,
  `entidad_id` int(11) NOT NULL,
  `tipo` varchar(20) NOT NULL,
  `monto` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `referencia_id` int(11) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `medio_pago` varchar(20) DEFAULT NULL,
  `pago_datos` text DEFAULT NULL,
  `comprobante` varchar(500) DEFAULT NULL,
  `fecha` date NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_entidad` (`entidad_tipo`,`entidad_id`),
  KEY `idx_fecha` (`fecha`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `depositos`
--

DROP TABLE IF EXISTS `depositos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `depositos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sucursal_id` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `es_principal` tinyint(1) NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `sucursal_id` (`sucursal_id`),
  CONSTRAINT `depositos_ibfk_1` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `devolucion_items`
--

DROP TABLE IF EXISTS `devolucion_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `devolucion_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `devolucion_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `cantidad` decimal(10,2) NOT NULL,
  `precio_unitario` decimal(14,4) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `devolucion_id` (`devolucion_id`),
  KEY `producto_id` (`producto_id`),
  CONSTRAINT `devolucion_items_ibfk_1` FOREIGN KEY (`devolucion_id`) REFERENCES `devoluciones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `devolucion_items_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `devoluciones`
--

DROP TABLE IF EXISTS `devoluciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `devoluciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venta_id` int(11) NOT NULL,
  `cliente_id` int(11) NOT NULL,
  `motivo` varchar(255) DEFAULT NULL,
  `monto_total` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `usuario_id` int(11) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_venta` (`venta_id`),
  KEY `idx_cliente` (`cliente_id`),
  CONSTRAINT `devoluciones_ibfk_1` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`),
  CONSTRAINT `devoluciones_ibfk_2` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `escalas_precio`
--

DROP TABLE IF EXISTS `escalas_precio`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `escalas_precio` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `producto_id` int(11) NOT NULL,
  `desde_cantidad` decimal(10,2) NOT NULL,
  `precio_unitario` decimal(14,4) NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_producto_cantidad` (`producto_id`,`desde_cantidad`),
  CONSTRAINT `escalas_precio_ibfk_1` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `listas_precio`
--

DROP TABLE IF EXISTS `listas_precio`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `listas_precio` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `porcentaje` decimal(7,2) NOT NULL DEFAULT 0.00 COMMENT 'Positivo = recargo, negativo = descuento',
  `activa` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_activa` (`activa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `log_acciones`
--

DROP TABLE IF EXISTS `log_acciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `log_acciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  `usuario_id` int(11) DEFAULT NULL,
  `usuario_nom` varchar(100) DEFAULT NULL,
  `accion` varchar(50) NOT NULL,
  `entidad` varchar(30) DEFAULT NULL,
  `entidad_id` int(11) DEFAULT NULL,
  `detalle` text DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `device_name` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fecha` (`fecha`),
  KEY `idx_accion` (`accion`),
  KEY `idx_usuario` (`usuario_id`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `login_intentos`
--

DROP TABLE IF EXISTS `login_intentos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_intentos` (
  `usuario_id` int(11) NOT NULL,
  `intentos` int(11) NOT NULL DEFAULT 0,
  `bloqueado_hasta` datetime DEFAULT NULL,
  `actualizado` datetime NOT NULL,
  PRIMARY KEY (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `movimientos_stock`
--

DROP TABLE IF EXISTS `movimientos_stock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `movimientos_stock` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `producto_id` int(11) NOT NULL,
  `deposito_id` int(11) NOT NULL DEFAULT 1,
  `tipo` varchar(20) NOT NULL,
  `cantidad` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `referencia_id` int(11) DEFAULT NULL,
  `fecha` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_producto` (`producto_id`),
  KEY `idx_fecha` (`fecha`),
  KEY `idx_tipo` (`tipo`),
  KEY `fk_movstock_deposito` (`deposito_id`),
  CONSTRAINT `fk_movstock_deposito` FOREIGN KEY (`deposito_id`) REFERENCES `depositos` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mp_pagos`
--

DROP TABLE IF EXISTS `mp_pagos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mp_pagos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_id` varchar(64) NOT NULL,
  `venta_id` int(11) DEFAULT NULL,
  `monto` decimal(14,2) NOT NULL,
  `origen` enum('webhook','manual') NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment` (`payment_id`),
  KEY `idx_venta` (`venta_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `nota_envio_items`
--

DROP TABLE IF EXISTS `nota_envio_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `nota_envio_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nota_envio_id` int(11) NOT NULL,
  `venta_item_id` int(11) NOT NULL,
  `producto_id` int(11) DEFAULT NULL,
  `nombre` varchar(255) NOT NULL,
  `codigo` varchar(50) DEFAULT NULL,
  `cantidad` decimal(14,4) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_nota` (`nota_envio_id`),
  KEY `idx_venta_item` (`venta_item_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notas_envio`
--

DROP TABLE IF EXISTS `notas_envio`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notas_envio` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venta_id` int(11) NOT NULL,
  `numero` int(11) NOT NULL DEFAULT 1,
  `fecha_emision` date NOT NULL,
  `fecha_entrega` date DEFAULT NULL,
  `transportista` varchar(255) DEFAULT NULL,
  `envio_precio` decimal(14,4) DEFAULT NULL,
  `envio_direccion` varchar(500) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_venta` (`venta_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `productos`
--

DROP TABLE IF EXISTS `productos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `productos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codigo` varchar(50) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `categoria` varchar(100) DEFAULT NULL,
  `subcategoria` varchar(100) DEFAULT NULL,
  `marca` varchar(100) DEFAULT NULL,
  `proveedor` varchar(150) DEFAULT NULL,
  `precio_venta` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `costo_actual` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `stock_actual` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `stock_minimo` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `iva_porcentaje` decimal(5,2) NOT NULL DEFAULT 21.00,
  `unidad_medida` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_codigo` (`codigo`),
  KEY `idx_nombre` (`nombre`),
  KEY `idx_activo` (`activo`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `productos_import_detalle`
--

DROP TABLE IF EXISTS `productos_import_detalle`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `productos_import_detalle` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lote_id` int(11) NOT NULL,
  `producto_id` int(11) DEFAULT NULL,
  `codigo` varchar(50) DEFAULT NULL,
  `accion` enum('crear','actualizar','error','desactivado') NOT NULL,
  `antes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`antes`)),
  `despues` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`despues`)),
  `mensaje` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lote` (`lote_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `productos_import_lotes`
--

DROP TABLE IF EXISTS `productos_import_lotes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `productos_import_lotes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `proveedor` varchar(150) NOT NULL,
  `archivo` varchar(255) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `total_filas` int(11) NOT NULL DEFAULT 0,
  `creados` int(11) NOT NULL DEFAULT 0,
  `actualizados` int(11) NOT NULL DEFAULT 0,
  `errores` int(11) NOT NULL DEFAULT 0,
  `desactivados` int(11) NOT NULL DEFAULT 0,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `productos_import_plantillas`
--

DROP TABLE IF EXISTS `productos_import_plantillas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `productos_import_plantillas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `proveedor` varchar(150) NOT NULL,
  `mapeo` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`mapeo`)),
  `opciones` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`opciones`)),
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_proveedor` (`proveedor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `proveedores`
--

DROP TABLE IF EXISTS `proveedores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `proveedores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) NOT NULL,
  `cuit` varchar(20) DEFAULT NULL,
  `condicion_iva` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `telefono` varchar(50) DEFAULT NULL,
  `domicilio` varchar(255) DEFAULT NULL,
  `localidad` varchar(100) DEFAULT NULL,
  `provincia` varchar(100) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `cc_habilitada` tinyint(1) NOT NULL DEFAULT 0,
  `limite_credito` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `plazo_pago_dias` int(11) DEFAULT NULL,
  `lista_precio_id` int(11) DEFAULT NULL,
  `creado_en` datetime DEFAULT current_timestamp(),
  `saldo_cuenta_corriente` decimal(14,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY (`id`),
  KEY `idx_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sesiones`
--

DROP TABLE IF EXISTS `sesiones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sesiones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token_hash` char(64) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `creado_en` datetime NOT NULL,
  `ultimo_uso` datetime NOT NULL,
  `expira` datetime NOT NULL,
  `device_name` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`token_hash`),
  KEY `idx_usuario` (`usuario_id`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `stock_depositos`
--

DROP TABLE IF EXISTS `stock_depositos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_depositos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `producto_id` int(11) NOT NULL,
  `deposito_id` int(11) NOT NULL,
  `stock_actual` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_prod_dep` (`producto_id`,`deposito_id`),
  KEY `deposito_id` (`deposito_id`),
  CONSTRAINT `stock_depositos_ibfk_1` FOREIGN KEY (`deposito_id`) REFERENCES `depositos` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sucursales`
--

DROP TABLE IF EXISTS `sucursales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sucursales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `nombre_fantasia` varchar(100) DEFAULT NULL,
  `domicilio` varchar(255) DEFAULT NULL,
  `telefono` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `punto_venta` int(11) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `usuarios`
--

DROP TABLE IF EXISTS `usuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `pin_hash` varchar(255) NOT NULL,
  `rol` enum('admin','user') NOT NULL DEFAULT 'user',
  `permisos` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`permisos`)),
  `sucursal_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`sucursal_ids`)),
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=101 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vendedores`
--

DROP TABLE IF EXISTS `vendedores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vendedores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `venta_items`
--

DROP TABLE IF EXISTS `venta_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `venta_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venta_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `cantidad` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `precio_unitario` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `precio_original` decimal(12,2) DEFAULT NULL,
  `ajuste_desc` varchar(30) DEFAULT NULL,
  `ajuste_visible` tinyint(1) NOT NULL DEFAULT 1,
  `costo_unitario` decimal(14,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY (`id`),
  KEY `idx_venta` (`venta_id`),
  KEY `idx_producto` (`producto_id`)
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `venta_pagos`
--

DROP TABLE IF EXISTS `venta_pagos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `venta_pagos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venta_id` int(11) NOT NULL,
  `tipo_pago` varchar(20) NOT NULL,
  `monto` decimal(14,4) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_venta` (`venta_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ventas`
--

DROP TABLE IF EXISTS `ventas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ventas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sucursal_id` int(11) NOT NULL DEFAULT 1,
  `fecha` date NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `cliente_id` int(11) DEFAULT NULL,
  `vendedor_id` int(11) DEFAULT NULL,
  `total` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `tipo_comprobante` varchar(50) DEFAULT NULL,
  `numero_afip` varchar(50) DEFAULT NULL,
  `numero_afip_pendiente` int(10) unsigned DEFAULT NULL,
  `punto_venta` smallint(6) DEFAULT NULL,
  `cbte_asoc_tipo` tinyint(4) DEFAULT NULL,
  `cbte_asoc_pto_vta` smallint(6) DEFAULT NULL,
  `cbte_asoc_nro` int(10) unsigned DEFAULT NULL,
  `cae` varchar(50) DEFAULT NULL,
  `cae_vencimiento` date DEFAULT NULL,
  `afip_error` varchar(500) DEFAULT NULL,
  `afip_response` mediumtext DEFAULT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'completado',
  `mp_init_point` varchar(500) DEFAULT NULL,
  `tipo_pago` enum('efectivo','transferencia','cc','tarjeta','cheque','mixto','mercado_pago') NOT NULL DEFAULT 'efectivo',
  `observaciones` varchar(255) DEFAULT NULL,
  `origen_descripcion` text DEFAULT NULL,
  `envio_precio` decimal(10,2) DEFAULT NULL,
  `envio_direccion` varchar(500) DEFAULT NULL,
  `caja_id` int(11) DEFAULT NULL,
  `turno_id` int(11) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fecha` (`fecha`),
  KEY `idx_cliente` (`cliente_id`),
  KEY `idx_numero_afip` (`numero_afip`),
  KEY `idx_cbte_asoc` (`cbte_asoc_tipo`,`cbte_asoc_pto_vta`,`cbte_asoc_nro`),
  KEY `fk_ventas_sucursal` (`sucursal_id`),
  CONSTRAINT `fk_ventas_sucursal` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-18 19:44:45
