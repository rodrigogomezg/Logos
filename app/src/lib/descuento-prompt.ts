// Pregunta compartida "¿mostrar los descuentos en este documento?" — pedido
// de Rodrigo 20/08/2026: la decisión de ocultar un descuento se toma recién
// al generar el remito (imprimir/descargar/mandar por mail), no al cargar
// el ítem en el POS — reemplaza el checkbox "en remito" que existía antes
// en la barra de ajuste del carrito.
//
// Solo tiene sentido preguntar si la venta realmente tiene algún ítem con
// descuento; si no hay ninguno, preguntarOcultarDescuentos() resuelve false
// sin mostrar nada. Montado globalmente (ver DescuentoPromptModal.svelte en
// +layout.svelte) para que cualquier pantalla (Ventas, Operaciones, POS)
// pueda usarlo con una sola llamada.
import { writable } from 'svelte/store';
import { api } from './api';

interface DescuentoPromptState {
	resolve: (ocultar: boolean) => void;
}

export const descuentoPrompt = writable<DescuentoPromptState | null>(null);

type ItemConAjuste = { ajuste_desc?: string | null; precio_original?: number | null };

/**
 * Devuelve true si hay que OCULTAR los descuentos al generar este documento
 * (el usuario eligió "Ocultar" en el modal). Trae los ítems de la venta,
 * chequea si hay algún descuento real, y solo si lo hay muestra la pregunta
 * — si no hay ninguno, resuelve false directo, sin interrumpir el flujo.
 */
export async function preguntarOcultarDescuentos(ventaId: number): Promise<boolean> {
	let items: ItemConAjuste[] = [];
	try {
		const r = await api(`/ventas/${ventaId}`);
		if (r.ok) {
			const v = await r.json();
			items = Array.isArray(v.items) ? v.items : [];
		}
	} catch {
		return false;
	}
	const hayDescuento = items.some((i) => !!i.ajuste_desc && i.precio_original != null);
	if (!hayDescuento) return false;

	return new Promise((resolve) => {
		descuentoPrompt.set({ resolve });
	});
}

export function resolverDescuentoPrompt(ocultar: boolean): void {
	let snap: DescuentoPromptState | null = null;
	descuentoPrompt.subscribe((s) => (snap = s))();
	if (!snap) return;
	(snap as DescuentoPromptState).resolve(ocultar);
	descuentoPrompt.set(null);
}
