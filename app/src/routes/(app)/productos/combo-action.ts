// Port de crearCombo() de pos/productos.html — dropdown de autocompletado
// flotante sobre un <input> de texto libre (proveedor, subcategoría, y los
// campos de bulk-edit). Página-específico: sin otro consumidor en el plan de
// migración, no promovido a $lib.
export function combobox(input: HTMLInputElement, lista: string[]) {
	let items = lista;
	const dd = document.createElement('div');
	dd.className = 'combo-dropdown';
	document.body.appendChild(dd);

	let idxActivo = -1;
	function e2(s: string) {
		return s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c] as string);
	}

	function posicionar() {
		const r = input.getBoundingClientRect();
		dd.style.left = r.left + 'px';
		dd.style.width = r.width + 'px';
		const spaceBelow = window.innerHeight - r.bottom - 4;
		const ddH = Math.min(200, dd.scrollHeight || 40);
		dd.style.top = spaceBelow >= ddH || spaceBelow >= r.top ? r.bottom + 2 + 'px' : Math.max(4, r.top - ddH - 2) + 'px';
	}

	function render(ops: string[]) {
		idxActivo = -1;
		dd.innerHTML = ops.length
			? ops.map((o) => `<div class="combo-item" data-val="${e2(o)}">${e2(o)}</div>`).join('')
			: '<div class="combo-vacio">Sin coincidencias</div>';
		dd.querySelectorAll('.combo-item').forEach((item) =>
			item.addEventListener('mousedown', (ev) => {
				ev.preventDefault();
				input.value = (item as HTMLElement).dataset.val ?? '';
				input.dispatchEvent(new Event('input', { bubbles: true }));
				cerrar();
			})
		);
		posicionar();
	}

	function filtrar() {
		const q = input.value.trim().toLowerCase();
		render(q ? items.filter((o) => o.toLowerCase().includes(q)) : items);
	}

	function abrir() {
		filtrar();
		dd.classList.add('abierto');
	}
	function cerrar() {
		dd.classList.remove('abierto');
		idxActivo = -1;
	}
	function setActivo(idx: number) {
		const els = [...dd.querySelectorAll('.combo-item')];
		if (!els.length) return;
		els.forEach((i) => i.classList.remove('activo'));
		idxActivo = Math.max(0, Math.min(idx, els.length - 1));
		els[idxActivo].classList.add('activo');
		els[idxActivo].scrollIntoView({ block: 'nearest' });
	}

	const onFocus = () => abrir();
	const onInput = () => {
		filtrar();
		if (!dd.classList.contains('abierto')) dd.classList.add('abierto');
	};
	const onBlur = () => setTimeout(cerrar, 150);
	const onKeydown = (e: KeyboardEvent) => {
		const open = dd.classList.contains('abierto');
		if (!open && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
			e.preventDefault();
			abrir();
			return;
		}
		if (!open) return;
		if (e.key === 'ArrowDown') {
			e.preventDefault();
			setActivo(idxActivo + 1);
		} else if (e.key === 'ArrowUp') {
			e.preventDefault();
			setActivo(Math.max(0, idxActivo - 1));
		} else if (e.key === 'Enter' && idxActivo >= 0) {
			const els = [...dd.querySelectorAll('.combo-item')];
			if (els[idxActivo]) {
				e.preventDefault();
				input.value = (els[idxActivo] as HTMLElement).dataset.val ?? '';
				input.dispatchEvent(new Event('input', { bubbles: true }));
				cerrar();
			}
		} else if (e.key === 'Escape') cerrar();
	};
	const repos = () => {
		if (dd.classList.contains('abierto')) posicionar();
	};

	input.addEventListener('focus', onFocus);
	input.addEventListener('input', onInput);
	input.addEventListener('blur', onBlur);
	input.addEventListener('keydown', onKeydown);
	window.addEventListener('scroll', repos, true);
	window.addEventListener('resize', repos);

	return {
		update(newLista: string[]) {
			items = newLista;
		},
		destroy() {
			input.removeEventListener('focus', onFocus);
			input.removeEventListener('input', onInput);
			input.removeEventListener('blur', onBlur);
			input.removeEventListener('keydown', onKeydown);
			window.removeEventListener('scroll', repos, true);
			window.removeEventListener('resize', repos);
			dd.remove();
		}
	};
}
