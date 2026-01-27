<?php

namespace App\Controller;

use App\Entity\Usuarios;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/actualizar')]
class ActualizarController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('', name: 'api_actualizar', methods: ['POST'])]
    public function actualizar(Request $request): JsonResponse
    {
        /** @var Usuarios $user */
        $user = $this->getUser();

        if (!$user) {
            return $this->json([
                'success' => false,
                'error' => 'Usuario no autenticado',
                'data' => null,
                'metadata' => ['timestamp' => (new \DateTime())->format('c')]
            ], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        // Actualizar ubicación si se proporciona
        if (isset($data['latitude']) && isset($data['longitude'])) {
            $lat = (float)$data['latitude'];
            $lon = (float)$data['longitude'];

            // Validar coordenadas
            if ($lat < -90 || $lat > 90) {
                return $this->json([
                    'success' => false,
                    'error' => 'Latitud inválida (debe estar entre -90 y 90)',
                    'data' => null,
                    'metadata' => ['timestamp' => (new \DateTime())->format('c')]
                ], Response::HTTP_BAD_REQUEST);
            }

            if ($lon < -180 || $lon > 180) {
                return $this->json([
                    'success' => false,
                    'error' => 'Longitud inválida (debe estar entre -180 y 180)',
                    'data' => null,
                    'metadata' => ['timestamp' => (new \DateTime())->format('c')]
                ], Response::HTTP_BAD_REQUEST);
            }

            $user->setLatitude((string)$lat);
            $user->setLongitude((string)$lon);
        }

        // Actualizar otros campos opcionales
        if (isset($data['nombre'])) {
            $user->setNombre($data['nombre']);
        }
        if (isset($data['apellidos'])) {
            $user->setApellidos($data['apellidos']);
        }
        if (isset($data['telefono'])) {
            $user->setTelefono($data['telefono']);
        }

        // Actualizar actividad
        $user->updateActivity();
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'data' => [
                'id' => $user->getId(),
                'username' => $user->getUsername(),
                'latitude' => (float)$user->getLatitude(),
                'longitude' => (float)$user->getLongitude(),
                'isOnline' => $user->getIsOnline(),
                'lastActivity' => $user->getLastActivity()->format('c'),
                'message' => 'Datos actualizados correctamente'
            ],
            'error' => null,
            'metadata' => ['timestamp' => (new \DateTime())->format('c')]
        ]);
    }

    #[Route('/ubicacion', name: 'api_actualizar_ubicacion', methods: ['GET'])]
    public function obtenerUbicaciones(): JsonResponse
    {
        /** @var Usuarios $currentUser */
        $currentUser = $this->getUser();

        if (!$currentUser) {
            return $this->json([
                'success' => false,
                'error' => 'Usuario no autenticado',
                'data' => null,
                'metadata' => ['timestamp' => (new \DateTime())->format('c')]
            ], Response::HTTP_UNAUTHORIZED);
        }

        $userLat = (float)$currentUser->getLatitude();
        $userLon = (float)$currentUser->getLongitude();

        // Obtener usuarios activos en radio de 5km
        $sql = "
            SELECT 
                u.id,
                u.username,
                u.latitude,
                u.longitude,
                u.is_online,
                u.last_activity,
                (
                    6371 * ACOS(
                        COS(RADIANS(:userLat)) 
                        * COS(RADIANS(u.latitude)) 
                        * COS(RADIANS(u.longitude) - RADIANS(:userLon)) 
                        + SIN(RADIANS(:userLat)) 
                        * SIN(RADIANS(u.latitude))
                    )
                ) AS distance
            FROM usuarios u
            WHERE u.id != :currentUserId
            AND u.last_activity >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            HAVING distance <= 5.0
            ORDER BY distance ASC
        ";

        $conn = $this->entityManager->getConnection();
        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery([
            'userLat' => $userLat,
            'userLon' => $userLon,
            'currentUserId' => $currentUser->getId()
        ]);

        $nearbyUsers = $result->fetchAllAssociative();

        $users = array_map(function ($user) {
            return [
                'id' => $user['id'],
                'username' => $user['username'],
                'latitude' => (float)$user['latitude'],
                'longitude' => (float)$user['longitude'],
                'distance' => round((float)$user['distance'], 2),
                'isOnline' => (bool)$user['is_online'],
                'lastActivity' => $user['last_activity']
            ];
        }, $nearbyUsers);

        return $this->json([
            'success' => true,
            'data' => [
                'users' => $users,
                'total' => count($users)
            ],
            'error' => null,
            'metadata' => [
                'timestamp' => (new \DateTime())->format('c'),
                'radiusKm' => 5.0
            ]
        ]);
    }
}
