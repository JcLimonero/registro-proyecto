document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('registroForm');
    const formContainer = document.getElementById('formContainer');
    const confirmationContainer = document.getElementById('confirmationContainer');
    const nuevoRegistroBtn = document.getElementById('nuevoRegistro');
    const descargarQRBtn = document.getElementById('descargarQR');

    const estadoCarga = document.getElementById('estadoCarga');
    const avisoCerrado = document.getElementById('avisoCerrado');
    const avisoCerradoTexto = document.getElementById('avisoCerradoTexto');
    const eventoActual = document.getElementById('eventoActual');
    const grupoEvento = document.getElementById('grupoEvento');
    const selectEvento = document.getElementById('eventoId');
    const selectAgencia = document.getElementById('agencia');
    const selectArea = document.getElementById('area');
    const errorForm = document.getElementById('errorForm');
    const btnRegistrar = document.getElementById('btnRegistrar');

    let qrCodeInstance = null;

    // «2026-10-05 18:30:00» -> «05/10/2026 18:30» (sin pasar por Date: ya viene en hora de México)
    function fechaLegible(valor) {
        const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(valor || '');
        return m ? `${m[3]}/${m[2]}/${m[1]} ${m[4]}:${m[5]}` : '';
    }

    function llenarSelect(select, placeholder, items, valorDe) {
        select.innerHTML = '';
        const vacio = document.createElement('option');
        vacio.value = '';
        vacio.textContent = placeholder;
        select.appendChild(vacio);
        items.forEach(item => {
            const op = document.createElement('option');
            op.value = valorDe(item);
            op.textContent = item.nombre;
            select.appendChild(op);
        });
    }

    function mostrarError(texto) {
        errorForm.textContent = texto;
        errorForm.hidden = !texto;
    }

    function mostrarCerrado(texto) {
        estadoCarga.hidden = true;
        form.hidden = true;
        eventoActual.hidden = true;
        avisoCerradoTexto.textContent = texto;
        avisoCerrado.hidden = false;
    }

    function textoCerrado(ref) {
        if (!ref) {
            return 'Por ahora no hay un evento con registro abierto.';
        }
        if (ref.tipo === 'proximo') {
            return `El registro para «${ref.nombre}» abre el ${fechaLegible(ref.registro_inicio)} (hora de México). El evento es del ${fechaLegible(ref.fecha_inicio)} al ${fechaLegible(ref.fecha_fin)}.`;
        }
        return `El registro para «${ref.nombre}» cerró el ${fechaLegible(ref.registro_fin)} (hora de México).`;
    }

    // Agencias, áreas y eventos vigentes vienen del admin; fuera del periodo no se envía nada.
    async function cargarCatalogos() {
        form.hidden = true;
        avisoCerrado.hidden = true;
        eventoActual.hidden = true;
        mostrarError('');
        estadoCarga.textContent = 'Cargando…';
        estadoCarga.hidden = false;
        btnRegistrar.disabled = true;

        try {
            const response = await fetch('backend/catalogos.php', { cache: 'no-store' });
            const cat = await response.json();
            if (!response.ok || !cat.success) {
                throw new Error(cat.message || 'Error al cargar');
            }

            if (!cat.abierto || !cat.eventos.length) {
                mostrarCerrado(textoCerrado(cat.referencia));
                return;
            }

            llenarSelect(selectAgencia, 'Selecciona una agencia', cat.agencias, a => a.nombre);
            llenarSelect(selectArea, 'Selecciona un área', cat.areas, a => a.nombre);

            if (cat.eventos.length === 1) {
                llenarSelect(selectEvento, '', cat.eventos, e => String(e.id));
                selectEvento.selectedIndex = 1;
                selectEvento.required = false;
                grupoEvento.hidden = true;
                eventoActual.textContent = cat.eventos[0].nombre + ' · ' + fechaLegible(cat.eventos[0].fecha_inicio) + ' – ' + fechaLegible(cat.eventos[0].fecha_fin);
                eventoActual.hidden = false;
            } else {
                llenarSelect(selectEvento, 'Selecciona un evento', cat.eventos, e => String(e.id));
                selectEvento.required = true;
                grupoEvento.hidden = false;
            }

            estadoCarga.hidden = true;
            form.hidden = false;
            if (!cat.agencias.length || !cat.areas.length) {
                mostrarError('El registro aún no está disponible: faltan agencias o áreas por configurar.');
            } else {
                btnRegistrar.disabled = false;
            }
        } catch (error) {
            console.error('Error:', error);
            estadoCarga.textContent = 'No se pudo cargar el registro. Recarga la página para intentar de nuevo.';
        }
    }

    cargarCatalogos();

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        mostrarError('');
        
        const formData = new FormData(form);
        const datos = {};
        formData.forEach((value, key) => {
            datos[key] = value;
        });
        datos.eventoNombre = selectEvento.selectedIndex > 0
            ? selectEvento.options[selectEvento.selectedIndex].textContent : '';

        const fechaRegistro = new Date().toLocaleString('es-MX', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
        datos.fechaRegistro = fechaRegistro;

        const idTicket = generarIdTicket();
        datos.idTicket = idTicket;

        btnRegistrar.disabled = true;
        try {
            const response = await fetch('backend/registrar.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(datos)
            });

            const result = await response.json();

            if (result.success) {
                datos.eventoNombre = result.evento || datos.eventoNombre;
                datos.eventoInicio = fechaLegible(result.fecha_inicio);
                datos.eventoFin = fechaLegible(result.fecha_fin);
                datos.eventoUbicacion = result.ubicacion || '';
                mostrarConfirmacion(datos);
                
                generarQR(idTicket);
                
                await enviarCorreo(datos);
            } else {
                mostrarError(result.message || 'No se pudo completar el registro.');
                // Si el periodo se cerró mientras llenabas el formulario, se actualiza la pantalla
                if (/cerrado|vigente/i.test(result.message || '')) {
                    await cargarCatalogos();
                    if (!form.hidden) {
                        mostrarError(result.message);
                    }
                    return;
                }
            }
        } catch (error) {
            console.error('Error:', error);
            mostrarError('Error al procesar el registro');
        }
        btnRegistrar.disabled = false;
    });

    function generarIdTicket() {
        const timestamp = Date.now().toString(36);
        const random = Math.random().toString(36).substring(2, 8).toUpperCase();
        return `TICKET-${timestamp}-${random}`;
    }

    function mostrarConfirmacion(datos) {
        document.getElementById('ticketId').textContent = datos.idTicket;
        document.getElementById('confirmEvento').textContent = datos.eventoNombre;
        document.getElementById('confirmInicio').textContent = datos.eventoInicio;
        document.getElementById('confirmFin').textContent = datos.eventoFin;
        const filaUbicacion = document.getElementById('filaUbicacion');
        const ligaUbicacion = document.getElementById('confirmUbicacion');
        if (datos.eventoUbicacion) {
            ligaUbicacion.href = datos.eventoUbicacion;
            ligaUbicacion.textContent = datos.eventoUbicacion;
            filaUbicacion.hidden = false;
        } else {
            ligaUbicacion.removeAttribute('href');
            ligaUbicacion.textContent = '';
            filaUbicacion.hidden = true;
        }
        document.getElementById('fechaRegistro').textContent = datos.fechaRegistro;
        document.getElementById('confirmNombre').textContent = datos.nombre;
        document.getElementById('confirmNumEmpleado').textContent = datos.numEmpleado;
        document.getElementById('confirmAgencia').textContent = datos.agencia;
        document.getElementById('confirmPuesto').textContent = datos.puesto;
        document.getElementById('confirmArea').textContent = datos.area;
        document.getElementById('confirmCorreo').textContent = datos.correo;

        formContainer.style.display = 'none';
        confirmationContainer.style.display = 'block';
    }

    function generarQR(texto) {
        const qrContainer = document.getElementById('qrCode');
        qrContainer.innerHTML = '';
        
        qrCodeInstance = new QRCode(qrContainer, {
            text: texto,
            width: 200,
            height: 200,
            colorDark: "#1a1a1a",
            colorLight: "#ffffff",
            correctLevel: QRCode.CorrectLevel.H
        });
    }

    async function enviarCorreo(datos) {
        try {
            const response = await fetch('backend/enviar-correo.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(datos)
            });
            
            const result = await response.json();
            if (!result.success) {
                console.warn('Error al enviar correo:', result.message);
            }
        } catch (error) {
            console.error('Error al enviar correo:', error);
        }
    }

    descargarQRBtn.addEventListener('click', function() {
        const pase = document.getElementById('pase');
        const ticketId = document.getElementById('ticketId').textContent;
        const evento = document.getElementById('confirmEvento').textContent;
        const nombre = 'QR-' + [evento, ticketId].filter(Boolean).join('-').replace(/[^\w.\-áéíóúñÁÉÍÓÚÑ]+/g, '_') + '.png';

        html2canvas(pase, { backgroundColor: '#ffffff', scale: 2 }).then(canvas => {
            const link = document.createElement('a');
            link.download = nombre;
            link.href = canvas.toDataURL('image/png');
            link.click();
        });
    });

    nuevoRegistroBtn.addEventListener('click', function() {
        form.reset();
        confirmationContainer.style.display = 'none';
        formContainer.style.display = 'block';
        document.getElementById('qrCode').innerHTML = '';
        cargarCatalogos();
    });
});