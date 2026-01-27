<?php

namespace App\Controller;

use App\Entity\Invitation;
use App\Entity\PrivateRoom;
use App\Entity\UserRoom;
use App\Entity\Usuarios;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/invitar')]
class InvitacionController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('', name: 'api_invitar', methods: ['POST'])]
    public function invitar(Request $request): JsonResponse
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

        if (!isset($data['userIds']) || !is_array($data['userIds']) || empty($data['userIds'])) {
            return $this->json([
                'success' => false,
                'error' => 'Debes proporcionar al menos un usuario para invitar',
                'data' => null,
                'metadata' => ['timestamp' => (new \DateTime())->format('c')]
            ], Response::HTTP_BAD_REQUEST);
        }

        $userIds = array_map('intval', $data['userIds']);
        $roomId = $data['roomId'] ?? null;

        // Si se especifica roomId, verificar que el usuario sea miembro
        if ($roomId) {
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
                ], Response::HTTP_FORBIDDEN);
            }
        } else {
            // Crear nueva sala
            $room = new PrivateRoom();
            $room->setCreatedBy($currentUser);
            $this->entityManager->persist($room);

            // Añadir al creador como participante
            $creatorUserRoom = new UserRoom();
            $creatorUserRoom->setUser($currentUser);
            $creatorUserRoom->setRoom($room);
            $this->entityManager->persist($creatorUserRoom);
            $room->incrementParticipants();
        }

        // Verificar usuarios y crear invitaciones
        $invitations = [];
        $errors = [];

        foreach ($userIds as $userId) {
            // No invitarse a sí mismo
            if ($userId == $currentUser->getId()) {
                $errors[] = "No puedes invitarte a ti mismo";
                continue;
            }

            $user = $this->entityManager->getRepository(Usuarios::class)->find($userId);

            if (!$user) {
                $errors[] = "Usuario con ID $userId no encontrado";
                continue;
            }

            // Verificar que el usuario esté activo
            if (!$user->isActive()) {
                $errors[] = "Usuario {$user->getUsername()} no está activo";
                continue;
            }

            // Verificar si ya es miembro
            $existingUserRoom = $this->entityManager->getRepository(UserRoom::class)
                ->findOneBy(['user' => $user, 'room' => $room]);

            if ($existingUserRoom) {
                $errors[] = "Usuario {$user->getUsername()} ya es miembro de la sala";
                continue;
            }

            // Verificar si ya tiene invitación pendiente
            $existingInvitation = $this->entityManager->getRepository(Invitation::class)
                ->findOneBy(['receiver' => $user, 'room' => $room, 'status' => 'pending']);

            if ($existingInvitation) {
                $errors[] = "Usuario {$user->getUsername()} ya tiene una invitación pendiente";
                continue;
            }

            // Límite de participantes (máximo 10)
            if ($room->getParticipantsCount() >= 10) {
                $errors[] = "La sala ha alcanzado el límite máximo de 10 participantes";
                break;
            }

            // Crear invitación
            $invitation = new Invitation();
            $invitation->setSender($currentUser);
            $invitation->setReceiver($user);
            $invitation->setRoom($room);
            $this->entityManager->persist($invitation);

            $invitations[] = [
                'id' => $invitation->getId(),
                'receiver' => [
                    'id' => $user->getId(),
                    'username' => $user->getUsername(),
                ],
                'status' => 'pending'
            ];
        }

        $currentUser->updateActivity();
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'data' => [
                'room' => [
                    'id' => $room->getId(),
                    'uuid' => $room->getUuid(),
                    'participantsCount' => $room->getParticipantsCount()
                ],
                'invitationsSent' => count($invitations),
                'invitations' => $invitations,
                'errors' => $errors,
                'message' => count($invitations) > 0 ?
                    "Se enviaron " . count($invitations) . " invitaciones" :
                    "No se pudo enviar ninguna invitación"
            ],
            'error' => null,
            'metadata' => ['timestamp' => (new \DateTime())->format('c')]
        ], Response::HTTP_CREATED);
    }

    #[Route('/pendientes', name: 'api_invitar_pendientes', methods: ['GET'])]
    public function pendientes(): JsonResponse
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

        $invitations = $this->entityManager->getRepository(Invitation::class)
            ->createQueryBuilder('i')
            ->where('i.receiver = :user')
            ->andWhere('i.status = :status')
            ->setParameter('user', $currentUser)
            ->setParameter('status', 'pending')
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $formattedInvitations = array_map(function (Invitation $invitation) {
            $room = $invitation->getRoom();
            $sender = $invitation->getSender();

            // Contar participantes actuales
            $participantsCount = $room->getParticipantsCount();

            return [
                'id' => $invitation->getId(),
                'sender' => [
                    'id' => $sender->getId(),
                    'username' => $sender->getUsername(),
                    'nombre' => $sender->getNombre(),
                ],
                'room' => [
                    'id' => $room->getId(),
                    'uuid' => $room->getUuid(),
                    'participantsCount' => $participantsCount,
                    'createdBy' => [
                        'id' => $room->getCreatedBy()->getId(),
                        'username' => $room->getCreatedBy()->getUsername(),
                    ]
                ],
                'createdAt' => $invitation->getCreatedAt()->format('c'),
                'status' => $invitation->getStatus()
            ];
        }, $invitations);

        return $this->json([
            'success' => true,
            'data' => [
                'invitations' => $formattedInvitations,
                'total' => count($formattedInvitations)
            ],
            'error' => null,
            'metadata' => ['timestamp' => (new \DateTime())->format('c')]
        ]);
    }

    #[Route('/aceptar/{invitationId}', name: 'api_invitar_aceptar', methods: ['POST'])]
    public function aceptar(int $invitationId): JsonResponse
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

        $invitation = $this->entityManager->getRepository(Invitation::class)->find($invitationId);

        if (!$invitation) {
            return $this->json([
                'success' => false,
                'error' => 'Invitación no encontrada',
                'data' => null,
                'metadata' => ['timestamp' => (new \DateTime())->format('c')]
            ], Response::HTTP_NOT_FOUND);
        }

        if ($invitation->getReceiver()->getId() !== $currentUser->getId()) {
            return $this->json([
                'success' => false,
                'error' => 'Esta invitación no es para ti',
                'data' => null,
                'metadata' => ['timestamp' => (new \DateTime())->format('c')]
            ], Response::HTTP_FORBIDDEN);
        }

        if (!$invitation->isPending()) {
            return $this->json([
                'success' => false,
                'error' => 'Esta invitación ya ha sido procesada',
                'data' => null,
                'metadata' => ['timestamp' => (new \DateTime())->format('c')]
            ], Response::HTTP_BAD_REQUEST);
        }

        $room = $invitation->getRoom();

        // Verificar límite de participantes
        if ($room->getParticipantsCount() >= 10) {
            return $this->json([
                'success' => false,
                'error' => 'La sala ha alcanzado el límite máximo de participantes',
                'data' => null,
                'metadata' => ['timestamp' => (new \DateTime())->format('c')]
            ], Response::HTTP_BAD_REQUEST);
        }

        // Añadir usuario a la sala
        $userRoom = new UserRoom();
        $userRoom->setUser($currentUser);
        $userRoom->setRoom($room);
        $this->entityManager->persist($userRoom);

        $room->incrementParticipants();
        $invitation->accept();

        $currentUser->updateActivity();
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'data' => [
                'message' => 'Te has unido a la sala correctamente',
                'room' => [
                    'id' => $room->getId(),
                    'uuid' => $room->getUuid(),
                    'participantsCount' => $room->getParticipantsCount()
                ]
            ],
            'error' => null,
            'metadata' => ['timestamp' => (new \DateTime())->format('c')]
        ]);
    }

    #[Route('/rechazar/{invitationId}', name: 'api_invitar_rechazar', methods: ['POST'])]
    public function rechazar(int $invitationId): JsonResponse
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

        $invitation = $this->entityManager->getRepository(Invitation::class)->find($invitationId);

        if (!$invitation) {
            return $this->json([
                'success' => false,
                'error' => 'Invitación no encontrada',
                'data' => null,
                'metadata' => ['timestamp' => (new \DateTime())->format('c')]
            ], Response::HTTP_NOT_FOUND);
        }

        if ($invitation->getReceiver()->getId() !== $currentUser->getId()) {
            return $this->json([
                'success' => false,
                'error' => 'Esta invitación no es para ti',
                'data' => null,
                'metadata' => ['timestamp' => (new \DateTime())->format('c')]
            ], Response::HTTP_FORBIDDEN);
        }

        if (!$invitation->isPending()) {
            return $this->json([
                'success' => false,
                'error' => 'Esta invitación ya ha sido procesada',
                'data' => null,
                'metadata' => ['timestamp' => (new \DateTime())->format('c')]
            ], Response::HTTP_BAD_REQUEST);
        }

        $invitation->reject();
        $currentUser->updateActivity();
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'data' => [
                'message' => 'Invitación rechazada'
            ],
            'error' => null,
            'metadata' => ['timestamp' => (new \DateTime())->format('c')]
        ]);
    }
}
