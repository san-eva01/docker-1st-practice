// URL relativa: Nginx reenvía /api/ al servicio "backend". Nunca se usa localhost.
const API = '/api';

const $ = (id) => document.getElementById(id);
const CAMPOS = ['espacio', 'solicitante', 'correo', 'fecha', 'hora_inicio', 'hora_fin', 'estado'];
let registros = [];

async function api(ruta, opciones = {}) {
  const resp = await fetch(API + ruta, {
    headers: { 'Content-Type': 'application/json' },
    ...opciones,
  });
  const datos = resp.status === 204 ? null : await resp.json().catch(() => null);
  if (!resp.ok) {
    let texto = datos?.error || `Error HTTP ${resp.status}`;
    if (datos?.detalles) {
      texto += ':\n' + Object.entries(datos.detalles).map(([c, m]) => `• ${c}: ${m}`).join('\n');
    }
    const err = new Error(texto);
    err.detalles = datos?.detalles;
    throw err;
  }
  return datos;
}

function mostrarMensaje(texto, tipo = 'exito') {
  const caja = $('mensaje');
  caja.textContent = texto;
  caja.className = `mensaje ${tipo}`;
  caja.hidden = false;
  clearTimeout(mostrarMensaje.timer);
  mostrarMensaje.timer = setTimeout(() => (caja.hidden = true), 6000);
}

function escapar(v) {
  const d = document.createElement('div');
  d.textContent = v ?? '';
  return d.innerHTML;
}

async function verificarSalud() {
  const chip = $('estado-api');
  try {
    const s = await api('/health');
    chip.textContent = `API: ok · MariaDB ${s.servicios.mariadb} · Redis ${s.servicios.redis}`;
    chip.className = 'chip ok';
  } catch {
    chip.textContent = 'API: sin conexión';
    chip.className = 'chip mal';
  }
}

async function cargar() {
  const buscar = $('buscar').value.trim();
  try {
    const r = await api('/reservaciones' + (buscar ? `?buscar=${encodeURIComponent(buscar)}` : ''));
    registros = r.datos;
    $('fuente').textContent = `Fuente: ${r.fuente === 'redis' ? 'Redis (caché)' : 'MariaDB'}`;
    $('contador').textContent = `Consultas: ${r.contador_consultas}`;
    $('total').textContent = `(${r.total}${buscar ? ` resultado(s) para "${buscar}"` : ''})`;
    pintarTabla();
  } catch (e) {
    mostrarMensaje('No se pudo cargar el listado: ' + e.message, 'error');
  }
}

function pintarTabla() {
  const tbody = $('tabla');
  if (!registros.length) {
    tbody.innerHTML = '<tr><td colspan="7" class="vacio">Sin reservaciones</td></tr>';
    return;
  }
  tbody.innerHTML = registros.map((r) => `
    <tr>
      <td>${r.id}</td>
      <td>${escapar(r.espacio)}</td>
      <td>${escapar(r.solicitante)}<span class="correo">${escapar(r.correo)}</span></td>
      <td>${escapar(r.fecha)}</td>
      <td>${escapar(r.hora_inicio.slice(0, 5))} – ${escapar(r.hora_fin.slice(0, 5))}</td>
      <td>
        <select class="estado ${r.estado}" data-id="${r.id}" aria-label="Estado">
          ${['pendiente', 'confirmada', 'cancelada']
            .map((e) => `<option ${e === r.estado ? 'selected' : ''}>${e}</option>`).join('')}
        </select>
      </td>
      <td class="botones">
        <button class="mini secundario" data-editar="${r.id}">Editar</button>
        <button class="mini peligro" data-eliminar="${r.id}">Eliminar</button>
      </td>
    </tr>`).join('');
}

function limpiarFormulario() {
  $('formulario').reset();
  $('id').value = '';
  $('titulo-form').textContent = 'Nueva reservación';
  $('btn-cancelar').hidden = true;
  document.querySelectorAll('.invalido').forEach((el) => el.classList.remove('invalido'));
}

function editar(id) {
  const r = registros.find((x) => x.id == id);
  if (!r) return;
  $('id').value = r.id;
  CAMPOS.forEach((c) => ($(c).value = c.startsWith('hora') ? r[c].slice(0, 5) : r[c]));
  $('titulo-form').textContent = `Editar reservación #${r.id}`;
  $('btn-cancelar').hidden = false;
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function validarFormulario() {
  let ok = true;
  CAMPOS.forEach((c) => {
    const el = $(c);
    const valido = el.checkValidity();
    el.classList.toggle('invalido', !valido);
    ok &&= valido;
  });
  if (ok && $('hora_fin').value <= $('hora_inicio').value) {
    $('hora_fin').classList.add('invalido');
    mostrarMensaje('La hora de fin debe ser posterior a la hora de inicio', 'error');
    return false;
  }
  if (!ok) mostrarMensaje('Revisa los campos marcados en rojo', 'error');
  return ok;
}

$('formulario').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  if (!validarFormulario()) return;
  const datos = Object.fromEntries(CAMPOS.map((c) => [c, $(c).value.trim()]));
  const id = $('id').value;
  try {
    if (id) {
      await api(`/reservaciones/${id}`, { method: 'PUT', body: JSON.stringify(datos) });
      mostrarMensaje(`Reservación #${id} actualizada`);
    } else {
      const r = await api('/reservaciones', { method: 'POST', body: JSON.stringify(datos) });
      mostrarMensaje(`Reservación #${r.datos.id} creada. Correo de notificación: ${r.correo} (ver Mailpit)`);
    }
    limpiarFormulario();
    cargar();
  } catch (e) {
    if (e.detalles) Object.keys(e.detalles).forEach((c) => $(c)?.classList.add('invalido'));
    mostrarMensaje(e.message, 'error');
  }
});

$('btn-cancelar').addEventListener('click', limpiarFormulario);

$('form-buscar').addEventListener('submit', (ev) => {
  ev.preventDefault();
  cargar();
});

$('tabla').addEventListener('click', async (ev) => {
  const { editar: idEditar, eliminar: idEliminar } = ev.target.dataset;
  if (idEditar) editar(idEditar);
  if (idEliminar) {
    const r = registros.find((x) => x.id == idEliminar);
    if (!confirm(`¿Eliminar la reservación #${idEliminar} (${r?.espacio})?`)) return;
    try {
      await api(`/reservaciones/${idEliminar}`, { method: 'DELETE' });
      mostrarMensaje(`Reservación #${idEliminar} eliminada`);
      cargar();
    } catch (e) {
      mostrarMensaje(e.message, 'error');
    }
  }
});

$('tabla').addEventListener('change', async (ev) => {
  const sel = ev.target;
  if (!sel.classList.contains('estado')) return;
  try {
    await api(`/reservaciones/${sel.dataset.id}`, {
      method: 'PUT',
      body: JSON.stringify({ estado: sel.value }),
    });
    mostrarMensaje(`Estado de la reservación #${sel.dataset.id} cambiado a "${sel.value}"`);
    cargar();
  } catch (e) {
    mostrarMensaje(e.message, 'error');
    cargar();
  }
});

verificarSalud();
cargar();
