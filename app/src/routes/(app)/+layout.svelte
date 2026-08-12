<script lang="ts">
	import { goto, afterNavigate } from '$app/navigation';
	import { page } from '$app/state';
	import { onMount } from 'svelte';
	import { apiUrl, apiJson, servidorCaido } from '$lib/api';
	import { toast_ } from '$lib/toast';
	import {
		leerSesion,
		cerrarSesion,
		puede,
		nombreDispositivo,
		type Sesion,
		type Sucursal
	} from '$lib/session';
	import { tieneMultiSucursal, setSucursalActiva, setCajaOperativa, cajaOperativaId } from '$lib/operativa';
	import Toast from '$lib/Toast.svelte';
	import ConfirmModal from '$lib/ConfirmModal.svelte';
	import PdfViewerModal from '$lib/PdfViewerModal.svelte';
	import ContactModal from '$lib/ContactModal.svelte';

	let { children } = $props();

	// ── Guard de sesión (mitad de pos/auth.js) ──────────────────────────────
	// Corre en el cliente (no SSR, ver +layout.ts raíz) — si no hay sesión
	// válida, no tiene sentido ni pintar el nav, redirige de una.
	let sesion = $state<Sesion | null>(null);
	let listo = $state(false);

	onMount(() => {
		const s = leerSesion();
		if (!s) {
			goto('/login');
			return;
		}
		sesion = s;
		listo = true;
		cargarLicencia();
		cargarAfip();
		cargarRazonSocial();
	});

	// ── Título de la ventana/pestaña con el nombre del negocio (port de
	// auth.js:314-320) — cada página fija su propio <title>"Logos — X"</title>
	// vía <svelte:head>, así que hay que reaplicar el reemplazo después de
	// cada navegación, no solo una vez al arrancar.
	let razonSocial = $state('');
	async function cargarRazonSocial() {
		try {
			const cfg = await apiJson<{ razon_social?: string }>('/configuracion');
			if (cfg.razon_social) {
				razonSocial = cfg.razon_social;
				document.title = document.title.replace(/^Logos/, razonSocial);
			}
		} catch {
			// sin conexión al backend — dejar el título default
		}
	}
	afterNavigate(() => {
		if (razonSocial) document.title = document.title.replace(/^Logos/, razonSocial);
	});

	// ── Licencia — badge + toast diario (port de pos/licencia.js) ──────────
	// GET /licencia/estado no tiene gate de sesión ni de IP: refleja el mismo
	// estado_efectivo en cualquier PC (Servidor o Cliente), todas comparten
	// la misma DB. El toast se muestra una sola vez por día (misma clave de
	// localStorage que usaba la página legacy, para no duplicar el aviso si
	// el usuario también pasa por ahí el mismo día).
	let licBadge = $state(false);
	async function cargarLicencia() {
		try {
			const estado = await apiJson<{ estado_efectivo: string; mensaje: string | null }>('/licencia/estado');
			if (estado.estado_efectivo === 'al_dia') return;
			licBadge = true;
			const hoy = new Date().toISOString().slice(0, 10);
			const clave = `lic_toast_${hoy}`;
			if (!localStorage.getItem(clave)) {
				localStorage.setItem(clave, '1');
				toast_(estado.mensaje ?? 'Hay un problema con la licencia. Revisá Configuración.', 'warn');
			}
		} catch {
			// sin conexión al backend — no bloquear el nav por esto
		}
	}

	// ── Certificado AFIP — badge + toast si vence pronto (port de
	// pos/afip-cert-aviso.js). Mismo GET /configuracion público que ya se usa
	// en toda la SPA — cero endpoints nuevos para esto.
	const AFIP_DIAS_AVISO = 30;
	let afipBadge = $state(false);
	async function cargarAfip() {
		try {
			const cfg = await apiJson<{ afip_configurado?: boolean; afip_cert_vencimiento?: string | null }>('/configuracion');
			if (!cfg.afip_configurado || !cfg.afip_cert_vencimiento) return;
			const [d, m, y] = cfg.afip_cert_vencimiento.split('/').map(Number);
			const venc = new Date(y, m - 1, d);
			const dias = Math.ceil((venc.getTime() - Date.now()) / 86400000);
			if (dias > AFIP_DIAS_AVISO) return;
			afipBadge = true;
			const vencido = dias < 0;
			const hoy = new Date().toISOString().slice(0, 10);
			const clave = `afip_toast_${hoy}`;
			if (!localStorage.getItem(clave)) {
				localStorage.setItem(clave, '1');
				toast_(
					vencido
						? 'Certificado AFIP vencido — renovalo en Configuración.'
						: `Certificado AFIP vence en ${dias} día${dias === 1 ? '' : 's'} — renovalo en Configuración.`,
					'warn'
				);
			}
		} catch {
			// sin conexión al backend — no bloquear el nav por esto
		}
	}

	// ── Item activo del nav — igual que $nav_activo de nav.php, calculado
	// desde la URL en vez de recibido por parámetro de include. Extender este
	// mapeo a medida que se migran más páginas (Fase 2).
	const navActivo = $derived.by(() => {
		const p = page.url.pathname;
		if (p.startsWith('/contactos')) return 'contactos';
		if (p.startsWith('/operaciones')) return 'operaciones';
		if (p.startsWith('/vendedores')) return 'vendedores';
		if (p.startsWith('/reglas-precio')) return 'reglas-precio';
		if (p.startsWith('/cierres')) return 'cierres';
		if (p.startsWith('/cheques')) return 'cheques';
		if (p.startsWith('/movimientos')) return 'movimientos';
		if (p.startsWith('/taxonomias')) return 'taxonomias';
		if (p.startsWith('/compras')) return 'compras';
		if (p.startsWith('/log')) return 'log';
		if (p.startsWith('/iva')) return 'iva';
		if (p.startsWith('/reportes')) return 'reportes';
		if (p.startsWith('/rentabilidad')) return 'rentabilidad';
		if (p.startsWith('/dashboard')) return 'dashboard';
		if (p.startsWith('/importar')) return 'importar';
		if (p.startsWith('/configuracion')) return 'configuracion';
		if (p.startsWith('/caja')) return 'caja';
		if (p.startsWith('/cuentacorriente')) return 'cuentacorriente';
		if (p.startsWith('/productos')) return 'productos';
		if (p.startsWith('/ventas')) return 'ventas';
		if (p === '/') return 'pos';
		return '';
	});
	const cajaGrupoActivo = $derived(
		['caja', 'movimientos', 'operaciones', 'cierres', 'cheques'].includes(navActivo)
	);
	const productosGrupoActivo = $derived(
		['productos', 'stock', 'importar', 'taxonomias', 'reglas-precio'].includes(navActivo)
	);
	const reportesGrupoActivo = $derived(
		['dashboard', 'rentabilidad', 'iva', 'reportes'].includes(navActivo)
	);
	const contTipo = $derived(page.url.searchParams.get('tipo') === 'proveedor' ? 'proveedor' : 'cliente');

	// ── Reloj ────────────────────────────────────────────────────────────
	let fechaHoy = $state('');
	function tickFecha() {
		fechaHoy = new Date().toLocaleDateString('es-AR', { dateStyle: 'short' });
	}

	// ── Selector de sucursal/caja (versión simplificada del Lote 0 — usa los
	// datos que ya vienen en la sesión, sin el refetch-siempre-fresco que
	// hace auth.js para admins; suficiente hasta que una tienda multi-sucursal
	// real ejercite esto de verdad) ─────────────────────────────────────────
	let sucursales = $state<Sucursal[]>([]);
	let sucursalSeleccionada = $state<number | null>(null);
	let cajas = $state<{ id: number; nombre: string }[]>([]);
	let cajaSeleccionada = $state<number | null>(null);

	async function cargarCajasSiCorresponde() {
		if (!sesion || !puede('cajas_todas')) return;
		const res = await fetch(apiUrl('/cajas'), {
			headers: { 'X-Auth-Token': sesion.token }
		});
		if (res.ok) {
			cajas = await res.json();
			cajaSeleccionada = cajaOperativaId();
		}
	}

	function onCambioSucursal(e: Event) {
		const id = Number((e.target as HTMLSelectElement).value);
		sucursalSeleccionada = id;
		setSucursalActiva(id);
	}

	function onCambioCaja(e: Event) {
		if (!sesion) return;
		const id = Number((e.target as HTMLSelectElement).value);
		cajaSeleccionada = id;
		setCajaOperativa(id, sesion.caja_id);
	}

	$effect(() => {
		if (!sesion) return;
		sucursales = sesion.sucursales || [];
		sucursalSeleccionada = sesion.sucursal_id;
		tickFecha();
		const t = setInterval(tickFecha, 60000);
		cargarCajasSiCorresponde();
		return () => clearInterval(t);
	});

	// ── Nombre de equipo (primera vez) ──────────────────────────────────────
	let pedirNombreEquipo = $state(false);
	let nombreEquipoInput = $state('');
	let nombreEquipoError = $state(false);

	$effect(() => {
		if (listo && !nombreDispositivo()) pedirNombreEquipo = true;
	});

	function guardarNombreEquipo() {
		const val = nombreEquipoInput.trim();
		if (!val) {
			nombreEquipoError = true;
			return;
		}
		localStorage.setItem('logos_device_name', val);
		pedirNombreEquipo = false;
	}

	async function logout() {
		await cerrarSesion();
		goto('/login');
	}

	// ── Dropdowns del navbar: abren en hover, pero el cierre se demora un
	// poco para que bajar el mouse en diagonal hacia una opción del
	// desplegable no lo cierre antes de llegar (el toggle es más angosto
	// que el menú, así que un movimiento diagonal sale de ambas cajas un
	// instante si el cierre es inmediato). Se cancela si el mouse vuelve
	// a entrar (al toggle o al menú) antes de que venza el timeout.
	let dropAbierto = $state<string | null>(null);
	let dropCerrarTimeout: ReturnType<typeof setTimeout> | null = null;
	function abrirDrop(id: string) {
		if (dropCerrarTimeout) {
			clearTimeout(dropCerrarTimeout);
			dropCerrarTimeout = null;
		}
		dropAbierto = id;
	}
	function programarCierreDrop() {
		dropCerrarTimeout = setTimeout(() => {
			dropAbierto = null;
		}, 300);
	}
	$effect(() => {
		page.url.pathname;
		dropAbierto = null;
	});
</script>

{#if listo && sesion}
	<nav class="app-nav">
		<a href="/" class="nav-brand"><img src="/Logos/logos_logo.png" alt="Logos" /></a>
		<a href="/" class="nav-link" class:activo={navActivo === 'pos'}>POS</a>
		<div class="nav-sep"></div>
		<a href="/ventas" class="nav-link" class:activo={navActivo === 'ventas'}>Ventas</a>
		<div class="nav-drop" onmouseenter={() => abrirDrop('ctacte')} onmouseleave={programarCierreDrop}>
			<a href="/cuentacorriente" class="nav-link nav-drop-toggle" class:activo={navActivo === 'cuentacorriente'}
				>Cta. Cte.</a
			>
			<div class="nav-drop-menu" class:abierto={dropAbierto === 'ctacte'}>
				<a href="/cuentacorriente?tipo=cliente">Clientes</a>
				<a href="/cuentacorriente?tipo=proveedor">Proveedores</a>
			</div>
		</div>
		<div class="nav-drop" onmouseenter={() => abrirDrop('contactos')} onmouseleave={programarCierreDrop}>
			<a
				href="/contactos"
				class="nav-link nav-drop-toggle"
				class:activo={navActivo === 'contactos' || navActivo === 'vendedores'}>Contactos</a
			>
			<div class="nav-drop-menu" class:abierto={dropAbierto === 'contactos'}>
				<a href="/contactos?tipo=cliente" class:activo={navActivo === 'contactos' && contTipo === 'cliente'}
					>Clientes</a
				>
				<a
					href="/contactos?tipo=proveedor"
					class:activo={navActivo === 'contactos' && contTipo === 'proveedor'}>Proveedores</a
				>
				{#if puede('gestionar_vendedores')}
					<a href="/vendedores" class:activo={navActivo === 'vendedores'}>Vendedores</a>
				{/if}
			</div>
		</div>
		<div class="nav-drop" onmouseenter={() => abrirDrop('productos')} onmouseleave={programarCierreDrop}>
			<a href="/productos" class="nav-link nav-drop-toggle" class:activo={productosGrupoActivo}
				>Productos</a
			>
			<div class="nav-drop-menu" class:abierto={dropAbierto === 'productos'}>
				<a href="/productos" class:activo={navActivo === 'productos'}>Productos</a>
				{#if puede('importar')}<a href="/importar" class:activo={navActivo === 'importar'}>Importar</a>{/if}
				<a href="/taxonomias?tipo=rubros" class:activo={navActivo === 'taxonomias'}>Rubros</a>
				<a href="/taxonomias?tipo=marcas" class:activo={navActivo === 'taxonomias'}>Marcas</a>
				<a href="/reglas-precio" class:activo={navActivo === 'reglas-precio'}>Reglas de precio</a>
			</div>
		</div>
		{#if puede('compras')}
			<a href="/compras" class="nav-link" class:activo={navActivo === 'compras'}>Compras</a>
		{/if}
		<div class="nav-drop" onmouseenter={() => abrirDrop('caja')} onmouseleave={programarCierreDrop}>
			<a href="#" class="nav-link nav-drop-toggle" class:activo={cajaGrupoActivo}>Caja</a>
			<div class="nav-drop-menu" class:abierto={dropAbierto === 'caja'}>
				<a href="/caja" class:activo={navActivo === 'caja'}>Abrir / Cerrar Caja</a>
				<a href="/movimientos" class:activo={navActivo === 'movimientos'}>Movimientos</a>
				<a href="/operaciones" class:activo={navActivo === 'operaciones'}>Operaciones</a>
				<a href="/cierres" class:activo={navActivo === 'cierres'}>Cierres Históricos</a>
				<a href="/cheques" class:activo={navActivo === 'cheques'}>Cheques</a>
			</div>
		</div>
		{#if puede('reportes') || puede('costos')}
			<div class="nav-drop" onmouseenter={() => abrirDrop('reportes')} onmouseleave={programarCierreDrop}>
				<a href="#" class="nav-link nav-drop-toggle" class:activo={reportesGrupoActivo}>Reportes</a>
				<div class="nav-drop-menu" class:abierto={dropAbierto === 'reportes'}>
					{#if puede('reportes')}
						<a href="/dashboard" class:activo={navActivo === 'dashboard'}>Dashboard</a>
					{/if}
					{#if puede('costos')}
						<a href="/rentabilidad" class:activo={navActivo === 'rentabilidad'}>Rentabilidad</a>
					{/if}
					{#if puede('reportes')}
						<a href="/iva" class:activo={navActivo === 'iva'}>Libro IVA</a>
						<a href="/reportes" class:activo={navActivo === 'reportes'}>Reportes</a>
					{/if}
				</div>
			</div>
		{/if}
		<div class="nav-spacer"></div>
		{#if sucursales.length > 1}
			<select class="nav-caja-select" value={sucursalSeleccionada} onchange={onCambioSucursal}>
				{#each sucursales as s (s.id)}
					<option value={s.id}>{s.nombre}</option>
				{/each}
			</select>
		{/if}
		{#if puede('cajas_todas') && cajas.length > 0}
			<select class="nav-caja-select" value={cajaSeleccionada} onchange={onCambioCaja}>
				{#each cajas as c (c.id)}
					<option value={c.id}>{c.nombre}</option>
				{/each}
			</select>
		{/if}
		<div class="nav-sep"></div>
		{#if puede('log')}
			<a href="/log" class="nav-link nav-icon-link" class:activo={navActivo === 'log'} title="Bitácora de acciones">
				<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
				<span class="nav-icon-label">Log</span>
			</a>
		{/if}
		{#if sesion.rol === 'admin'}
			<a href="/configuracion" class="nav-link nav-icon-link" class:activo={navActivo === 'configuracion'} title="Configuración">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
				<span class="nav-icon-label">Config</span>
				{#if licBadge}<span class="lic-badge" title="Hay un problema con la licencia"></span>{/if}
				{#if afipBadge}<span class="lic-badge afip-badge" title="El certificado AFIP vence pronto o está vencido"></span>{/if}
			</a>
		{/if}
		<div class="nav-sep"></div>
		<div class="nav-fecha">{fechaHoy}</div>
		<div class="nav-sep"></div>
		<div class="nav-usuario">
			{sesion.nombre}{#if puede('cajas_todas')}{:else}
				<span>· {sesion.caja_nombre}</span>{/if}
		</div>
		<div class="nav-sep"></div>
		<a
			href="#"
			class="nav-link nav-icon-link"
			title="Cerrar sesión"
			onclick={(e) => (e.preventDefault(), logout())}
		>
			<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
			<span class="nav-icon-label">Salir</span>
		</a>
	</nav>

	<main class="contenido">
		{@render children()}
	</main>

	{#if pedirNombreEquipo}
		<div class="device-overlay" role="presentation">
			<div class="device-modal">
				<div class="device-titulo">Identificá este equipo</div>
				<div class="device-texto">
					Asigná un nombre a este dispositivo. Aparecerá en el log de acciones para identificar desde
					qué PC se realizó cada operación.<br /><br />
					Ej: <em>Caja 1</em>, <em>Administración</em>, <em>Notebook Rodrigo</em>
				</div>
				<input
					type="text"
					placeholder="Nombre de este equipo"
					maxlength="100"
					bind:value={nombreEquipoInput}
					class:error={nombreEquipoError}
					onkeydown={(e) => e.key === 'Enter' && guardarNombreEquipo()}
				/>
				<button onclick={guardarNombreEquipo}>Guardar</button>
			</div>
		</div>
	{/if}

	{#if $servidorCaido}
		<div class="down-overlay">
			<div class="down-icon">⚠️</div>
			<div class="down-titulo">Servidor desconectado</div>
			<div class="down-texto">
				No se puede conectar al servidor.<br />Verificá que la PC servidor esté encendida y conectada
				a la red.
			</div>
			<button onclick={() => location.reload()}>Reintentar</button>
		</div>
	{/if}

	<Toast />
	<ConfirmModal />
	<PdfViewerModal />
	<ContactModal />
{/if}

<style>
	.app-nav {
		display: flex;
		align-items: center;
		gap: 4px;
		padding: 0 12px;
		height: 52px;
		background: var(--neo-bg, #fff);
		box-shadow: var(--neo-e2, 0 1px 3px rgba(0, 0, 0, 0.1));
		flex-shrink: 0;
		position: relative;
		/* Tiene que ganarle a cualquier header/barra de filtros sticky de las
		   páginas (esos usan z-index chico, 10 típicamente) — si empatan con
		   el nav, gana el que está después en el DOM (el contenido), y el
		   menú desplegable queda atrapado detrás. Se mantiene bien por debajo
		   de modales/overlays reales (1000+) para que esos sigan tapando el
		   nav cuando corresponde. */
		z-index: 500;
	}
	.nav-brand img {
		height: 26px;
		display: block;
	}
	.nav-link {
		padding: 8px 12px;
		font-size: 13px;
		font-weight: 600;
		color: var(--neo-text-2, #444);
		text-decoration: none;
		border-radius: 6px;
		white-space: nowrap;
	}
	.nav-link:hover,
	.nav-link.activo {
		color: var(--color-primary, #2563eb);
		background: var(--primary-soft, rgba(37, 99, 235, 0.08));
		/* pos-base-core.css (compartido, global) le pone a .nav-link.activo un
		   box-shadow inset de línea inferior curva — no la queremos, el
		   recuadro completo de arriba ya indica la pestaña activa. */
		box-shadow: none;
	}
	.nav-sep {
		width: 1px;
		height: 24px;
		background: var(--color-bg-alt, #e5e5e5);
		margin: 0 6px;
	}
	.nav-drop {
		position: relative;
	}
	.nav-drop-menu {
		display: none;
		position: absolute;
		top: 100%;
		left: 0;
		background: #fff;
		box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
		border-radius: 8px;
		padding: 6px;
		min-width: 180px;
		z-index: 20;
	}
	/* pos-base-core.css (compartido, global) también controla la visibilidad
	   de .nav-drop-menu vía :hover (opacity/visibility/transform, no
	   display) — su regla de :hover puede pisar el estado "abierto" que
	   maneja abrirDrop()/programarCierreDrop() durante el delay de cierre
	   (el mouse ya salió de .nav-drop, así que su :hover real ya es
	   falso). El selector .app-nav de acá le gana en especificidad para
	   forzar visible mientras dropAbierto siga marcando este menú. */
	.app-nav .nav-drop-menu.abierto {
		display: flex;
		flex-direction: column;
		opacity: 1;
		visibility: visible;
		transform: none;
	}
	.nav-drop-menu a {
		padding: 8px 12px;
		font-size: 13px;
		color: var(--neo-text-2, #444);
		text-decoration: none;
		border-radius: 6px;
		white-space: nowrap;
	}
	.nav-drop-menu a:hover,
	.nav-drop-menu a.activo {
		background: var(--primary-soft, rgba(37, 99, 235, 0.08));
		color: var(--color-primary, #2563eb);
	}
	.nav-spacer {
		flex: 1;
	}
	.nav-caja-select {
		padding: 6px 10px;
		border-radius: 6px;
		border: 1px solid var(--borde-fuerte, #ccc);
		font-size: 12px;
		font-family: inherit;
	}
	.nav-icon-link {
		display: flex;
		align-items: center;
		gap: 4px;
		position: relative;
	}
	.nav-icon-label {
		font-size: 11px;
	}
	.lic-badge {
		position: absolute;
		top: -3px;
		right: -6px;
		width: 8px;
		height: 8px;
		border-radius: 50%;
		background: var(--neo-danger);
	}
	.lic-badge.afip-badge {
		right: -16px;
		background: #f39c12;
	}
	.nav-fecha,
	.nav-usuario {
		font-size: 12px;
		color: var(--neo-text-3, #888);
		white-space: nowrap;
	}
	.contenido {
		flex: 1;
		overflow: auto;
		display: flex;
		flex-direction: column;
	}

	.device-overlay,
	.down-overlay {
		position: fixed;
		inset: 0;
		z-index: 99998;
		display: flex;
		align-items: center;
		justify-content: center;
	}
	.device-overlay {
		background: rgba(0, 0, 0, 0.55);
	}
	.device-modal {
		background: #fff;
		border-radius: 16px;
		padding: 32px;
		max-width: 400px;
		width: 90%;
		box-shadow: 0 8px 32px rgba(0, 0, 0, 0.25);
		display: flex;
		flex-direction: column;
		gap: 16px;
	}
	.device-titulo {
		font-size: 18px;
		font-weight: 700;
	}
	.device-texto {
		font-size: 13px;
		color: #888;
		line-height: 1.6;
	}
	.device-modal input {
		padding: 10px 14px;
		border: none;
		border-radius: 8px;
		font-size: 14px;
		font-family: inherit;
		background: #eee;
		outline: none;
	}
	.device-modal input.error {
		box-shadow: 0 0 0 2px #dc2626;
	}
	.device-modal button {
		padding: 10px 20px;
		background: var(--color-primary, #2563eb);
		color: #fff;
		border: none;
		border-radius: 8px;
		font-size: 14px;
		font-weight: 600;
		cursor: pointer;
		font-family: inherit;
	}

	.down-overlay {
		background: #0f1117;
		flex-direction: column;
		gap: 16px;
	}
	.down-icon {
		font-size: 40px;
	}
	.down-titulo {
		font-size: 17px;
		font-weight: 700;
		color: #f87171;
	}
	.down-texto {
		font-size: 13px;
		color: #9ca3af;
		text-align: center;
		max-width: 380px;
		line-height: 1.7;
	}
	.down-overlay button {
		background: #4f8ef7;
		color: #fff;
		border: none;
		border-radius: 8px;
		padding: 11px 28px;
		font-size: 13px;
		font-weight: 600;
		cursor: pointer;
		font-family: inherit;
	}
</style>
