<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AuthSessionManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth')]
final class AuthApiController extends AbstractController
{
    private const MAX_NAME_LENGTH = 45;
    private const MIN_PASSWORD_LENGTH = 6;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly AuthSessionManager $authSession,
    ) {
    }

    #[Route('/signup', name: 'api_auth_signup', methods: ['POST'])]
    public function signup(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['success' => false, 'error' => 'Invalid JSON.'], Response::HTTP_BAD_REQUEST);
        }

        $name = $this->normalizeName($payload['name'] ?? null);
        $password = $payload['password'] ?? null;
        if ($name === null) {
            return $this->json([
                'success' => false,
                'error' => sprintf('Name is required and must be 1-%d chars.', self::MAX_NAME_LENGTH),
            ], Response::HTTP_BAD_REQUEST);
        }
        if (!is_string($password) || mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return $this->json([
                'success' => false,
                'error' => sprintf('Password must be at least %d chars.', self::MIN_PASSWORD_LENGTH),
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            if ($this->users->findOneByName($name) !== null) {
                return $this->json(['success' => false, 'error' => 'Name already exists.'], Response::HTTP_CONFLICT);
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            if ($hash === false) {
                return $this->json(['success' => false, 'error' => 'Unable to hash password.'], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $user = (new User())
                ->setName($name)
                ->setHashpass($hash);

            $this->entityManager->persist($user);
            $this->entityManager->flush();
            $this->authSession->login($request, $user);

            return $this->json([
                'success' => true,
                'user' => $this->publicUser($user),
            ], Response::HTTP_CREATED);
        } catch (\Throwable $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while signing up.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['success' => false, 'error' => 'Invalid JSON.'], Response::HTTP_BAD_REQUEST);
        }

        $name = $this->normalizeName($payload['name'] ?? null);
        $password = $payload['password'] ?? null;
        if ($name === null || !is_string($password) || $password === '') {
            return $this->json(['success' => false, 'error' => 'Name and password are required.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $user = $this->users->findOneByName($name);
            if ($user === null) {
                return $this->json(['success' => false, 'error' => 'Invalid credentials.'], Response::HTTP_UNAUTHORIZED);
            }

            $hash = $user->getHashpass();
            if (!password_verify($password, $hash)) {
                return $this->json(['success' => false, 'error' => 'Invalid credentials.'], Response::HTTP_UNAUTHORIZED);
            }

            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                if (is_string($newHash) && $newHash !== '') {
                    $user->setHashpass($newHash);
                    $this->entityManager->flush();
                }
            }

            $this->authSession->login($request, $user);

            return $this->json([
                'success' => true,
                'user' => $this->publicUser($user),
            ]);
        } catch (\Throwable $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while logging in.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/me', name: 'api_auth_me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        $user = $this->authSession->currentUser($request);
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'user' => null], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'success' => true,
            'user' => $this->publicUser($user),
        ]);
    }

    #[Route('/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $this->authSession->logout($request);

        return $this->json(['success' => true]);
    }

    private function normalizeName(mixed $name): ?string
    {
        if (!is_string($name)) {
            return null;
        }

        $trimmed = trim($name);
        if ($trimmed === '' || mb_strlen($trimmed) > self::MAX_NAME_LENGTH) {
            return null;
        }

        return $trimmed;
    }

    /**
     * @return array<string, mixed>
     */
    private function publicUser(User $user): array
    {
        return [
            'id' => $user->getId() ?? 0,
            'name' => $user->getName(),
            'isAdmin' => $user->isAdmin(),
        ];
    }

}
