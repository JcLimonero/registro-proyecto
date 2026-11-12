document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('registroForm');
    const recuperarForm = document.getElementById('recuperarForm');
    const pagina = document.querySelector('.pagina');
    const formContainer = document.getElementById('formContainer');
    const confirmationContainer = document.getElementById('confirmationContainer');
    const enlaceRecuperar = document.getElementById('enlaceRecuperar');
    const btnMostrarRecuperar = document.getElementById('btnMostrarRecuperar');
    const btnVolverRegistro = document.getElementById('btnVolverRegistro');
    const btnRecuperarDesdeConfirmacion = document.getElementById('btnRecuperarDesdeConfirmacion');
    const errorRecuperar = document.getElementById('errorRecuperar');
    const grupoEventoRecuperar = document.getElementById('grupoEventoRecuperar');
    const selectEventoRecuperar = document.getElementById('eventoRecuperar');
    const btnRecuperar = document.getElementById('btnRecuperar');
    const confirmAviso = document.getElementById('confirmAviso');

    function togglePaginaConfirmacion(visible) {
        if (pagina) {
            pagina.classList.toggle('pagina--confirmacion', visible);
        }
    }
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
    const imgCartel = document.getElementById('cartelEvento');

    let qrCodeInstance = null;
    let eventosVigentes = [];
    let registroAbierto = false;
    const CARTEL_DEFECTO = 'assets/posada-vanguardia.jpg';

    function urlCartel(evento) {
        return (evento && evento.cartel) ? evento.cartel : CARTEL_DEFECTO;
    }

    function eventoPorId(id) {
        if (!id) return null;
        return eventosVigentes.find(e => String(e.id) === String(id)) || null;
    }

    function eventoPredeterminado(eventos) {
        if (!eventos || !eventos.length) return null;
        return eventos.find(e => e.es_principal) || eventos[0];
    }

    function subtituloEvento(evento) {
        return evento.nombre + ' · ' + fechaLegible(evento.fecha_inicio) + ' – ' + fechaLegible(evento.fecha_fin);
    }

    function actualizarCartel(evento) {
        if (!imgCartel) return;
        const src = urlCartel(evento);
        imgCartel.onerror = function () {
            imgCartel.onerror = null;
            const actual = imgCartel.getAttribute('src') || '';
            if (actual !== CARTEL_DEFECTO && !actual.endsWith(CARTEL_DEFECTO)) {
                imgCartel.src = CARTEL_DEFECTO;
            }
        };
        if (imgCartel.getAttribute('src') !== src) {
            imgCartel.src = src;
        }
        imgCartel.alt = evento && evento.nombre ? `Cartel: ${evento.nombre}` : 'Cartel del evento';
    }

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

    function mostrarErrorRecuperar(texto) {
        errorRecuperar.textContent = texto;
        errorRecuperar.hidden = !texto;
    }

    function mostrarEnlaceRecuperar(visible) {
        if (enlaceRecuperar) {
            enlaceRecuperar.hidden = !visible;
        }
    }

    function vistaRecuperar() {
        if (recuperarForm) {
            recuperarForm.hidden = false;
        }
        form.hidden = true;
        mostrarEnlaceRecuperar(false);
        avisoCerrado.hidden = true;
        estadoCarga.hidden = true;
        mostrarError('');
        mostrarErrorRecuperar('');
        confirmationContainer.style.display = 'none';
        formContainer.style.display = 'block';
        togglePaginaConfirmacion(false);
    }

    function volverDesdeRecuperar() {
        if (recuperarForm) {
            recuperarForm.hidden = true;
        }
        mostrarErrorRecuperar('');
        if (registroAbierto) {
            form.hidden = false;
            mostrarEnlaceRecuperar(true);
            avisoCerrado.hidden = true;
        } else {
            form.hidden = true;
            avisoCerrado.hidden = false;
            mostrarEnlaceRecuperar(true);
        }
        grupoEventoRecuperar.hidden = true;
        selectEventoRecuperar.required = false;
        selectEventoRecuperar.innerHTML = '';
    }

    function mostrarCerrado(texto) {
        registroAbierto = false;
        estadoCarga.hidden = true;
        form.hidden = true;
        if (recuperarForm) {
            recuperarForm.hidden = true;
        }
        eventoActual.hidden = true;
        avisoCerradoTexto.textContent = texto;
        avisoCerrado.hidden = false;
        mostrarEnlaceRecuperar(true);
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

            eventosVigentes = cat.eventos || [];

            if (!cat.abierto || !cat.eventos.length) {
                if (cat.referencia) {
                    actualizarCartel(cat.referencia);
                }
                mostrarCerrado(textoCerrado(cat.referencia));
                return;
            }

            registroAbierto = true;
            if (recuperarForm) {
                recuperarForm.hidden = true;
            }

            llenarSelect(selectAgencia, 'Selecciona una agencia', cat.agencias, a => a.nombre);
            llenarSelect(selectArea, 'Selecciona un área', cat.areas, a => a.nombre);

            const predeterminado = eventoPredeterminado(cat.eventos);
            actualizarCartel(predeterminado);

            if (cat.eventos.length === 1) {
                llenarSelect(selectEvento, '', cat.eventos, e => String(e.id));
                selectEvento.selectedIndex = 1;
                selectEvento.required = false;
                grupoEvento.hidden = true;
                eventoActual.textContent = subtituloEvento(cat.eventos[0]);
                eventoActual.hidden = false;
            } else {
                llenarSelect(selectEvento, 'Selecciona un evento', cat.eventos, e => String(e.id));
                selectEvento.required = true;
                grupoEvento.hidden = false;
                eventoActual.textContent = predeterminado ? subtituloEvento(predeterminado) : '';
                eventoActual.hidden = !predeterminado;
            }

            estadoCarga.hidden = true;
            form.hidden = false;
            mostrarEnlaceRecuperar(true);
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

    selectEvento.addEventListener('change', function() {
        const elegido = eventoPorId(selectEvento.value);
        const mostrar = elegido || eventoPredeterminado(eventosVigentes);
        actualizarCartel(mostrar);
        if (eventosVigentes.length > 1) {
            if (elegido) {
                eventoActual.textContent = subtituloEvento(elegido);
                eventoActual.hidden = false;
            } else if (mostrar) {
                eventoActual.textContent = subtituloEvento(mostrar);
                eventoActual.hidden = false;
            } else {
                eventoActual.hidden = true;
            }
        }
    });

    async function solicitarRecuperacion(correo, reenviarCorreo) {
        const eventoId = selectEventoRecuperar.value || '';
        const payload = { correo: correo.trim(), reenviarCorreo: !!reenviarCorreo };
        if (eventoId) {
            payload.eventoId = eventoId;
        }
        const response = await fetch('backend/recuperar-qr.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        return response.json();
    }

    if (btnMostrarRecuperar) {
        btnMostrarRecuperar.addEventListener('click', vistaRecuperar);
    }
    if (btnVolverRegistro) {
        btnVolverRegistro.addEventListener('click', volverDesdeRecuperar);
    }
    if (btnRecuperarDesdeConfirmacion) {
        btnRecuperarDesdeConfirmacion.addEventListener('click', function () {
            vistaRecuperar();
        });
    }

    if (recuperarForm) {
        recuperarForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            mostrarErrorRecuperar('');
            const correo = document.getElementById('correoRecuperar').value;
            const reenviar = document.getElementById('reenviarCorreo').checked;
            btnRecuperar.disabled = true;
            try {
                const result = await solicitarRecuperacion(correo, reenviar);
                if (result.necesita_evento && result.eventos && result.eventos.length) {
                    if (result.eventos.length === 1) {
                        const unico = result.eventos[0];
                        llenarSelect(selectEventoRecuperar, '', [unico], ev => String(ev.id));
                        selectEventoRecuperar.selectedIndex = 1;
                        grupoEventoRecuperar.hidden = true;
                        const retry = await solicitarRecuperacion(correo, reenviar);
                        if (retry.success && retry.registro) {
                            let aviso = '';
                            if (reenviar && retry.correoEnviado === false) {
                                aviso = retry.mensajeCorreo || 'No se pudo reenviar el correo; aún puedes ver y descargar tu QR aquí.';
                            } else if (reenviar && retry.correoEnviado) {
                                aviso = 'Te reenviamos el correo con tu QR.';
                            }
                            mostrarConfirmacion(retry.registro, aviso);
                            generarQrs(retry.registro.idTicket, retry.registro.eventoUbicacion || '');
                            return;
                        }
                        mostrarErrorRecuperar(retry.message || 'No se encontró el registro.');
                        return;
                    }
                    llenarSelect(selectEventoRecuperar, 'Selecciona el evento', result.eventos, ev => String(ev.id));
                    grupoEventoRecuperar.hidden = false;
                    selectEventoRecuperar.required = true;
                    mostrarErrorRecuperar(result.message || 'Elige el evento de tu registro.');
                    return;
                }
                if (!result.success || !result.registro) {
                    mostrarErrorRecuperar(result.message || 'No se encontró el registro.');
                    return;
                }
                let aviso = '';
                if (reenviar && result.correoEnviado === false) {
                    aviso = result.mensajeCorreo || 'No se pudo reenviar el correo; aún puedes ver y descargar tu QR aquí.';
                } else if (reenviar && result.correoEnviado) {
                    aviso = 'Te reenviamos el correo con tu QR.';
                }
                mostrarConfirmacion(result.registro, aviso);
                generarQrs(result.registro.idTicket, result.registro.eventoUbicacion || '');
            } catch (err) {
                console.error(err);
                mostrarErrorRecuperar('No se pudo completar la recuperación. Intenta de nuevo.');
            } finally {
                btnRecuperar.disabled = false;
            }
        });
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
                generarQrs(idTicket, datos.eventoUbicacion || '');
                
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

    function mostrarConfirmacion(datos, avisoExtra) {
        if (confirmAviso) {
            if (avisoExtra) {
                confirmAviso.textContent = avisoExtra;
                confirmAviso.hidden = false;
            } else {
                confirmAviso.textContent = '';
                confirmAviso.hidden = true;
            }
        }
        document.getElementById('ticketId').textContent = datos.idTicket;
        document.getElementById('confirmEvento').textContent = datos.eventoNombre;
        document.getElementById('confirmInicio').textContent = datos.eventoInicio;
        document.getElementById('confirmFin').textContent = datos.eventoFin;
        const filaUbicacion = document.getElementById('filaUbicacion');
        const ligaUbicacion = document.getElementById('confirmUbicacion');
        if (datos.eventoUbicacion) {
            ligaUbicacion.href = datos.eventoUbicacion;
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
        togglePaginaConfirmacion(true);
    }

    function generarQrs(ticketId, ubicacionUrl) {
        const qrContainer = document.getElementById('qrCode');
        const ubicacionCont = document.getElementById('qrUbicacion');
        const pasePie = document.getElementById('pasePie');
        if (!qrContainer) {
            return;
        }
        qrContainer.innerHTML = '';
        if (ubicacionCont) {
            ubicacionCont.innerHTML = '';
        }

        const pintar = function () {
            qrCodeInstance = new QRCode(qrContainer, {
                text: String(ticketId),
                width: 200,
                height: 200,
                colorDark: '#1a1a1a',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.H
            });
            const url = (ubicacionUrl || '').trim();
            if (url && ubicacionCont && pasePie) {
                pasePie.hidden = false;
                new QRCode(ubicacionCont, {
                    text: url,
                    width: 128,
                    height: 128,
                    colorDark: '#1a1a1a',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.M
                });
            } else if (pasePie) {
                pasePie.hidden = true;
            }
        };

        requestAnimationFrame(pintar);
    }

    function esperarImagenesQr(contenedor) {
        const imgs = contenedor.querySelectorAll('img, canvas');
        return Promise.all(Array.from(imgs).map(function (el) {
            if (el.tagName === 'CANVAS') {
                return Promise.resolve();
            }
            if (el.complete && el.naturalWidth > 0) {
                return Promise.resolve();
            }
            return new Promise(function (resolve) {
                el.onload = resolve;
                el.onerror = resolve;
            });
        }));
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

        esperarImagenesQr(pase).then(function () {
            return html2canvas(pase, {
                backgroundColor: '#ffffff',
                scale: 2,
                useCORS: true,
                logging: false
            });
        }).then(function (canvas) {
            const link = document.createElement('a');
            link.download = nombre;
            link.href = canvas.toDataURL('image/png');
            link.click();
        });
    });

    nuevoRegistroBtn.addEventListener('click', function() {
        form.reset();
        if (recuperarForm) {
            recuperarForm.reset();
            recuperarForm.hidden = true;
        }
        document.getElementById('reenviarCorreo').checked = true;
        grupoEventoRecuperar.hidden = true;
        selectEventoRecuperar.innerHTML = '';
        confirmationContainer.style.display = 'none';
        formContainer.style.display = 'block';
        togglePaginaConfirmacion(false);
        document.getElementById('qrCode').innerHTML = '';
        const qrUb = document.getElementById('qrUbicacion');
        if (qrUb) {
            qrUb.innerHTML = '';
        }
        const pasePie = document.getElementById('pasePie');
        if (pasePie) {
            pasePie.hidden = true;
        }
        cargarCatalogos();
    });
});