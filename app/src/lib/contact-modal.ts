// Modal compartido de edición/alta de contacto (subconjunto "core" de
// pos/contacto-modal.php, mismo alcance que contactos/+page.svelte).
// compras-nueva.html tiene su propio flujo de alta rápida inline, más
// liviano, no reemplazado por esto.
import { writable } from 'svelte/store';
import { api } from './api';

export type ContactoTipo = 'clientes' | 'proveedores';

export interface ContactForm {
	id: number | null;
	nombre: string;
	cuit: string;
	condicion_iva: string;
	email: string;
	telefono: string;
	domicilio: string;
	localidad: string;
	provincia: string;
	observaciones: string;
	cc_habilitada: boolean;
	limite_credito: number;
	plazo_pago_dias: number | null;
	lista_precio_id: number | null;
	regla_precio_id: number | null;
	descuento_extra: number;
	activo: boolean;
}

export interface ContactModalState {
	tipo: ContactoTipo;
	form: ContactForm;
	cargando: boolean;
	guardando: boolean;
	error: string;
	confirmarEliminar: boolean;
	listas: { id: number; nombre: string; porcentaje: number }[];
	reglas: { id: number; nombre: string; porcentaje_recargo: number }[];
	onGuardado?: (contacto?: { id: number; nombre: string }) => void | Promise<void>;
}

export const contactModal = writable<ContactModalState | null>(null);

function vacio(id: number | null): ContactForm {
	return {
		id,
		nombre: '',
		cuit: '',
		condicion_iva: '',
		email: '',
		telefono: '',
		domicilio: '',
		localidad: '',
		provincia: '',
		observaciones: '',
		cc_habilitada: false,
		limite_credito: 0,
		plazo_pago_dias: null,
		lista_precio_id: null,
		regla_precio_id: null,
		descuento_extra: 0,
		activo: true
	};
}

export async function abrirContacto(
	id: number | null,
	tipo: ContactoTipo,
	opts: { onGuardado?: (contacto?: { id: number; nombre: string }) => void | Promise<void> } = {}
): Promise<void> {
	contactModal.set({
		tipo,
		form: vacio(id),
		cargando: id !== null,
		guardando: false,
		error: '',
		confirmarEliminar: false,
		listas: [],
		reglas: [],
		onGuardado: opts.onGuardado
	});

	const [listasRes, reglasRes, contactoRes] = await Promise.all([
		tipo === 'clientes' ? api('/listas-precio?activas=1') : Promise.resolve(null),
		tipo === 'proveedores' ? api('/reglas-precio?activas=1') : Promise.resolve(null),
		id !== null ? api(`/${tipo}/${id}`) : Promise.resolve(null)
	]);

	if (contactoRes && !contactoRes.ok) {
		contactModal.update((s) => (s ? { ...s, cargando: false, error: 'Error al cargar los datos.' } : s));
		return;
	}
	const r = contactoRes ? await contactoRes.json() : null;
	const listas = listasRes && listasRes.ok ? await listasRes.json() : [];
	const reglas = reglasRes && reglasRes.ok ? await reglasRes.json() : [];

	contactModal.update((s) =>
		s
			? {
					...s,
					cargando: false,
					listas,
					reglas,
					form: r
						? {
								id: r.id,
								nombre: r.nombre ?? '',
								cuit: r.cuit ?? '',
								condicion_iva: r.condicion_iva ?? '',
								email: r.email ?? '',
								telefono: r.telefono ?? '',
								domicilio: r.domicilio ?? '',
								localidad: r.localidad ?? '',
								provincia: r.provincia ?? '',
								observaciones: r.observaciones ?? '',
								cc_habilitada: !!r.cc_habilitada,
								limite_credito: r.limite_credito ?? 0,
								plazo_pago_dias: r.plazo_pago_dias ?? null,
								lista_precio_id: r.lista_precio_id ?? null,
								regla_precio_id: r.regla_precio_id ?? null,
								descuento_extra: r.descuento_extra ?? 0,
								activo: r.activo !== false
							}
						: s.form
				}
			: s
	);
}

export function cerrarContacto(): void {
	contactModal.set(null);
}

export async function guardarContacto(): Promise<void> {
	let snap: ContactModalState | null = null;
	contactModal.subscribe((s) => (snap = s))();
	if (!snap) return;
	const s = snap as ContactModalState;

	if (!s.form.nombre.trim()) {
		contactModal.update((st) => (st ? { ...st, error: 'El nombre es obligatorio.' } : st));
		return;
	}
	contactModal.update((st) => (st ? { ...st, guardando: true, error: '' } : st));

	const body: Record<string, unknown> = {
		nombre: s.form.nombre,
		cuit: s.form.cuit,
		condicion_iva: s.form.condicion_iva,
		email: s.form.email,
		telefono: s.form.telefono,
		domicilio: s.form.domicilio,
		localidad: s.form.localidad,
		provincia: s.form.provincia,
		observaciones: s.form.observaciones,
		cc_habilitada: s.form.cc_habilitada,
		limite_credito: Number(s.form.limite_credito) || 0,
		plazo_pago_dias: s.form.plazo_pago_dias,
		activo: s.form.activo
	};
	if (s.tipo === 'clientes') {
		body.lista_precio_id = s.form.lista_precio_id;
		body.descuento_extra = Number(s.form.descuento_extra) || 0;
	} else {
		body.regla_precio_id = s.form.regla_precio_id;
	}

	const esNuevo = s.form.id === null;
	const res = await api(esNuevo ? `/${s.tipo}` : `/${s.tipo}/${s.form.id}`, {
		method: esNuevo ? 'POST' : 'PUT',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify(body)
	});
	const data = await res.json();

	if (!res.ok) {
		contactModal.update((st) => (st ? { ...st, guardando: false, error: data.error || 'Error al guardar.' } : st));
		return;
	}
	const onGuardado = s.onGuardado;
	const idFinal = s.form.id ?? data.id;
	cerrarContacto();
	if (onGuardado) await onGuardado({ id: idFinal, nombre: s.form.nombre });
}

export async function eliminarContacto(): Promise<void> {
	let snap: ContactModalState | null = null;
	contactModal.subscribe((s) => (snap = s))();
	if (!snap) return;
	const s = snap as ContactModalState;
	if (s.form.id === null) return;

	const res = await api(`/${s.tipo}/${s.form.id}`, { method: 'DELETE' });
	const data = await res.json();
	if (!res.ok) {
		contactModal.update((st) =>
			st ? { ...st, error: data.error || 'No se pudo eliminar.', confirmarEliminar: false } : st
		);
		return;
	}
	const onGuardado = s.onGuardado;
	cerrarContacto();
	if (onGuardado) await onGuardado();
}
