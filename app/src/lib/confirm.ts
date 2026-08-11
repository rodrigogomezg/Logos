// Port de confirmarModal() de pos/helpers.js. Misma ergonomía de call-site:
// `if (await confirmar('¿Seguro?')) { ... }` — un solo diálogo a la vez
// (igual que el original, que también era un singleton inyectado en el DOM).
import { writable } from 'svelte/store';

export interface ConfirmOpts {
	titulo?: string;
	confirmLabel?: string;
	danger?: boolean;
}

interface ConfirmState extends Required<ConfirmOpts> {
	mensaje: string;
	resolve: (v: boolean) => void;
}

export const confirmState = writable<ConfirmState | null>(null);

export function confirmar(mensaje: string, opts: ConfirmOpts = {}): Promise<boolean> {
	return new Promise((resolve) => {
		confirmState.set({
			mensaje,
			titulo: opts.titulo ?? 'Confirmar',
			confirmLabel: opts.confirmLabel ?? 'Confirmar',
			danger: opts.danger ?? false,
			resolve
		});
	});
}

export function _resolverConfirm(valor: boolean): void {
	confirmState.update((s) => {
		s?.resolve(valor);
		return null;
	});
}
