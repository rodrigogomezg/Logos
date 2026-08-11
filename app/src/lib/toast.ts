// Port de toast_() de pos/helpers.js. Store en vez de DOM-injection directa;
// el componente Toast.svelte (montado una vez en (app)/+layout.svelte) es el
// que efectivamente pinta. Misma duración (3s) y mismos 4 tipos.
import { writable } from 'svelte/store';

export type ToastTipo = 'ok' | 'err' | 'info' | 'warn';
export interface ToastItem {
	id: number;
	mensaje: string;
	tipo: ToastTipo;
}

export const toasts = writable<ToastItem[]>([]);
let nextId = 1;

export function toast_(mensaje: string, tipo: ToastTipo = 'info'): void {
	const id = nextId++;
	toasts.update((list) => [...list, { id, mensaje, tipo }]);
	setTimeout(() => {
		toasts.update((list) => list.filter((t) => t.id !== id));
	}, 3000);
}
