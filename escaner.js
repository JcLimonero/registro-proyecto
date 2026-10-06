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
let temporizadorAviso = null;

// Bloqueo corto para no repetir el mismo QR mientras sigue en cuadro
const BLOQUEO_MISMO_CODIGO_MS = 4000;
const AVISO_YA_REGISTRADO_MS = 4000;

// Cámara: lectura de QR con html5-qrcode (CDN)
const camara = {
    scanner: null,
    activa: false,      // el usuario la quiere encendida
    iniciando: false,
    ultimoCodigo: '',
    ultimoTiempo: 0,
    alLeer: null        // se asigna dentro de DOMContentLoaded
};

function mensajeCamara(texto, tipo) {
    const el = document.getElementById('cameraStatus');
    if (!el) return;
    el.textContent = texto || '';
    el.className = 'camera-status' + (tipo ? ' ' + tipo : '');
}

function actualizarBotonCamara() {
    const btn = document.getElementById('btnCamera');
    const placeholder = document.getElementById('cameraPlaceholder');
    if (btn) {
        btn.textContent = camara.activa ? 'Apagar cámara' : 'Encender cámara';
        btn.disabled = camara.iniciando;
    }
    if (placeholder) {
        placeholder.style.display = camara.activa ? 'none' : 'flex';
    }
}

function textoError(err) {
    if (!err) return '';
    return String(err.name ? err.name + ' ' : '') + String(err.message || err);
}

async function encenderCamara() {
    if (camara.activa || camara.iniciando) return;

    if (typeof Html5Qrcode === 'undefined') {
        mensajeCamara('No se pudo cargar el lector de cámara. Usa el lector o escribe el código.', 'error');
        return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        mensajeCamara('Este navegador no permite usar la cámara (se requiere HTTPS). Usa el lector o escribe el código.', 'error');
        return;
    }

    camara.iniciando = true;
    actualizarBotonCamara();
    mensajeCamara('Solicitando acceso a la cámara...', 'info');

    try {
        if (!camara.scanner) {
            camara.scanner = new Html5Qrcode('qrReader');
        }
        await camara.scanner.start(
            { facingMode: 'environment' },
            {
                fps: 10,
                qrbox: function(ancho, alto) {
                    const lado = Math.floor(Math.min(ancho, alto) * 0.7);
                    return { width: lado, height: lado };
                }
            },
            function(texto) {
                if (camara.alLeer) camara.alLeer(texto);
            },
            function() { /* sin QR en el cuadro: ignorar */ }
        );
        camara.activa = true;
        mensajeCamara('Cámara activa. Apunta al código QR.', 'ok');
    } catch (err) {
        camara.activa = false;
        const detalle = textoError(err);
        console.error('Error de cámara:', err);
        if (/NotAllowed|Permission|denied/i.test(detalle)) {
            mensajeCamara('Permiso de cámara denegado. Habilítalo en el navegador o usa el lector / escribe el código.', 'error');
        } else if (/NotFound|No camera|Requested device not found|OverconstrainedError/i.test(detalle)) {
            mensajeCamara('No se encontró ninguna cámara. Usa el lector o escribe el código.', 'error');
        } else if (/NotReadable|TrackStart|in use/i.test(detalle)) {
            mensajeCamara('La cámara está en uso por otra aplicación. Ciérrala o usa el lector.', 'error');
        } else {
            mensajeCamara('No se pudo iniciar la cámara. Usa el lector o escribe el código.', 'error');
        }
    } finally {
        camara.iniciando = false;
        actualizarBotonCamara();
    }
}

async function apagarCamara() {
    if (!camara.scanner || !camara.activa) {
        camara.activa = false;
        actualizarBotonCamara();
        return;
    }
    camara.activa = false;
    try {
        await camara.scanner.stop();
        camara.scanner.clear();
    } catch (err) {
        console.error('Error al apagar la cámara:', err);
    }
    mensajeCamara('Cámara apagada.', '');
    actualizarBotonCamara();
}

function pausarCamara() {
    if (!camara.scanner || !camara.activa) return;
    try {
        camara.scanner.pause(true);
    } catch (err) {
        // ya estaba en pausa o no está escaneando
    }
}

async function reanudarCamara() {
    if (!camara.scanner || !camara.activa) return;
    // Bloqueo corto: el mismo QR sigue en cuadro al volver a escanear
    camara.ultimoTiempo = Date.now();
    try {
        camara.scanner.resume();
    } catch (err) {
        // Si no se pudo reanudar, reiniciar la cámara
        camara.activa = false;
        try { await camara.scanner.stop(); } catch (e) { /* ignorar */ }
        await encenderCamara();
    }
}

function lecturaCamara(texto, validar) {
    const codigo = String(texto || '').trim();
    if (!codigo || procesando) return;

    const ahora = Date.now();
    if (codigo === camara.ultimoCodigo && ahora - camara.ultimoTiempo < BLOQUEO_MISMO_CODIGO_MS) {
        return;
    }
    camara.ultimoCodigo = codigo;
    camara.ultimoTiempo = ahora;

    procesando = true;
    validar(codigo);
}

// En pantallas táctiles no se fuerza el foco (abriría el teclado en cada momento)
const esTactil = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;

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
        if (!esTactil && confirmSection.style.display === 'none') {
            ticketInput.focus();
        }
    }

    setInterval(mantenerFoco, 500);
    document.addEventListener('click', function(e) {
        if (e.target.closest && e.target.closest('#btnCamera')) return;
        mantenerFoco();
    });

    camara.alLeer = function(texto) {
        lecturaCamara(texto, validarTicket);
    };

    document.getElementById('btnCamera').addEventListener('click', function() {
        if (camara.activa) {
            apagarCamara();
        } else {
            encenderCamara();
        }
    });
    actualizarBotonCamara();
    encenderCamara();

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
        pausarCamara();
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
            reanudarCamara();
            if (!esTactil) ticketInput.focus();
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

        programarRegreso(3000);
    }

    function mostrarYaRegistrado(data) {
        confirmContent.innerHTML = `
            <div class="confirm-card warning">
                <div class="confirm-icon">⚠️</div>
                <h2>Este código ya fue escaneado</h2>
                <p class="aviso-nombre">${escapeHtml(data.nombre)}</p>
                <p>Ya entró. Esta lectura no se registra otra vez.</p>
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
                    <strong>Entrada anterior:</strong>
                    <span>${escapeHtml(data.fecha_entrada)}</span>
                </div>
            </div>
            
            <button class="btn-new-scan" onclick="nuevoEscaneo()">
                Escanear siguiente
            </button>
        `;

        programarRegreso(AVISO_YA_REGISTRADO_MS);
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

        programarRegreso(2000);
    }

    function programarRegreso(ms) {
        clearTimeout(temporizadorAviso);
        temporizadorAviso = setTimeout(() => {
            if (confirmSection.style.display !== 'none') {
                nuevoEscaneo();
            }
        }, ms);
    }
});

function nuevoEscaneo() {
    clearTimeout(temporizadorAviso);
    document.getElementById('scanSection').style.display = 'block';
    document.getElementById('confirmSection').style.display = 'none';
    document.getElementById('scanResult').className = 'scan-result info';
    document.getElementById('scanResult').textContent = '✅ Listo para escanear. Escanea el QR con la cámara o el lector.';
    document.getElementById('ticketInput').value = '';
    if (!esTactil) document.getElementById('ticketInput').focus();
    procesando = false;
    reanudarCamara();
}