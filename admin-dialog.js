(function () {
    const dialogCat = document.getElementById('dialogCatalogo');
    const formCat = document.getElementById('formDialogCatalogo');
    if (dialogCat && formCat) {
        const dlgSimple = formCat.querySelector('.dlg-simple');
        const dlgEvento = formCat.querySelector('.dlg-evento');
        const dlgTitulo = document.getElementById('dialogCatalogoTitulo');
        const dlgCartelPrev = document.getElementById('dlg-cartel-prev');

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

        toggleDlgCampos('simple');

        document.querySelectorAll('.js-editar-catalogo').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const tipo = btn.dataset.tipo;
                formCat.action = btn.dataset.action || formCat.action;
                setVal('dlg-id', btn.dataset.id);
                setVal('dlg-p', btn.dataset.p);
                const submit = document.getElementById('dlg-submit');
                if (submit) {
                    submit.value = btn.dataset.accionValor || 'guardar_agencia';
                }

                if (tipo === 'evento') {
                    let data;
                    try {
                        data = JSON.parse(btn.dataset.evento || '{}');
                    } catch (_e) {
                        return;
                    }
                    toggleDlgCampos('evento');
                    dlgTitulo.textContent = 'Editar evento';
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
                } else {
                    toggleDlgCampos('simple');
                    dlgTitulo.textContent = tipo === 'agencia' ? 'Editar agencia' : 'Editar área';
                    setVal('dlg-nombre', btn.dataset.nombre);
                }

                dialogCat.showModal();
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
