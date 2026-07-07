/**
 * Modal de confirmación para cierre de caja
 * Se carga en caja.html antes de cerrar un turno
 */

function mostrarModalConfirmacionCierre(turnoId, totalEsperado, totalContado) {
  const overlay = document.createElement('div');
  overlay.className = 'cierre-overlay';
  overlay.innerHTML = `
    <div class="cierre-modal">
      <div class="cierre-head">
        <h3>Confirmación de cierre</h3>
        <button class="cierre-cerrar" id="cierre-cancel">×</button>
      </div>
      <div class="cierre-body">
        <div class="cierre-resumen">
          <div class="cierre-row">
            <span>Efectivo esperado:</span>
            <strong>${fmt(totalEsperado)}</strong>
          </div>
          <div class="cierre-row">
            <span>Efectivo contado:</span>
            <strong>${fmt(totalContado)}</strong>
          </div>
          <div class="cierre-row cierre-diferencia ${Math.abs(totalContado - totalEsperado) > 0.01 ? 'cierre-error' : 'cierre-ok'}">
            <span>Diferencia:</span>
            <strong>${fmt(totalContado - totalEsperado)}</strong>
          </div>
        </div>
        ${Math.abs(totalContado - totalEsperado) > 0.01 ? 
          `<div class="cierre-alerta">
            ⚠️ Hay diferencia de efectivo. Verificá antes de confirmar.
          </div>` 
          : 
          `<div class="cierre-exito">
            ✅ El arqueo cuadra correctamente.
          </div>`
        }
      </div>
      <div class="cierre-foot">
        <button class="btn-sec" id="cierre-cancel-btn">Cancelar</button>
        <button class="btn-ok" id="cierre-confirmar-btn">Confirmar cierre</button>
      </div>
    </div>
  `;

  document.body.appendChild(overlay);

  const btnCancel = document.getElementById('cierre-cancel');
  const btnCancelBtn = document.getElementById('cierre-cancel-btn');
  const btnConfirmar = document.getElementById('cierre-confirmar-btn');

  const cerrar = () => overlay.remove();

  btnCancel.addEventListener('click', cerrar);
  btnCancelBtn.addEventListener('click', cerrar);

  btnConfirmar.addEventListener('click', async () => {
    btnConfirmar.disabled = true;
    btnConfirmar.textContent = 'Cerrando...';

    try {
      await confirmarCierreTurno(turnoId);
      toast_('✅ Turno cerrado correctamente', 'ok');
      cerrar();
      location.reload();
    } catch (e) {
      toast_(e.message, 'err');
      btnConfirmar.disabled = false;
      btnConfirmar.textContent = 'Confirmar cierre';
    }
  });
}

const estilo = document.createElement('style');
estilo.textContent = `
  .cierre-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .cierre-modal {
    background: white;
    border-radius: 8px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    width: 90%;
    max-width: 450px;
    overflow: hidden;
  }
  .cierre-head {
    background: var(--azul);
    color: white;
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .cierre-head h3 {
    margin: 0;
    font-size: 16px;
  }
  .cierre-cerrar {
    background: none;
    border: none;
    color: white;
    font-size: 24px;
    cursor: pointer;
    padding: 0;
  }
  .cierre-body {
    padding: 20px;
  }
  .cierre-resumen {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-bottom: 16px;
  }
  .cierre-row {
    display: flex;
    justify-content: space-between;
    font-size: 14px;
    padding: 8px;
    border-radius: 4px;
  }
  .cierre-diferencia {
    font-weight: 700;
  }
  .cierre-diferencia.cierre-ok {
    background: #dcfce7;
    color: #15803d;
  }
  .cierre-diferencia.cierre-error {
    background: #fee2e2;
    color: #991b1b;
  }
  .cierre-alerta {
    background: #fffbeb;
    border: 1px solid #fcd34d;
    padding: 12px;
    border-radius: 6px;
    font-size: 13px;
    color: #78350f;
  }
  .cierre-exito {
    background: #dcfce7;
    border: 1px solid #86efac;
    padding: 12px;
    border-radius: 6px;
    font-size: 13px;
    color: #15803d;
  }
  .cierre-foot {
    padding: 16px 20px;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    background: var(--gris1);
    border-top: 1px solid var(--borde);
  }
`;
document.head.appendChild(estilo);
