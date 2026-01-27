# 🔐 CÓMO FUNCIONAN LOS TOKENS Y ENDPOINTS

## 📋 ÍNDICE
1. [Sistema de Tokens](#sistema-de-tokens)
2. [Flujo de Autenticación](#flujo-de-autenticación)
3. [Sistema de Geolocalización](#sistema-de-geolocalización)
4. [Sistema de Invitaciones a Salas](#sistema-de-invitaciones-a-salas)
5. [Cómo Funcionan los Endpoints](#endpoints)
6. [Ejemplos Prácticos](#ejemplos-prácticos)

---

## 🎫 SISTEMA DE TOKENS

### ¿Qué es un Token?
Un **token** es como una "llave digital" que identifica a un usuario en cada petición a la API.

### Características de Nuestros Tokens

```php
// Entidad ApiToken (src/Entity/ApiToken.php)

class ApiToken {
    private string $token;           // Token de 64 caracteres hexadecimales
    private Usuarios $user;          // Usuario dueño del token
    private DateTime $expiresAt;     // Fecha de expiración (24 horas)
    private DateTime $createdAt;     // Fecha de creación
}
```

**Características:**
- ✅ **Longitud**: 64 caracteres hexadecimales
- ✅ **Generación**: `bin2hex(random_bytes(32))` - Completamente aleatorio
- ✅ **Único**: Campo `unique` en base de datos
- ✅ **Temporal**: Expira en 24 horas
- ✅ **Seguro**: Almacenado en base de datos, no en cookies

**Ejemplo de token real:**
```
f69d588286251cd31fd05efadddd6deaed2023980e6a082b66c0bac6605bd457
```

---

## 🔄 FLUJO DE AUTENTICACIÓN

### PASO 1: Registro de Usuario

```http
POST /api/register
Content-Type: application/json

{
  "email": "usuario@example.com",
  "password": "miPassword123",
  "username": "usuario1",
  "nombre": "Juan",
  "apellidos": "Pérez",
  "latitude": 40.4168,
  "longitude": -3.7038
}
```

**¿Qué sucede internamente?**

```php
// src/Controller/SecurityController.php - register()

1. Valida que todos los campos requeridos existan
2. Verifica que el email no esté registrado
3. Crea el usuario en la BD
4. Hashea la contraseña con bcrypt
5. Genera un token API automáticamente:
   
   $apiToken = new ApiToken();
   $apiToken->setUser($user);
   // Token generado: "f69d588286..."
   // Expira: +24 horas desde ahora
   
6. Guarda el token en la tabla api_token
7. Devuelve el token al cliente
```

**Respuesta:**
```json
{
  "success": true,
  "data": {
    "user": {
      "id": 1,
      "email": "usuario@example.com",
      "username": "usuario1"
    },
    "token": "f69d588286251cd31fd05efadddd6deaed2023980e6a082b66c0bac6605bd457"
  },
  "error": null,
  "metadata": {
    "timestamp": "2026-01-15T10:30:00+01:00"
  }
}
```

**💾 En la base de datos:**
```sql
-- Tabla: api_token
id | token                                                            | user_id | expires_at          | created_at
1  | f69d588286251cd31fd05efadddd6deaed2023980e6a082b66c0bac6605bd457 | 1       | 2026-01-16 10:30:00 | 2026-01-15 10:30:00
```

---

### PASO 2: Login (Si Ya Tienes Cuenta)

```http
POST /api/login
Content-Type: application/json

{
  "email": "usuario@example.com",
  "password": "miPassword123"
}
```

**¿Qué sucede internamente?**

```php
// src/Controller/SecurityController.php - login()

1. Busca el usuario por email
2. Verifica la contraseña con password_verify()
3. Invalida tokens antiguos del usuario (opcional)
4. Genera un NUEVO token
5. Guarda el nuevo token en BD
6. Devuelve el nuevo token
```

**Respuesta:**
```json
{
  "success": true,
  "data": {
    "token": "acc308f048d63826317748c013ee2f17b23769ad114ca768bce0fb25b9e4cd0c",
    "user": {
      "id": 1,
      "email": "usuario@example.com",
      "username": "usuario1",
      "nombre": "Juan"
    }
  }
}
```

---

### PASO 3: Usar el Token en Peticiones

**Todas las peticiones protegidas requieren el token:**

```http
GET /api/home
Authorization: Bearer acc308f048d63826317748c013ee2f17b23769ad114ca768bce0fb25b9e4cd0c
```

**Formato del header:**
```
Authorization: Bearer {TU_TOKEN_AQUÍ}
```

**Alternativa (también válida):**
```
X-API-TOKEN: {TU_TOKEN_AQUÍ}
```

---

## � SISTEMA DE GEOLOCALIZACIÓN

### ¿Cómo se Obtiene y Almacena la Ubicación del Usuario?

La geolocalización es fundamental en esta aplicación para encontrar usuarios cercanos dentro de un **radio de 5km**.

---

### 1️⃣ ALMACENAMIENTO EN BASE DE DATOS

Cada usuario tiene dos campos de coordenadas:

```php
// src/Entity/Usuarios.php

class Usuarios implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Column(type: 'decimal', precision: 10, scale: 8, nullable: true)]
    private ?string $latitude = null;   // Ejemplo: 40.41678900
    
    #[ORM\Column(type: 'decimal', precision: 11, scale: 8, nullable: true)]
    private ?string $longitude = null;  // Ejemplo: -3.70379400
}
```

**Características:**
- ✅ **Latitude**: -90 a +90 (Norte/Sur)
- ✅ **Longitude**: -180 a +180 (Este/Oeste)
- ✅ **Precisión**: 8 decimales (~1.1mm de precisión)
- ✅ **Tipo DECIMAL**: Evita errores de redondeo de FLOAT

**Ejemplo en BD:**
```sql
id | email              | latitude    | longitude    | last_activity
1  | usuario1@test.com | 40.41678900 | -3.70379400  | 2026-01-15 10:30:00
2  | usuario2@test.com | 40.41750000 | -3.70450000  | 2026-01-15 10:31:00
```

---

### 2️⃣ OBTENCIÓN INICIAL: Registro

La primera vez que se registra un usuario, **DEBE proporcionar su ubicación**:

```http
POST /api/register
Content-Type: application/json

{
  "email": "usuario@example.com",
  "password": "miPassword123",
  "username": "usuario1",
  "nombre": "Juan",
  "apellidos": "Pérez",
  "latitude": 40.4168,     ← OBLIGATORIO
  "longitude": -3.7038     ← OBLIGATORIO
}
```

**¿Cómo obtener estas coordenadas en el cliente?**

#### JavaScript (Navegador Web):
```javascript
// Solicitar permisos de geolocalización al usuario
if ("geolocation" in navigator) {
    navigator.geolocation.getCurrentPosition(
        // Éxito
        (position) => {
            const lat = position.coords.latitude;
            const lon = position.coords.longitude;
            const accuracy = position.coords.accuracy; // Precisión en metros
            
            console.log(`Ubicación obtenida: ${lat}, ${lon}`);
            console.log(`Precisión: ${accuracy} metros`);
            
            // Ahora puedes enviar al servidor
            registrarUsuario(lat, lon);
        },
        // Error
        (error) => {
            console.error('Error obteniendo ubicación:', error.message);
            // error.code puede ser:
            // 1 = PERMISSION_DENIED (usuario rechazó)
            // 2 = POSITION_UNAVAILABLE (no disponible)
            // 3 = TIMEOUT (tiempo agotado)
        },
        // Opciones
        {
            enableHighAccuracy: true,  // Mayor precisión (usa GPS si está disponible)
            timeout: 10000,            // 10 segundos máximo
            maximumAge: 0              // No usar ubicación en caché
        }
    );
} else {
    console.error('Geolocalización no soportada');
}

// Función para registrar con ubicación
async function registrarUsuario(lat, lon) {
    const response = await fetch('http://localhost:8000/api/register', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            email: 'usuario@test.com',
            password: 'pass123',
            username: 'usuario1',
            nombre: 'Juan',
            apellidos: 'Pérez',
            latitude: lat,
            longitude: lon
        })
    });
    
    const data = await response.json();
    console.log('Token:', data.data.token);
}
```

#### React Native / Expo:
```javascript
import * as Location from 'expo-location';

async function obtenerUbicacion() {
    // Solicitar permisos
    let { status } = await Location.requestForegroundPermissionsAsync();
    
    if (status !== 'granted') {
        console.error('Permiso de ubicación denegado');
        return;
    }
    
    // Obtener ubicación actual
    let location = await Location.getCurrentPositionAsync({
        accuracy: Location.Accuracy.High
    });
    
    const lat = location.coords.latitude;
    const lon = location.coords.longitude;
    
    console.log(`Ubicación: ${lat}, ${lon}`);
    
    // Registrar usuario
    await registrarUsuario(lat, lon);
}
```

#### Android (Java/Kotlin):
```kotlin
// MainActivity.kt
import android.location.Location
import com.google.android.gms.location.*

private lateinit var fusedLocationClient: FusedLocationProviderClient

override fun onCreate(savedInstanceState: Bundle?) {
    super.onCreate(savedInstanceState)
    
    fusedLocationClient = LocationServices.getFusedLocationProviderClient(this)
    
    // Solicitar ubicación
    fusedLocationClient.lastLocation.addOnSuccessListener { location: Location? ->
        location?.let {
            val lat = it.latitude
            val lon = it.longitude
            
            // Registrar usuario
            registrarUsuario(lat, lon)
        }
    }
}
```

#### iOS (Swift):
```swift
import CoreLocation

class LocationManager: NSObject, CLLocationManagerDelegate {
    let locationManager = CLLocationManager()
    
    func obtenerUbicacion() {
        locationManager.delegate = self
        locationManager.requestWhenInUseAuthorization()
        locationManager.requestLocation()
    }
    
    func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        if let location = locations.first {
            let lat = location.coordinate.latitude
            let lon = location.coordinate.longitude
            
            print("Ubicación: \(lat), \(lon)")
            
            // Registrar usuario
            registrarUsuario(lat: lat, lon: lon)
        }
    }
}
```

---

### 3️⃣ ACTUALIZACIÓN DE UBICACIÓN

Los usuarios pueden actualizar su ubicación en cualquier momento:

```http
POST /api/actualizar
Authorization: Bearer {token}
Content-Type: application/json

{
  "latitude": 40.4170,
  "longitude": -3.7040
}
```

**¿Cuándo actualizar?**
- ✅ Cuando el usuario se mueve (cada X minutos)
- ✅ Al abrir la app
- ✅ Antes de buscar usuarios cercanos
- ✅ Antes de enviar mensajes globales

**Código interno del endpoint:**
```php
// src/Controller/ActualizarController.php

public function actualizar(Request $request): JsonResponse
{
    $user = $this->getUser();
    $data = json_decode($request->getContent(), true);
    
    if (isset($data['latitude']) && isset($data['longitude'])) {
        $lat = (float)$data['latitude'];
        $lon = (float)$data['longitude'];
        
        // Validar rango válido
        if ($lat < -90 || $lat > 90) {
            return $this->json(['error' => 'Latitud inválida'], 400);
        }
        
        if ($lon < -180 || $lon > 180) {
            return $this->json(['error' => 'Longitud inválida'], 400);
        }
        
        // Actualizar en BD
        $user->setLatitude((string)$lat);
        $user->setLongitude((string)$lon);
        $user->updateActivity();
        
        $this->entityManager->flush();
    }
    
    // Devolver usuarios cercanos actualizados
    return $this->json([...]);
}
```

---

### 4️⃣ CÁLCULO DE DISTANCIAS: Fórmula de Haversine

Para encontrar usuarios dentro de 5km, usamos la **Fórmula de Haversine**.

#### ¿Qué es Haversine?

Es una fórmula matemática que calcula la distancia más corta entre dos puntos en la superficie de una esfera (la Tierra).

**Fórmula matemática:**
```
d = 2r × arcsin(√(sin²((lat2-lat1)/2) + cos(lat1) × cos(lat2) × sin²((lon2-lon1)/2)))

Donde:
- d = distancia en km
- r = radio de la Tierra (6371 km)
- lat1, lon1 = coordenadas del usuario actual
- lat2, lon2 = coordenadas del usuario a comparar
```

#### Implementación en MySQL

```sql
-- src/Controller/HomeController.php

SELECT 
    u.id,
    u.username,
    u.nombre,
    u.latitude,
    u.longitude,
    -- Cálculo de Haversine en MySQL:
    (
        6371 * ACOS(
            COS(RADIANS(:userLat))           -- Coseno de latitud del usuario actual
            * COS(RADIANS(u.latitude))       -- Coseno de latitud del otro usuario
            * COS(RADIANS(u.longitude) - RADIANS(:userLon))  -- Diferencia de longitudes
            + SIN(RADIANS(:userLat))         -- Seno de latitud actual
            * SIN(RADIANS(u.latitude))       -- Seno de latitud del otro usuario
        )
    ) AS distance
FROM usuarios u
WHERE u.id != :currentUserId
    AND u.last_activity >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)  -- Solo activos
HAVING distance <= 5.0    -- Filtro: máximo 5km
ORDER BY distance ASC     -- Ordenar por más cercano primero
```

**Ejemplo real:**

```
Usuario Actual:
- Latitud: 40.4168°N (Madrid, España)
- Longitud: -3.7038°E

Usuario2:
- Latitud: 40.4175°N
- Longitud: -3.7045°E

Cálculo:
1. Diferencia de latitud: 40.4175 - 40.4168 = 0.0007°
2. Diferencia de longitud: -3.7045 - (-3.7038) = -0.0007°
3. Aplicar Haversine...
4. Resultado: 0.07 km (70 metros)
```

---

### 5️⃣ RESPUESTA CON DISTANCIAS

Cuando consultas `/api/home`, recibes usuarios con sus distancias:

```json
{
  "success": true,
  "data": {
    "nearbyUsers": [
      {
        "id": 2,
        "username": "usuario2",
        "nombre": "Maria",
        "latitude": 40.4175,
        "longitude": -3.7045,
        "distance": 0.07,        ← Distancia en KM
        "isOnline": true
      },
      {
        "id": 3,
        "username": "usuario3",
        "nombre": "Pedro",
        "latitude": 40.4180,
        "longitude": -3.7050,
        "distance": 0.12,        ← 120 metros
        "isOnline": true
      }
    ],
    "totalUsers": 2,
    "radius": "5km"
  }
}
```

---

### 6️⃣ EJEMPLO COMPLETO: Actualización Periódica

#### JavaScript - Actualizar cada 2 minutos:
```javascript
let token = localStorage.getItem('api_token');

// Función para obtener y actualizar ubicación
async function actualizarUbicacion() {
    if ("geolocation" in navigator) {
        navigator.geolocation.getCurrentPosition(async (position) => {
            const lat = position.coords.latitude;
            const lon = position.coords.longitude;
            
            console.log(`Actualizando ubicación: ${lat}, ${lon}`);
            
            // Enviar al servidor
            const response = await fetch('http://localhost:8000/api/actualizar', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${token}`
                },
                body: JSON.stringify({
                    latitude: lat,
                    longitude: lon
                })
            });
            
            const data = await response.json();
            console.log('Usuarios cercanos actualizados:', data.data.nearbyUsers);
        });
    }
}

// Actualizar al inicio
actualizarUbicacion();

// Actualizar cada 2 minutos (120000 ms)
setInterval(actualizarUbicacion, 120000);
```

#### Python - Actualización con geopy:
```python
import requests
from geopy.geocoders import Nominatim

def actualizar_ubicacion_por_direccion(token, direccion):
    """
    Convertir dirección a coordenadas (geocoding)
    """
    geolocator = Nominatim(user_agent="mi_app")
    location = geolocator.geocode(direccion)
    
    if location:
        lat = location.latitude
        lon = location.longitude
        
        # Actualizar en API
        url = 'http://localhost:8000/api/actualizar'
        headers = {
            'Authorization': f'Bearer {token}',
            'Content-Type': 'application/json'
        }
        data = {
            'latitude': lat,
            'longitude': lon
        }
        
        response = requests.post(url, json=data, headers=headers)
        return response.json()

# Uso
token = 'tu_token_aqui'
resultado = actualizar_ubicacion_por_direccion(token, "Gran Vía, Madrid, España")
print(f"Ubicación actualizada: {resultado}")
```

---

### 7️⃣ PRECISIÓN Y OPTIMIZACIÓN

#### Niveles de Precisión GPS:

| Decimales | Precisión          | Uso                        |
|-----------|--------------------|----------------------------|
| 0         | ~111 km            | País                       |
| 1         | ~11.1 km           | Ciudad grande              |
| 2         | ~1.1 km            | Pueblo                     |
| 3         | ~110 m             | Campo / Barrio             |
| 4         | ~11 m              | Parcela / Edificio         |
| 5         | ~1.1 m             | Árbol / Persona            |
| 6         | ~0.11 m (11 cm)    | Precisión móvil típica    |
| 7         | ~1.1 cm            | Topografía profesional     |
| 8         | ~1.1 mm            | **Nuestra BD** (excesivo) |

**Recomendación:** Para una app de chat con geolocalización, **6 decimales** son más que suficientes.

#### Optimización de Consultas:

Para mejorar el rendimiento con muchos usuarios, añade un índice espacial:

```sql
-- Optimización futura (opcional)
ALTER TABLE usuarios 
ADD SPATIAL INDEX idx_location (latitude, longitude);
```

---

### 8️⃣ RESTRICCIONES Y VALIDACIONES

#### Radio de 5km ESTRICTO

En **TODA** la aplicación se respeta el límite de 5km:

```php
// Chat Global: Solo mensajes de usuarios ≤ 5km
HAVING distance <= 5.0

// Usuarios cercanos: Solo mostrar ≤ 5km
HAVING distance <= 5.0

// Invitaciones: Solo usuarios activos ≤ 5km (validado en isActive())
```

#### Privacidad de Ubicación

- ✅ **Nunca se expone la ubicación exacta** a otros usuarios
- ✅ Solo se muestra la **distancia calculada** (ej: "0.07 km")
- ✅ Los usuarios pueden **actualizar su ubicación cuando quieran**
- ✅ Si un usuario no actualiza en 5 min, se marca **offline**

---

### 🗺️ RESUMEN VISUAL DEL FLUJO

```
1. REGISTRO
   │
   ├─→ Cliente obtiene coordenadas GPS del dispositivo
   │   (navigator.geolocation, Location Services, etc.)
   │
   ├─→ POST /api/register con latitude/longitude
   │
   └─→ Servidor guarda en BD: usuarios(latitude, longitude)

2. USO CONTINUO
   │
   ├─→ Cliente actualiza ubicación periódicamente
   │   POST /api/actualizar { latitude, longitude }
   │
   ├─→ Servidor actualiza BD y calcula usuarios cercanos
   │   con Haversine
   │
   └─→ Respuesta incluye lista de usuarios ≤ 5km
       ordenados por distancia

3. CHAT GLOBAL
   │
   ├─→ GET /api/general
   │
   ├─→ Servidor filtra mensajes:
   │   - Sender a ≤ 5km del usuario actual
   │   - Calcula distancia en tiempo real
   │
   └─→ Respuesta con mensajes + distancia de cada sender
```

---

### 📊 EJEMPLO REAL DE CÁLCULO

**Escenario:**
```
Usuario1 (tú):
  Lat: 40.416775°
  Lon: -3.703790°
  Ubicación: Puerta del Sol, Madrid

Usuario2:
  Lat: 40.423150°
  Lon: -3.692367°
  Ubicación: Gran Vía, Madrid
  
Usuario3:
  Lat: 40.411755°
  Lon: -3.705440°
  Ubicación: Plaza Mayor, Madrid
  
Usuario4:
  Lat: 40.463667°
  Lon: -3.749220°
  Ubicación: Chamartín (lejos)
```

**Query SQL ejecutada:**
```sql
SELECT 
    username,
    (6371 * ACOS(
        COS(RADIANS(40.416775)) 
        * COS(RADIANS(latitude)) 
        * COS(RADIANS(longitude) - RADIANS(-3.703790)) 
        + SIN(RADIANS(40.416775)) 
        * SIN(RADIANS(latitude))
    )) AS distance
FROM usuarios
HAVING distance <= 5.0
ORDER BY distance ASC;
```

**Resultado:**
```json
{
  "nearbyUsers": [
    {
      "username": "usuario3",
      "distance": 0.58  // Plaza Mayor - 580 metros
    },
    {
      "username": "usuario2",
      "distance": 1.12  // Gran Vía - 1.12 km
    }
    // Usuario4 NO aparece (distancia ~5.8 km > 5km)
  ]
}
```

---

## 💌 SISTEMA DE INVITACIONES A SALAS

### ¿Qué son las Salas Privadas?

Las **salas privadas** son espacios de chat cerrados donde solo los miembros invitados pueden participar. A diferencia del chat global (donde todos los usuarios dentro de 5km ven los mensajes), las salas privadas son exclusivas.

### Características Principales

```php
// Entidades involucradas:

// 1. PrivateRoom (Sala)
class PrivateRoom {
    private int $id;
    private string $name;                    // Nombre de la sala
    private Usuarios $owner;                 // Creador/dueño de la sala
    private Collection $members;             // Miembros (UserRoom)
    private Collection $messages;            // Mensajes de la sala
    private DateTime $createdAt;             // Fecha de creación
}

// 2. Invitation (Invitación)
class Invitation {
    private int $id;
    private Usuarios $sender;                // Quien invita
    private Usuarios $receiver;              // Quien recibe
    private PrivateRoom $room;               // Sala a la que invita
    private string $status;                  // pending/accepted/rejected
    private DateTime $expiresAt;             // Expira en 24 horas
    private DateTime $createdAt;
}

// 3. UserRoom (Membresía)
class UserRoom {
    private Usuarios $user;                  // Usuario miembro
    private PrivateRoom $room;               // Sala
    private DateTime $joinedAt;              // Fecha de ingreso
}
```

**Reglas del sistema:**
- ✅ Máximo **10 usuarios** por sala (incluyendo el owner)
- ✅ Las invitaciones **expiran en 24 horas**
- ✅ Solo puedes invitar a usuarios **dentro de 5km** y **activos** (último 5 min)
- ✅ Si una sala queda **sin miembros**, se **auto-elimina**
- ✅ El owner puede **salir** de su propia sala (se transfiere ownership)

---

### 🔄 FLUJO COMPLETO DE INVITACIONES

#### PASO 1: Crear Sala e Invitar Usuarios

```http
POST /api/invitar
Authorization: Bearer {tu_token}
Content-Type: application/json

{
  "name": "Sala de Amigos",      // Nombre de la sala (opcional)
  "userIds": [2, 3, 4]            // IDs de usuarios a invitar
}
```

**¿Qué sucede internamente?**

```php
// src/Controller/InvitacionController.php - invitar()

1. Validar que haya userIds
2. Buscar si ya existe una sala creada por este usuario
3. Si NO existe → Crear nueva PrivateRoom:
   
   $room = new PrivateRoom();
   $room->setName($data['name'] ?? 'Sala Privada');
   $room->setOwner($currentUser);
   
   // Añadir al owner como primer miembro:
   $userRoom = new UserRoom();
   $userRoom->setUser($currentUser);
   $userRoom->setRoom($room);
   
4. Validar límite de 10 usuarios:
   
   $currentMembers = count($room->getMembers());
   $maxInvites = 10 - $currentMembers;
   
   if (count($userIds) > $maxInvites) {
       return error: "Solo puedes invitar X usuarios más"
   }
   
5. Para cada userId:
   a. Buscar el usuario en BD
   b. Verificar que NO sea el mismo usuario
   c. Verificar que esté activo (isActive() → dentro de 5km)
   d. Verificar que NO esté ya en la sala
   e. Verificar que NO tenga invitación pendiente
   f. Crear nueva Invitation:
   
      $invitation = new Invitation();
      $invitation->setSender($currentUser);
      $invitation->setReceiver($userToInvite);
      $invitation->setRoom($room);
      $invitation->setStatus('pending');
      $invitation->setExpiresAt(+24 hours);
      
6. Guardar todo en BD
7. Devolver lista de invitaciones enviadas
```

**Respuesta:**
```json
{
  "success": true,
  "data": {
    "room": {
      "id": 1,
      "name": "Sala de Amigos",
      "ownerId": 1,
      "ownerUsername": "usuario1",
      "totalMembers": 1
    },
    "invitations": [
      {
        "id": 1,
        "receiverId": 2,
        "receiverUsername": "usuario2",
        "status": "pending",
        "expiresAt": "2026-01-16T10:30:00+01:00"
      },
      {
        "id": 2,
        "receiverId": 3,
        "receiverUsername": "usuario3",
        "status": "pending",
        "expiresAt": "2026-01-16T10:30:00+01:00"
      }
    ],
    "message": "Invitaciones enviadas exitosamente"
  }
}
```

---

#### PASO 2: Ver Invitaciones Recibidas

```http
GET /api/invitaciones
Authorization: Bearer {tu_token}
```

**¿Qué sucede internamente?**

```php
// src/Controller/InvitacionController.php - listar()

1. Buscar invitaciones donde receiver = usuario actual
2. Filtrar solo status = 'pending'
3. Filtrar solo NO expiradas (expiresAt > NOW)
4. Para cada invitación, incluir datos del sender y room
```

**Respuesta:**
```json
{
  "success": true,
  "data": {
    "invitations": [
      {
        "id": 1,
        "roomId": 1,
        "roomName": "Sala de Amigos",
        "sender": {
          "id": 1,
          "username": "usuario1",
          "nombre": "Juan"
        },
        "status": "pending",
        "createdAt": "2026-01-15T10:30:00+01:00",
        "expiresAt": "2026-01-16T10:30:00+01:00"
      }
    ],
    "totalInvitations": 1
  }
}
```

---

#### PASO 3: Aceptar Invitación

```http
POST /api/invitaciones/{id}/aceptar
Authorization: Bearer {tu_token}
```

**Ejemplo:**
```http
POST /api/invitaciones/1/aceptar
Authorization: Bearer ae235f52-8601-4197-87e6-7cbb93b5b3e0
```

**¿Qué sucede internamente?**

```php
// src/Controller/InvitacionController.php - aceptar()

1. Buscar la invitación por ID
2. Verificar que el receiver sea el usuario actual
3. Verificar que status = 'pending'
4. Verificar que NO esté expirada
5. Verificar límite de 10 usuarios en la sala:
   
   if (count($room->getMembers()) >= 10) {
       return error: "La sala está llena"
   }
   
6. Cambiar status a 'accepted'
7. Crear UserRoom (membresía):
   
   $userRoom = new UserRoom();
   $userRoom->setUser($currentUser);
   $userRoom->setRoom($room);
   
8. Guardar en BD
9. Devolver información de la sala
```

**Respuesta:**
```json
{
  "success": true,
  "data": {
    "message": "Te has unido a la sala exitosamente",
    "room": {
      "id": 1,
      "name": "Sala de Amigos",
      "totalMembers": 2,
      "members": [
        {
          "id": 1,
          "username": "usuario1",
          "nombre": "Juan"
        },
        {
          "id": 2,
          "username": "usuario2",
          "nombre": "Maria"
        }
      ]
    }
  }
}
```

**💾 En la base de datos:**

```sql
-- Tabla: user_room (membresías)
user_id | room_id | joined_at
1       | 1       | 2026-01-15 10:30:00  ← Owner
2       | 1       | 2026-01-15 10:35:00  ← Nuevo miembro

-- Tabla: invitation
id | sender_id | receiver_id | room_id | status   | expires_at
1  | 1         | 2           | 1       | accepted | 2026-01-16 10:30:00
```

---

#### PASO 4: Rechazar Invitación

```http
POST /api/invitaciones/{id}/rechazar
Authorization: Bearer {tu_token}
```

**¿Qué sucede internamente?**

```php
// src/Controller/InvitacionController.php - rechazar()

1. Buscar la invitación por ID
2. Verificar que el receiver sea el usuario actual
3. Verificar que status = 'pending'
4. Cambiar status a 'rejected'
5. Guardar en BD
```

**Respuesta:**
```json
{
  "success": true,
  "data": {
    "message": "Invitación rechazada"
  }
}
```

---

### 📋 GESTIÓN DE SALAS

#### Ver Mis Salas

```http
GET /api/privado
Authorization: Bearer {tu_token}
```

**Respuesta:**
```json
{
  "success": true,
  "data": {
    "rooms": [
      {
        "id": 1,
        "name": "Sala de Amigos",
        "ownerId": 1,
        "ownerUsername": "usuario1",
        "totalMembers": 3,
        "createdAt": "2026-01-15T10:30:00+01:00"
      }
    ],
    "totalRooms": 1
  }
}
```

---

#### Ver Miembros de una Sala

```http
GET /api/privado/{roomId}
Authorization: Bearer {tu_token}
```

**Ejemplo:**
```http
GET /api/privado/1
Authorization: Bearer {token}
```

**Respuesta:**
```json
{
  "success": true,
  "data": {
    "room": {
      "id": 1,
      "name": "Sala de Amigos",
      "ownerId": 1,
      "ownerUsername": "usuario1"
    },
    "members": [
      {
        "id": 1,
        "username": "usuario1",
        "nombre": "Juan",
        "isOwner": true,
        "joinedAt": "2026-01-15T10:30:00+01:00"
      },
      {
        "id": 2,
        "username": "usuario2",
        "nombre": "Maria",
        "isOwner": false,
        "joinedAt": "2026-01-15T10:35:00+01:00"
      }
    ],
    "totalMembers": 2,
    "maxMembers": 10
  }
}
```

---

#### Salir de una Sala

```http
POST /api/privado/{roomId}/salir
Authorization: Bearer {tu_token}
```

**¿Qué sucede si el OWNER sale?**

```php
// src/Controller/PrivadoController.php - salir()

1. Si hay otros miembros en la sala:
   → Transferir ownership al siguiente miembro más antiguo
   
   $members = $room->getMembers();
   $nextOwner = $members[0]->getUser();  // Primer miembro por joinedAt
   $room->setOwner($nextOwner);
   
2. Si NO hay otros miembros:
   → Eliminar la sala completa (auto-cleanup)
   
   $entityManager->remove($room);
```

**Respuesta:**
```json
{
  "success": true,
  "data": {
    "message": "Has salido de la sala exitosamente",
    "newOwnerId": 2  // Solo si eras el owner
  }
}
```

---

### 💬 CHAT EN SALAS PRIVADAS

#### Enviar Mensaje

```http
POST /api/privado/{roomId}/mensajes
Authorization: Bearer {tu_token}
Content-Type: application/json

{
  "content": "Hola a todos en la sala!"
}
```

**Respuesta:**
```json
{
  "success": true,
  "data": {
    "message": {
      "id": 1,
      "content": "Hola a todos en la sala!",
      "senderId": 1,
      "senderUsername": "usuario1",
      "roomId": 1,
      "createdAt": "2026-01-15T10:40:00+01:00"
    }
  }
}
```

---

#### Ver Mensajes de la Sala

```http
GET /api/privado/{roomId}/mensajes?limit=50&offset=0
Authorization: Bearer {tu_token}
```

**Respuesta:**
```json
{
  "success": true,
  "data": {
    "messages": [
      {
        "id": 1,
        "content": "Hola a todos en la sala!",
        "sender": {
          "id": 1,
          "username": "usuario1",
          "nombre": "Juan"
        },
        "createdAt": "2026-01-15T10:40:00+01:00"
      },
      {
        "id": 2,
        "content": "Hola Juan!",
        "sender": {
          "id": 2,
          "username": "usuario2",
          "nombre": "Maria"
        },
        "createdAt": "2026-01-15T10:41:00+01:00"
      }
    ],
    "totalMessages": 2,
    "roomId": 1
  }
}
```

---

### 🔧 COMANDO DE LIMPIEZA AUTOMÁTICA

Las invitaciones expiradas se limpian automáticamente cada día:

```bash
php bin/console app:cleanup-old-invitations
```

**¿Qué hace?**
```php
// src/Command/CleanupOldInvitationsCommand.php

1. Buscar todas las invitaciones con:
   - status = 'pending'
   - expiresAt < NOW (expiradas)
   
2. Eliminar de la BD

3. Reportar cuántas se eliminaron
```

**Añadir a cron (Linux/Mac):**
```bash
# Ejecutar todos los días a las 3:00 AM
0 3 * * * cd /ruta/proyecto && php bin/console app:cleanup-old-invitations
```

**Añadir a Task Scheduler (Windows):**
1. Abrir "Programador de tareas"
2. Crear tarea básica
3. Configurar: Diario a las 3:00 AM
4. Acción: `php.exe`
5. Argumentos: `c:\xampp\htdocs\tortura1\bin\console app:cleanup-old-invitations`

---

### 🚨 VALIDACIONES Y RESTRICCIONES

#### ❌ Errores Comunes

**1. Sala llena (10 usuarios)**
```json
{
  "success": false,
  "error": "La sala ha alcanzado el límite de 10 usuarios"
}
```

**2. Usuario no activo o fuera de rango**
```json
{
  "success": false,
  "error": "El usuario X no está activo o fuera de rango"
}
```

**3. Invitación expirada**
```json
{
  "success": false,
  "error": "La invitación ha expirado"
}
```

**4. Usuario ya está en la sala**
```json
{
  "success": false,
  "error": "El usuario X ya está en esta sala"
}
```

**5. Ya existe invitación pendiente**
```json
{
  "success": false,
  "error": "Ya existe una invitación pendiente para el usuario X"
}
```

---

### 🔍 VERIFICACIÓN DE USUARIOS ACTIVOS

**¿Cómo se determina si un usuario está "activo"?**

```php
// src/Entity/Usuarios.php - isActive()

public function isActive(): bool
{
    $now = new \DateTime();
    $fiveMinutesAgo = (clone $now)->modify('-5 minutes');
    
    // Usuario activo si:
    // 1. Tiene coordenadas (latitude y longitude)
    // 2. last_activity dentro de los últimos 5 minutos
    
    return $this->latitude !== null 
        && $this->longitude !== null
        && $this->lastActivity >= $fiveMinutesAgo;
}
```

**Además, para invitaciones se verifica la distancia:**

```php
// src/Controller/InvitacionController.php

// Calcular distancia con Haversine
$distance = calcularDistancia(
    $currentUser->getLatitude(),
    $currentUser->getLongitude(),
    $userToInvite->getLatitude(),
    $userToInvite->getLongitude()
);

// Solo invitar si está dentro de 5km
if ($distance > 5.0) {
    continue;  // Saltar este usuario
}
```

---

### 📊 EJEMPLO COMPLETO: Crear Sala con 3 Amigos

#### Escenario:
```
Usuario1 (tú):  ID=1, username="juan"
Usuario2:       ID=2, username="maria"  (2.5 km de distancia)
Usuario3:       ID=3, username="pedro"  (4.0 km de distancia)
Usuario4:       ID=4, username="luis"   (6.0 km de distancia - FUERA DE RANGO)
```

#### JavaScript - Flujo Completo:

```javascript
const token = 'tu_token_aqui';

// PASO 1: Crear sala e invitar
async function crearSala() {
    const response = await fetch('http://localhost:8000/api/invitar', {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${token}`,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            name: 'Sala de Amigos',
            userIds: [2, 3, 4]  // Luis (4) será rechazado por distancia
        })
    });
    
    const data = await response.json();
    console.log('Invitaciones enviadas:', data);
    
    /*
    Respuesta:
    {
      "success": true,
      "data": {
        "room": { "id": 1, "name": "Sala de Amigos" },
        "invitations": [
          { "id": 1, "receiverUsername": "maria", "status": "pending" },
          { "id": 2, "receiverUsername": "pedro", "status": "pending" }
        ],
        "errors": [
          { "userId": 4, "error": "Usuario no activo o fuera de rango" }
        ]
      }
    }
    */
}

// PASO 2: Ver invitaciones (como Maria)
async function verInvitaciones() {
    const response = await fetch('http://localhost:8000/api/invitaciones', {
        headers: {
            'Authorization': `Bearer ${tokenMaria}`
        }
    });
    
    const data = await response.json();
    console.log('Mis invitaciones:', data.data.invitations);
}

// PASO 3: Aceptar invitación (como Maria)
async function aceptarInvitacion(invitationId) {
    const response = await fetch(`http://localhost:8000/api/invitaciones/${invitationId}/aceptar`, {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${tokenMaria}`
        }
    });
    
    const data = await response.json();
    console.log('Unido a sala:', data.data.room);
}

// PASO 4: Ver miembros de la sala
async function verMiembros(roomId) {
    const response = await fetch(`http://localhost:8000/api/privado/${roomId}`, {
        headers: {
            'Authorization': `Bearer ${token}`
        }
    });
    
    const data = await response.json();
    console.log('Miembros:', data.data.members);
    /*
    [
      { "username": "juan", "isOwner": true },
      { "username": "maria", "isOwner": false },
      { "username": "pedro", "isOwner": false }
    ]
    */
}

// PASO 5: Enviar mensaje en la sala
async function enviarMensaje(roomId, contenido) {
    const response = await fetch(`http://localhost:8000/api/privado/${roomId}/mensajes`, {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${token}`,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ content: contenido })
    });
    
    const data = await response.json();
    console.log('Mensaje enviado:', data.data.message);
}

// EJECUTAR
crearSala();
```

---

### 🔄 POLLING: Ver Nuevas Invitaciones

Para recibir invitaciones en tiempo real, usa `/api/updates`:

```javascript
// Cada 3 segundos, verificar nuevas invitaciones
setInterval(async () => {
    const response = await fetch('http://localhost:8000/api/updates', {
        headers: {
            'Authorization': `Bearer ${token}`
        }
    });
    
    const data = await response.json();
    
    // Verificar si hay nuevas invitaciones
    if (data.data.newInvitations > 0) {
        console.log(`Tienes ${data.data.newInvitations} invitaciones nuevas!`);
        
        // Obtener las invitaciones
        const invitations = await fetch('http://localhost:8000/api/invitaciones', {
            headers: { 'Authorization': `Bearer ${token}` }
        });
        
        const invitationsData = await invitations.json();
        mostrarNotificacion(invitationsData.data.invitations);
    }
}, 3000);
```

**Respuesta de `/api/updates`:**
```json
{
  "success": true,
  "data": {
    "user": { "id": 2, "username": "maria" },
    "pendingInvitations": 2,        ← Total de invitaciones pendientes
    "newInvitations": 1,            ← Invitaciones recibidas desde último check
    "nearbyUsers": 5,
    "globalMessages": 12,
    "privateRooms": [
      {
        "roomId": 1,
        "roomName": "Sala de Amigos",
        "unreadMessages": 3
      }
    ]
  }
}
```

---

### 🎯 RESUMEN DEL FLUJO DE INVITACIONES

```
1. CREAR SALA E INVITAR
   │
   ├─→ POST /api/invitar { name, userIds }
   │
   ├─→ Sistema valida:
   │   • Límite de 10 usuarios
   │   • Usuarios dentro de 5km
   │   • Usuarios activos (últimos 5 min)
   │   • No duplicados
   │
   └─→ Crea PrivateRoom + Invitations (status: pending)

2. RECIBIR INVITACIÓN
   │
   ├─→ GET /api/invitaciones
   │
   └─→ Lista de invitaciones pendientes (no expiradas)

3. ACEPTAR/RECHAZAR
   │
   ├─→ POST /api/invitaciones/{id}/aceptar
   │   • Cambia status a 'accepted'
   │   • Crea UserRoom (membresía)
   │   • Usuario ahora es miembro de la sala
   │
   └─→ POST /api/invitaciones/{id}/rechazar
       • Cambia status a 'rejected'
       • No se crea membresía

4. USAR LA SALA
   │
   ├─→ GET /api/privado → Ver mis salas
   │
   ├─→ GET /api/privado/{id} → Ver miembros
   │
   ├─→ POST /api/privado/{id}/mensajes → Enviar mensaje
   │
   ├─→ GET /api/privado/{id}/mensajes → Ver mensajes
   │
   └─→ POST /api/privado/{id}/salir → Salir de la sala

5. AUTO-LIMPIEZA
   │
   ├─→ Invitaciones expiradas (24h) → Eliminadas por cron
   │
   └─→ Salas sin miembros → Auto-eliminadas
```

---

### 🔑 PUNTOS CLAVE

**✅ Invitaciones:**
- Expiran en 24 horas
- Solo a usuarios dentro de 5km y activos
- Estados: pending → accepted/rejected
- Se limpian automáticamente

**✅ Salas Privadas:**
- Máximo 10 usuarios (incluyendo owner)
- Mensajes privados solo para miembros
- Auto-eliminación si quedan sin miembros
- Transferencia de ownership si el dueño sale

**✅ Seguridad:**
- Solo miembros pueden ver mensajes de la sala
- Solo el receiver puede aceptar/rechazar su invitación
- Validación de distancia en tiempo de invitación

---

## �🛡️ CÓMO FUNCIONA EL AUTHENTICATOR

### ApiTokenAuthenticator.php

Este es el "guardián" que verifica cada petición:

```php
// src/Security/ApiTokenAuthenticator.php

class ApiTokenAuthenticator extends AbstractAuthenticator
{
    // 1. DECIDE SI DEBE AUTENTICAR
    public function supports(Request $request): ?bool
    {
        $path = $request->getPathInfo();
        
        // ❌ NO autenticar estas rutas (públicas):
        if (
            str_starts_with($path, '/api/register') ||
            str_starts_with($path, '/api/login')
        ) {
            return false;  // Dejar pasar sin autenticación
        }
        
        // ✅ Autenticar si hay header Authorization
        return $request->headers->has('Authorization');
    }
    
    // 2. VALIDA EL TOKEN
    public function authenticate(Request $request): Passport
    {
        // Extrae el token del header
        $apiToken = $request->headers->get('Authorization');
        
        // Quita el prefijo "Bearer "
        if (str_starts_with($apiToken, 'Bearer ')) {
            $apiToken = substr($apiToken, 7);
        }
        
        // Busca el token en BD
        $token = $this->tokenRepo->findOneBy(['token' => $apiToken]);
        
        if (!$token) {
            throw new Exception('Invalid API token');
        }
        
        // Verifica si expiró
        if ($token->isExpired()) {
            throw new Exception('API token expired');
        }
        
        // Actualiza última actividad del usuario
        $user = $token->getUser();
        $user->updateActivity();
        
        // ✅ Token válido - permite el acceso
        return new SelfValidatingPassport(...);
    }
}
```

---

## 🌐 ENDPOINTS: PÚBLICOS VS PROTEGIDOS

### 🟢 Endpoints PÚBLICOS (Sin Token)

Estas rutas NO requieren autenticación:

```php
❌ /api/register  → Crear cuenta nueva
❌ /api/login     → Obtener token
```

**Ejemplo:**
```bash
curl -X POST http://localhost:8000/api/register \
  -H "Content-Type: application/json" \
  -d '{"email":"test@test.com","password":"pass123",...}'
```

---

### 🔴 Endpoints PROTEGIDOS (Requieren Token)

Todas las demás rutas SÍ requieren token:

```php
🔒 /api/logout
🔒 /api/perfil
🔒 /api/home
🔒 /api/actualizar
🔒 /api/general (GET y POST)
🔒 /api/privado/*
🔒 /api/invitar/*
🔒 /api/updates
```

**Ejemplo:**
```bash
curl -X GET http://localhost:8000/api/home \
  -H "Authorization: Bearer f69d588286251cd31fd05efadddd6deaed2023980e6a082b66c0bac6605bd457"
```

---

## 🔍 PROCESO COMPLETO DE UNA PETICIÓN PROTEGIDA

### Ejemplo: GET /api/home

```
1. CLIENTE envía petición:
   ↓
   GET /api/home
   Authorization: Bearer f69d588286...
   
2. SYMFONY recibe la petición
   ↓
3. ApiTokenAuthenticator::supports()
   ↓
   - Verifica que NO sea /api/register ni /api/login ✅
   - Verifica que haya header Authorization ✅
   - return true → "Sí, debo autenticar"
   
4. ApiTokenAuthenticator::authenticate()
   ↓
   - Extrae el token del header
   - Busca en BD: SELECT * FROM api_token WHERE token = 'f69d588286...'
   - Verifica que exista ✅
   - Verifica que NO esté expirado (expires_at > NOW()) ✅
   - Obtiene el usuario asociado
   - Actualiza last_activity del usuario
   - return Passport ✅
   
5. SYMFONY permite el acceso
   ↓
6. HomeController::index() se ejecuta
   ↓
   - $currentUser = $this->getUser();  // Usuario autenticado
   - Calcula usuarios cercanos con Haversine
   - Devuelve JSON
   
7. CLIENTE recibe respuesta:
   ↓
   {
     "success": true,
     "data": {
       "nearbyUsers": [...]
     }
   }
```

---

## ❌ ¿QUÉ PASA SI EL TOKEN ES INVÁLIDO?

### Caso 1: Token Inexistente

```http
GET /api/home
Authorization: Bearer token_falso_12345
```

**Resultado:**
```json
HTTP 401 Unauthorized
{
  "success": false,
  "error": "Invalid API token",
  "data": null,
  "metadata": {
    "timestamp": "2026-01-15T10:30:00+01:00"
  }
}
```

### Caso 2: Token Expirado

```http
GET /api/home
Authorization: Bearer f69d588286...  (creado hace > 24 horas)
```

**Resultado:**
```json
HTTP 401 Unauthorized
{
  "success": false,
  "error": "API token expired",
  "data": null
}
```

**Solución:** Hacer login de nuevo para obtener un token nuevo.

### Caso 3: Sin Token

```http
GET /api/home
(sin header Authorization)
```

**Resultado:**
```json
HTTP 401 Unauthorized
{
  "success": false,
  "error": "No API token provided",
  "data": null
}
```

---

## 📝 EJEMPLOS PRÁCTICOS

### Ejemplo 1: Flujo Completo en JavaScript

```javascript
// 1. REGISTRO
const registrar = async () => {
  const response = await fetch('http://localhost:8000/api/register', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      email: 'usuario@test.com',
      password: 'pass123',
      username: 'usuario1',
      nombre: 'Juan',
      apellidos: 'Pérez',
      latitude: 40.4168,
      longitude: -3.7038
    })
  });
  
  const data = await response.json();
  const token = data.data.token;
  
  // Guardar token en localStorage
  localStorage.setItem('api_token', token);
  
  console.log('Token obtenido:', token);
};

// 2. USAR TOKEN EN PETICIONES
const obtenerUsuariosCercanos = async () => {
  const token = localStorage.getItem('api_token');
  
  const response = await fetch('http://localhost:8000/api/home', {
    method: 'GET',
    headers: {
      'Authorization': `Bearer ${token}`
    }
  });
  
  const data = await response.json();
  console.log('Usuarios cercanos:', data.data.nearbyUsers);
};

// 3. ENVIAR MENSAJE GLOBAL
const enviarMensaje = async (contenido) => {
  const token = localStorage.getItem('api_token');
  
  const response = await fetch('http://localhost:8000/api/general', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`
    },
    body: JSON.stringify({
      content: contenido
    })
  });
  
  const data = await response.json();
  console.log('Mensaje enviado:', data);
};

// 4. LOGOUT
const cerrarSesion = async () => {
  const token = localStorage.getItem('api_token');
  
  await fetch('http://localhost:8000/api/logout', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`
    }
  });
  
  localStorage.removeItem('api_token');
  console.log('Sesión cerrada');
};
```

---

### Ejemplo 2: Flujo Completo en PHP (cURL)

```php
<?php

// 1. REGISTRO
function registrar() {
    $url = 'http://localhost:8000/api/register';
    
    $data = [
        'email' => 'usuario@test.com',
        'password' => 'pass123',
        'username' => 'usuario1',
        'nombre' => 'Juan',
        'apellidos' => 'Pérez',
        'latitude' => 40.4168,
        'longitude' => -3.7038
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    $token = $result['data']['token'];
    
    // Guardar token en sesión o archivo
    $_SESSION['api_token'] = $token;
    
    return $token;
}

// 2. OBTENER USUARIOS CERCANOS
function obtenerUsuariosCercanos($token) {
    $url = 'http://localhost:8000/api/home';
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $token"
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// 3. ENVIAR MENSAJE
function enviarMensaje($token, $contenido) {
    $url = 'http://localhost:8000/api/general';
    
    $data = ['content' => $contenido];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        "Authorization: Bearer $token"
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// USO:
$token = registrar();
$usuarios = obtenerUsuariosCercanos($token);
$mensaje = enviarMensaje($token, 'Hola desde PHP!');
```

---

### Ejemplo 3: Flujo Completo en Python

```python
import requests

# 1. REGISTRO
def registrar():
    url = 'http://localhost:8000/api/register'
    
    data = {
        'email': 'usuario@test.com',
        'password': 'pass123',
        'username': 'usuario1',
        'nombre': 'Juan',
        'apellidos': 'Pérez',
        'latitude': 40.4168,
        'longitude': -3.7038
    }
    
    response = requests.post(url, json=data)
    result = response.json()
    token = result['data']['token']
    
    return token

# 2. OBTENER USUARIOS CERCANOS
def obtener_usuarios_cercanos(token):
    url = 'http://localhost:8000/api/home'
    headers = {
        'Authorization': f'Bearer {token}'
    }
    
    response = requests.get(url, headers=headers)
    return response.json()

# 3. ENVIAR MENSAJE
def enviar_mensaje(token, contenido):
    url = 'http://localhost:8000/api/general'
    headers = {
        'Authorization': f'Bearer {token}',
        'Content-Type': 'application/json'
    }
    data = {'content': contenido}
    
    response = requests.post(url, json=data, headers=headers)
    return response.json()

# USO:
token = registrar()
usuarios = obtener_usuarios_cercanos(token)
mensaje = enviar_mensaje(token, 'Hola desde Python!')
```

---

## 🔑 RESUMEN CLAVE

### Tokens
- ✅ Se generan en `/api/register` y `/api/login`
- ✅ Son strings de 64 caracteres hexadecimales
- ✅ Expiran en 24 horas
- ✅ Se envían en header `Authorization: Bearer {token}`
- ✅ Se validan en cada petición protegida

### Endpoints
- 🟢 **Públicos**: `/api/register`, `/api/login` (no requieren token)
- 🔴 **Protegidos**: Todos los demás (requieren token válido)

### Seguridad
- 🔒 Tokens almacenados en base de datos (no JWT en cliente)
- 🔒 Contraseñas hasheadas con bcrypt
- 🔒 Actualización automática de `last_activity` en cada petición
- 🔒 Usuarios marcados offline si inactividad > 5 minutos

### Flujo Típico
```
1. POST /api/register → Obtener token
2. Guardar token en cliente (localStorage, sesión, variable)
3. Incluir token en TODAS las peticiones siguientes
4. Si token expira (24h) → POST /api/login → Obtener nuevo token
```

---

## 📚 REFERENCIAS

- [Documentación completa API](API_DOCUMENTATION.md)
- Autenticador: `src/Security/ApiTokenAuthenticator.php`
- Entidad Token: `src/Entity/ApiToken.php`
- Controlador Auth: `src/Controller/SecurityController.php`
- Configuración Seguridad: `config/packages/security.yaml`
