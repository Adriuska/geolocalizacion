<?php

namespace App\Controller;

use App\Entity\Message;
use App\Entity\Invitation;
use App\Entity\UserRoom;
use App\Entity\Usuarios;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class UpdatesController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('/api/updates', name: 'api_updates', methods: ['GET'])]
    public function getUpdates(Request $request): JsonResponse
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

        $currentUser->updateActivity();

        // Obtener timestamp del último check (opcional)
        $lastCheck = $request->query->get('since');
        $sinceDateTime = $lastCheck ? new \DateTime($lastCheck) : new \DateTime('-5 minutes');

        // 1. Contar mensajes globales nuevos (sin filtro de distancia para simplificar)
        $newGlobalMessages = $this->entityManager->getRepository(Message::class)
            ->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.isGlobal = :isGlobal')
            ->andWhere('m.createdAt > :since')
            ->andWhere('m.sender != :user')
            ->setParameter('isGlobal', true)
            ->setParameter('since', $sinceDateTime)
            ->setParameter('user', $currentUser)
            ->getQuery()
            ->getSingleScalarResult();

        $lat = $currentUser->getLatitude();
        $lon = $currentUser->getLongitude();

        // 2. Contar mensajes en salas privadas del usuario
        $userRooms = $this->entityManager->getRepository(UserRoom::class)
            ->createQueryBuilder('ur')
            ->select('IDENTITY(ur.room)')
            ->where('ur.user = :user')
            ->setParameter('user', $currentUser)
            ->getQuery()
            ->getSingleColumnResult();

        $roomIds = $userRooms;

        $newPrivateMessages = 0;
        if (!empty($roomIds)) {
            $newPrivateMessages = $this->entityManager->getRepository(Message::class)
                ->createQueryBuilder('m')
                ->select('COUNT(m.id)')
                ->where('m.room IN (:rooms)')
                ->andWhere('m.createdAt > :since')
                ->andWhere('m.sender != :user')
                ->setParameter('rooms', $roomIds)
                ->setParameter('since', $sinceDateTime)
                ->setParameter('user', $currentUser)
                ->getQuery()
                ->getSingleScalarResult();
        }

        // 3. Contar invitaciones pendientes
        $pendingInvitations = $this->entityManager->getRepository(Invitation::class)
            ->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.receiver = :user')
            ->andWhere('i.status = :status')
            ->setParameter('user', $currentUser)
            ->setParameter('status', 'pending')
            ->getQuery()
            ->getSingleScalarResult();

        // 4. Usuarios cercanos activos (dentro de 5km)
        $nearbyUsers = [];
        if ($lat && $lon) {
            $sql = "
                SELECT u.id, u.username, u.nombre, u.is_online,
                    (6371 * acos(
                        cos(radians(:lat)) * cos(radians(u.latitude)) * 
                        cos(radians(u.longitude) - radians(:lon)) + 
                        sin(radians(:lat)) * sin(radians(u.latitude))
                    )) AS distance
                FROM usuarios u
                WHERE u.id != :userId
                    AND u.is_online = 1
                    AND (6371 * acos(
                        cos(radians(:lat)) * cos(radians(u.latitude)) * 
                        cos(radians(u.longitude) - radians(:lon)) + 
                        sin(radians(:lat)) * sin(radians(u.latitude))
                    )) <= 5
                ORDER BY distance ASC
                LIMIT 20
            ";

            $stmt = $this->entityManager->getConnection()->prepare($sql);
            $result = $stmt->executeQuery([
                'lat' => $lat,
                'lon' => $lon,
                'userId' => $currentUser->getId()
            ]);

            $nearbyUsers = $result->fetchAllAssociative();
            $nearbyUsers = array_map(function ($user) {
                return [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'nombre' => $user['nombre'],
                    'isOnline' => (bool)$user['is_online'],
                    'distance' => round($user['distance'], 2)
                ];
            }, $nearbyUsers);
        }

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'data' => [
                'newMessages' => [
                    'global' => (int)$newGlobalMessages,
                    'private' => (int)$newPrivateMessages,
                    'total' => (int)$newGlobalMessages + (int)$newPrivateMessages
                ],
                'pendingInvitations' => (int)$pendingInvitations,
                'nearbyUsers' => [
                    'count' => count($nearbyUsers),
                    'users' => $nearbyUsers
                ],
                'user' => [
                    'isOnline' => $currentUser->getIsOnline(),
                    'lastActivity' => $currentUser->getLastActivity()?->format('c')
                ],
                'since' => $sinceDateTime->format('c')
            ],
            'error' => null,
            'metadata' => [
                'timestamp' => (new \DateTime())->format('c'),
                'checkInterval' => '30-60 seconds recommended'
            ]
        ]);
    }
}
