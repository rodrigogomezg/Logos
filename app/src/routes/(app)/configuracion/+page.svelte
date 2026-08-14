<script lang="ts">
	import { onMount, onDestroy } from 'svelte';
	import { api, apiJson } from '$lib/api';
	import { toast_ } from '$lib/toast';
	import { confirmar } from '$lib/confirm';
	import { esRolServidor } from '$lib/instalacion';

	type Vista = 'negocio' | 'contables' | 'avanzado';
	type Config = Record<string, unknown>;
	type Caja = { id: number; nombre: string; tipo: 'venta' | 'compra'; activo: boolean | number };
	type Sucursal = { id: number; nombre: string; nombre_fantasia: string | null; domicilio: string | null; telefono: string | null; email: string | null; punto_venta: number | string | null; activo: boolean | number };
	type Deposito = { id: number; nombre: string; descripcion: string | null; es_principal: boolean | number; activo: boolean | number };
	type Usuario = { id: number; nombre: string; rol: 'admin' | 'user'; activo: boolean | number; sucursal_ids: number[] | null; permisos: Record<string, boolean> | null };
	type PosnetTerminal = { nombre: string };
	type BackupOpt = { nombre: string; origen: string; modificado: string; tamano: number };

	// ── Tabs ─────────────────────────────────────────────────────
	let vistaActual = $state<Vista>('negocio');
	function setVista(v: Vista) {
		if (hayCambios) {
			// las pestañas internas no navegan, no hace falta el guard de salida
		}
		vistaActual = v;
		if (v === 'negocio') cargarSucursales();
	}

	// ── Configuración general ───────────────────────────────────
	let cfg = $state<Config>({});
	let cfgRazonSocial = $state('');
	let cfgCuit = $state('');
	let cfgCondicionIva = $state('');
	let cfgDomicilio = $state('');
	let cfgIibb = $state('');
	let cfgTelefono = $state('');
	let cfgWebsite = $state('');
	let cfgPuntoVenta = $state(1);
	let cfgIvaPorcentaje = $state(21);
	let cfgTipos = $state<Set<string>>(new Set());
	let cfgImpresora = $state('');
	let cfgCarpetaComprobantes = $state('');
	let cfgCarpetaBackups = $state('');
	let cfgCarpetaBackupsSecundaria = $state('');
	let cfgBackupAutoCierre = $state(false);
	let cfgVentasSinStock = $state(false);
	let cfgClaveAutorizacion = $state('');
	let cfgClaveConfigurada = $state(false);
	let cfgWaPhoneId = $state('');
	let cfgWaTemplateName = $state('');
	let cfgMpConfigurado = $state(false);
	let cfgWaConfigurado = $state(false);
	let cfgAfipEntorno = $state('homologacion');
	let cfgAfipConfigurado = $state(false);
	let cfgAfipVencimiento = $state<string | null>(null);
	let impresoras = $state<string[]>([]);

	const TIPOS_COMPROBANTE = ['FC B-ELECT', 'FC A-ELECT', 'FC C-ELECT', 'REMITO', 'PRESUPUESTO'];

	async function cargarConfig() {
		const res = await api('/configuracion');
		const d = await res.json();
		cfg = d;
		cfgRazonSocial = d.razon_social || '';
		cfgCuit = d.cuit || '';
		cfgCondicionIva = d.condicion_iva || '';
		cfgDomicilio = d.domicilio || '';
		cfgIibb = d.iibb || '';
		cfgTelefono = d.telefono || '';
		cfgWebsite = d.website || '';
		cfgPuntoVenta = d.punto_venta || 1;
		cfgIvaPorcentaje = d.iva_porcentaje || 21;
		const tiposEnabled: string[] = Array.isArray(d.tipos_habilitados) ? d.tipos_habilitados : [];
		cfgTipos = new Set(tiposEnabled.length === 0 ? TIPOS_COMPROBANTE : tiposEnabled);
		cfgCarpetaComprobantes = d.carpeta_comprobantes || '';
		cfgCarpetaBackups = d.carpeta_backups || '';
		cfgCarpetaBackupsSecundaria = d.carpeta_backups_secundaria || '';
		cfgBackupAutoCierre = !!d.backup_auto_cierre;
		cfgVentasSinStock = !!d.ventas_sin_stock;
		cfgClaveAutorizacion = '';
		cfgClaveConfigurada = !!d.clave_autorizacion_configurada;
		posnetTerminales = Array.isArray(d.posnet_terminales) ? d.posnet_terminales : [];
		cfgAfipConfigurado = !!d.afip_configurado;
		cfgAfipEntorno = d.afip_entorno || 'homologacion';
		cfgAfipVencimiento = d.afip_cert_vencimiento || null;
		if (!afipAlias) afipAlias = (d.nombre_fantasia as string) || (d.razon_social as string) || '';
		cfgWaPhoneId = d.wa_phone_id || '';
		cfgWaTemplateName = d.wa_template_name || 'envio_comprobante';
		cfgMpConfigurado = !!d.mp_configurado;
		cfgWaConfigurado = !!d.wa_configurado;
		cfgImpresora = d.impresora_nombre || '';
		smtpHost = d.smtp_host || '';
		smtpPuerto = d.smtp_puerto || 587;
		smtpSeguridad = d.smtp_seguridad || 'tls';
		smtpUsuario = d.smtp_usuario || '';
		smtpDeNombre = d.smtp_de_nombre || '';
		smtpDeEmail = d.smtp_de_email || '';
		smtpReplyTo = d.smtp_reply_to || '';
		smtpConfigurado = !!d.smtp_configurado;
	}

	async function cargarImpresoras() {
		try {
			const res = await api('/configuracion/impresoras');
			const nombres = await res.json();
			impresoras = Array.isArray(nombres) ? nombres : [];
		} catch {
			impresoras = [];
		}
	}

	function recolectarConfig() {
		return {
			razon_social: cfgRazonSocial.trim(),
			cuit: cfgCuit.trim(),
			condicion_iva: cfgCondicionIva.trim(),
			domicilio: cfgDomicilio.trim(),
			iibb: cfgIibb.trim(),
			telefono: cfgTelefono.trim(),
			website: cfgWebsite.trim(),
			punto_venta: cfgPuntoVenta || 1,
			iva_porcentaje: cfgIvaPorcentaje || 21,
			tipos_habilitados: TIPOS_COMPROBANTE.filter((t) => cfgTipos.has(t)),
			impresora_nombre: cfgImpresora,
			carpeta_comprobantes: cfgCarpetaComprobantes.trim(),
			carpeta_backups: cfgCarpetaBackups.trim(),
			carpeta_backups_secundaria: cfgCarpetaBackupsSecundaria.trim(),
			backup_auto_cierre: cfgBackupAutoCierre ? 1 : 0,
			ventas_sin_stock: cfgVentasSinStock ? 1 : 0,
			clave_autorizacion: cfgClaveAutorizacion,
			wa_phone_id: cfgWaPhoneId.trim(),
			wa_template_name: cfgWaTemplateName.trim()
		};
	}

	let guardandoConfig = $state(false);
	async function guardarConfig() {
		guardandoConfig = true;
		try {
			const res = await api('/configuracion', { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(recolectarConfig()) });
			const data = await res.json();
			if (!res.ok) {
				toast_(data.error || 'Error al guardar', 'err');
				return;
			}
			cfg = data;
			cfgClaveAutorizacion = '';
			cfgClaveConfigurada = !!data.clave_autorizacion_configurada;
			limpiarCambios();
			toast_('Configuración guardada', 'ok');
		} catch {
			toast_('Error de conexión', 'err');
		} finally {
			guardandoConfig = false;
		}
	}

	// ── Dirty tracking (solo los campos que persiste guardarConfig) ─
	let hayCambios = $state(false);
	function marcarCambio() {
		hayCambios = true;
	}
	function limpiarCambios() {
		hayCambios = false;
	}
	function onLinkClick(e: MouseEvent) {
		if (!hayCambios) return;
		const a = (e.target as HTMLElement).closest('a[href]') as HTMLAnchorElement | null;
		if (!a || a.target === '_blank' || a.getAttribute('href')?.startsWith('#')) return;
		e.preventDefault();
		confirmar('Hay cambios sin guardar en la configuración. ¿Salir igualmente?', { titulo: 'Cambios sin guardar', confirmLabel: 'Salir sin guardar', danger: true }).then((ok) => {
			if (ok) {
				hayCambios = false;
				location.href = a.href;
			}
		});
	}
	function onBeforeUnload(e: BeforeUnloadEvent) {
		if (!hayCambios) return;
		e.preventDefault();
		e.returnValue = '';
	}

	onMount(() => {
		document.addEventListener('click', onLinkClick);
		window.addEventListener('beforeunload', onBeforeUnload);
	});
	onDestroy(() => {
		document.removeEventListener('click', onLinkClick);
		window.removeEventListener('beforeunload', onBeforeUnload);
	});

	// ── MercadoPago / WhatsApp ───────────────────────────────────
	let mpToken = $state('');
	let mpWebhookSecret = $state('');
	let guardandoMp = $state(false);
	async function guardarMp() {
		guardandoMp = true;
		try {
			const res = await api('/configuracion', {
				method: 'PUT',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ ...recolectarConfig(), mp_access_token: mpToken.trim(), mp_webhook_secret: mpWebhookSecret.trim() })
			});
			const data = await res.json();
			if (!res.ok) {
				toast_(data.error || 'Error al guardar', 'err');
				return;
			}
			cfg = data;
			mpToken = '';
			mpWebhookSecret = '';
			cfgMpConfigurado = !!data.mp_configurado;
			limpiarCambios();
			toast_('MercadoPago guardado', 'ok');
		} catch {
			toast_('Error de conexión', 'err');
		} finally {
			guardandoMp = false;
		}
	}

	let waToken = $state('');
	let guardandoWa = $state(false);
	async function guardarWa() {
		guardandoWa = true;
		try {
			const res = await api('/configuracion', {
				method: 'PUT',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ ...recolectarConfig(), wa_token: waToken.trim() })
			});
			const data = await res.json();
			if (!res.ok) {
				toast_(data.error || 'Error al guardar', 'err');
				return;
			}
			cfg = data;
			waToken = '';
			cfgWaConfigurado = !!data.wa_configurado;
			limpiarCambios();
			toast_('WhatsApp guardado', 'ok');
		} catch {
			toast_('Error de conexión', 'err');
		} finally {
			guardandoWa = false;
		}
	}

	// ── Email SMTP ───────────────────────────────────────────────
	let smtpHost = $state('');
	let smtpPuerto = $state(587);
	let smtpSeguridad = $state('tls');
	let smtpUsuario = $state('');
	let smtpClave = $state('');
	let smtpDeNombre = $state('');
	let smtpDeEmail = $state('');
	let smtpReplyTo = $state('');
	let smtpConfigurado = $state(false);
	let guardandoSmtp = $state(false);
	let probandoSmtp = $state(false);

	async function guardarSmtp() {
		guardandoSmtp = true;
		try {
			const res = await api('/configuracion/smtp', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({
					smtp_host: smtpHost.trim(),
					smtp_puerto: parseInt(String(smtpPuerto)) || 587,
					smtp_seguridad: smtpSeguridad,
					smtp_usuario: smtpUsuario.trim(),
					smtp_clave: smtpClave,
					smtp_de_nombre: smtpDeNombre.trim(),
					smtp_de_email: smtpDeEmail.trim(),
					smtp_reply_to: smtpReplyTo.trim()
				})
			});
			const data = await res.json();
			if (!res.ok) {
				toast_(data.error || 'Error al guardar', 'err');
				return;
			}
			cfg = data;
			smtpClave = '';
			smtpConfigurado = !!data.smtp_configurado;
			toast_('Configuración de email guardada', 'ok');
		} finally {
			guardandoSmtp = false;
		}
	}
	async function probarSmtp() {
		const destino = smtpDeEmail.trim() || smtpUsuario.trim();
		if (!destino) {
			toast_('Completá el email remitente antes de probar', 'err');
			return;
		}
		probandoSmtp = true;
		try {
			const res = await api('/mail/probar', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ para: destino }) });
			if (res.ok) toast_(`Mail de prueba enviado a ${destino}`, 'ok');
			else {
				const d = await res.json().catch(() => ({}));
				toast_(d.error || 'Error al enviar', 'err');
			}
		} finally {
			probandoSmtp = false;
		}
	}

	// ── Nombre de equipo (localStorage) ──────────────────────────
	let deviceName = $state('');
	onMount(() => {
		try {
			deviceName = localStorage.getItem('logos_device_name') || '';
		} catch {
			/* sin storage disponible */
		}
	});
	function guardarDeviceName() {
		try {
			localStorage.setItem('logos_device_name', deviceName.trim());
		} catch {
			/* sin storage disponible */
		}
		toast_('Nombre de equipo guardado', 'ok');
	}

	// ── Versión instalada (Electron) ─────────────────────────────
	let appVersion = $state('');
	onMount(() => {
		const logos = (window as unknown as { logos?: { getAppVersion?: () => Promise<string> } }).logos;
		if (logos?.getAppVersion) logos.getAppVersion().then((v) => (appVersion = v)).catch(() => {});
	});

	// ── Logo ─────────────────────────────────────────────────────
	let logoSrc = $state('/Logos/api/configuracion/logo?t=0');
	// El <input type="file"> nativo nunca muestra el nombre de un archivo ya
	// subido en cargas anteriores (lo borra el navegador en cada recarga, por
	// seguridad) — eso confunde ("dice que no cargué nada" aunque sí haya un
	// logo). Este flag, derivado de si la preview carga o no, es el indicador
	// real de si hay un logo guardado.
	let logoExiste = $state(true);
	async function subirLogo(e: Event) {
		const file = (e.target as HTMLInputElement).files?.[0];
		if (!file) return;
		const fd = new FormData();
		fd.append('logo', file);
		try {
			const res = await api('/configuracion/logo', { method: 'POST', body: fd });
			const data = await res.json();
			if (!res.ok) {
				toast_(data.error || 'Error al subir el logo', 'err');
				return;
			}
			logoSrc = data.path;
			logoExiste = true;
			toast_('Logo actualizado', 'ok');
		} catch {
			toast_('Error de conexión', 'err');
		}
	}

	// ── Probar impresión ─────────────────────────────────────────
	async function probarImpresion() {
		if (!cfgImpresora) {
			toast_('Elegí una impresora primero', 'err');
			return;
		}
		try {
			const res = await api('/configuracion/probar-impresion', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ impresora_nombre: cfgImpresora }) });
			const data = await res.json();
			if (!res.ok) {
				toast_(data.error || 'Error al imprimir', 'err');
				return;
			}
			toast_('Enviado a imprimir', 'ok');
		} catch {
			toast_('Error de conexión', 'err');
		}
	}

	// ── Backup ───────────────────────────────────────────────────
	let backupEstadoLinea = $state('');
	let backupEstadoAlerta = $state(false);
	async function cargarEstadoBackup() {
		try {
			const res = await api('/backup/estado');
			if (!res.ok) return;
			const estado = await res.json();
			backupEstadoAlerta = !!estado.alerta;
			if (!estado.ultimo_en) {
				backupEstadoLinea = 'Todavía no se generó ningún backup automático.';
				return;
			}
			const fecha = new Date(estado.ultimo_en.replace(' ', 'T')).toLocaleString('es-AR');
			backupEstadoLinea = estado.ultimo_ok ? `Último backup: ${fecha} (OK)` : `Último backup falló (${fecha}): ${estado.mensaje || ''}`;
		} catch {
			/* no romper la pantalla si esto falla */
		}
	}
	async function backupAhora() {
		try {
			const res = await api('/configuracion/backup', { method: 'POST' });
			const data = await res.json();
			if (!res.ok) {
				toast_(data.error || 'Error al hacer el backup', 'err');
				return;
			}
			toast_('Backup creado: ' + data.archivo, 'ok');
			cargarEstadoBackup();
		} catch {
			toast_('Error de conexión', 'err');
		}
	}

	// ── Cajas ────────────────────────────────────────────────────
	let cajas = $state<Caja[]>([]);
	let cajaNuevoNombre = $state('');
	let cajaNuevoTipo = $state<'venta' | 'compra'>('venta');
	const hayCajaVentaActiva = $derived(cajas.some((c) => c.tipo === 'venta' && !!c.activo));

	async function cargarCajas() {
		const res = await api('/cajas');
		cajas = await res.json();
	}
	async function guardarCaja(c: Caja) {
		if (!c.nombre.trim()) {
			toast_('El nombre es requerido', 'err');
			return;
		}
		const res = await api(`/cajas/${c.id}`, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ nombre: c.nombre.trim(), tipo: c.tipo, activo: !!c.activo }) });
		if (!res.ok) {
			toast_('Error al guardar la caja', 'err');
			return;
		}
		toast_('Caja guardada', 'ok');
	}
	async function borrarCaja(id: number) {
		const res = await api(`/cajas/${id}`, { method: 'DELETE' });
		if (!res.ok) {
			toast_('Error al borrar la caja', 'err');
			return;
		}
		toast_('Caja borrada', 'ok');
		cargarCajas();
	}
	async function agregarCaja() {
		const nombre = cajaNuevoNombre.trim();
		if (!nombre) {
			toast_('Ingresá un nombre', 'err');
			return;
		}
		const res = await api('/cajas', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ nombre, tipo: cajaNuevoTipo }) });
		if (!res.ok) {
			toast_('Error al crear la caja', 'err');
			return;
		}
		cajaNuevoNombre = '';
		toast_('Caja creada', 'ok');
		cargarCajas();
	}

	// ── Terminales Posnet ────────────────────────────────────────
	let posnetTerminales = $state<PosnetTerminal[]>([]);
	let posnetNuevoNombre = $state('');
	function agregarPosnet() {
		const nombre = posnetNuevoNombre.trim();
		if (!nombre) {
			toast_('Ingresá un nombre para la terminal', 'err');
			return;
		}
		posnetTerminales = [...posnetTerminales, { nombre }];
		posnetNuevoNombre = '';
	}
	function quitarPosnet(i: number) {
		posnetTerminales = posnetTerminales.filter((_, idx) => idx !== i);
	}
	async function guardarPosnet() {
		try {
			const res = await api('/configuracion', { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...recolectarConfig(), posnet_terminales: posnetTerminales }) });
			const d = await res.json();
			if (!res.ok) {
				toast_(d.error || 'Error al guardar', 'err');
				return;
			}
			cfg = d;
			posnetTerminales = Array.isArray(d.posnet_terminales) ? d.posnet_terminales : [];
			limpiarCambios();
			toast_('Terminales guardadas', 'ok');
		} catch {
			toast_('Error de conexión', 'err');
		}
	}

	// ── Usuarios ─────────────────────────────────────────────────
	const PERMISOS_DEF: [string, string, string][] = [
		['compras', 'Compras', 'Cargar y anular compras a proveedores'],
		['importar', 'Importar', 'Importador masivo de productos, clientes y proveedores'],
		['productos_editar', 'Editar productos y precios', 'Crear/editar productos, ajustar stock, listas de precio'],
		['costos', 'Ver costos y ganancia', 'Costo de productos, margen, rentabilidad y ganancia del negocio'],
		['reportes', 'Reportes', 'Dashboard de ventas y Libro IVA'],
		['cc_ver', 'Ver cuentas corrientes', 'Saldos, deudas y vencimientos de clientes y proveedores'],
		['cc_cobrar', 'Registrar cobros de cuenta corriente', 'Asentar pagos/cobros de CC'],
		['anular', 'Anular sin clave', 'Anular ventas y movimientos de caja sin clave de autorización'],
		['log', 'Ver bitácora', 'Registro de acciones de todos los usuarios'],
		['cajas_todas', 'Operar todas las cajas', 'Vender y filtrar reportes de cualquier caja, no solo la propia'],
		['gestionar_vendedores', 'Gestionar vendedores', 'Crear/editar vendedores y ver sus reportes y comisiones']
	];
	const PERM_TPL: Record<string, Record<string, boolean>> = {
		vendedor: {},
		encargado: { productos_editar: true, reportes: true, cc_ver: true, cc_cobrar: true, anular: true, cajas_todas: true }
	};

	function perfilUsuario(u: Usuario): string {
		if (u.rol === 'admin') return 'Admin';
		const p = u.permisos || {};
		const claves = PERMISOS_DEF.map((d) => d[0]);
		const coincide = (tpl: Record<string, boolean>) => claves.every((k) => !!tpl[k] === !!p[k]);
		if (coincide(PERM_TPL.vendedor)) return 'Vendedor';
		if (coincide(PERM_TPL.encargado)) return 'Encargado';
		return 'Personalizado';
	}
	function etiquetaRolUser(u: Usuario): string {
		return u.rol === 'admin' ? 'Vendedor' : perfilUsuario(u);
	}
	function resumenSucursales(ids: number[] | null): string {
		if (!Array.isArray(ids) || ids.length === 0) return 'Todas';
		if (ids.length === 1) {
			const s = sucursales.find((x) => x.id === ids[0]);
			return s ? s.nombre : '1 sucursal';
		}
		return ids.length + ' sucursales';
	}

	let usuarios = $state<Usuario[]>([]);
	let usuarioNuevoNombre = $state('');
	let usuarioNuevoRol = $state<'vendedor' | 'encargado' | 'admin'>('vendedor');
	let usuarioNuevoPin = $state('');
	let usuarioPins = $state<Record<number, string>>({});

	async function cargarUsuarios() {
		const res = await api('/usuarios/todos');
		usuarios = await res.json();
	}
	async function guardarUsuario(u: Usuario) {
		const nombre = u.nombre.trim();
		const pin = (usuarioPins[u.id] || '').trim();
		if (!nombre) {
			toast_('El nombre es requerido', 'err');
			return;
		}
		if (pin && !/^\d{4,6}$/.test(pin)) {
			toast_('El PIN debe ser numérico de 4 a 6 dígitos', 'err');
			return;
		}
		const body: Record<string, unknown> = { nombre, rol: u.rol, activo: !!u.activo };
		if (pin) body.pin = pin;
		const res = await api(`/usuarios/${u.id}`, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
		const data = await res.json();
		if (!res.ok) {
			toast_(data.error || 'Error al guardar el usuario', 'err');
			return;
		}
		toast_('Usuario guardado', 'ok');
		cargarUsuarios();
	}
	async function borrarUsuario(id: number) {
		const res = await api(`/usuarios/${id}`, { method: 'DELETE' });
		if (!res.ok) {
			toast_('Error al borrar el usuario', 'err');
			return;
		}
		toast_('Usuario borrado', 'ok');
		cargarUsuarios();
	}
	async function agregarUsuario() {
		const nombre = usuarioNuevoNombre.trim();
		const pin = usuarioNuevoPin.trim();
		if (!nombre) {
			toast_('Ingresá un nombre', 'err');
			return;
		}
		if (!/^\d{4,6}$/.test(pin)) {
			toast_('El PIN debe ser numérico de 4 a 6 dígitos', 'err');
			return;
		}
		const body = usuarioNuevoRol === 'admin' ? { nombre, rol: 'admin', pin } : { nombre, rol: 'user', pin, permisos: PERM_TPL[usuarioNuevoRol] || {} };
		const res = await api('/usuarios', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
		const data = await res.json();
		if (!res.ok) {
			toast_(data.error || 'Error al crear el usuario', 'err');
			return;
		}
		usuarioNuevoNombre = '';
		usuarioNuevoPin = '';
		toast_('Usuario creado', 'ok');
		cargarUsuarios();
	}

	async function guardarSucursalesUsuario(id: number, ids: number[] | null) {
		const r = await api(`/usuarios/${id}`, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ sucursal_ids: ids }) });
		if (!r.ok) {
			toast_('Error al actualizar el acceso', 'err');
			cargarUsuarios();
			return;
		}
		const u = usuarios.find((x) => x.id === id);
		if (u) u.sucursal_ids = ids;
		toast_('Acceso actualizado', 'ok');
	}

	// Menú flotante de sucursales por usuario. El estado de los checkboxes se
	// borronea acá (sucMenuDraftIds) en vez de leerse directo de u.sucursal_ids:
	// destildar "Todas" no guarda nada todavía (queda sin sucursales elegidas
	// hasta tildar al menos una), así que si el checkbox reflejara el server
	// directo se auto-re-tildaría en el siguiente render y el click del
	// usuario no tendría efecto visible.
	let sucMenuAbiertoId = $state<number | null>(null);
	let sucMenuPos = $state({ top: 0, left: 0 });
	let sucMenuDraftIds = $state<number[] | null>(null);
	function abrirSucMenu(u: Usuario, ev: MouseEvent) {
		if (sucMenuAbiertoId === u.id) {
			sucMenuAbiertoId = null;
			return;
		}
		const r = (ev.currentTarget as HTMLElement).getBoundingClientRect();
		sucMenuPos = { top: r.bottom + 4, left: r.left };
		sucMenuAbiertoId = u.id;
		sucMenuDraftIds = Array.isArray(u.sucursal_ids) ? [...u.sucursal_ids] : null;
	}
	function cerrarSucMenu() {
		sucMenuAbiertoId = null;
	}
	function toggleSucTodas(u: Usuario, checked: boolean) {
		if (!checked) {
			sucMenuDraftIds = [];
			return;
		}
		sucMenuDraftIds = null;
		u.sucursal_ids = null;
		guardarSucursalesUsuario(u.id, null);
	}
	function toggleSucUna(u: Usuario, sucId: number, checked: boolean) {
		const actuales = sucMenuDraftIds ?? [];
		const nuevos = checked ? [...actuales, sucId] : actuales.filter((x) => x !== sucId);
		sucMenuDraftIds = nuevos;
		if (nuevos.length === 0) return;
		u.sucursal_ids = nuevos;
		guardarSucursalesUsuario(u.id, nuevos);
	}
	onMount(() => {
		const onDocClick = (e: MouseEvent) => {
			const menu = document.getElementById('suc-floating-menu');
			if (menu && !menu.contains(e.target as Node) && !(e.target as HTMLElement).closest('.suc-toggle')) cerrarSucMenu();
		};
		document.addEventListener('click', onDocClick);
		document.addEventListener('scroll', cerrarSucMenu, true);
		return () => {
			document.removeEventListener('click', onDocClick);
			document.removeEventListener('scroll', cerrarSucMenu, true);
		};
	});

	// ── Modal de permisos granulares ─────────────────────────────
	let modalPermisos = $state(false);
	let permUsuario = $state<Usuario | null>(null);
	let permChecks = $state<Record<string, boolean>>({});
	function abrirPermisos(u: Usuario) {
		permUsuario = u;
		permChecks = { ...(u.permisos || {}) };
		modalPermisos = true;
	}
	function cerrarPermisos() {
		modalPermisos = false;
		permUsuario = null;
	}
	function aplicarPlantillaPerm(tpl: string) {
		const nuevo: Record<string, boolean> = {};
		for (const [k] of PERMISOS_DEF) nuevo[k] = !!PERM_TPL[tpl][k];
		permChecks = nuevo;
	}
	async function guardarPermisos() {
		if (!permUsuario) return;
		const body = { nombre: permUsuario.nombre.trim(), rol: permUsuario.rol, activo: !!permUsuario.activo, permisos: permChecks };
		const res = await api(`/usuarios/${permUsuario.id}`, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
		const data = await res.json();
		if (!res.ok) {
			toast_(data.error || 'Error al guardar permisos', 'err');
			return;
		}
		toast_('Permisos guardados. El usuario los ve completos al volver a iniciar sesión.', 'ok');
		cerrarPermisos();
		cargarUsuarios();
	}

	// ── Sucursales y Depósitos ────────────────────────────────────
	let sucursales = $state<Sucursal[]>([]);
	let sucActivaId = $state<number | null>(null);
	let sucForm = $state({ nombre: '', nombre_fantasia: '', domicilio: '', telefono: '', email: '', punto_venta: '', activo: true });
	let depositos = $state<Deposito[]>([]);
	let depositosCargando = $state(false);

	function cargarFormDesdeSucursal(s: Sucursal | null) {
		sucForm = {
			nombre: s?.nombre || '',
			nombre_fantasia: s?.nombre_fantasia || '',
			domicilio: s?.domicilio || '',
			telefono: s?.telefono || '',
			email: s?.email || '',
			punto_venta: s?.punto_venta != null ? String(s.punto_venta) : '',
			activo: s === null || !!s.activo
		};
	}

	async function cargarDepositos(sucursalId: number) {
		depositosCargando = true;
		try {
			const res = await api(`/sucursales/depositos?sucursal_id=${sucursalId}`);
			depositos = res.ok ? await res.json() : [];
		} finally {
			depositosCargando = false;
		}
	}

	function seleccionarSucursal(id: number | null) {
		sucActivaId = id;
		if (id === null) {
			cargarFormDesdeSucursal(null);
			depositos = [];
		} else {
			const s = sucursales.find((x) => x.id === id);
			cargarFormDesdeSucursal(s ?? null);
			cargarDepositos(id);
		}
	}

	async function cargarSucursales() {
		const res = await api('/sucursales?todas=1');
		sucursales = res.ok ? await res.json() : [];
		if (!sucursales.find((s) => s.id === sucActivaId)) {
			sucActivaId = sucursales[0]?.id ?? null;
		}
		if (sucActivaId !== null) {
			const activa = sucursales.find((s) => s.id === sucActivaId);
			cargarFormDesdeSucursal(activa ?? null);
			if (activa) await cargarDepositos(activa.id);
		} else {
			cargarFormDesdeSucursal(null);
		}
		await cargarUsuarios();
	}

	let guardandoSucursal = $state(false);
	async function guardarSucursal() {
		const body = {
			nombre: sucForm.nombre.trim(),
			nombre_fantasia: sucForm.nombre_fantasia.trim() || null,
			domicilio: sucForm.domicilio.trim() || null,
			telefono: sucForm.telefono.trim() || null,
			email: sucForm.email.trim() || null,
			punto_venta: sucForm.punto_venta || null,
			activo: sucForm.activo
		};
		if (!body.nombre) {
			toast_('El nombre de la sucursal es requerido', 'err');
			return;
		}
		guardandoSucursal = true;
		try {
			const url = sucActivaId ? `/sucursales/${sucActivaId}` : '/sucursales';
			const method = sucActivaId ? 'PUT' : 'POST';
			const res = await api(url, { method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
			const data = await res.json();
			if (!res.ok) {
				toast_(data.error || 'Error al guardar la sucursal', 'err');
				return;
			}
			sucActivaId = sucActivaId || data.id;
			await cargarSucursales();
			toast_('Sucursal guardada', 'ok');
		} finally {
			guardandoSucursal = false;
		}
	}

	let eliminandoSucursal = $state(false);
	async function eliminarSucursal() {
		if (!sucActivaId) return;
		const s = sucursales.find((x) => x.id === sucActivaId);
		const ok = await confirmar(`Esto borra la sucursal "${s?.nombre ?? ''}" y su depósito. Solo se puede hacer si no tiene cajas, ventas, compras ni turnos registrados — si los tiene, desactivala en vez de borrarla.`, {
			titulo: 'Eliminar sucursal',
			confirmLabel: 'Eliminar',
			danger: true
		});
		if (!ok) return;
		eliminandoSucursal = true;
		try {
			const res = await api(`/sucursales/${sucActivaId}`, { method: 'DELETE' });
			const data = await res.json();
			if (!res.ok) {
				toast_(data.error || 'Error al eliminar la sucursal', 'err');
				return;
			}
			sucActivaId = null;
			await cargarSucursales();
			toast_('Sucursal eliminada', 'ok');
		} finally {
			eliminandoSucursal = false;
		}
	}

	async function setPrincipal(depositoId: number) {
		await api(`/sucursales/deposito/${depositoId}`, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ es_principal: true }) });
		if (sucActivaId) cargarDepositos(sucActivaId);
		toast_('Depósito principal actualizado', 'ok');
	}

	// Modal Depósito
	let modalDeposito = $state(false);
	let editDepId = $state<number | null>(null);
	let depForm = $state({ nombre: '', descripcion: '', activo: true });
	function abrirModalDeposito(dep: Deposito | null = null) {
		editDepId = dep?.id ?? null;
		depForm = { nombre: dep?.nombre || '', descripcion: dep?.descripcion || '', activo: dep ? !!dep.activo : true };
		modalDeposito = true;
	}
	let guardandoDeposito = $state(false);
	async function guardarDeposito() {
		const nombre = depForm.nombre.trim();
		if (!nombre) {
			toast_('El nombre del depósito es requerido', 'err');
			return;
		}
		const body = { nombre, descripcion: depForm.descripcion.trim() || null, activo: depForm.activo, sucursal_id: sucActivaId };
		const url = editDepId ? `/sucursales/deposito/${editDepId}` : '/sucursales/deposito';
		const method = editDepId ? 'PUT' : 'POST';
		guardandoDeposito = true;
		try {
			const res = await api(url, { method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
			const data = await res.json();
			if (!res.ok) {
				toast_(data.error || 'Error al guardar el depósito', 'err');
				return;
			}
			modalDeposito = false;
			if (sucActivaId) await cargarDepositos(sucActivaId);
			toast_('Depósito guardado', 'ok');
		} catch {
			toast_('Error de conexión', 'err');
		} finally {
			guardandoDeposito = false;
		}
	}

	// Días hasta el vencimiento del certificado AFIP (negativo = ya vencido).
	// Alimenta la escalada visual del estado de abajo — mismo umbral (30 días)
	// que pos/afip-cert-aviso.js / cargarAfip() en (app)/+layout.svelte.
	const afipDiasVencimiento = $derived.by(() => {
		if (!cfgAfipVencimiento) return null;
		const [d, m, y] = cfgAfipVencimiento.split('/').map(Number);
		const venc = new Date(y, m - 1, d);
		return Math.ceil((venc.getTime() - Date.now()) / 86400000);
	});

	// ── Certificado AFIP — autoservicio de CSR (Logos genera clave+CSR, el
	// cliente sube el CSR a ARCA y vuelve con el certificado firmado) ──────
	// La clave privada pendiente vive en sessionStorage (no en $state): tiene
	// que sobrevivir un F5 de la SPA mientras el cliente va y vuelve del
	// portal de ARCA, igual que en pos/configuracion.html (versión legacy,
	// ver commit de "autoservicio de certificado ARCA").
	let afipAlias = $state('');
	let generandoCsr = $state(false);
	let afipCertFirmadoInput = $state<HTMLInputElement | undefined>();
	let mostrarSubirFirmado = $state(false);
	let guardandoFirmado = $state(false);

	async function generarCsrAfip() {
		generandoCsr = true;
		try {
			const res = await api('/configuracion/afip-csr', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ alias: afipAlias.trim() }),
			});
			const d = await res.json();
			if (!res.ok) {
				toast_(d.error || 'Error al generar el CSR', 'err');
				return;
			}
			sessionStorage.setItem('afip_key_pendiente', d.key);
			const blob = new Blob([d.csr], { type: 'application/pkcs10' });
			const a = document.createElement('a');
			a.href = URL.createObjectURL(blob);
			a.download = 'logos_certificado.csr';
			a.click();
			URL.revokeObjectURL(a.href);
			toast_('CSR descargado. Subilo a ARCA y volvé con el certificado firmado.', 'ok');
			mostrarSubirFirmado = true;
		} catch {
			toast_('Error de conexión', 'err');
		} finally {
			generandoCsr = false;
		}
	}

	async function subirCertFirmado() {
		const file = afipCertFirmadoInput?.files?.[0];
		if (!file) {
			toast_('Seleccioná el certificado firmado', 'err');
			return;
		}
		const key = sessionStorage.getItem('afip_key_pendiente');
		if (!key) {
			toast_('Se perdió la clave generada (¿recargaste la página?). Generá el CSR de nuevo.', 'err');
			return;
		}
		const fd = new FormData();
		fd.append('cert_pem', await file.text());
		fd.append('key_pem', key);
		fd.append('entorno', cfgAfipEntorno);
		guardandoFirmado = true;
		try {
			const res = await api('/configuracion/cert-afip', { method: 'POST', body: fd });
			const data = await res.json();
			if (!res.ok) {
				toast_(data.error || 'Error al guardar el certificado', 'err');
				return;
			}
			sessionStorage.removeItem('afip_key_pendiente');
			toast_(`Certificado guardado — ${data.entorno === 'produccion' ? 'Producción' : 'Homologación'}`, 'ok');
			if (afipCertFirmadoInput) afipCertFirmadoInput.value = '';
			mostrarSubirFirmado = false;
			await cargarConfig();
		} catch {
			toast_('Error de conexión', 'err');
		} finally {
			guardandoFirmado = false;
		}
	}

	// ── Certificado AFIP — .p12 manual (opción avanzada) ────────
	let afipCertInput = $state<HTMLInputElement | undefined>();
	let afipCertPass = $state('');
	let subiendoCert = $state(false);
	async function subirCertAfip() {
		const file = afipCertInput?.files?.[0];
		if (!file) {
			toast_('Seleccioná un archivo .p12', 'err');
			return;
		}
		const fd = new FormData();
		fd.append('cert_p12', file);
		fd.append('cert_pass', afipCertPass);
		fd.append('entorno', cfgAfipEntorno);
		subiendoCert = true;
		try {
			const res = await api('/configuracion/cert-afip', { method: 'POST', body: fd });
			const data = await res.json();
			if (!res.ok) {
				toast_(data.error || 'Error al cargar el certificado', 'err');
				return;
			}
			toast_(`Certificado guardado — ${data.entorno === 'produccion' ? 'Producción' : 'Homologación'}`, 'ok');
			if (afipCertInput) afipCertInput.value = '';
			afipCertPass = '';
			await cargarConfig();
		} catch {
			toast_('Error de conexión', 'err');
		} finally {
			subiendoCert = false;
		}
	}

	// ── Zona de peligro: restaurar backup ───────────────────────
	// Solo tiene sentido en rol Servidor (es la única PC con MariaDB propia
	// para restaurar) — antes dependía de window.logos.getConfig(), que solo
	// existe bajo Electron: bajo Tauri el chequeo nunca corría y el control
	// quedaba SIEMPRE visible, sin importar el rol real de la instalación.
	let mostrarRestaurar = $state(true);
	onMount(() => {
		esRolServidor().then((esServidor) => {
			if (!esServidor) mostrarRestaurar = false;
			licMostrarControles = esServidor;
		});
		cargarLicencia();
	});
	let backupsDisponibles = $state<BackupOpt[]>([]);
	async function cargarListaBackups() {
		try {
			const res = await api('/backup/listar');
			backupsDisponibles = res.ok ? await res.json() : [];
		} catch {
			backupsDisponibles = [];
		}
	}
	let rbArchivoSeleccionado = $state('');
	let rbArchivoInput = $state<HTMLInputElement | undefined>();
	let rbConfirmacion = $state('');
	let rbClave = $state('');
	let rbRestaurando = $state(false);
	let rbArchivoFileNombre = $state('');
	const rbBotonHabilitado = $derived(rbConfirmacion.trim() === 'RESTAURAR BACKUP' && (rbArchivoFileNombre !== '' || rbArchivoSeleccionado !== ''));

	function onRbArchivoChange() {
		rbArchivoFileNombre = rbArchivoInput?.files?.[0]?.name || '';
	}

	async function restaurarBackup() {
		const ok = await confirmar('Esto reemplaza TODOS los datos actuales por los del backup elegido. Se guarda un backup del estado de ahora antes de empezar, pero la restauración en sí no se puede deshacer.', {
			titulo: 'Restaurar backup',
			confirmLabel: 'Restaurar',
			danger: true
		});
		if (!ok) return;
		rbRestaurando = true;
		const fd = new FormData();
		const file = rbArchivoInput?.files?.[0];
		if (file) {
			fd.append('backup', file);
		} else {
			const [nombre, origen] = rbArchivoSeleccionado.split('|');
			fd.append('archivo', nombre);
			fd.append('origen', origen);
		}
		fd.append('confirmacion', rbConfirmacion.trim());
		fd.append('clave_autorizacion', rbClave);
		try {
			const res = await api('/backup/restaurar', { method: 'POST', body: fd });
			const data = await res.json();
			if (!res.ok) {
				toast_(data.error || 'Error al restaurar', 'err');
				return;
			}
			localStorage.removeItem('logos_sesion');
			sessionStorage.clear();
			const logos = (window as unknown as { logos?: { restartApp?: () => void } }).logos;
			if (logos?.restartApp) {
				toast_('Restauración completa. Reiniciando la aplicación…', 'ok');
				setTimeout(() => logos.restartApp?.(), 1200);
			} else {
				toast_('Restauración completa. Cerrá y volvé a abrir Logos POS en la PC Servidor para terminar.', 'ok');
			}
		} catch {
			toast_('Error de conexión', 'err');
		} finally {
			rbRestaurando = false;
		}
	}

	// ── Licencia ─────────────────────────────────────────────────
	type LicenciaEstado = {
		estado_efectivo: string;
		modo_restringido: boolean;
		mensaje: string | null;
		fecha_ultima_verificacion_exitosa: string | null;
	};
	let licEstado = $state<LicenciaEstado | null>(null);
	let licMostrarControles = $state(false); // solo rol Servidor: /licencia/verificar exige localhost
	let licTokenNuevo = $state('');
	let licVerificando = $state(false);

	async function cargarLicencia() {
		try {
			licEstado = await apiJson<LicenciaEstado>('/licencia/estado');
		} catch {
			licEstado = null;
		}
	}

	async function licenciaVerificarAhora() {
		licVerificando = true;
		try {
			const r = await api('/licencia/verificar', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({})
			});
			const d = await r.json();
			licEstado = d;
			toast_(
				d.verificacion_ok ? 'Licencia verificada correctamente.' : 'No se pudo verificar la licencia ahora.',
				d.verificacion_ok ? 'ok' : 'err'
			);
		} catch {
			toast_('Error de conexión al verificar la licencia.', 'err');
		} finally {
			licVerificando = false;
		}
	}

	async function licenciaGuardarToken() {
		const token = licTokenNuevo.trim();
		if (!token) return;
		licVerificando = true;
		try {
			const r = await api('/licencia/verificar', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ token })
			});
			const d = await r.json();
			licEstado = d;
			if (d.verificacion_ok) {
				licTokenNuevo = '';
				toast_('Token guardado y verificado correctamente.', 'ok');
			} else {
				toast_(
					d.motivo_fallo === 'token_invalido'
						? 'Token inválido. Verificá que lo copiaste completo y sin espacios.'
						: 'No se pudo conectar a internet para validar el token.',
					'err'
				);
			}
		} catch {
			toast_('Error de conexión al guardar el token.', 'err');
		} finally {
			licVerificando = false;
		}
	}

	// ── Zona de peligro: reset de fábrica ───────────────────────
	let rfConfirmacion = $state('');
	let rfClave = $state('');
	let rfRestableciendo = $state(false);
	const rfBotonHabilitado = $derived(rfConfirmacion.trim() === 'BORRAR TODO');

	async function resetFabrica() {
		const ok = await confirmar('Esto borra TODOS los datos (productos, clientes, ventas, usuarios, cajas) y no se puede deshacer.', { titulo: 'Restablecer de fábrica', confirmLabel: 'Borrar todo', danger: true });
		if (!ok) return;
		rfRestableciendo = true;
		try {
			const res = await api('/configuracion/reset-fabrica', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ confirmacion: rfConfirmacion.trim(), clave_autorizacion: rfClave })
			});
			const data = await res.json();
			if (!res.ok) {
				toast_(data.error || 'Error al restablecer', 'err');
				return;
			}
			localStorage.removeItem('logos_sesion');
			sessionStorage.clear();
			location.href = '/Logos/pos/instalar.html';
		} catch {
			toast_('Error de conexión', 'err');
		} finally {
			rfRestableciendo = false;
		}
	}

	// ── Init ─────────────────────────────────────────────────────
	(async () => {
		await cargarConfig();
		await cargarImpresoras();
		await cargarCajas();
		await cargarSucursales();
		cargarEstadoBackup();
		cargarListaBackups();
	})();
</script>

<svelte:head>
	<title>Logos — Configuración</title>
</svelte:head>

<div class="page-header">
	<h1>Configuración</h1>
	<p>Datos del negocio y sucursales, datos contables y configuración avanzada</p>
</div>

<div class="tab-bar">
	<button class="tab-btn" class:activo={vistaActual === 'negocio'} onclick={() => setVista('negocio')}>Datos del negocio</button>
	<button class="tab-btn" class:activo={vistaActual === 'contables'} onclick={() => setVista('contables')}>Datos contables</button>
	<button class="tab-btn" class:activo={vistaActual === 'avanzado'} onclick={() => setVista('avanzado')}>Configuración avanzada</button>
</div>

<div class="contenido">
	<div class="contenido-inner">
		{#if vistaActual === 'negocio'}
			<div class="cols cols-negocio">
				<div class="col">
					<div class="card">
						<div class="card-titulo">Usuarios</div>
						<div class="card-body">
							<div class="form-hint" style="margin-bottom:14px">
								"Admin" tiene acceso total y entra directo a la caja de tipo Compra. "Vendedor" y "Encargado" son plantillas de permisos sobre el rol User: el usuario nace con esos accesos y podés afinarlos cuando quieras con el botón <b>Permisos</b>.
							</div>
							<div class="table-wrap">
								<table class="cajas">
									<thead>
										<tr><th>Nombre</th><th style="width:120px">Rol</th><th style="width:150px">Sucursales</th><th style="width:140px">Nuevo PIN</th><th style="width:90px">Activo</th><th style="width:110px"></th></tr>
									</thead>
									<tbody>
										{#if !usuarios.length}
											<tr><td colspan="6" style="color:var(--gris3)">Sin usuarios todavía.</td></tr>
										{:else}
											{#each usuarios as u (u.id)}
												<tr>
													<td><input type="text" class="form-input" bind:value={u.nombre} /></td>
													<td>
														<select class="form-select" bind:value={u.rol}>
															<option value="user">{etiquetaRolUser(u)}</option>
															<option value="admin">Admin</option>
														</select>
													</td>
													<td>
														{#if u.rol === 'admin'}
															<span class="badge ok">Todas</span>
														{:else}
															<button type="button" class="btn-mini suc-toggle" onclick={(ev) => abrirSucMenu(u, ev)}>{resumenSucursales(u.sucursal_ids)} ▾</button>
														{/if}
													</td>
													<td><input type="text" class="form-input" placeholder="(sin cambios)" inputmode="numeric" value={usuarioPins[u.id] || ''} oninput={(e) => (usuarioPins[u.id] = (e.target as HTMLInputElement).value)} /></td>
													<td><label class="check-label"><input type="checkbox" checked={!!u.activo} onchange={(e) => (u.activo = (e.target as HTMLInputElement).checked)} /></label></td>
													<td class="btn-cell">
														{#if u.rol === 'user'}
															<button class="btn-mini" onclick={() => abrirPermisos(u)}>Permisos</button>
														{/if}
														<button class="btn-mini" onclick={() => guardarUsuario(u)}>Guardar</button>
														<button class="btn-mini danger" onclick={() => borrarUsuario(u.id)}>Borrar</button>
													</td>
												</tr>
											{/each}
										{/if}
									</tbody>
								</table>
							</div>
							<div class="fila-nueva">
								<input type="text" class="form-input" placeholder="Nombre" bind:value={usuarioNuevoNombre} />
								<select class="form-select" style="flex:0 0 140px" bind:value={usuarioNuevoRol} title="Vendedor y Encargado son plantillas de permisos (ajustables después); Admin tiene acceso total">
									<option value="vendedor">Vendedor</option>
									<option value="encargado">Encargado</option>
									<option value="admin">Admin</option>
								</select>
								<input type="text" class="form-input" placeholder="PIN (4-6 dígitos)" style="flex:0 0 160px" inputmode="numeric" bind:value={usuarioNuevoPin} />
								<button class="btn btn-sec" onclick={agregarUsuario}>Agregar usuario</button>
							</div>
						</div>
					</div>

					<div class="card" id="card-suc-deps">
						<div class="card-header">
							<h3>Sucursales y Depósitos</h3>
							<div class="suc-tabs">
								{#each sucursales as s (s.id)}
									<button class="suc-tab" class:activo={s.id === sucActivaId} onclick={() => seleccionarSucursal(s.id)}>{s.nombre}</button>
								{/each}
								{#if sucActivaId === null}
									<button class="suc-tab activo">Nueva sucursal</button>
								{/if}
								<button class="suc-tab-add" title="Agregar sucursal" onclick={() => seleccionarSucursal(null)}>+</button>
							</div>
						</div>
						<div class="suc-panel-body">
							<div class="suc-form-grid">
								<div class="form-group"><label for="suc-f-nombre">Nombre *</label><input id="suc-f-nombre" type="text" class="form-input" placeholder="Ej: Casa Central" bind:value={sucForm.nombre} /></div>
								<div class="form-group"><label for="suc-f-fantasia">Nombre fantasía</label><input id="suc-f-fantasia" type="text" class="form-input" bind:value={sucForm.nombre_fantasia} /></div>
								<div class="form-group"><label for="suc-f-domicilio">Domicilio</label><input id="suc-f-domicilio" type="text" class="form-input" bind:value={sucForm.domicilio} /></div>
								<div class="form-group"><label for="suc-f-telefono">Teléfono</label><input id="suc-f-telefono" type="text" class="form-input" bind:value={sucForm.telefono} /></div>
								<div class="form-group"><label for="suc-f-email">Email</label><input id="suc-f-email" type="email" class="form-input" bind:value={sucForm.email} /></div>
								<div class="form-group"><label for="suc-f-pto">Punto de venta AFIP (override)</label><input id="suc-f-pto" type="number" class="form-input" placeholder="Dejar vacío para el global" bind:value={sucForm.punto_venta} /></div>
							</div>
							<div class="suc-form-footer">
								<label><input type="checkbox" bind:checked={sucForm.activo} /> Activa</label>
								{#if sucActivaId !== null && sucursales.length > 1}
									<button class="btn-sec" disabled={eliminandoSucursal} onclick={eliminarSucursal}>Eliminar sucursal</button>
								{/if}
								<button class="btn-accion" disabled={guardandoSucursal} onclick={guardarSucursal}>Guardar sucursal</button>
							</div>
							{#if sucActivaId !== null}
								<div class="suc-dep-header">
									<span class="suc-dep-titulo">Depósitos</span>
									<button class="btn-accion" onclick={() => abrirModalDeposito(null)}>+ Nuevo depósito</button>
								</div>
								<table class="tabla">
									<thead><tr><th>#</th><th>Nombre</th><th>Descripción</th><th>Principal</th><th>Activo</th><th></th></tr></thead>
									<tbody>
										{#if depositosCargando}
											<tr><td colspan="6" class="empty">Cargando…</td></tr>
										{:else if !depositos.length}
											<tr><td colspan="6" class="empty">Sin depósitos — agregá el primero</td></tr>
										{:else}
											{#each depositos as d (d.id)}
												<tr>
													<td>{d.id}</td>
													<td>{d.nombre}</td>
													<td>{d.descripcion || '—'}</td>
													<td>
														{#if d.es_principal}
															<span class="badge ok">Principal</span>
														{:else}
															<button class="btn-mini" onclick={() => setPrincipal(d.id)}>Establecer</button>
														{/if}
													</td>
													<td><span class="badge {d.activo ? 'ok' : 'warn'}">{d.activo ? 'Sí' : 'No'}</span></td>
													<td class="acciones"><button class="btn-mini" onclick={() => abrirModalDeposito(d)}>Editar</button></td>
												</tr>
											{/each}
										{/if}
									</tbody>
								</table>
							{/if}
						</div>
					</div>
				</div>
				<div class="col">
					<div class="card">
						<div class="card-titulo">Cajas</div>
						<div class="card-body">
							<div class="form-hint" style="margin-bottom:14px">El tipo define quién puede usar cada caja: los usuarios "Admin" pueden usar cualquiera, los usuarios "User" solo las de tipo Venta.</div>
							{#if !hayCajaVentaActiva}
								<div style="display:block;margin-bottom:14px;padding:10px 14px;border-radius:var(--neo-r-sm);background:rgba(243,156,18,.12);color:var(--neo-warning);font-size:13px;font-weight:600">
									⚠ No hay ninguna caja de tipo Venta activa: los usuarios no administradores no van a poder iniciar sesión.
								</div>
							{/if}
							<div class="table-wrap">
								<table class="cajas">
									<thead><tr><th>Nombre</th><th style="width:130px">Tipo</th><th style="width:90px">Activa</th><th style="width:110px"></th></tr></thead>
									<tbody>
										{#if !cajas.length}
											<tr><td colspan="4" style="color:var(--gris3)">Sin cajas todavía.</td></tr>
										{:else}
											{#each cajas as c (c.id)}
												<tr>
													<td><input type="text" class="form-input" bind:value={c.nombre} /></td>
													<td>
														<select class="form-select" bind:value={c.tipo}>
															<option value="venta">Venta</option>
															<option value="compra">Compra</option>
														</select>
													</td>
													<td><label class="check-label"><input type="checkbox" checked={!!c.activo} onchange={(e) => (c.activo = (e.target as HTMLInputElement).checked)} /></label></td>
													<td class="btn-cell">
														<button class="btn-mini" onclick={() => guardarCaja(c)}>Guardar</button>
														<button class="btn-mini danger" onclick={() => borrarCaja(c.id)}>Borrar</button>
													</td>
												</tr>
											{/each}
										{/if}
									</tbody>
								</table>
							</div>
							<div class="fila-nueva">
								<input type="text" class="form-input" placeholder="Nombre de la nueva caja" bind:value={cajaNuevoNombre} />
								<select class="form-select" style="flex:0 0 130px" bind:value={cajaNuevoTipo}>
									<option value="venta">Venta</option>
									<option value="compra">Compra</option>
								</select>
								<button class="btn btn-sec" onclick={agregarCaja}>Agregar caja</button>
							</div>
						</div>
					</div>

					<div class="card">
						<div class="card-titulo">Logo</div>
						<div class="card-body">
							<div class="logo-row">
								<div class="logo-preview">
									<img src={logoSrc} alt="" onload={() => (logoExiste = true)} onerror={() => (logoExiste = false)} />
								</div>
								<div>
									<input type="file" accept="image/png,image/jpeg,image/gif,image/webp" onchange={subirLogo} />
									{#if logoExiste}
										<div class="form-hint" style="margin-top:8px; color:var(--ok, #2a8c5e)">✓ Ya tenés un logo cargado (se ve en la vista previa).</div>
									{:else}
										<div class="form-hint" style="margin-top:8px">Todavía no cargaste un logo.</div>
									{/if}
									<div class="form-hint" style="margin-top:2px">Se usa en los comprobantes PDF.</div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		{:else if vistaActual === 'contables'}
			<div class="cols cols-3">
				<div class="col">
					<div class="card">
						<div class="card-titulo">Datos fiscales</div>
						<div class="card-body">
							<div class="form-grid">
								<div class="form-group full"><label class="form-label" for="cfg-razon">Razón social</label><input class="form-input" id="cfg-razon" bind:value={cfgRazonSocial} oninput={marcarCambio} /></div>
								<div class="form-group"><label class="form-label" for="cfg-cuit">CUIT</label><input class="form-input" id="cfg-cuit" placeholder="20396273455" bind:value={cfgCuit} oninput={marcarCambio} /></div>
								<div class="form-group"><label class="form-label" for="cfg-condicion">Condición IVA</label><input class="form-input" id="cfg-condicion" bind:value={cfgCondicionIva} oninput={marcarCambio} /></div>
								<div class="form-group"><label class="form-label" for="cfg-iibb">IIBB</label><input class="form-input" id="cfg-iibb" bind:value={cfgIibb} oninput={marcarCambio} /></div>
								<div class="form-group"><label class="form-label" for="cfg-tel">Teléfono</label><input class="form-input" id="cfg-tel" bind:value={cfgTelefono} oninput={marcarCambio} /></div>
								<div class="form-group"><label class="form-label" for="cfg-pv">Punto de venta</label><input class="form-input" type="number" min="1" id="cfg-pv" bind:value={cfgPuntoVenta} oninput={marcarCambio} /></div>
								<div class="form-group"><label class="form-label" for="cfg-ivap">% IVA</label><input class="form-input" type="number" min="0" max="100" step="0.01" id="cfg-ivap" bind:value={cfgIvaPorcentaje} oninput={marcarCambio} /></div>
								<div class="form-group full">
									<span class="form-label">Tipos de comprobante habilitados</span>
									<div class="checkbox-group">
										{#each [['FC B-ELECT', 'Factura B'], ['FC A-ELECT', 'Factura A'], ['FC C-ELECT', 'Factura C'], ['REMITO', 'Remito'], ['PRESUPUESTO', 'Presupuesto']] as [val, lbl] (val)}
											<label class="checkbox-item">
												<input
													type="checkbox"
													checked={cfgTipos.has(val)}
													onchange={(e) => {
														const s = new Set(cfgTipos);
														(e.target as HTMLInputElement).checked ? s.add(val) : s.delete(val);
														cfgTipos = s;
														marcarCambio();
													}}
												/>
												{lbl}
											</label>
										{/each}
									</div>
									<div class="form-hint">Si ninguno está seleccionado, todos quedan habilitados.</div>
								</div>
								<div class="form-group full"><label class="form-label" for="cfg-dom">Domicilio</label><input class="form-input" id="cfg-dom" bind:value={cfgDomicilio} oninput={marcarCambio} /></div>
								<div class="form-group full"><label class="form-label" for="cfg-web">Website</label><input class="form-input" id="cfg-web" bind:value={cfgWebsite} oninput={marcarCambio} /></div>
							</div>
						</div>
					</div>
				</div>
				<div class="col">
					<div class="card">
						<div class="card-titulo">Facturación electrónica AFIP</div>
						<div class="card-body">
							{#if cfgAfipConfigurado}
								{#if afipDiasVencimiento !== null && afipDiasVencimiento < 0}
									<div class="afip-estado err"><span class="afip-dot"></span>Certificado vencido el {cfgAfipVencimiento} — las facturas electrónicas no van a funcionar hasta que lo renueves.</div>
								{:else if afipDiasVencimiento !== null && afipDiasVencimiento <= 30}
									<div class="afip-estado warn"><span class="afip-dot"></span>Certificado cargado · {cfgAfipEntorno === 'produccion' ? 'Producción' : 'Homologación'} — vence en {afipDiasVencimiento} día{afipDiasVencimiento === 1 ? '' : 's'} ({cfgAfipVencimiento}). Generá uno nuevo antes de que venza.</div>
								{:else}
									<div class="afip-estado ok"><span class="afip-dot"></span>Certificado cargado · {cfgAfipEntorno === 'produccion' ? 'Producción' : 'Homologación'}{cfgAfipVencimiento ? ` — vence el ${cfgAfipVencimiento}` : ''}</div>
								{/if}
							{:else}
								<div class="afip-estado warn"><span class="afip-dot"></span>Sin certificado — las facturas electrónicas no van a funcionar hasta que lo configures.</div>
							{/if}

							<details class="afip-pasos-details" open={!cfgAfipConfigurado}>
								<summary>Cómo hacer el trámite en ARCA (paso a paso)</summary>
								<div class="afip-pasos">
									<div class="afip-paso">
										<div class="afip-paso-num">1</div>
										<div class="afip-paso-body">
											<strong>Tener clave fiscal nivel 3</strong>
											Si solo tenés nivel 2, pedí la suba de nivel en cualquier agencia de ARCA o por TAD.
										</div>
									</div>
									<div class="afip-paso">
										<div class="afip-paso-num">2</div>
										<div class="afip-paso-body">
											<strong>Adherir "Administración de Certificados Digitales"</strong>
											En ARCA: Administrador de Relaciones → Adherir Servicio → ARCA → Servicios Interactivos → Administración de Certificados Digitales → confirmar.
										</div>
									</div>
									<div class="afip-paso">
										<div class="afip-paso-num">3</div>
										<div class="afip-paso-body">
											<strong>Generar la clave y el CSR acá abajo</strong>
											Completá el alias y hacé clic en "Generar clave y CSR nuevo" — se descarga un archivo <code>.csr</code>. No hace falta entender qué es, solo guardalo.
										</div>
									</div>
									<div class="afip-paso">
										<div class="afip-paso-num">4</div>
										<div class="afip-paso-body">
											<strong>Subir el CSR a ARCA y volver con el certificado</strong>
											En ARCA, misma sección → "Agregar alias" → subí el <code>.csr</code> que descargaste → ARCA te da para descargar el certificado firmado (no es un <code>.p12</code>). Volvé acá y subilo donde dice "Certificado firmado".
										</div>
									</div>
									<div class="afip-paso">
										<div class="afip-paso-num">5</div>
										<div class="afip-paso-body">
											<strong>Adherir "Facturación Electrónica"</strong>
											En ARCA: Adherir Servicio → ARCA → WebServices → Facturación Electrónica. Al confirmar, hacé clic en "BUSCAR" al lado de "Representante" y elegí el alias del paso 3 — aunque ya aparezca cargado, si no hacés esto ARCA rechaza la adhesión.
										</div>
									</div>
									<div class="afip-paso">
										<div class="afip-paso-num">6</div>
										<div class="afip-paso-body">
											<strong>Punto de venta</strong>
											Tiene que estar creado en ARCA. El número se carga en la pestaña "Datos del negocio" de esta misma pantalla.
										</div>
									</div>
								</div>
							</details>

							<div class="form-grid" style="margin-top:12px">
								<div class="form-group full">
									<label class="form-label" for="afip-alias">Alias del certificado</label>
									<input type="text" class="form-input" id="afip-alias" placeholder="Ej: LogosPOS" bind:value={afipAlias} />
								</div>
								<div class="form-group">
									<label class="form-label" for="afip-ent">Entorno</label>
									<select class="form-select" id="afip-ent" bind:value={cfgAfipEntorno}>
										<option value="homologacion">Homologación (pruebas)</option>
										<option value="produccion">Producción</option>
									</select>
								</div>
							</div>
							<div class="btn-row">
								<button class="btn btn-ok" disabled={generandoCsr} onclick={generarCsrAfip}>{generandoCsr ? 'Generando…' : 'Generar clave y CSR nuevo'}</button>
							</div>

							{#if mostrarSubirFirmado}
								<div class="form-group full" style="margin-top:10px">
									<label class="form-label" for="afip-cert-firmado">Certificado firmado (descargado de ARCA)</label>
									<input type="file" class="form-input" id="afip-cert-firmado" accept=".pem,.crt,.cer,.txt" bind:this={afipCertFirmadoInput} />
									<div class="btn-row">
										<button class="btn btn-ok" disabled={guardandoFirmado} onclick={subirCertFirmado}>{guardandoFirmado ? 'Guardando…' : 'Guardar certificado'}</button>
									</div>
								</div>
							{/if}

							<details style="margin-top:14px;font-size:12px">
								<summary style="cursor:pointer;font-weight:600">Subir un archivo .p12 en vez (opción avanzada)</summary>
								<div class="form-grid" style="margin-top:10px">
									<div class="form-group full">
										<label class="form-label" for="afip-cert">Nuevo certificado digital (.p12)</label>
										<input type="file" class="form-input" id="afip-cert" accept=".p12,.pfx" bind:this={afipCertInput} />
										<div class="form-hint">Subir un nuevo archivo reemplaza el anterior.</div>
									</div>
									<div class="form-group"><label class="form-label" for="afip-pass">Contraseña del .p12</label><input type="password" class="form-input" id="afip-pass" autocomplete="new-password" bind:value={afipCertPass} /></div>
								</div>
								<div class="btn-row">
									<button class="btn btn-ok" disabled={subiendoCert} onclick={subirCertAfip}>{subiendoCert ? 'Cargando…' : 'Guardar certificado'}</button>
								</div>
							</details>
						</div>
					</div>
				</div>
				<div class="col">
					<div class="card">
						<div class="card-titulo">Comportamiento de ventas</div>
						<div class="card-body">
							<label class="checkbox-item" style="font-size:13px;gap:10px;cursor:pointer">
								<input type="checkbox" bind:checked={cfgVentasSinStock} onchange={marcarCambio} />
								Permitir ventas sin stock suficiente (el stock puede quedar negativo)
							</label>
							<div class="form-hint" style="margin-top:8px">Si está activo, el POS permite confirmar una venta aunque el producto no tenga stock disponible. El stock resultante quedará en negativo.</div>
						</div>
					</div>
					<div class="card">
						<div class="card-titulo">Terminales Posnet</div>
						<div class="card-body">
							<div class="form-hint" style="margin-bottom:14px">Cada terminal aparecerá como un campo de cierre en la pantalla de Caja (X / Z). Ingresá el nombre o número que identifica cada dispositivo.</div>
							<div class="table-wrap">
								<table class="cajas">
									<thead><tr><th>Nombre / Identificación</th><th style="width:90px"></th></tr></thead>
									<tbody>
										{#if !posnetTerminales.length}
											<tr><td colspan="2" style="color:var(--gris3)">Sin terminales configuradas.</td></tr>
										{:else}
											{#each posnetTerminales as t, i (i)}
												<tr>
													<td><input type="text" class="form-input" bind:value={t.nombre} /></td>
													<td><button class="btn-mini danger" onclick={() => quitarPosnet(i)}>Borrar</button></td>
												</tr>
											{/each}
										{/if}
									</tbody>
								</table>
							</div>
							<div class="fila-nueva">
								<input type="text" class="form-input" placeholder="Ej: Terminal 1, Naranja X, etc." bind:value={posnetNuevoNombre} />
								<button class="btn btn-sec" onclick={agregarPosnet}>Agregar terminal</button>
							</div>
							<div class="btn-row">
								<button class="btn btn-ok" onclick={guardarPosnet}>Guardar terminales</button>
							</div>
						</div>
					</div>
				</div>
			</div>
		{:else if vistaActual === 'avanzado'}
			<div class="avz-seccion">Configuración</div>
			<div class="cols-avz">
				<div class="col">
					<div class="card">
						<div class="card-titulo">Seguridad</div>
						<div class="card-body">
							<div class="form-grid">
								<div class="form-group full">
									<label class="form-label" for="cfg-clave">Clave de autorización para eliminar ventas</label>
									<input class="form-input" type="password" id="cfg-clave" placeholder="(sin cambios)" autocomplete="new-password" bind:value={cfgClaveAutorizacion} oninput={marcarCambio} />
									<div class="form-hint">
										{cfgClaveConfigurada ? 'Clave configurada. ' : 'Todavía no hay clave configurada — los usuarios no Admin podrán eliminar libremente. '}
										Los usuarios sin rol Admin deberán ingresar esta clave para eliminar remitos/presupuestos/facturas en Ventas.
									</div>
								</div>
							</div>
						</div>
					</div>
					<div class="card">
						<div class="card-titulo">Este equipo</div>
						<div class="card-body">
							<div class="form-hint" style="margin-bottom:14px">El nombre de este equipo aparece en el log de acciones para identificar desde qué PC se realizó cada operación. Se guarda solo en este navegador.</div>
							<div class="form-grid">
								<div class="form-group full"><label class="form-label" for="cfg-dev">Nombre del equipo</label><input class="form-input" id="cfg-dev" placeholder="Ej: Caja 1, Administración…" maxlength="100" bind:value={deviceName} /></div>
							</div>
							<div class="btn-row">
								<button class="btn btn-ok" onclick={guardarDeviceName}>Guardar nombre</button>
							</div>
							{#if appVersion}
								<div class="form-grid" style="margin-top:14px">
									<div class="form-group full"><span class="form-label">Versión instalada</span><div class="form-hint" style="font-size:13px;font-weight:600;color:var(--neo-text)">{appVersion}</div></div>
								</div>
							{/if}
						</div>
					</div>
				</div>
				<div class="col">
					<div class="card">
						<div class="card-titulo">Impresión</div>
						<div class="card-body">
							<div class="form-grid">
								<div class="form-group full">
									<label class="form-label" for="cfg-imp">Impresora por defecto</label>
									<select class="form-select" id="cfg-imp" bind:value={cfgImpresora} onchange={marcarCambio}>
										{#if !impresoras.length}
											<option value="">Cargando impresoras…</option>
										{:else}
											<option value="">— Sin impresora —</option>
											{#each impresoras as n (n)}
												<option value={n}>{n}</option>
											{/each}
											{#if cfgImpresora && !impresoras.includes(cfgImpresora)}
												<option value={cfgImpresora}>{cfgImpresora} (no detectada)</option>
											{/if}
										{/if}
									</select>
								</div>
								<div class="form-group full">
									<label class="form-label" for="cfg-carpc">Carpeta donde guardar copia de cada comprobante</label>
									<input class="form-input" id="cfg-carpc" placeholder="C:\Logos\comprobantes" bind:value={cfgCarpetaComprobantes} oninput={marcarCambio} />
									<div class="form-hint">Ruta en este servidor. Se guarda una copia automática de cada comprobante generado.</div>
								</div>
							</div>
							<div class="btn-row">
								<button class="btn btn-sec" onclick={probarImpresion}>Probar impresión</button>
							</div>
						</div>
					</div>
					<div class="card">
						<div class="card-titulo">Backup de la base de datos</div>
						<div class="card-body">
							<div class="form-grid">
								<div class="form-group full"><label class="form-label" for="cfg-cbk">Carpeta de backups</label><input class="form-input" id="cfg-cbk" placeholder="Vacío = C:\ProgramData\LogosPOS\backups (automático)" bind:value={cfgCarpetaBackups} oninput={marcarCambio} /></div>
								<div class="form-group full"><label class="form-label" for="cfg-cbk2">Carpeta secundaria (copia externa)</label><input class="form-input" id="cfg-cbk2" placeholder="Unidad de red o carpeta sincronizada a la nube (Drive, Dropbox)" bind:value={cfgCarpetaBackupsSecundaria} oninput={marcarCambio} /></div>
							</div>
							<div class="form-group full" style="margin-top:10px">
								<label class="check-label"><input type="checkbox" bind:checked={cfgBackupAutoCierre} onchange={marcarCambio} /> Hacer backup automáticamente al cerrar el turno diario (Z)</label>
							</div>
							{#if backupEstadoLinea}
								<p style="margin:10px 0 0;font-size:13px;color:{backupEstadoAlerta ? '#c0392b' : 'var(--texto-secundario,#666)'}">{backupEstadoLinea}</p>
							{/if}
							<div class="btn-row">
								<button class="btn btn-sec" onclick={backupAhora}>Backup ahora</button>
							</div>
						</div>
					</div>
				</div>
			</div>

			<div class="avz-seccion">Integraciones</div>
			<div class="cols cols-3">
				<div class="col">
					<div class="card">
						<div class="card-titulo">MercadoPago</div>
						<div class="card-body">
							<div class="form-hint" style="margin-bottom:14px">Generá links de pago QR desde Ventas para que el cliente pague con MercadoPago.</div>
							<div class="form-grid">
								<div class="form-group full"><label class="form-label" for="mp-tok">Access Token de producción</label><input class="form-input" type="password" id="mp-tok" placeholder="APP_USR-..." autocomplete="new-password" bind:value={mpToken} /></div>
							</div>
							<div style="margin:8px 0 4px;font-size:13px;color:{cfgMpConfigurado ? 'var(--neo-ok)' : '#888'}">{cfgMpConfigurado ? '✓ MercadoPago configurado' : 'Sin configurar'}</div>
							<div class="btn-row">
								<button class="btn btn-ok" disabled={guardandoMp} onclick={guardarMp}>{guardandoMp ? 'Guardando...' : 'Guardar MercadoPago'}</button>
							</div>
						</div>
					</div>
				</div>
				<div class="col">
					<div class="card">
						<div class="card-titulo">WhatsApp Business</div>
						<div class="card-body">
							<div class="form-hint" style="margin-bottom:14px">Enviá comprobantes PDF al cliente directamente desde Ventas. Requiere cuenta Meta Business con número verificado y plantilla aprobada.</div>
							<div class="form-grid">
								<div class="form-group full"><label class="form-label" for="wa-id">Phone Number ID</label><input class="form-input" id="wa-id" placeholder="123456789012345" bind:value={cfgWaPhoneId} oninput={marcarCambio} /></div>
								<div class="form-group full"><label class="form-label" for="wa-tok">Access Token permanente</label><input class="form-input" type="password" id="wa-tok" placeholder="EAAxxxxx..." autocomplete="new-password" bind:value={waToken} /></div>
								<div class="form-group full">
									<label class="form-label" for="wa-tpl">Nombre de la plantilla aprobada</label>
									<input class="form-input" id="wa-tpl" placeholder="envio_comprobante" bind:value={cfgWaTemplateName} oninput={marcarCambio} />
									<div class="form-hint">La plantilla debe tener un header de tipo documento (PDF) y 4 variables en el cuerpo: nombre, tipo comprobante, número y total.</div>
								</div>
							</div>
							<div style="margin:8px 0 4px;font-size:13px;color:{cfgWaConfigurado ? 'var(--neo-ok)' : '#888'}">{cfgWaConfigurado ? '✓ WhatsApp configurado' : 'Sin configurar'}</div>
							<div class="btn-row">
								<button class="btn btn-ok" disabled={guardandoWa} onclick={guardarWa}>{guardandoWa ? 'Guardando...' : 'Guardar WhatsApp'}</button>
							</div>
						</div>
					</div>
				</div>
				<div class="col">
					<div class="card">
						<div class="card-titulo">Email SMTP</div>
						<div class="card-body">
							<div class="form-hint" style="margin-bottom:14px">
								Enviá comprobantes PDF por mail directamente desde Ventas. Funciona en el sistema de escritorio sin depender de ningún cliente de correo instalado. Para Gmail necesitás activar verificación en dos pasos y generar una <em>App Password</em> de 16 caracteres.
							</div>
							<div class="form-grid">
								<div class="form-group"><label class="form-label" for="smtp-host">Servidor SMTP</label><input class="form-input" id="smtp-host" placeholder="smtp.gmail.com" bind:value={smtpHost} /></div>
								<div class="form-group"><label class="form-label" for="smtp-port">Puerto</label><input class="form-input" id="smtp-port" type="number" placeholder="587" bind:value={smtpPuerto} /></div>
								<div class="form-group">
									<label class="form-label" for="smtp-sec">Seguridad</label>
									<select class="form-input" id="smtp-sec" bind:value={smtpSeguridad}>
										<option value="tls">TLS (recomendado · puerto 587)</option>
										<option value="ssl">SSL (puerto 465)</option>
										<option value="none">Sin cifrado</option>
									</select>
								</div>
								<div class="form-group"><label class="form-label" for="smtp-usr">Usuario SMTP</label><input class="form-input" id="smtp-usr" placeholder="ventas@miempresa.com" autocomplete="off" bind:value={smtpUsuario} /></div>
								<div class="form-group full">
									<label class="form-label" for="smtp-pass">Contraseña / App Password</label>
									<input class="form-input" id="smtp-pass" type="password" placeholder="Dejar vacío para no cambiar" autocomplete="new-password" bind:value={smtpClave} />
									<div class="form-hint">Para Gmail: generá una App Password en myaccount.google.com/apppasswords (requiere 2FA activo).</div>
								</div>
								<div class="form-group"><label class="form-label" for="smtp-nom">Nombre del remitente</label><input class="form-input" id="smtp-nom" placeholder="Ferretería López" bind:value={smtpDeNombre} /></div>
								<div class="form-group"><label class="form-label" for="smtp-from">Email remitente (From)</label><input class="form-input" id="smtp-from" type="email" placeholder="ventas@ferreteria.com" bind:value={smtpDeEmail} /></div>
								<div class="form-group full"><label class="form-label" for="smtp-reply">Reply-To (opcional)</label><input class="form-input" id="smtp-reply" type="email" placeholder="Dirección a donde responden los clientes, si es distinta al remitente" bind:value={smtpReplyTo} /></div>
							</div>
							<div style="margin:8px 0 4px;font-size:13px;color:{smtpConfigurado ? 'var(--neo-ok)' : '#888'}">{smtpConfigurado ? '✓ Email configurado' : 'Sin configurar'}</div>
							<div class="btn-row" style="gap:8px">
								<button class="btn btn-ok" disabled={guardandoSmtp} onclick={guardarSmtp}>{guardandoSmtp ? 'Guardando...' : 'Guardar Email'}</button>
								<button class="btn btn-sec" disabled={probandoSmtp} onclick={probarSmtp}>{probandoSmtp ? 'Probando...' : 'Probar conexión'}</button>
							</div>
						</div>
					</div>
				</div>
			</div>

			<div class="avz-seccion">Licencia</div>
			<div class="row">
				<div class="col">
					<div class="card">
						<div class="card-titulo">Estado de la licencia</div>
						<div class="card-body">
							{#if licEstado}
								<div
									style="margin-bottom:10px;font-size:13px;font-weight:600;color:{licEstado.modo_restringido
										? 'var(--neo-danger)'
										: licEstado.estado_efectivo === 'en_gracia'
											? 'var(--neo-warning)'
											: 'var(--neo-ok)'}"
								>
									{licEstado.modo_restringido ? '⚠ ' : '✓ '}{licEstado.mensaje ??
										(licEstado.estado_efectivo === 'al_dia' ? 'Licencia al día.' : licEstado.estado_efectivo)}
								</div>
								{#if licEstado.fecha_ultima_verificacion_exitosa}
									<div class="form-hint" style="margin-bottom:14px">
										Última verificación exitosa: {licEstado.fecha_ultima_verificacion_exitosa}
									</div>
								{/if}
							{:else}
								<div class="form-hint" style="margin-bottom:14px">Cargando estado de licencia...</div>
							{/if}

							{#if licMostrarControles}
								<div class="form-grid">
									<div class="form-group full">
										<label class="form-label" for="lic-token">Token de licencia</label>
										<input
											class="form-input"
											id="lic-token"
											placeholder="Pegá un token nuevo para actualizarlo"
											autocomplete="off"
											spellcheck="false"
											bind:value={licTokenNuevo}
										/>
									</div>
								</div>
								<div class="btn-row" style="gap:8px">
									<button
										class="btn btn-ok"
										disabled={licVerificando || !licTokenNuevo.trim()}
										onclick={licenciaGuardarToken}>{licVerificando ? 'Verificando...' : 'Guardar token'}</button
									>
									<button class="btn btn-sec" disabled={licVerificando} onclick={licenciaVerificarAhora}
										>{licVerificando ? 'Verificando...' : 'Verificar ahora'}</button
									>
								</div>
							{/if}
						</div>
					</div>
				</div>
			</div>

			<div class="avz-seccion avz-seccion-danger">Zona de peligro</div>

			{#if mostrarRestaurar}
				<div class="card danger-card">
					<div class="card-body">
						<div class="card-titulo" style="margin-bottom:10px">Restaurar backup</div>
						<div class="form-hint" style="margin-bottom:16px">
							Reemplaza <b>todos</b> los datos actuales (productos, clientes, ventas, cajas, todo) por los de un backup elegido. Antes de tocar nada se genera automáticamente un backup del estado actual, por si el archivo elegido no era el correcto. La restauración en sí no se puede
							deshacer, y la aplicación se reinicia sola al terminar. Puede tardar varios minutos con backups grandes — no cierres la ventana.
						</div>
						<div class="form-grid">
							<div class="form-group full">
								<label class="form-label" for="rb-sel">Backup a restaurar (de la carpeta configurada)</label>
								<select class="form-select" id="rb-sel" bind:value={rbArchivoSeleccionado}>
									{#if !backupsDisponibles.length}
										<option value="">No hay backups en la carpeta configurada</option>
									{:else}
										<option value="">Elegí un backup…</option>
										{#each backupsDisponibles as b (b.nombre + b.origen)}
											<option value="{b.nombre}|{b.origen}">{b.nombre} — {new Date(b.modificado.replace(' ', 'T')).toLocaleString('es-AR')} ({(b.tamano / 1024 / 1024).toFixed(1)} MB){b.origen === 'secundaria' ? ' [carpeta secundaria]' : ''}</option>
										{/each}
									{/if}
								</select>
							</div>
							<div class="form-group full">
								<label class="form-label" for="rb-file">…o subí un archivo .sql de otro lado</label>
								<input type="file" class="form-input" id="rb-file" accept=".sql" bind:this={rbArchivoInput} onchange={onRbArchivoChange} />
							</div>
							<div class="form-group full">
								<label class="form-label" for="rb-conf">Escribí RESTAURAR BACKUP para habilitar el botón</label>
								<input class="form-input" id="rb-conf" placeholder="RESTAURAR BACKUP" autocomplete="off" bind:value={rbConfirmacion} />
							</div>
							{#if cfgClaveConfigurada}
								<div class="form-group full">
									<label class="form-label" for="rb-clave">Clave de autorización</label>
									<input class="form-input" type="password" id="rb-clave" autocomplete="new-password" bind:value={rbClave} />
								</div>
							{/if}
						</div>
						<div class="btn-row">
							<button class="btn" disabled={!rbBotonHabilitado || rbRestaurando} onclick={restaurarBackup}>{rbRestaurando ? 'Restaurando… no cierres la ventana' : 'Restaurar backup'}</button>
						</div>
					</div>
				</div>
			{/if}

			<div class="card danger-card">
				<div class="card-body">
					<div class="form-hint" style="margin-bottom:16px">
						Restablecer de fábrica borra <b>todos</b> los datos operativos (productos, clientes, ventas, compras, movimientos, cajas y usuarios) y deja el sistema como recién instalado. Se genera un backup completo antes de borrar nada. Esta acción no se puede deshacer.
					</div>
					<div class="form-grid">
						<div class="form-group full">
							<label class="form-label" for="rf-conf">Escribí BORRAR TODO para habilitar el botón</label>
							<input class="form-input" id="rf-conf" placeholder="BORRAR TODO" autocomplete="off" bind:value={rfConfirmacion} />
						</div>
						{#if cfgClaveConfigurada}
							<div class="form-group full">
								<label class="form-label" for="rf-clave">Clave de autorización</label>
								<input class="form-input" type="password" id="rf-clave" autocomplete="new-password" bind:value={rfClave} />
							</div>
						{/if}
					</div>
					<div class="btn-row">
						<button class="btn" id="btn-reset-fabrica" disabled={!rfBotonHabilitado || rfRestableciendo} onclick={resetFabrica}>{rfRestableciendo ? 'Restableciendo…' : 'Restablecer de fábrica'}</button>
					</div>
				</div>
			</div>
		{/if}
	</div>
</div>

{#if sucMenuAbiertoId !== null}
	{@const u = usuarios.find((x) => x.id === sucMenuAbiertoId)}
	{#if u}
		<div id="suc-floating-menu" class="suc-menu" style="top:{sucMenuPos.top}px;left:{sucMenuPos.left}px">
			<label><input type="checkbox" checked={sucMenuDraftIds === null} onchange={(e) => toggleSucTodas(u, (e.target as HTMLInputElement).checked)} /> Todas</label>
			<div class="suc-sep"></div>
			{#if sucMenuDraftIds === null}
				<div class="suc-menu-hint">Destildá "Todas" para elegir sucursales específicas</div>
			{/if}
			{#each sucursales as s (s.id)}
				<label>
					<input type="checkbox" checked={sucMenuDraftIds?.includes(s.id) ?? false} disabled={sucMenuDraftIds === null} onchange={(e) => toggleSucUna(u, s.id, (e.target as HTMLInputElement).checked)} />
					{s.nombre}
				</label>
			{/each}
		</div>
	{/if}
{/if}

{#if modalPermisos && permUsuario}
	<div class="perm-overlay" role="presentation">
		<div class="perm-modal">
			<div class="perm-head">
				<h3>Permisos — {permUsuario.nombre}</h3>
				<button onclick={cerrarPermisos} aria-label="Cerrar">✕</button>
			</div>
			<div class="perm-body">
				<div style="display:flex;gap:8px;margin-bottom:14px">
					<button class="btn btn-sec" style="font-size:12px" onclick={() => aplicarPlantillaPerm('vendedor')}>Plantilla Vendedor</button>
					<button class="btn btn-sec" style="font-size:12px" onclick={() => aplicarPlantillaPerm('encargado')}>Plantilla Encargado</button>
				</div>
				<div style="display:flex;flex-direction:column;gap:2px">
					{#each PERMISOS_DEF as [k, label, desc] (k)}
						<label class="perm-item">
							<input type="checkbox" checked={!!permChecks[k]} onchange={(e) => (permChecks[k] = (e.target as HTMLInputElement).checked)} />
							<span><b>{label}</b><small>{desc}</small></span>
						</label>
					{/each}
				</div>
			</div>
			<div class="perm-foot">
				<button class="btn btn-sec" onclick={cerrarPermisos}>Cancelar</button>
				<button class="btn btn-ok" onclick={guardarPermisos}>Guardar permisos</button>
			</div>
		</div>
	</div>
{/if}

{#if modalDeposito}
	<!-- pos/neo.css define .modal-overlay oculto por defecto (opacity:0,
	     visibility:hidden, pointer-events:none) — pensado para modales legacy
	     que togglean .abierto por JS con classList sobre un elemento siempre
	     montado. Acá el {#if} ya monta/desmonta el div entero, así que .abierto
	     va fijo: si no, el estilo local (que solo pisa display/position/etc, no
	     opacity/visibility) deja el modal invisible e inclickeable en silencio. -->
	<div class="modal-overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && (modalDeposito = false)}>
		<div class="modal" style="max-width:440px">
			<div class="modal-header"><h3>{editDepId ? 'Editar depósito' : 'Nuevo depósito'}</h3><button class="modal-close" onclick={() => (modalDeposito = false)}>✕</button></div>
			<div class="modal-body">
				<div class="form-group"><label for="md-nombre">Nombre *</label><input id="md-nombre" type="text" class="form-input" placeholder="Ej: Depósito Norte" bind:value={depForm.nombre} /></div>
				<div class="form-group"><label for="md-desc">Descripción</label><input id="md-desc" type="text" class="form-input" bind:value={depForm.descripcion} /></div>
				<div class="form-group form-check"><label><input type="checkbox" bind:checked={depForm.activo} /> Activo</label></div>
			</div>
			<div class="modal-footer">
				<button class="btn-sec" onclick={() => (modalDeposito = false)}>Cancelar</button>
				<button class="btn-accion" disabled={guardandoDeposito} onclick={guardarDeposito}>{guardandoDeposito ? 'Guardando…' : 'Guardar'}</button>
			</div>
		</div>
	</div>
{/if}

{#if hayCambios}
	<button class="guardar-flotante" disabled={guardandoConfig} onclick={guardarConfig}>{guardandoConfig ? 'Guardando…' : 'Guardar cambios'}</button>
{/if}

<style>
	.page-header {
		background: var(--neo-bg);
		box-shadow: 0 3px 8px var(--neo-sd), 0 -1px 4px var(--neo-sl);
		padding: 16px clamp(20px, 4vw, 48px);
		flex-shrink: 0;
		position: relative;
		z-index: 10;
	}
	.page-header h1 {
		font-size: 13px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 1.2px;
		color: var(--neo-text);
	}
	.page-header p {
		font-size: 11px;
		color: var(--neo-text-3);
		margin-top: 3px;
	}
	.tab-bar {
		background: var(--neo-bg);
		border-bottom: 1px solid var(--borde-fuerte);
		display: flex;
		padding: 0 clamp(20px, 4vw, 48px);
		flex-shrink: 0;
		overflow-x: auto;
	}
	.tab-btn {
		padding: 12px 18px;
		font-size: 11px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.8px;
		border: none;
		background: none;
		cursor: pointer;
		color: var(--neo-text-3);
		border-bottom: 2px solid transparent;
		margin-bottom: -1px;
		white-space: nowrap;
		flex-shrink: 0;
		font-family: inherit;
	}
	.tab-btn:hover {
		color: var(--neo-text);
	}
	.tab-btn.activo {
		color: var(--neo-accent);
		border-bottom-color: var(--neo-accent);
	}
	.contenido {
		flex: 1;
		min-height: 0;
		overflow-y: auto;
		padding: 24px clamp(20px, 4vw, 48px);
		background: var(--neo-bg-deep);
	}
	.contenido-inner {
		max-width: 1600px;
		margin: 0 auto;
		min-height: 100%;
		display: flex;
		flex-direction: column;
	}
	.cols {
		display: grid;
		grid-template-columns: 1fr;
		gap: 16px;
		align-items: stretch;
		margin-bottom: 16px;
		flex: 1;
	}
	.cols .col {
		display: flex;
		flex-direction: column;
		gap: 16px;
		min-width: 0;
	}
	.cols :global(.card) {
		margin-bottom: 0;
		flex: 1 1 auto;
	}
	.card {
		background: var(--neo-bg);
		border: 1px solid #000;
		border-radius: var(--neo-r-lg);
		box-shadow: var(--neo-e2);
		margin-bottom: 16px;
		overflow: hidden;
		container-type: inline-size;
	}
	.card-titulo {
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 1px;
		color: var(--neo-text-3);
		padding: 10px 20px;
		border-bottom: 1px solid var(--borde);
	}
	.card-body {
		padding: 20px;
	}
	.btn-cell {
		display: flex;
		gap: 5px;
		align-items: center;
	}
	.form-grid {
		display: grid;
		grid-template-columns: repeat(3, 1fr);
		gap: 14px 20px;
	}
	@container (max-width: 720px) {
		.form-grid {
			grid-template-columns: repeat(2, 1fr);
		}
	}
	@container (max-width: 470px) {
		.form-grid {
			grid-template-columns: 1fr;
		}
	}
	.form-group {
		display: flex;
		flex-direction: column;
		gap: 5px;
		min-width: 0;
	}
	.form-group.full {
		grid-column: 1 / -1;
	}
	.form-label {
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.6px;
		color: var(--neo-text-3);
	}
	.form-input,
	.form-select {
		padding: 9px 12px;
		border: none;
		border-radius: var(--neo-r-sm);
		font-size: 13px;
		font-family: inherit;
		outline: none;
		background: var(--neo-bg);
		color: var(--neo-text);
		box-shadow: var(--neo-i1);
		width: 100%;
	}
	.form-hint {
		font-size: 11px;
		color: var(--neo-text-3);
		margin-top: 3px;
		line-height: 1.5;
	}
	.checkbox-group {
		display: flex;
		flex-wrap: wrap;
		gap: 8px 20px;
		margin-top: 4px;
	}
	.checkbox-item {
		display: flex;
		align-items: center;
		gap: 6px;
		font-size: 13px;
		cursor: pointer;
	}
	.checkbox-item input[type='checkbox'] {
		width: 15px;
		height: 15px;
		cursor: pointer;
		accent-color: var(--neo-accent);
	}
	.btn {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		padding: 10px 20px;
		border: none;
		border-radius: var(--neo-r-sm);
		font-size: 11px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.6px;
		cursor: pointer;
		font-family: inherit;
	}
	.btn:disabled {
		opacity: 0.5;
		cursor: not-allowed;
	}
	.btn-ok {
		background: var(--neo-accent);
		color: #fff;
		box-shadow: 4px 4px 10px var(--neo-accent-glow), -2px -2px 5px rgba(255, 255, 255, 0.15);
	}
	.btn-ok:hover:not(:disabled) {
		background: var(--neo-accent-h);
	}
	.btn-sec {
		background: var(--neo-bg);
		color: var(--neo-text);
		box-shadow: var(--neo-e2);
	}
	.btn-sec:hover {
		box-shadow: var(--neo-e3);
	}
	.btn-row {
		display: flex;
		gap: 10px;
		align-items: center;
		margin-top: 16px;
		flex-wrap: wrap;
	}
	#btn-reset-fabrica:not(:disabled) {
		background: var(--neo-danger);
		color: #fff;
	}
	.logo-row {
		display: flex;
		align-items: center;
		gap: 20px;
		flex-wrap: wrap;
	}
	.logo-preview {
		width: 60px;
		height: 60px;
		border-radius: var(--neo-r-sm);
		box-shadow: var(--neo-i1);
		display: flex;
		align-items: center;
		justify-content: center;
		overflow: hidden;
		background: var(--neo-bg-deep);
		flex-shrink: 0;
	}
	.logo-preview img {
		max-width: 100%;
		max-height: 100%;
		object-fit: contain;
	}
	.table-wrap {
		overflow-x: auto;
		border-radius: var(--neo-r-md);
		box-shadow: var(--neo-i1);
	}
	table.cajas {
		width: 100%;
		border-collapse: collapse;
		min-width: 480px;
	}
	table.cajas th {
		text-align: left;
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.6px;
		color: var(--color-ink);
		padding: 9px 12px;
		border-bottom: 1px solid var(--borde-fuerte);
		white-space: nowrap;
		background: var(--neo-bg-deep);
	}
	table.cajas td {
		padding: 9px 12px;
		font-size: 13px;
		border-bottom: 1px solid var(--borde);
		vertical-align: middle;
		color: var(--neo-text);
	}
	table.cajas tbody tr:hover {
		background: var(--color-bg-alt);
	}
	table.cajas input[type='text'] {
		padding: 7px 10px;
		border: none;
		border-radius: var(--neo-r-xs);
		font-size: 13px;
		font-family: inherit;
		outline: none;
		width: 100%;
		background: var(--neo-bg);
		box-shadow: var(--neo-i1);
		color: var(--neo-text);
	}
	.check-label {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 13px;
		cursor: pointer;
		color: var(--neo-text);
	}
	.check-label input {
		width: 15px;
		height: 15px;
		accent-color: var(--neo-accent);
		cursor: pointer;
	}
	.btn-mini {
		padding: 5px 11px;
		border: none;
		border-radius: var(--neo-r-xs);
		background: var(--neo-bg);
		color: var(--neo-text);
		box-shadow: var(--neo-e1);
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.4px;
		cursor: pointer;
		white-space: nowrap;
		font-family: inherit;
	}
	.btn-mini:hover {
		box-shadow: var(--neo-e2);
	}
	.btn-mini.danger {
		color: var(--neo-danger);
	}
	.fila-nueva {
		display: flex;
		gap: 8px;
		padding-top: 14px;
		margin-top: 14px;
		border-top: 1px solid var(--borde-fuerte);
		flex-wrap: wrap;
	}
	.fila-nueva input {
		flex: 1;
		min-width: 160px;
	}
	.afip-estado {
		display: flex;
		align-items: center;
		gap: 10px;
		padding: 10px 14px;
		border-radius: var(--neo-r-sm);
		font-size: 13px;
		font-weight: 600;
		box-shadow: var(--neo-e1);
		margin-bottom: 16px;
		background: var(--neo-bg);
		color: var(--neo-text-2);
	}
	.afip-estado.ok {
		background: rgba(39, 174, 96, 0.1);
		color: var(--neo-success);
	}
	.afip-estado.warn {
		background: rgba(243, 156, 18, 0.1);
		color: var(--neo-warning);
	}
	.afip-estado.err {
		background: rgba(231, 76, 60, 0.1);
		color: var(--neo-danger);
	}
	.afip-estado.err .afip-dot {
		background: var(--neo-danger);
	}
	.afip-pasos-details {
		margin-bottom: 16px;
		font-size: 12px;
	}
	.afip-pasos-details summary {
		cursor: pointer;
		font-weight: 600;
		color: var(--neo-text);
		padding: 4px 0;
	}
	.afip-pasos-details[open] summary {
		margin-bottom: 10px;
	}
	.afip-pasos {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	.afip-paso {
		display: flex;
		gap: 10px;
		background: var(--neo-bg);
		border-radius: var(--neo-r-sm);
		box-shadow: var(--neo-e1);
		padding: 10px 12px;
	}
	.afip-paso-num {
		width: 20px;
		height: 20px;
		border-radius: 50%;
		background: var(--primary-soft-2);
		color: var(--neo-accent);
		font-size: 10px;
		font-weight: 800;
		display: flex;
		align-items: center;
		justify-content: center;
		flex-shrink: 0;
		margin-top: 1px;
	}
	.afip-paso-body {
		font-size: 12px;
		color: var(--neo-text-2);
		line-height: 1.5;
	}
	.afip-paso-body strong {
		color: var(--neo-text);
		font-weight: 700;
		display: block;
		margin-bottom: 2px;
	}
	.afip-paso-body code {
		font-weight: 700;
		font-size: inherit;
		font-family: inherit;
		color: var(--neo-text);
	}
	.afip-estado .afip-dot {
		width: 8px;
		height: 8px;
		border-radius: 50%;
		flex-shrink: 0;
		background: currentColor;
	}
	.avz-seccion {
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 1px;
		color: var(--neo-text-3);
		padding: 8px 0 10px;
		border-bottom: 1px solid var(--borde);
		margin-bottom: 16px;
	}
	.avz-seccion-danger {
		color: var(--neo-danger);
		border-color: rgba(231, 76, 60, 0.25);
		margin-top: 8px;
	}
	.cols-avz {
		display: grid;
		grid-template-columns: 1fr;
		gap: 16px;
		align-items: start;
		margin-bottom: 16px;
	}
	.cols-avz .col {
		display: flex;
		flex-direction: column;
		gap: 16px;
		min-width: 0;
	}
	.danger-card {
		box-shadow: var(--neo-e2), 0 0 0 2px rgba(231, 76, 60, 0.3) !important;
	}
	.danger-card .card-titulo {
		color: var(--neo-danger);
		border-bottom-color: rgba(231, 76, 60, 0.2);
	}
	.guardar-flotante {
		position: fixed;
		right: 28px;
		bottom: 24px;
		z-index: 300;
		padding: 13px 26px;
		box-shadow: 0 8px 24px var(--neo-accent-glow);
		background: var(--neo-accent);
		color: #fff;
		border: none;
		border-radius: var(--neo-r-sm);
		font-size: 11px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.6px;
		cursor: pointer;
		font-family: inherit;
	}
	.card-header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		padding: 14px 20px;
		border-bottom: 1px solid var(--borde);
	}
	.card-header h3 {
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 1px;
		color: var(--neo-text-3);
		margin: 0;
	}
	.suc-tabs {
		display: flex;
		align-items: center;
		gap: 2px;
		flex-wrap: wrap;
	}
	.suc-tab {
		padding: 5px 13px;
		border: 1px solid transparent;
		border-radius: var(--neo-r-xs);
		background: none;
		font-size: 12px;
		font-weight: 500;
		color: var(--neo-text-2);
		cursor: pointer;
		font-family: inherit;
	}
	.suc-tab:hover {
		background: var(--neo-bg-deep);
		color: var(--neo-text);
	}
	.suc-tab.activo {
		background: var(--neo-bg-deep);
		color: var(--neo-accent);
		font-weight: 700;
		border-color: var(--borde-fuerte);
	}
	.suc-tab-add {
		padding: 4px 10px;
		border: 1px dashed var(--borde-fuerte);
		border-radius: var(--neo-r-xs);
		background: none;
		font-size: 16px;
		line-height: 1;
		cursor: pointer;
		font-family: inherit;
		color: var(--neo-text-3);
		margin-left: 4px;
	}
	.suc-tab-add:hover {
		border-color: var(--neo-accent);
		color: var(--neo-accent);
	}
	.suc-form-grid {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 10px 16px;
		padding: 16px 20px;
	}
	.suc-form-grid label {
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.6px;
		color: var(--neo-text-3);
		display: block;
		margin-bottom: 4px;
	}
	.suc-form-footer {
		display: flex;
		align-items: center;
		justify-content: space-between;
		padding: 10px 20px;
		border-top: 1px solid var(--borde);
	}
	.suc-form-footer label {
		display: flex;
		align-items: center;
		gap: 7px;
		font-size: 13px;
		cursor: pointer;
	}
	.suc-form-footer input[type='checkbox'] {
		accent-color: var(--neo-accent);
		width: 15px;
		height: 15px;
	}
	.suc-dep-header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		padding: 10px 16px;
		border-top: 2px solid var(--borde-fuerte);
		background: var(--neo-bg-deep);
	}
	.suc-dep-titulo {
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.7px;
		color: var(--neo-text-3);
	}
	.btn-accion {
		display: inline-flex;
		align-items: center;
		gap: 5px;
		padding: 7px 14px;
		border: none;
		border-radius: var(--neo-r-xs);
		background: var(--neo-accent);
		color: #fff;
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		cursor: pointer;
		font-family: inherit;
	}
	.btn-accion:hover {
		background: var(--neo-accent-h);
	}
	.tabla {
		width: 100%;
		border-collapse: collapse;
	}
	.tabla th {
		text-align: left;
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.6px;
		color: var(--color-ink);
		padding: 9px 16px;
		border-bottom: 1px solid var(--borde-fuerte);
		background: var(--neo-bg-deep);
		white-space: nowrap;
	}
	.tabla td {
		padding: 9px 16px;
		font-size: 13px;
		border-bottom: 1px solid var(--borde);
		vertical-align: middle;
		color: var(--neo-text);
	}
	.tabla tbody tr:last-child td {
		border-bottom: none;
	}
	.tabla tbody tr:hover {
		background: var(--color-bg-alt);
	}
	.tabla td.empty {
		color: var(--neo-text-3);
		font-style: italic;
		text-align: center;
		padding: 24px;
	}
	.tabla td.acciones {
		text-align: right;
		white-space: nowrap;
	}
	.badge {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		padding: 3px 8px;
		border-radius: 4px;
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.4px;
		background: var(--color-bg-alt);
		color: var(--neo-text-2);
	}
	.badge.ok {
		background: rgba(39, 174, 96, 0.12);
		color: var(--neo-success, #27ae60);
	}
	.badge.warn {
		background: rgba(243, 156, 18, 0.12);
		color: var(--neo-warning, #f39c12);
	}
	.form-group.form-check {
		flex-direction: row;
		align-items: center;
		gap: 8px;
	}
	.form-group.form-check label {
		font-size: 13px;
		cursor: pointer;
		display: flex;
		align-items: center;
		gap: 7px;
	}
	.form-group.form-check input[type='checkbox'] {
		width: 15px;
		height: 15px;
		accent-color: var(--neo-accent);
		cursor: pointer;
		flex-shrink: 0;
	}
	.modal-overlay {
		position: fixed;
		inset: 0;
		z-index: 500;
		display: flex;
		align-items: center;
		justify-content: center;
		background: rgba(49, 52, 75, 0.48);
	}
	.modal {
		background: #fff;
		border: 1px solid var(--borde-fuerte);
		box-shadow: 0 4px 24px rgba(0, 0, 0, 0.13);
		width: min(94vw, 500px);
		display: flex;
		flex-direction: column;
	}
	.modal-header {
		padding: 14px 20px;
		background: var(--color-bg-alt);
		border-bottom: 1px solid var(--borde-fuerte);
		display: flex;
		align-items: center;
		justify-content: space-between;
	}
	.modal-header h3 {
		font-size: 13px;
		font-weight: 700;
		margin: 0;
	}
	.modal-close {
		background: none;
		border: none;
		cursor: pointer;
		font-size: 15px;
	}
	.modal-body {
		padding: 20px;
		display: flex;
		flex-direction: column;
		gap: 14px;
	}
	.modal-body .form-group label {
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.6px;
		color: var(--neo-text-3);
		margin-bottom: 2px;
		display: block;
	}
	.modal-footer {
		display: flex;
		justify-content: flex-end;
		gap: 8px;
		padding: 12px 20px;
		border-top: 1px solid var(--borde-fuerte);
	}
	.btn-sec {
		padding: 8px 16px;
		background: #fff;
		color: var(--color-primary);
		border: 1px solid var(--color-primary);
		cursor: pointer;
		font-size: 12px;
		font-weight: 600;
		font-family: inherit;
	}
	.suc-menu {
		position: fixed;
		z-index: 60;
		background: var(--neo-bg);
		border-radius: var(--neo-r-sm);
		box-shadow: var(--neo-e3);
		padding: 10px 12px;
		min-width: 180px;
		display: flex;
		flex-direction: column;
		gap: 7px;
	}
	.suc-menu label {
		display: flex;
		gap: 7px;
		align-items: center;
		font-size: 12px;
		cursor: pointer;
		white-space: nowrap;
		color: var(--neo-text);
	}
	.suc-menu label input {
		accent-color: var(--neo-accent);
		cursor: pointer;
	}
	.suc-sep {
		border-top: 1px solid var(--borde);
		margin: 1px 0;
	}
	.suc-menu-hint {
		font-size: 11px;
		color: var(--neo-text-3);
		line-height: 1.4;
		white-space: normal;
		max-width: 180px;
	}
	.perm-overlay {
		position: fixed;
		inset: 0;
		z-index: 400;
		background: rgba(0, 0, 0, 0.45);
		display: flex;
		align-items: center;
		justify-content: center;
	}
	.perm-modal {
		background: #fff;
		border: 1px solid var(--borde-fuerte);
		width: min(94vw, 560px);
		max-height: 88vh;
		display: flex;
		flex-direction: column;
	}
	.perm-head {
		display: flex;
		align-items: center;
		justify-content: space-between;
		padding: 12px 20px;
		border-bottom: 1px solid var(--borde-fuerte);
		background: var(--color-bg-alt);
		flex-shrink: 0;
	}
	.perm-head h3 {
		font-size: 11px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.8px;
		color: #9b9590;
		margin: 0;
	}
	.perm-head button {
		background: none;
		border: none;
		cursor: pointer;
		color: #9b9590;
		font-size: 16px;
		line-height: 1;
		padding: 2px 6px;
	}
	.perm-body {
		flex: 1;
		overflow-y: auto;
		padding: 16px 20px;
	}
	.perm-foot {
		display: flex;
		gap: 8px;
		justify-content: flex-end;
		padding: 12px 20px;
		border-top: 1px solid var(--borde-fuerte);
		flex-shrink: 0;
	}
	:global(.perm-item) {
		display: flex;
		gap: 10px;
		align-items: flex-start;
		padding: 8px 10px;
		cursor: pointer;
		border-bottom: 1px solid var(--color-bg-alt);
	}
	:global(.perm-item:last-child) {
		border-bottom: none;
	}
	:global(.perm-item:hover) {
		background: #fafaf9;
	}
	:global(.perm-item input) {
		margin-top: 3px;
		flex-shrink: 0;
	}
	:global(.perm-item span) {
		display: flex;
		flex-direction: column;
		gap: 1px;
	}
	:global(.perm-item b) {
		font-size: 13px;
		color: #111;
		font-weight: 600;
	}
	:global(.perm-item small) {
		font-size: 11px;
		color: #9b9590;
		line-height: 1.4;
	}
</style>
