<?php

namespace App\Controller;

use App\Entity\Usuarios;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api')]
class SecurityController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private ValidatorInterface $validator
    ) {}

    #[Route('/register', name: 'api_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            // Validar datos requeridos
            $requiredFields = ['email', 'password', 'username', 'latitude', 'longitude'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    return $this->json([
                        'success' => false,
                        'error' => "El campo {$field} es requerido",
                        'data' => null,
                        'metadata' => ['timestamp' => (new \DateTime())->format('c')]
                    ], Response::HTTP_BAD_REQUEST);
                }
            }

            // Validar coordenadas
            $lat = (float)$data['latitude'];
            $lon = (float)$data['longitude'];

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

            // Verificar si el email ya existe
            $existingUser = $this->entityManager->getRepository(Usuarios::class)
                ->findOneBy(['email' => $data['email']]);

            if ($existingUser) {
                return $this->json([
                    'success' => false,
                    'error' => 'El email ya está registrado',
                    'data' => null,
                    'metadata' => ['timestamp' => (new \DateTime())->format('c')]
                ], Response::HTTP_CONFLICT);
            }

            // Crear nuevo usuario
            $user = new Usuarios();
            $user->setEmail($data['email']);
            $user->setUsername($data['username']);
            $user->setLatitude((string)$lat);
            $user->setLongitude((string)$lon);

            // Hashear contraseña
            $hashedPassword = $this->passwordHasher->hashPassword($user, $data['password']);
            $user->setPassword($hashedPassword);

            // Campos opcionales
            if (isset($data['nombre'])) {
                $user->setNombre($data['nombre']);
            }
            if (isset($data['apellidos'])) {
                $user->setApellidos($data['apellidos']);
            }
            if (isset($data['telefono'])) {
                $user->setTelefono($data['telefono']);
            }

            // Marcar como activo
            $user->updateActivity();

            // Validar entidad
            $errors = $this->validator->validate($user);
            if (count($errors) > 0) {
                $errorMessages = [];
                foreach ($errors as $error) {
                    $errorMessages[] = $error->getMessage();
                }
                return $this->json([
                    'success' => false,
                    'error' => implode(', ', $errorMessages),
                    'data' => null,
                    'metadata' => ['timestamp' => (new \DateTime())->format('c')]
                ], Response::HTTP_BAD_REQUEST);
            }

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'data' => [
                    'id' => $user->getId(),
                    'email' => $user->getEmail(),
                    'username' => $user->getUsername(),
                    'message' => 'Usuario registrado exitosamente. Ahora puedes iniciar sesión.'
                ],
                'error' => null,
                'metadata' => [
                    'timestamp' => (new \DateTime())->format('c')
                ]
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Error al registrar usuario: ' . $e->getMessage(),
                'data' => null,
                'metadata' => ['timestamp' => (new \DateTime())->format('c')]
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/login', name: 'api_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (!isset($data['email']) || !isset($data['password'])) {
                return $this->json([
                    'success' => false,
                    'error' => 'Email y password son requeridos',
                    'data' => null,
                    'metadata' => ['timestamp' => (new \DateTime())->format('c')]
                ], Response::HTTP_BAD_REQUEST);
            }

            $user = $this->entityManager->getRepository(Usuarios::class)
                ->findOneBy(['email' => $data['email']]);

            if (!$user || !$this->passwordHasher->isPasswordValid($user, $data['password'])) {
                return $this->json([
                    'success' => false,
                    'error' => 'Credenciales inválidas',
                    'data' => null,
                    'metadata' => ['timestamp' => (new \DateTime())->format('c')]
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Crear token API
            $apiToken = new \App\Entity\ApiToken();
            $apiToken->setUser($user);

            $this->entityManager->persist($apiToken);
            $user->updateActivity();
            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'data' => [
                    'token' => $apiToken->getToken(),
                    'expiresAt' => $apiToken->getExpiresAt()->format('c'),
                    'user' => [
                        'id' => $user->getId(),
                        'email' => $user->getEmail(),
                        'username' => $user->getUsername()
                    ]
                ],
                'error' => null,
                'metadata' => ['timestamp' => (new \DateTime())->format('c')]
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Error al iniciar sesión: ' . $e->getMessage(),
                'data' => null,
                'metadata' => ['timestamp' => (new \DateTime())->format('c')]
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/logout', name: 'api_logout', methods: ['POST'])]
    public function logout(): JsonResponse
    {
        /** @var Usuarios $user */
        $user = $this->getUser();

        if ($user) {
            $user->setIsOnline(false);
            $this->entityManager->flush();
        }

        return $this->json([
            'success' => true,
            'data' => ['message' => 'Sesión cerrada exitosamente'],
            'error' => null,
            'metadata' => ['timestamp' => (new \DateTime())->format('c')]
        ]);
    }

    #[Route('/perfil', name: 'api_perfil', methods: ['GET'])]
    public function perfil(): JsonResponse
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

        // Actualizar actividad
        $user->updateActivity();
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'data' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'username' => $user->getUsername(),
                'nombre' => $user->getNombre(),
                'apellidos' => $user->getApellidos(),
                'telefono' => $user->getTelefono(),
                'latitude' => (float)$user->getLatitude(),
                'longitude' => (float)$user->getLongitude(),
                'isOnline' => $user->getIsOnline(),
                'isActive' => $user->isActive(),
                'lastActivity' => $user->getLastActivity()->format('c'),
                'createdAt' => $user->getCreatedAt()->format('c')
            ],
            'error' => null,
            'metadata' => ['timestamp' => (new \DateTime())->format('c')]
        ]);
    }
}
