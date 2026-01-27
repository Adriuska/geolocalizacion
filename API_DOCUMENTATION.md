# API REST - Sistema de Geolocalización y Chat

## RESUMEN COMPLETO DEL PROYECTO

**Stack**: Symfony 7.2 + PHP 8.2.12 + MySQL  
**Autenticación**: Custom API Token (64 caracteres hex, 24h expiración)  
**Geolocalización**: Haversine formula con radio 5km estricto  
**CORS**: Configurado con nelmio/cors-bundle  

---

## ENDPOINTS API

### 🔐 AUTENTICACIÓN

#### POST `/api/register`
Registro de nuevo usuario con ubicación
```json
{
  "email": "usuario@test.com",
  "password": "password123",
  "username": "usuario1",
  "nombre": "Juan",
  "apellidos": "Pérez",
  "latitude": 40.4168,
  "longitude": -3.7038
}
```
**Respuesta**: Token de autenticación + datos de usuario

#### POST `/api/login`
Login de usuario existente
```json
{
  "email": "usuario@test.com",
  "password": "password123"
}
```
**Respuesta**: Token de autenticación válido por 24h

#### POST `/api/logout`
Cerrar sesión (invalida token actual)
**Headers**: `Authorization: Bearer {token}`

#### GET `/api/perfil`
Obtener información del usuario autenticado
**Headers**: `Authorization: Bearer {token}`

---

### 📍 GEOLOCALIZACIÓN

#### GET `/api/home`
Usuarios activos dentro de 5km con distancia calculada
**Headers**: `Authorization: Bearer {token}`
**Respuesta**:
```json
{
  "nearbyUsers": [
    {
      "id": 2,
      "username": "usuario2",
      "nombre": "Maria",
      "distance": 0.07,
      "isOnline": true
    }
  ],
  "totalUsers": 1,
  "radius": "5km"
}
```

#### POST `/api/actualizar`
Actualizar ubicación y obtener usuarios cercanos
**Headers**: `Authorization: Bearer {token}`
```json
{
  "latitude": 40.4170,
  "longitude": -3.7040
}
```
**Respuesta**: Misma estructura que `/api/home`

---

### 💬 CHAT GLOBAL

#### GET `/api/general`
Obtener mensajes globales de usuarios cercanos (5km)
**Headers**: `Authorization: Bearer {token}`
**Query params**: `?limit=50` (opcional)
**Respuesta**:
```json
{
  "messages": [
    {
      "id": 1,
      "content": "Hola a todos!",
      "sender": {
        "id": 1,
        "username": "usuario1",
        "nombre": "Juan"
      },
      "distance": 0.07,
      "createdAt": "2026-01-15T10:00:00+01:00",
      "timeAgo": "Hace 5 minutos"
    }
  ],
  "totalMessages": 1,
  "activeUsers": [...]
}
```

#### POST `/api/general`
Enviar mensaje al chat global
**Headers**: `Authorization: Bearer {token}`
```json
{
  "content": "Hola a todos!"
}
```
**Validación**: Máximo 1000 caracteres

---

### 🚪 SALAS PRIVADAS

#### GET `/api/privado`
Listar salas privadas del usuario con último mensaje
**Headers**: `Authorization: Bearer {token}`
**Respuesta**:
```json
{
  "rooms": [
    {
      "id": 1,
      "uuid": "215b02d7-069a-45ed-aad7-a0625bf64594",
      "participantsCount": 2,
      "participants": [...],
      "createdBy": {...},
      "lastMessage": {
        "content": "Último mensaje",
        "sender": "usuario2",
        "createdAt": "2026-01-15T10:31:48+01:00"
      },
      "joinedAt": "2026-01-15T10:31:10+01:00"
    }
  ]
}
```

#### GET `/api/privado/{roomId}`
Obtener mensajes y participantes de una sala
**Headers**: `Authorization: Bearer {token}`
**Respuesta**:
```json
{
  "room": {...},
  "messages": [...],
  "participants": [...],
  "totalMessages": 10
}
```

#### POST `/api/privado/{roomId}/mensajes`
Enviar mensaje en sala privada
**Headers**: `Authorization: Bearer {token}`
```json
{
  "content": "Mensaje privado"
}
```

#### POST `/api/privado/salir/{roomId}`
Salir de una sala privada
**Headers**: `Authorization: Bearer {token}`
**Respuesta**:
```json
{
  "roomDeleted": false,
  "remainingParticipants": 1,
  "message": "Has salido de la sala correctamente"
}
```
**Nota**: La sala se elimina automáticamente cuando sale el último participante

---

### 📨 INVITACIONES

#### POST `/api/invitar`
Crear sala nueva e invitar usuarios, o invitar a sala existente
**Headers**: `Authorization: Bearer {token}`
```json
{
  "userIds": [2, 3, 4],
  "roomId": null
}
```
- `roomId: null` → Crea nueva sala y añade al creador
- `roomId: 1` → Invita a sala existente (requiere ser miembro)
- **Validaciones**: 
  - Máximo 10 participantes por sala
  - Solo usuarios activos (última actividad < 5min)
  - No invitaciones duplicadas
  
**Respuesta**:
```json
{
  "room": {
    "id": 1,
    "uuid": "...",
    "participantsCount": 1
  },
  "invitationsSent": 3,
  "invitations": [...],
  "errors": []
}
```

#### GET `/api/invitar/pendientes`
Listar invitaciones pendientes del usuario
**Headers**: `Authorization: Bearer {token}`
**Respuesta**:
```json
{
  "invitations": [
    {
      "id": 1,
      "sender": {...},
      "room": {...},
      "createdAt": "2026-01-15T10:30:45+01:00",
      "status": "pending"
    }
  ],
  "total": 1
}
```

#### POST `/api/invitar/aceptar/{invitationId}`
Aceptar invitación y unirse a sala
**Headers**: `Authorization: Bearer {token}`
**Respuesta**:
```json
{
  "message": "Te has unido a la sala correctamente",
  "room": {
    "id": 1,
    "participantsCount": 2
  }
}
```

#### POST `/api/invitar/rechazar/{invitationId}`
Rechazar invitación
**Headers**: `Authorization: Bearer {token}`

---

### 🔄 POLLING (Actualizaciones)

#### GET `/api/updates`
Obtener actualizaciones para polling del cliente
**Headers**: `Authorization: Bearer {token}`
**Query params**: `?since=2026-01-15T10:00:00+01:00` (opcional, defecto: -5 min)
**Respuesta**:
```json
{
  "newMessages": {
    "global": 3,
    "private": 5,
    "total": 8
  },
  "pendingInvitations": 2,
  "nearbyUsers": {
    "count": 4,
    "users": [...]
  },
  "user": {
    "isOnline": true,
    "lastActivity": "2026-01-15T10:38:31+01:00"
  },
  "since": "2026-01-15T10:33:31+01:00"
}
```
**Recomendación**: Polling cada 30-60 segundos

---

## COMANDOS CRON

### Marcar Usuarios Inactivos
```bash
php bin/console app:mark-inactive-users
```
**Frecuencia recomendada**: Cada 1 minuto  
**Función**: Marca como offline a usuarios con última actividad > 5 minutos

### Purgar Mensajes Antiguos
```bash
php bin/console app:purge-old-messages [-d|--days 30]
```
**Frecuencia recomendada**: Cada día a las 3:00 AM  
**Función**: Elimina mensajes globales y privados con antigüedad > N días

### Limpiar Invitaciones Antiguas
```bash
php bin/console app:cleanup-old-invitations [-d|--days 7]
```
**Frecuencia recomendada**: Cada día a las 4:00 AM  
**Función**: Elimina invitaciones aceptadas/rechazadas con antigüedad > N días  
**Nota**: Las invitaciones pendientes nunca se eliminan automáticamente

---

## ENTIDADES Y RELACIONES

### Estructura del Proyecto
```
tortura1/
├── bin/                  # Console commands
├── config/              # Configuración Symfony
│   ├── packages/       # Configuración de bundles
│   └── routes/         # Definición de rutas
├── migrations/         # Migraciones de base de datos
├── public/            # Punto de entrada web
│   └── index.php
├── src/               # Código fuente de la aplicación
│   ├── Command/      # Comandos de consola
│   │   ├── MarkInactiveUsersCommand.php
│   │   ├── PurgeOldMessagesCommand.php
│   │   └── CleanupOldInvitationsCommand.php
│   ├── Controller/   # Controladores REST API
│   │   ├── ActualizarController.php
│   │   ├── ChatGlobalController.php
│   │   ├── HomeController.php
│   │   ├── InvitacionController.php
│   │   ├── PrivadoController.php
│   │   ├── SecurityController.php
│   │   └── UpdatesController.php
│   ├── Entity/       # Entidades Doctrine
│   │   ├── ApiToken.php
│   │   ├── Invitation.php
│   │   ├── Message.php
│   │   ├── PrivateRoom.php
│   │   ├── UserRoom.php
│   │   └── Usuarios.php
│   ├── Repository/   # Repositorios Doctrine
│   │   ├── ApiTokenRepository.php
│   │   ├── InvitationRepository.php
│   │   ├── MessageRepository.php
│   │   ├── PrivateRoomRepository.php
│   │   ├── UserRoomRepository.php
│   │   └── UsuariosRepository.php
│   ├── Security/     # Autenticación custom
│   │   └── ApiTokenAuthenticator.php
│   └── Kernel.php
├── var/              # Cache y logs
└── vendor/           # Dependencias Composer
```

### Bundles Activos
```php
FrameworkBundle         // Core de Symfony
DoctrineBundle          // ORM para base de datos
MigrationsBundle        // Migraciones de BD
MakerBundle            // Generador de código (dev)
SecurityBundle         // Sistema de seguridad
NelmioCorsBundle       // CORS para API
```

### Usuarios
- Geolocalización: `latitude`, `longitude` (DECIMAL precision)
- Estado online: `isOnline`, `lastActivity` (actualizado en cada request autenticado)
- Método: `isActive()` → true si `lastActivity` < 5 minutos

### ApiToken
- Token: 64 caracteres hexadecimales
- Expiración: 24 horas desde creación
- Relación: ManyToOne con Usuarios

### Message
- Campo `isGlobal`: true para chat global, false para salas privadas
- Campo `distanceWhenSent`: distancia del sender cuando envió mensaje global
- Relación opcional: ManyToOne con PrivateRoom

### PrivateRoom
- UUID generado automáticamente (PHP nativo)
- `participantsCount`: actualizado con `incrementParticipants()`/`decrementParticipants()`
- Eliminación automática cuando `participantsCount <= 0`

### UserRoom
- Relación ManyToMany: Usuarios ↔ PrivateRoom
- Primary Key compuesta: `(user_id, room_id)`
- Campo: `joinedAt` timestamp

### Invitation
- Estados: `pending`, `accepted`, `rejected`
- Métodos: `accept()`, `reject()`, `isPending()`
- CASCADE delete cuando se elimina la sala

---

## CARACTERÍSTICAS CLAVE

### ✅ Seguridad
- Autenticación custom con tokens en base de datos
- Rutas públicas: `/api/register`, `/api/login`
- Todas las demás rutas requieren token válido
- Actualización automática de `lastActivity` en cada request

### ✅ Geolocalización
- Radio 5km ESTRICTO con Haversine formula en MySQL
- Precisión: 2 decimales en resultados de distancia
- Cálculo en tiempo real para chat global y usuarios cercanos

### ✅ Chat Global
- Solo muestra mensajes de usuarios dentro de 5km
- Incluye distancia del sender en cada mensaje
- Formato `timeAgo` para UX mejorada

### ✅ Salas Privadas
- Creación automática en primera invitación
- Límite: 10 participantes por sala
- Cualquier miembro puede invitar a más usuarios
- Eliminación automática cuando todos salen

### ✅ Sistema de Invitaciones
- Validación: solo usuarios activos (< 5 min inactividad)
- Prevención de duplicados
- Batch invitations (invitar múltiples usuarios a la vez)
- Control de límite de participantes

### ✅ Optimización
- Eliminación en lotes para comando purge (100 mensajes por lote)
- Índices en campos críticos: `isGlobal`, `createdAt`, `status`
- Queries optimizadas con QueryBuilder de Doctrine

---

## EJEMPLO DE USO COMPLETO

```bash
# 1. Registro de usuario1
curl -X POST http://localhost:8000/api/register \
  -H "Content-Type: application/json" \
  -d '{"email":"u1@test.com","password":"pass","username":"u1","nombre":"Juan","apellidos":"P","latitude":40.4168,"longitude":-3.7038}'

# 2. Login (guardar token)
TOKEN=$(curl -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"u1@test.com","password":"pass"}' | jq -r '.data.token')

# 3. Ver usuarios cercanos
curl -X GET http://localhost:8000/api/home \
  -H "Authorization: Bearer $TOKEN"

# 4. Enviar mensaje global
curl -X POST http://localhost:8000/api/general \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"content":"Hola desde Madrid!"}'

# 5. Crear sala e invitar a usuario2
curl -X POST http://localhost:8000/api/invitar \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"userIds":[2],"roomId":null}'

# 6. Polling de actualizaciones
curl -X GET "http://localhost:8000/api/updates?since=2026-01-15T10:00:00+01:00" \
  -H "Authorization: Bearer $TOKEN"
```

---

## CONFIGURACIÓN CRON (Linux)

Editar crontab:
```bash
crontab -e
```

Añadir líneas:
```cron
# Marcar usuarios inactivos cada minuto
* * * * * cd /path/to/tortura1 && php bin/console app:mark-inactive-users >> /var/log/symfony-cron.log 2>&1

# Purgar mensajes antiguos diariamente a las 3:00 AM
0 3 * * * cd /path/to/tortura1 && php bin/console app:purge-old-messages -d 30 >> /var/log/symfony-cron.log 2>&1

# Limpiar invitaciones antiguas diariamente a las 4:00 AM
0 4 * * * cd /path/to/tortura1 && php bin/console app:cleanup-old-invitations -d 7 >> /var/log/symfony-cron.log 2>&1
```

---

## FORMATO DE RESPUESTA ESTÁNDAR

Todas las respuestas JSON siguen este formato:
```json
{
  "success": true,
  "data": { ... },
  "error": null,
  "metadata": {
    "timestamp": "2026-01-15T10:00:00+01:00"
  }
}
```

En caso de error:
```json
{
  "success": false,
  "data": null,
  "error": "Mensaje de error descriptivo",
  "metadata": {
    "timestamp": "2026-01-15T10:00:00+01:00"
  }
}
```

---

**PROYECTO COMPLETADO - TODAS LAS FASES (1-5) IMPLEMENTADAS Y PROBADAS**
