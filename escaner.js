function escapeHtml(valor) {
    return String(valor === null || valor === undefined ? '' : valor)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

let procesando = false;
let ultimosEscaneos = [];

document.addEventListener('DOMContentLoaded', function() {
    const ticketInput = document.getElementById('ticketInput');
    const scanResult = document.getElementById('scanResult');
    const scanSection = document.getElementById('scanSection');
    const confirmSection = document.getElementById('confirmSection');
    const confirmContent = document.getElementById('confirmContent');
    const recentList = document.getElementById('recentList');

    cargarEstadisticas();

    setInterval(cargarEstadisticas, 30000);

    function mantenerFoco() {
        if (confirmSection.style.display === 'none') {
            ticketInput.focus();
        }
    }

    setInterval(mantenerFoco, 500);
    document.addEventListener('click', mantenerFoco);

    ticketInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const idTicket = ticketInput.value.trim();
            
            if (idTicket && !procesando) {
                procesando = true;
                ticketInput.value = '';
                validarTicket(idTicket);
            }
        }
    });

    async function cargarEstadisticas() {
        try {
            const response = await fetch('backend/estadisticas.php');
            const result = await response.json();

            if (result.success) {
                actualizarContador('statTotal', result.data.total);
                actualizarContador('statConfirmados', result.data.confirmados);
                actualizarContador('statPendientes', result.data.pendientes);
            }
        } catch (error) {
            console.error('Error al cargar estadísticas:', error);
        }
    }

    function actualizarContador(elementId, nuevoValor) {
        const el = document.getElementById(elementId);
        if (!el) return;
        
        const valorAnterior = parseInt(el.textContent) || 0;
        
        if (valorAnterior !== nuevoValor) {
            el.textContent = nuevoValor;
            el.classList.add('updated');
            setTimeout(() => el.classList.remove('updated'), 500);
        }
    }

    async function validarTicket(idTicket) {
        scanResult.className = 'scan-result info';
        scanResult.textContent = '⏳ Validando ticket...';

        try {
            const response = await fetch('backend/validar-asistencia.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ idTicket: idTicket })
            });

            const result = await response.json();

            agregarAlHistorial(result, idTicket);

            cargarEstadisticas();

            scanSection.style.display = 'none';
            confirmSection.style.display = 'block';

            if (result.success) {
                if (result.tipo === 'primera_entrada') {
                    mostrarConfirmacionExitosa(result.data);
                } else {
                    mostrarYaRegistrado(result.data);
                }
            } else {
                mostrarError(result.message);
            }

        } catch (error) {
            console.error('Error:', error);
            scanResult.className = 'scan-result error';
            scanResult.textContent = '❌ Error de conexión al validar';
            procesando = false;
            ticketInput.focus();
        }
    }

    function agregarAlHistorial(result, idTicket) {
        const ahora = new Date().toLocaleTimeString('es-MX', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });

        let item;
        if (result.success && result.tipo === 'primera_entrada') {
            item = {
                clase: 'success',
                nombre: result.data.nombre,
                hora: ahora,
                icono: '✅'
            };
        } else if (result.success && result.tipo === 'ya_registrado') {
            item = {
                clase: 'warning',
                nombre: result.data.nombre + ' (ya registrado)',
                hora: ahora,
                icono: '⚠️'
            };
        } else {
            item = {
                clase: 'error',
                nombre: 'Ticket inválido',
                hora: ahora,
                icono: '❌'
            };
        }

        ultimosEscaneos.unshift(item);
        ultimosEscaneos = ultimosEscaneos.slice(0, 5);
        renderizarHistorial();
    }

    function renderizarHistorial() {
        if (ultimosEscaneos.length === 0) {
            recentList.innerHTML = '<p class="no-recent">Aún no hay escaneos en esta sesión</p>';
            return;
        }

        recentList.innerHTML = ultimosEscaneos.map(item => `
            <div class="recent-item ${item.clase}">
                <span class="nombre">${item.icono} ${escapeHtml(item.nombre)}</span>
                <span class="hora">${item.hora}</span>
            </div>
        `).join('');
    }

    function mostrarConfirmacionExitosa(data) {
        confirmContent.innerHTML = `
            <div class="confirm-card success">
                <div class="confirm-icon">✅</div>
                <h2>¡Entrada Confirmada!</h2>
                <p>Asistencia registrada exitosamente</p>
            </div>
            
            <div class="info-box">
                <div class="info-row">
                    <strong>Nombre:</strong>
                    <span>${escapeHtml(data.nombre)}</span>
                </div>
                <div class="info-row">
                    <strong>No. Empleado:</strong>
                    <span>${escapeHtml(data.num_empleado)}</span>
                </div>
                <div class="info-row">
                    <strong>Agencia:</strong>
                    <span>${escapeHtml(data.agencia)}</span>
                </div>
                <div class="info-row">
                    <strong>Puesto:</strong>
                    <span>${escapeHtml(data.puesto)}</span>
                </div>
                <div class="info-row">
                    <strong>Área:</strong>
                    <span>${escapeHtml(data.area)}</span>
                </div>
                <div class="info-row">
                    <strong>ID Ticket:</strong>
                    <span>${escapeHtml(data.id_ticket)}</span>
                </div>
                <div class="info-row">
                    <strong>Fecha de Entrada:</strong>
                    <span>${escapeHtml(data.fecha_entrada)}</span>
                </div>
            </div>
            
            <button class="btn-new-scan" onclick="nuevoEscaneo()">
                🔄 Escanear Siguiente
            </button>
        `;

        setTimeout(() => {
            if (confirmSection.style.display !== 'none') {
                nuevoEscaneo();
            }
        }, 3000);
    }

    function mostrarYaRegistrado(data) {
        confirmContent.innerHTML = `
            <div class="confirm-card warning">
                <div class="confirm-icon">⚠️</div>
                <h2>Ya Registrado</h2>
                <p>Esta entrada ya había sido registrada hoy</p>
            </div>
            
            <div class="info-box">
                <div class="info-row">
                    <strong>Nombre:</strong>
                    <span>${escapeHtml(data.nombre)}</span>
                </div>
                <div class="info-row">
                    <strong>No. Empleado:</strong>
                    <span>${escapeHtml(data.num_empleado)}</span>
                </div>
                <div class="info-row">
                    <strong>Entrada Anterior:</strong>
                    <span>${escapeHtml(data.fecha_entrada)}</span>
                </div>
            </div>
            
            <button class="btn-new-scan" onclick="nuevoEscaneo()">
                🔄 Escanear Siguiente
            </button>
        `;

        setTimeout(() => {
            if (confirmSection.style.display !== 'none') {
                nuevoEscaneo();
            }
        }, 2000);
    }

    function mostrarError(mensaje) {
        confirmContent.innerHTML = `
            <div class="confirm-card error">
                <div class="confirm-icon">❌</div>
                <h2>Ticket No Válido</h2>
                <p>${escapeHtml(mensaje)}</p>
            </div>
            
            <button class="btn-new-scan" onclick="nuevoEscaneo()">
                🔄 Intentar de Nuevo
            </button>
        `;

        setTimeout(() => {
            if (confirmSection.style.display !== 'none') {
                nuevoEscaneo();
            }
        }, 2000);
    }
});

function nuevoEscaneo() {
    document.getElementById('scanSection').style.display = 'block';
    document.getElementById('confirmSection').style.display = 'none';
    document.getElementById('scanResult').className = 'scan-result info';
    document.getElementById('scanResult').textContent = '✅ Listo para escanear. Pasa el código QR por el lector.';
    document.getElementById('ticketInput').value = '';
    document.getElementById('ticketInput').focus();
    procesando = false;
}