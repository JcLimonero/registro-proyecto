(function () {
    const dialogCat = document.getElementById('dialogCatalogo');
    const formCat = document.getElementById('formDialogCatalogo');
    if (dialogCat && formCat) {
        const dlgSimple = formCat.querySelector('.dlg-simple');
        const dlgEvento = formCat.querySelector('.dlg-evento');
        const dlgTitulo = document.getElementById('dialogCatalogoTitulo');
        const dlgCartelPrev = document.getElementById('dlg-cartel-prev');
        const dlgSubmit = document.getElementById('dlg-submit');
        const dlgId = document.getElementById('dlg-id');

        function cerrarCatalogo() {
            if (dialogCat.open) {
                dialogCat.close();
            }
        }

        document.getElementById('dialogCatalogoCerrar')?.addEventListener('click', cerrarCatalogo);
        document.getElementById('dialogCatalogoCancelar')?.addEventListener('click', cerrarCatalogo);
        dialogCat.addEventListener('cancel', function (e) {
            e.preventDefault();
            cerrarCatalogo();
        });
        dialogCat.addEventListener('click', function (e) {
            if (e.target === dialogCat) {
                cerrarCatalogo();
            }
        });

        function setVal(id, val) {
            const el = document.getElementById(id);
            if (el) {
                el.value = val ?? '';
            }
        }

        function toggleDlgCampos(modo) {
            const esEvento = modo === 'evento';
            dlgSimple.hidden = esEvento;
            dlgEvento.hidden = !esEvento;
            dlgSimple.querySelectorAll('input, select, textarea').forEach(function (el) {
                el.disabled = esEvento;
                if (el.id === 'dlg-nombre') {
                    el.required = !esEvento;
                    if (esEvento) {
                        el.removeAttribute('name');
                    } else {
                        el.setAttribute('name', 'nombre');
                    }
                }
            });
            dlgEvento.querySelectorAll('input, select, textarea').forEach(function (el) {
                const esNombre = el.id === 'dlg-ev-nombre';
                if (esNombre) {
                    el.disabled = !esEvento;
                    if (esEvento) {
                        el.setAttribute('name', 'nombre');
                        el.required = true;
                    } else {
                        el.removeAttribute('name');
                        el.required = false;
                    }
                    return;
                }
                el.disabled = !esEvento;
                if (!esEvento && el.type !== 'file') {
                    el.required = false;
                } else if (esEvento && el.type === 'datetime-local') {
                    el.required = true;
                }
            });
        }

        function limpiarFormularioCatalogo(esEvento) {
            if (esEvento) {
                ['dlg-ev-nombre', 'dlg-ev-fecha-inicio', 'dlg-ev-fecha-fin', 'dlg-ev-reg-inicio', 'dlg-ev-reg-fin', 'dlg-ev-ubicacion'].forEach(function (id) {
                    setVal(id, '');
                });
                const chk = document.getElementById('dlg-ev-principal');
                if (chk) {
                    chk.checked = false;
                }
                const file = document.getElementById('dlg-ev-cartel');
                if (file) {
                    file.value = '';
                }
                if (dlgCartelPrev) {
                    dlgCartelPrev.removeAttribute('src');
                    dlgCartelPrev.hidden = true;
                }
            } else {
                setVal('dlg-nombre', '');
            }
        }

        function abrirDialogo(btn, esNuevo) {
            const tipo = btn.dataset.tipo;
            formCat.action = btn.dataset.action || formCat.action;
            setVal('dlg-p', btn.dataset.p);
            if (dlgId) {
                dlgId.value = esNuevo ? '' : (btn.dataset.id || '');
                dlgId.name = esNuevo ? '' : 'id';
                if (esNuevo) {
                    dlgId.removeAttribute('name');
                } else {
                    dlgId.setAttribute('name', 'id');
                }
            }
            if (dlgSubmit) {
                dlgSubmit.value = btn.dataset.accionValor || 'guardar_agencia';
                dlgSubmit.textContent = esNuevo ? 'Agregar' : 'Guardar';
            }

            const max = parseInt(btn.dataset.max, 10);
            const nombreInput = document.getElementById('dlg-nombre');
            if (nombreInput && max > 0) {
                nombreInput.maxLength = max;
            }
            const evNombre = document.getElementById('dlg-ev-nombre');
            if (evNombre && max > 0 && tipo === 'evento') {
                evNombre.maxLength = max;
            }

            if (tipo === 'evento') {
                toggleDlgCampos('evento');
                dlgTitulo.textContent = esNuevo ? (btn.dataset.tituloNuevo || 'Nuevo evento') : 'Editar evento';
                if (esNuevo) {
                    limpiarFormularioCatalogo(true);
                } else {
                    let data;
                    try {
                        data = JSON.parse(btn.dataset.evento || '{}');
                    } catch (_e) {
                        return;
                    }
                    setVal('dlg-ev-nombre', data.nombre);
                    setVal('dlg-ev-fecha-inicio', data.fecha_inicio);
                    setVal('dlg-ev-fecha-fin', data.fecha_fin);
                    setVal('dlg-ev-reg-inicio', data.registro_inicio);
                    setVal('dlg-ev-reg-fin', data.registro_fin);
                    setVal('dlg-ev-ubicacion', data.ubicacion);
                    const chk = document.getElementById('dlg-ev-principal');
                    if (chk) {
                        chk.checked = !!data.es_principal;
                    }
                    const file = document.getElementById('dlg-ev-cartel');
                    if (file) {
                        file.value = '';
                    }
                    if (dlgCartelPrev) {
                        if (data.cartel) {
                            dlgCartelPrev.src = data.cartel;
                            dlgCartelPrev.hidden = false;
                        } else {
                            dlgCartelPrev.removeAttribute('src');
                            dlgCartelPrev.hidden = true;
                        }
                    }
                }
            } else {
                toggleDlgCampos('simple');
                dlgTitulo.textContent = esNuevo
                    ? (btn.dataset.tituloNuevo || 'Nuevo')
                    : (tipo === 'agencia' ? 'Editar agencia' : 'Editar área');
                if (esNuevo) {
                    limpiarFormularioCatalogo(false);
                } else {
                    setVal('dlg-nombre', btn.dataset.nombre);
                }
            }

            dialogCat.showModal();
        }

        toggleDlgCampos('simple');

        document.querySelectorAll('.js-editar-catalogo').forEach(function (btn) {
            btn.addEventListener('click', function () {
                abrirDialogo(btn, false);
            });
        });

        document.querySelectorAll('.js-nuevo-catalogo').forEach(function (btn) {
            btn.addEventListener('click', function () {
                abrirDialogo(btn, true);
            });
        });
    }

    const dialogReg = document.getElementById('dialogRegistro');
    if (dialogReg) {
        function cerrarRegistro() {
            if (dialogReg.open) {
                dialogReg.close();
            }
        }
        document.getElementById('dialogRegistroCerrar')?.addEventListener('click', function () {
            const cancel = document.getElementById('dialogRegistroCancelar');
            if (cancel) {
                window.location.href = cancel.getAttribute('href');
                return;
            }
            cerrarRegistro();
        });
        dialogReg.addEventListener('click', function (e) {
            if (e.target === dialogReg) {
                const cancel = document.getElementById('dialogRegistroCancelar');
                if (cancel) {
                    window.location.href = cancel.getAttribute('href');
                }
            }
        });
        if (dialogReg.dataset.autoopen === '1') {
            dialogReg.showModal();
        }
    }
})();
