<?php

namespace App\Controller;

use App\Entity\Message;
use App\Entity\PrivateRoom;
use App\Entity\UserRoom;
use App\Entity\Usuarios;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/privado')]
class PrivadoController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('', name: 'api_privado_list', methods: ['GET'])]
    public function listRooms(): JsonResponse
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
        $this->entityManager->flush();

        // Obtener salas del usuario
        $userRooms = $this->entityManager->getRepository(UserRoom::class)
            ->createQueryBuilder('ur')
            ->where('ur.user = :user')
            ->setParameter('user', $currentUser)
            ->getQuery()
            ->getResult();

        $rooms = [];
        foreach ($userRooms as $userRoom) {
            $room = $userRoom->getRoom();

            // Obtener último mensaje de la sala
            $lastMessage = $this->entityManager->getRepository(Message::class)
                ->createQueryBuilder('m')
                ->where('m.room = :room')
                ->setParameter('room', $room)
                ->orderBy('m.createdAt', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            // Obtener participantes
            $participants = $this->entityManager->getRepository(UserRoom::class)
                ->createQueryBuilder('ur')
                ->select('u.id, u.username, u.nombre')
                ->join('ur.user', 'u')
                ->where('ur.room = :room')
                ->setParameter('room', $room)
                ->getQuery()
                ->getArrayResult();

            $rooms[] = [
                'id' => $room->getId(),
                'uuid' => $room->getUuid(),
                'participantsCount' => $room->getParticipantsCount(),
                'participants' => $participants,
                'createdBy' => [
                    'id' => $room->getCreatedBy()->getId(),
                    'username' => $room->getCreatedBy()->getUsername(),
                ],
                'lastMessage' => $lastMessage ? [
                    'content' => $lastMessage->getContent(),
                    'sender' => $lastMessage->getSender()->getUsername(),
                    'createdAt' => $lastMessage->getCreatedAt()->format('c')
                ] : null,
                'createdAt' => $room->getCreatedAt()->format('c'),
                'joinedAt' => $userRoom->getJoinedAt()->format('c')
            ];
        }

        return $this->json([
            'success' => true,
            'data' => [
                'rooms' => $rooms,
                'totalRooms' => count($rooms)
            ],
            'error' => null,
            'metadata' => ['timestamp' => (new \DateTime())->format('c')]
        ]);
    }

    #[Route('/{roomId}', name: 'api_privado_get', methods: ['GET'])]
    public function getRoom(int $roomId): JsonResponse
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

        $room = $this->entityManager->getRepository(PrivateRoom::class)->find($roomId);

        if (!$room) {
            return $this->json([
                'success' => false,
                'error' => 'Sala no encontrada',
                'data' => null,
                'metadata' => ['timestamp' => (new \DateTime())->format('c')]
            ], Response::HTTP_NOT_FOUND);
        }

        // Verificar que el usuario es miembro
        $userRoom = $this->entityManager->getRepository(UserRoom::class)
            ->findOneBy(['user' => $currentUser, 'room' => $room]);

        if (!$userRoom) {
            return $this->json([
                'success' => false,
                'error' => 'No tienes acceso a esta sala',
                'data' => null,
                'metadata' => ['timestamp' => (new \DateTime())->format('c')]
            ], Response::HTTP_FORBIDDEN);
        }

        $currentUser->updateActivity();
        $this->entityManager->flush();

        // Obtener mensajes de la sala
        $messages = $this->entityManager->getRepository(Message::class)
            ->createQueryBuilder('m')
            ->where('m.room = :room')
            ->setParameter('room', $room)
            ->orderBy('m.createdAt', 'ASC')
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();

        $formattedMessages = array_map(function (Message $message) {
            return [
                'id' => $message->getId(),
                'content' => $message->getContent(),
                'sender' => [
                    'id' => $message->getSender()->getId(),
                    'username' => $message->getSender()->getUsername(),
                    'nombre' => $message->getSender()->getNombre(),
                ],
                'createdAt' => $message->getCreatedAt()->format('c'),
                'timeAgo' => $this->getTimeAgo($message->getCreatedAt())
            ];
        }, $messages);

        // Obtener participantes
        $userRooms = $this->entityManager->getRepository(UserRoom::class)
            ->createQueryBuilder('ur')
            ->where('ur.room = :room')
            ->setParameter('room', $room)
            ->getQuery()
            ->getResult();

        $participants = array_map(function (UserRoom $ur) {
            $user = $ur->getUser();
            return [
                'id' => $user->getId(),
                'username' => $user->getUsername(),
                'nombre' => $user->getNombre(),
                'isOnline' => $user->getIsOnline(),
                'joinedAt' => $ur->getJoinedAt()->format('c')
            ];
        }, $userRooms);

        return $this->json([
            'success' => true,
            'data' => [
                'room' => [
                    'id' => $room->getId(),
                    'uuid' => $room->getUuid(),
                    'createdBy' => [
                        'id' => $room->getCreatedBy()->getId(),
                        'username' => $room->getCreatedBy()->getUsername(),
                    ],
                    'participantsCount' => $room->getParticipantsCount(),
                    'createdAt' => $room->getCreatedAt()->format('c')
                ],
                'messages' => $formattedMessages,
                'participants' => $participants,
                'totalMessages' => count($formattedMessages)
            ],
            'error' => null,
            'metadata' => ['timestamp' => (new \DateTime())->format('c')]
        ]);
    }

    #[Route('/{roomId}/mensajes', name: 'api_privado_send_message', methods: ['POST'])]
    public function sendMessage(int $roomId, Request $request): JsonResponse
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

        $room = $this->entityManager->getRepository(PrivateRoom::class)->find($roomId);

        if (!$room) {
            return $this->json([
                'success' => false,
                'error' => 'Sala no encontrada',
                'data' => null,
                'metadata' => ['timestamp' => (new \DateTime())->format('c')]
            ], Response::HTTP_NOT_FOUND);
        }

        // Verificar membresía
        $userRoom = $this->entityManager->getRepository(UserRoom::class)
            ->findOneBy(['user' => $currentUser, 'room' => $room]);

        if (!$userRoom) {
            return $this->json([
                'success' => false,
                'error' => 'No eres miembro de esta sala',
                'data' => null,
                'metadata' => ['timestamp' => (new \DateTime())->format('c')]
            ], Response::HTTP_FORBIDDEN);
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

        $message = new Message();
        $message->setContent($content);
        $message->setSender($currentUser);
        $message->setRoom($room);
        $message->setIsGlobal(false);

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
                'room' => [
                    'id' => $room->getId(),
                    'uuid' => $room->getUuid()
                ]
            ],
            'error' => null,
            'metadata' => ['timestamp' => (new \DateTime())->format('c')]
        ], Response::HTTP_CREATED);
    }

    #[Route('/salir/{roomId}', name: 'api_privado_leave', methods: ['POST'])]
    public function leaveRoom(int $roomId): JsonResponse
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

        $room = $this->entityManager->getRepository(PrivateRoom::class)->find($roomId);

        if (!$room) {
            return $this->json([
                'success' => false,
                'error' => 'Sala no encontrada',
                'data' => null,
                'metadata' => ['timestamp' => (new \DateTime())->format('c')]
            ], Response::HTTP_NOT_FOUND);
        }

        $userRoom = $this->entityManager->getRepository(UserRoom::class)
            ->findOneBy(['user' => $currentUser, 'room' => $room]);

        if (!$userRoom) {
            return $this->json([
                'success' => false,
                'error' => 'No eres miembro de esta sala',
                'data' => null,
                'metadata' => ['timestamp' => (new \DateTime())->format('c')]
            ], Response::HTTP_BAD_REQUEST);
        }

        // Eliminar relación usuario-sala
        $this->entityManager->remove($userRoom);
        $room->decrementParticipants();

        // Si la sala queda vacía, eliminarla completamente
        if ($room->getParticipantsCount() <= 0) {
            $this->entityManager->remove($room);
            $wasDeleted = true;
        } else {
            $wasDeleted = false;
        }

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'data' => [
                'message' => $wasDeleted ? 'Has salido de la sala. La sala ha sido eliminada porque quedó vacía.' : 'Has salido de la sala correctamente',
                'roomDeleted' => $wasDeleted,
                'remainingParticipants' => $wasDeleted ? 0 : $room->getParticipantsCount()
            ],
            'error' => null,
            'metadata' => ['timestamp' => (new \DateTime())->format('c')]
        ]);
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
