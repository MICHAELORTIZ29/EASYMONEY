<?php
if (!isset($_GET['dni'])) {
  echo json_encode(['success' => false, 'mensaje' => 'DNI no recibido']);
  exit;
}

$dni = $_GET['dni'];
$token = 'ZcIxj3EALHdwmYMxbJqC5VsGuboDStCKTEnjvO2l7JMrRPj9WX'; // ← Reemplaza esto con tu token real

$url = "https://apiperu.net/api/dni/$dni?api_token=$token";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code == 200) {
  $data = json_decode($response, true);

  if (isset($data['data']['nombres'])) {
    $nombreCompleto = $data['data']['nombres'] . ' ' . $data['data']['apellido_paterno'] . ' ' . $data['data']['apellido_materno'];
    echo json_encode(['success' => true, 'nombre' => $nombreCompleto]);
  } else {
    echo json_encode(['success' => false, 'mensaje' => 'No se encontró el nombre.']);
  }
} else {
  echo json_encode(['success' => false, 'mensaje' => 'Error al consultar API.']);
}
