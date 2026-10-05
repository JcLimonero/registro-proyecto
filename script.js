document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('registroForm');
    const formContainer = document.getElementById('formContainer');
    const confirmationContainer = document.getElementById('confirmationContainer');
    const nuevoRegistroBtn = document.getElementById('nuevoRegistro');
    const descargarQRBtn = document.getElementById('descargarQR');

    let qrCodeInstance = null;

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const formData = new FormData(form);
        const datos = {};
        formData.forEach((value, key) => {
            datos[key] = value;
        });

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
                mostrarConfirmacion(datos);
                
                generarQR(idTicket);
                
                await enviarCorreo(datos);
            } else {
                alert('Error al registrar: ' + result.message);
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Error al procesar el registro');
        }
    });

    function generarIdTicket() {
        const timestamp = Date.now().toString(36);
        const random = Math.random().toString(36).substring(2, 8).toUpperCase();
        return `TICKET-${timestamp}-${random}`;
    }

    function mostrarConfirmacion(datos) {
        document.getElementById('ticketId').textContent = datos.idTicket;
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
            colorDark: "#000000",
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
        const qrContainer = document.getElementById('qrCode');
        const ticketId = document.getElementById('ticketId').textContent;
        
        html2canvas(qrContainer).then(canvas => {
            const link = document.createElement('a');
            link.download = `QR-${ticketId}.png`;
            link.href = canvas.toDataURL('image/png');
            link.click();
        });
    });

    nuevoRegistroBtn.addEventListener('click', function() {
        form.reset();
        confirmationContainer.style.display = 'none';
        formContainer.style.display = 'block';
        document.getElementById('qrCode').innerHTML = '';
    });
});