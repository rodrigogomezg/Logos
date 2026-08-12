<script lang="ts">
	import { goto } from '$app/navigation';
	import { apiUrl } from '$lib/api';
	import { guardarSesion, nombreDispositivo, type Sesion, type Sucursal } from '$lib/session';

	type Usuario = {
		id: number;
		nombre: string;
		rol: 'admin' | 'user';
		permisos: Record<string, boolean>;
		debe_cambiar_pin?: boolean;
	};
	type Caja = { id: number; nombre: string; sucursal_id: number };
	type LoginData = { usuario: Usuario; token: string; sucursales: Sucursal[]; cajas: Caja[]; caja_default: Caja | null };

	let paso = $state<'nombre' | 'pin' | 'cambiar-pin' | 'caja' | 'sin-conexion'>('nombre');
	let usuarios = $state<Usuario[]>([]);
	let nombreInput = $state('');
	let nombreError = $state('');
	let usuarioActual = $state<Usuario | null>(null);
	let pin = $state('');
	let pinError = $state('');
	let tokenSesion: string | null = null;
	let sucursalesData: Sucursal[] = [];
	let cajasDisponibles = $state<Caja[]>([]);
	let cajasVacio = $state(false);
	let nombreInputEl = $state<HTMLInputElement | null>(null);
	let loginPendiente: LoginData | null = null;
	let nuevoPin = $state('');
	let nuevoPinConfirm = $state('');
	let cambiarPinError = $state('');
	let cambiandoPin = $state(false);

	const teclas = ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'borrar', '0', 'ingresar'];

	// ── arranque: igual que pos/login.html — primero chequear si hace falta
	// el wizard de instalación o si no hay conexión, recién después cargar
	// la lista de usuarios para la búsqueda por nombre.
	chequearInstalacionYCargar();

	async function chequearInstalacionYCargar() {
		try {
			const estado = await fetch(apiUrl('/instalacion/estado')).then((r) => r.json());
			if (estado.sin_conexion) {
				paso = 'sin-conexion';
				return;
			}
			if (
				estado.requiere_conexion ||
				estado.requiere_schema ||
				estado.requiere_admin ||
				estado.requiere_negocio ||
				estado.requiere_caja
			) {
				// El wizard todavía vive en la app legacy — no migrado en este POC.
				window.location.href = '/Logos/pos/instalar.html';
				return;
			}
		} catch {
			// Si falla el chequeo (ej. red), seguimos al login normal en vez de bloquear
			// — mismo criterio que pos/login.html::chequearInstalacion().
		}
		await cargarUsuarios();
	}

	async function cargarUsuarios() {
		try {
			const res = await fetch(apiUrl('/usuarios'));
			usuarios = await res.json();
		} catch {
			usuarios = [];
		}
		nombreInputEl?.focus();
	}

	function buscarYSeleccionar() {
		const val = nombreInput.trim();
		if (!val) {
			nombreError = 'Ingresá tu nombre de usuario';
			return;
		}
		const u = usuarios.find((x) => x.nombre.trim().toLowerCase() === val.toLowerCase());
		if (!u) {
			nombreError = 'Usuario no encontrado';
			return;
		}
		nombreError = '';
		seleccionarUsuario(u);
	}

	function seleccionarUsuario(u: Usuario) {
		usuarioActual = u;
		pin = '';
		pinError = '';
		paso = 'pin';
	}

	function volver() {
		usuarioActual = null;
		pin = '';
		nombreInput = '';
		nombreError = '';
		paso = 'nombre';
	}

	function onTecla(t: string) {
		if (t === 'borrar') {
			pin = pin.slice(0, -1);
		} else if (t === 'ingresar') {
			intentarLogin();
		} else if (pin.length < 6) {
			pin += t;
		}
	}

	async function intentarLogin() {
		if (pin.length < 4) {
			pinError = 'PIN incompleto';
			return;
		}
		const dev = nombreDispositivo();
		const res = await fetch(apiUrl('/usuarios/login'), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				...(dev ? { 'X-Device-Name': dev } : {})
			},
			body: JSON.stringify({ usuario_id: usuarioActual!.id, pin })
		});
		const data = await res.json();
		if (!res.ok) {
			pinError = data.error || 'PIN incorrecto';
			pin = '';
			return;
		}

		tokenSesion = data.token;
		sucursalesData = data.sucursales || [];

		if (data.usuario.debe_cambiar_pin) {
			// Cuenta con PIN de fábrica sin cambiar (ver migrate/74_fix_admin_semilla.sql)
			// — no se completa el login hasta que fije un PIN propio. El token ya
			// es válido en el servidor, así que la llamada de cambio de PIN se
			// autentica con él aunque todavía no se haya guardado la sesión local.
			loginPendiente = data;
			nuevoPin = '';
			nuevoPinConfirm = '';
			cambiarPinError = '';
			paso = 'cambiar-pin';
			return;
		}
		continuarLogin(data);
	}

	function continuarLogin(data: LoginData) {
		if (data.usuario.rol === 'admin' && data.caja_default) {
			iniciarSesion(data.usuario, data.caja_default);
			return;
		}
		if (data.cajas.length === 1) {
			iniciarSesion(data.usuario, data.cajas[0]);
			return;
		}
		cajasVacio = data.cajas.length === 0;
		cajasDisponibles = data.cajas;
		paso = 'caja';
	}

	async function confirmarNuevoPin() {
		if (!/^\d{4,6}$/.test(nuevoPin)) {
			cambiarPinError = 'El PIN debe tener entre 4 y 6 dígitos';
			return;
		}
		if (nuevoPin !== nuevoPinConfirm) {
			cambiarPinError = 'Los PIN no coinciden';
			return;
		}
		if (!loginPendiente) return;
		cambiandoPin = true;
		cambiarPinError = '';
		try {
			const res = await fetch(apiUrl(`/usuarios/${loginPendiente.usuario.id}`), {
				method: 'PUT',
				headers: { 'Content-Type': 'application/json', 'X-Auth-Token': loginPendiente.token },
				body: JSON.stringify({ pin: nuevoPin })
			});
			const data = await res.json();
			if (!res.ok) {
				cambiarPinError = data.error || 'No se pudo cambiar el PIN';
				return;
			}
			const pendiente = loginPendiente;
			loginPendiente = null;
			continuarLogin(pendiente);
		} finally {
			cambiandoPin = false;
		}
	}

	function iniciarSesion(usuario: Usuario, caja: Caja) {
		const sucursal =
			sucursalesData.find((s) => s.id === (caja.sucursal_id || 1)) ?? sucursalesData[0] ?? null;
		const sesion: Sesion = {
			usuario_id: usuario.id,
			nombre: usuario.nombre,
			rol: usuario.rol,
			permisos: usuario.permisos || {},
			caja_id: caja.id,
			caja_nombre: caja.nombre,
			sucursales: sucursalesData,
			sucursal_id: sucursal?.id ?? 1,
			deposito_id: sucursal?.deposito_principal_id ?? 1,
			token: tokenSesion!
		};
		guardarSesion(sesion);
		// POC: todavía no migramos la pantalla de venta — el destino post-login
		// es la otra página del spike (navegación SPA, sin recarga completa,
		// que es justamente lo que este POC tiene que demostrar).
		goto('/contactos');
	}

	function onKeydown(e: KeyboardEvent) {
		if (paso !== 'pin') return;
		if (e.key >= '0' && e.key <= '9') onTecla(e.key);
		else if (e.key === 'Backspace') onTecla('borrar');
		else if (e.key === 'Enter') onTecla('ingresar');
		else if (e.key === 'Escape') volver();
	}
</script>

<svelte:window onkeydown={onKeydown} />

<svelte:head>
	<title>Logos — Ingresar</title>
</svelte:head>

<div class="login-wrap">
	<div class="login-box">
		<img src="/Logos/logos_logo.png" alt="Logos" />

		{#if paso === 'nombre'}
			<div class="paso">
				<div class="titulo">Iniciar sesión</div>
				<input
					type="text"
					class="login-input"
					placeholder="Nombre de usuario"
					autocomplete="off"
					spellcheck="false"
					bind:value={nombreInput}
					bind:this={nombreInputEl}
					onkeydown={(e) => e.key === 'Enter' && buscarYSeleccionar()}
				/>
				<div class="error-msg">{nombreError}</div>
				<button class="login-btn" onclick={buscarYSeleccionar}>Continuar</button>
			</div>
		{:else if paso === 'pin'}
			<div class="paso">
				<div class="titulo">{usuarioActual?.nombre}</div>
				<div class="pin-dots">
					{#each { length: Math.max(pin.length, 4) } as _, i (i)}
						<div class="pin-dot" class:lleno={i < pin.length}></div>
					{/each}
				</div>
				<div class="error-msg">{pinError}</div>
				<div class="pin-pad">
					{#each teclas as t (t)}
						<button
							class="pin-tecla"
							class:secundaria={t === 'borrar' || t === 'ingresar'}
							onclick={() => onTecla(t)}
						>
							{t === 'borrar' ? '⌫' : t === 'ingresar' ? 'OK' : t}
						</button>
					{/each}
				</div>
				<a class="volver" href={'#'} onclick={(e) => (e.preventDefault(), volver())}
					>‹ Volver</a
				>
			</div>
		{:else if paso === 'cambiar-pin'}
			<div class="paso">
				<div class="titulo">Elegí un PIN nuevo</div>
				<p class="sin-conexion-text" style="max-width:320px">
					Esta cuenta todavía tiene el PIN de fábrica. Por seguridad, tenés que
					reemplazarlo por uno propio antes de continuar.
				</p>
				<input
					type="password"
					inputmode="numeric"
					class="login-input"
					placeholder="PIN nuevo (4 a 6 dígitos)"
					autocomplete="off"
					bind:value={nuevoPin}
				/>
				<input
					type="password"
					inputmode="numeric"
					class="login-input"
					placeholder="Repetí el PIN nuevo"
					autocomplete="off"
					bind:value={nuevoPinConfirm}
					onkeydown={(e) => e.key === 'Enter' && confirmarNuevoPin()}
				/>
				<div class="error-msg">{cambiarPinError}</div>
				<button class="login-btn" disabled={cambiandoPin} onclick={confirmarNuevoPin}>
					{cambiandoPin ? 'Guardando…' : 'Guardar y continuar'}
				</button>
			</div>
		{:else if paso === 'caja'}
			<div class="paso">
				<div class="titulo">¿Con qué caja vas a trabajar?</div>
				{#if cajasVacio}
					<div class="sin-conexion-text" style="max-width:320px">
						No hay cajas disponibles para tu usuario.<br />
						Pedile a un administrador que cree una <b>caja de tipo Venta</b> en Configuración, o
						que te dé el permiso <b>"Operar todas las cajas"</b>.
					</div>
				{:else}
					<div class="caja-grid">
						{#each cajasDisponibles as c (c.id)}
							<button class="caja-btn" onclick={() => iniciarSesion(usuarioActual!, c)}>
								{c.nombre}
							</button>
						{/each}
					</div>
				{/if}
			</div>
		{:else if paso === 'sin-conexion'}
			<div class="paso">
				<div class="titulo">Sin conexión a la base de datos</div>
				<p class="sin-conexion-text">
					El servidor de base de datos no responde.<br />
					Cerrá y volvé a abrir la aplicación. Si el problema persiste, reiniciá la PC.
				</p>
				<button class="login-btn sec" onclick={() => location.reload()}>Reintentar</button>
			</div>
		{/if}
	</div>
</div>

<style>
	.login-wrap {
		background: #fff;
		border: 1px solid var(--borde-fuerte);
		border-radius: var(--neo-r-lg);
		padding: 40px 36px 36px;
		width: 380px;
		margin: auto;
	}
	.login-box {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 28px;
	}
	.login-box img {
		height: 48px;
	}
	.paso {
		width: 100%;
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 18px;
	}
	.titulo {
		color: var(--neo-text);
		font-size: 18px;
		font-weight: 700;
		letter-spacing: -0.2px;
		text-align: center;
	}
	.login-input {
		width: 100%;
		padding: 11px 14px;
		border: 1px solid var(--borde-fuerte);
		border-radius: var(--neo-r-md);
		font-size: 14px;
		font-family: inherit;
		color: var(--neo-text);
		background: #fff;
		box-sizing: border-box;
		outline: none;
		transition: border-color var(--neo-t-fast);
	}
	.login-input:focus {
		border-color: var(--color-primary);
	}
	.login-btn {
		width: 100%;
		background: var(--color-primary);
		color: #fff;
		border: none;
		border-radius: var(--neo-r-md);
		padding: 12px;
		font-size: 14px;
		font-weight: 600;
		cursor: pointer;
		font-family: inherit;
		transition: opacity var(--neo-t-fast);
	}
	.login-btn:hover {
		opacity: 0.88;
	}
	.login-btn.sec {
		background: #fff;
		color: var(--neo-text);
		border: 1px solid var(--borde-fuerte);
	}
	.pin-dots {
		display: flex;
		gap: 12px;
		height: 20px;
		align-items: center;
	}
	.pin-dot {
		width: 14px;
		height: 14px;
		border-radius: 50%;
		background: var(--color-bg-alt);
		border: 1px solid var(--borde-fuerte);
		transition:
			background var(--neo-t-fast),
			border-color var(--neo-t-fast),
			transform var(--neo-t-fast);
	}
	.pin-dot.lleno {
		background: var(--color-primary);
		border-color: var(--color-primary);
		transform: scale(1.1);
	}
	.pin-pad {
		display: grid;
		grid-template-columns: repeat(3, 72px);
		gap: 12px;
	}
	.pin-tecla {
		background: #fff;
		color: var(--neo-text);
		border: 1px solid var(--borde-fuerte);
		border-radius: var(--neo-r-md);
		height: 64px;
		font-size: 20px;
		font-weight: 700;
		cursor: pointer;
		font-family: inherit;
		transition:
			border-color var(--neo-t-fast),
			background var(--neo-t-fast),
			color var(--neo-t-fast);
	}
	.pin-tecla:hover {
		border-color: var(--color-primary);
		color: var(--color-primary);
	}
	.pin-tecla:active {
		background: var(--primary-soft);
	}
	.pin-tecla.secundaria {
		font-size: 13px;
		font-weight: 600;
		color: var(--neo-text-2);
	}
	.volver {
		color: var(--neo-text-3);
		font-size: 12px;
		cursor: pointer;
		text-decoration: none;
		font-weight: 500;
		transition: color var(--neo-t-fast);
	}
	.volver:hover {
		color: var(--neo-text-2);
	}
	.error-msg {
		color: var(--neo-danger);
		font-size: 12px;
		font-weight: 600;
		min-height: 16px;
		text-align: center;
	}
	.caja-grid {
		display: flex;
		flex-wrap: wrap;
		gap: 10px;
		justify-content: center;
		width: 100%;
	}
	.caja-btn {
		background: #fff;
		color: var(--neo-text);
		border: 1px solid var(--borde-fuerte);
		border-radius: var(--neo-r-md);
		padding: 18px 24px;
		font-size: 14px;
		font-weight: 600;
		cursor: pointer;
		font-family: inherit;
		min-width: 140px;
		transition:
			border-color var(--neo-t-fast),
			color var(--neo-t-fast),
			background var(--neo-t-fast);
	}
	.caja-btn:hover {
		border-color: var(--color-primary);
		color: var(--color-primary);
	}
	.sin-conexion-text {
		font-size: 13px;
		color: var(--neo-text-2);
		line-height: 1.6;
		text-align: center;
	}
</style>
