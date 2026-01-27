<?php

namespace App\Controller;

use App\Entity\Message;
use App\Entity\Usuarios;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/general')]
class ChatGlobalController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('', name: 'api_general_get', methods: ['GET'])]
    public function getChatGlobal(): JsonResponse
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

        // Actualizar actividad
        $currentUser->updateActivity();
        $this->entityManager->flush();

        $userLat = (float)$currentUser->getLatitude();
        $userLon = (float)$currentUser->getLongitude();

        // Obtener mensajes globales (últimos 100)
        $messages = $this->entityManager->getRepository(Message::class)
            ->createQueryBuilder('m')
            ->where('m.isGlobal = :isGlobal')
            ->setParameter('isGlobal', true)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();

        // Formatear mensajes con distancia del emisor
        $formattedMessages = array_map(function (Message $message) use ($userLat, $userLon) {
            $sender = $message->getSender();
            $senderLat = (float)$sender->getLatitude();
            $senderLon = (float)$sender->getLongitude();

            // Calcular distancia actual entre usuario y emisor
            $distance = $this->calculateDistance($userLat, $userLon, $senderLat, $senderLon);

            return [
                'id' => $message->getId(),
                'content' => $message->getContent(),
                'sender' => [
                    'id' => $sender->getId(),
                    'username' => $sender->getUsername(),
                    'nombre' => $sender->getNombre(),
                ],
                'distanceFromMe' => round($distance, 2),
                'distanceWhenSent' => $message->getDistanceWhenSent() ? (float)$message->getDistanceWhenSent() : null,
                'createdAt' => $message->getCreatedAt()->format('c'),
                'timeAgo' => $this->getTimeAgo($message->getCreatedAt())
            ];
        }, $messages);

        // Obtener usuarios activos en radio
        $sql = "
            SELECT 
                u.id,
                u.username,
                u.nombre,
                u.apellidos,
                u.latitude,
                u.longitude,
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

        $activeUsers = array_map(function ($user) {
            return [
                'id' => $user['id'],
                'username' => $user['username'],
                'nombre' => $user['nombre'],
                'apellidos' => $user['apellidos'],
                'distance' => round((float)$user['distance'], 2),
                'isOnline' => (bool)$user['is_online']
            ];
        }, $result->fetchAllAssociative());

        return $this->json([
            'success' => true,
            'data' => [
                'messages' => array_reverse($formattedMessages), // Orden cronológico
                'activeUsers' => $activeUsers,
                'totalMessages' => count($formattedMessages),
                'totalActiveUsers' => count($activeUsers)
            ],
            'error' => null,
            'metadata' => [
                'timestamp' => (new \DateTime())->format('c'),
                'radiusKm' => 5.0
            ]
        ]);
    }

    #[Route('', name: 'api_general_post', methods: ['POST'])]
    public function sendMessage(Request $request): JsonResponse
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

        $data = json_decode($request->getContent(), true);

        if (!isset($data['content']) || empty(trim($data['content']))) {
            return $this->json([
                'success' => false,
                'error' => 'El mensaje no puede estar vacío',
                'data' => null,
                'metadata' => ['timestamp' => (new \DateTime())->format('c')]
            ], Response::HTTP_BAD_REQUEST);
        }

        $content = trim($data['content']);

        if (strlen($content) > 1000) {
            return $this->json([
                'success' => false,
                'error' => 'El mensaje no puede exceder 1000 caracteres',
                'data' => null,
                'metadata' => ['timestamp' => (new \DateTime())->format('c')]
            ], Response::HTTP_BAD_REQUEST);
        }

        // Crear mensaje
        $message = new Message();
        $message->setContent($content);
        $message->setSender($currentUser);
        $message->setIsGlobal(true);

        // Actualizar actividad
        $currentUser->updateActivity();

        $this->entityManager->persist($message);
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'data' => [
                'message' => [
                    'id' => $message->getId(),
                    'content' => $message->getContent(),
                    'sender' => [
                        'id' => $currentUser->getId(),
                        'username' => $currentUser->getUsername(),
                    ],
                    'createdAt' => $message->getCreatedAt()->format('c')
                ],
                'info' => 'Mensaje enviado al chat global'
            ],
            'error' => null,
            'metadata' => ['timestamp' => (new \DateTime())->format('c')]
        ], Response::HTTP_CREATED);
    }

    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // km

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distance = $earthRadius * $c;

        return $distance;
    }

    private function getTimeAgo(\DateTimeInterface $datetime): string
    {
        $now = new \DateTime();
        $diff = $now->getTimestamp() - $datetime->getTimestamp();

        if ($diff < 60) return 'Hace ' . $diff . ' segundos';
        if ($diff < 3600) return 'Hace ' . floor($diff / 60) . ' minutos';
        if ($diff < 86400) return 'Hace ' . floor($diff / 3600) . ' horas';
        return 'Hace ' . floor($diff / 86400) . ' días';
    }
}
