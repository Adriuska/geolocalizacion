<?php

namespace App\Controller;

use App\Entity\Usuarios;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
final class HomeController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('/home', name: 'api_home', methods: ['GET'])]
    public function index(): JsonResponse
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

        // Actualizar actividad del usuario
        $currentUser->updateActivity();
        $this->entityManager->flush();

        $userLat = (float)$currentUser->getLatitude();
        $userLon = (float)$currentUser->getLongitude();

        // Obtener usuarios activos en radio de 5km usando Haversine
        $sql = "
            SELECT 
                u.id,
                u.email,
                u.username,
                u.nombre,
                u.apellidos,
                u.latitude,
                u.longitude,
                u.last_activity,
                u.is_online,
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

        // Formatear usuarios
        $users = array_map(function ($user) {
            return [
                'id' => $user['id'],
                'email' => $user['email'],
                'username' => $user['username'],
                'nombre' => $user['nombre'],
                'apellidos' => $user['apellidos'],
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
                'currentUser' => [
                    'id' => $currentUser->getId(),
                    'username' => $currentUser->getUsername(),
                    'latitude' => $userLat,
                    'longitude' => $userLon
                ],
                'nearbyUsers' => $users,
                'totalUsersNearby' => count($users),
                'radiusKm' => 5.0
            ],
            'error' => null,
            'metadata' => [
                'timestamp' => (new \DateTime())->format('c'),
                'radiusKm' => 5.0
            ]
        ]);
    }
}
