<?php
include 'includes/conexion.php';
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Agregar Cliente</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>

<body>
    <div class="container mt-5">
        <h2 class="mb-4">Agregar Cliente</h2>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($_GET['error']) ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['mensaje'])): ?>
            <div class="alert alert-success"><?= htmlspecialchars($_GET['mensaje']) ?></div>
        <?php endif; ?>

        <form action="cliente_agregar_procesar.php" method="POST" id="formCliente" enctype="multipart/form-data">
            <div class="mb-3 row">
                <label for="dni" class="col-sm-2 col-form-label">DNI</label>
                <div class="col-sm-6">
                    <input type="text" class="form-control" id="dni" name="dni" maxlength="8">
                </div>
                <div class="col-sm-4">
                    <button type="button" class="btn btn-primary" id="buscarDNI">Buscar DNI</button>
                </div>
            </div>

            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="manualCheck">
                <label class="form-check-label" for="manualCheck">Ingresar cliente manualmente</label>
            </div>

            <div class="mb-3">
                <label for="nombre" class="form-label">Nombre completo</label>
                <input type="text" class="form-control" id="nombre" name="nombre" readonly>
            </div>

            <div class="mb-3">
                <div id="mensajeExiste" class="text-danger fw-bold"></div>
            </div>

            <!-- Foto DNI -->
            <div class="mb-3">
                <label for="dni_frontal" class="form-label">Foto DNI (Frontal)</label>
                <input type="file" class="form-control" id="dni_frontal" name="dni_frontal" accept="image/*">
            </div>

            <div class="mb-3">
                <label for="dni_posterior" class="form-label">Foto DNI (Posterior)</label>
                <input type="file" class="form-control" id="dni_posterior" name="dni_posterior" accept="image/*">
            </div>

            <!-- Documentos -->
            <div class="mb-3">
                <label for="documentos" class="form-label">Documentos (PDF, Word, Imágenes)</label>
                <input type="file" class="form-control" id="documentos" name="documentos[]" multiple
                    accept=".pdf,.doc,.docx,image/*">
            </div>

            <div class="mb-3">
                <label for="direccion" class="form-label">Dirección del domicilio</label>
                <input type="text" class="form-control" id="direccion" name="direccion">
            </div>

            <div class="mb-3">
                <label for="referencia" class="form-label">Referencia del domicilio</label>
                <input type="text" class="form-control" id="referencia" name="referencia">
            </div>

            <!-- Ubicación -->
            <div class="mb-3">
                <label class="form-label">Ubicación</label>
                <div id="map" style="height: 300px; border: 1px solid #ccc;"></div>
                <input type="hidden" name="latitud" id="latitud">
                <input type="hidden" name="longitud" id="longitud">
            </div>

            <button type="submit" class="btn btn-success" id="btnGuardar">Guardar</button>
            <a href="clientes.php" class="btn btn-secondary">Cancelar</a>
        </form>
    </div>

    <script>
        const manualCheck = document.getElementById('manualCheck');
        const buscarDNI = document.getElementById('buscarDNI');
        const nombreInput = document.getElementById('nombre');
        const btnGuardar = document.getElementById('btnGuardar');
        const mensajeExiste = document.getElementById('mensajeExiste');

        // Cambiar comportamiento según el check manual
manualCheck.addEventListener('change', () => {
    const dniInput = document.getElementById('dni');
    if (manualCheck.checked) {
        dniInput.value = "";
        dniInput.disabled = true; // ✅ no se enviará al servidor
        buscarDNI.disabled = true;
        nombreInput.readOnly = false;
        mensajeExiste.textContent = "";
    } else {
        dniInput.disabled = false;
        buscarDNI.disabled = false;
        nombreInput.readOnly = true;
        nombreInput.value = "";
        mensajeExiste.textContent = "";
    }
});


        document.getElementById('buscarDNI').addEventListener('click', async function () {
            const dni = document.getElementById('dni').value.trim();

            if (dni.length !== 8 || isNaN(dni)) {
                alert("Ingrese un DNI válido de 8 dígitos.");
                return;
            }

            // Verificar si ya existe
            const response = await fetch(`verificar_cliente.php?dni=${dni}`);
            const existe = await response.json();

            if (existe.existe) {
                mensajeExiste.textContent = "⚠️ El cliente con este DNI ya está registrado.";
                nombreInput.value = existe.nombre;
                return;
            }

            // Consultar API RENIEC
            try {
                const apiResponse = await fetch(`https://apiperu.net/api/dni/${dni}?api_token=ZcIxj3EALHdwmYMxbJqC5VsGuboDStCKTEnjvO2l7JMrRPj9WX`);
                const data = await apiResponse.json();

                if (data.success) {
                    const nombres = `${data.data.nombres} ${data.data.apellido_paterno} ${data.data.apellido_materno}`;
                    nombreInput.value = nombres;
                    mensajeExiste.textContent = "";
                    nombreInput.readOnly = true;
                } else {
                    nombreInput.value = "";
                    mensajeExiste.textContent = "DNI no encontrado en RENIEC.";
                }
            } catch (error) {
                nombreInput.value = "";
                mensajeExiste.textContent = "Error al consultar el API.";
            }
        });

        // Validación antes de enviar el formulario
        document.getElementById("formCliente").addEventListener("submit", function (e) {
            if (manualCheck.checked) {
                if (nombreInput.value.trim() === "") {
                    e.preventDefault();
                    alert("Debe ingresar al menos el nombre del cliente en modo manual.");
                }
            } else {
                const dni = document.getElementById('dni').value.trim();
                if (dni.length !== 8 || nombreInput.value.trim() === "") {
                    e.preventDefault();
                    alert("Debe buscar un cliente válido por DNI antes de guardar.");
                }
            }
        });
    </script>

    <!-- Leaflet CSS y JS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>

    <script>
        document.addEventListener("DOMContentLoaded", () => {
            const map = L.map('map').setView([-12.0464, -77.0428], 13);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);

            let marker;

            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    pos => {
                        const lat = pos.coords.latitude;
                        const lng = pos.coords.longitude;

                        map.setView([lat, lng], 16);
                        marker = L.marker([lat, lng], { draggable: true }).addTo(map);

                        document.getElementById("latitud").value = lat;
                        document.getElementById("longitud").value = lng;

                        marker.on("dragend", function (e) {
                            const coords = e.target.getLatLng();
                            document.getElementById("latitud").value = coords.lat;
                            document.getElementById("longitud").value = coords.lng;
                        });
                    },
                    error => {
                        console.error(error);
                        alert("⚠️ No se pudo obtener tu ubicación. Activa el GPS y permite acceso al navegador.");
                    },
                    { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
                );
            }

            map.on("click", function (e) {
                if (marker) {
                    marker.setLatLng(e.latlng);
                } else {
                    marker = L.marker(e.latlng, { draggable: true }).addTo(map);
                }
                document.getElementById("latitud").value = e.latlng.lat;
                document.getElementById("longitud").value = e.latlng.lng;
            });
        });
    </script>
</body>
</html>
