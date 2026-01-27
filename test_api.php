<?php
// Script de prueba de API
$baseUrl = 'http://127.0.0.1:8000';

echo "=== TEST API FASE 1 ===\n\n";

// Test 1: Registrar usuario
echo "1. Registrando usuario...\n";
$ch = curl_init($baseUrl . '/api/register');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'email' => 'usuario1@test.com',
    'password' => 'password123',
    'username' => 'usuario1',
    'latitude' => 40.4168,
    'longitude' => -3.7038,
    'nombre' => 'Juan',
    'apellidos' => 'Pérez'
]));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Status: $httpCode\n";
echo "Response: $response\n\n";

if ($httpCode === 201) {
    echo "✓ Usuario registrado exitosamente\n\n";

    // Test 2: Login
    echo "2. Haciendo login...\n";
    $ch = curl_init($baseUrl . '/api/login');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'email' => 'usuario1@test.com',
        'password' => 'password123'
    ]));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "Status: $httpCode\n";
    echo "Response: $response\n\n";

    $loginData = json_decode($response, true);
    if (isset($loginData['token'])) {
        $token = $loginData['token'];
        echo "✓ Login exitoso. Token recibido\n\n";

        // Test 3: Ver home
        echo "3. Consultando /api/home...\n";
        $ch = curl_init($baseUrl . '/api/home');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        echo "Status: $httpCode\n";
        echo "Response: $response\n\n";

        // Test 4: Ver perfil
        echo "4. Consultando perfil...\n";
        $ch = curl_init($baseUrl . '/api/perfil');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        echo "Status: $httpCode\n";
        echo "Response: $response\n\n";

        echo "✓ FASE 1 completada exitosamente\n";
    }
} else {
    echo "✗ Error en registro\n";
}
