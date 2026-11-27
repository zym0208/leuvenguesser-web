<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ChallengeRepository;
use App\Repository\UserRepository;
use App\Service\AuthSessionManager;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin')]
final class AdminApiController extends AbstractController
{
    public function __construct(
        private readonly AuthSessionManager $authSession,
        private readonly ChallengeRepository $challenges,
        private readonly UserRepository $users,
    ) {
    }

    #[Route('/me', name: 'api_admin_me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        $user = $this->authSession->currentUser($request);
        if ($user === null || !$user->isAdmin()) {
            return $this->json(['success' => false, 'error' => 'Admin login required.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'success' => true,
            'user' => [
                'id' => $user->getId(),
                'name' => $user->getName(),
                'isAdmin' => true,
            ],
        ]);
    }

    #[Route('/challenges', name: 'api_admin_challenges', methods: ['GET'])]
    public function challenges(Request $request): JsonResponse
    {
        if (!$this->authSession->isAdmin($request)) {
            return $this->adminRequired();
        }

        $filters = [
            'sort' => $request->query->getString('sort', 'newest'),
            'status' => $request->query->getString('status', 'all'),
            'q' => trim($request->query->getString('q', '')),
            'author' => trim($request->query->getString('author', '')),
            'region' => trim($request->query->getString('region', '')),
        ];

        try {
            return $this->json([
                'success' => true,
                'challenges' => $this->challenges->listChallengeSetsForAdmin($filters),
            ]);
        } catch (Exception $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while loading admin challenge sets.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/challenges/{id}', name: 'api_admin_challenge_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function challengeDetail(Request $request, int $id): JsonResponse
    {
        if (!$this->authSession->isAdmin($request)) {
            return $this->adminRequired();
        }

        try {
            $challenge = $this->challenges->findChallengeSetForAdmin($id);
        } catch (Exception $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while loading admin challenge set.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($challenge === null) {
            return $this->json(['success' => false, 'error' => 'Challenge set not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['success' => true, 'challenge' => $challenge]);
    }

    #[Route('/challenges/{id}/publish', name: 'api_admin_challenge_publish', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function publishChallenge(Request $request, int $id): JsonResponse
    {
        return $this->setPublished($request, $id, true);
    }

    #[Route('/challenges/{id}/unpublish', name: 'api_admin_challenge_unpublish', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function unpublishChallenge(Request $request, int $id): JsonResponse
    {
        return $this->setPublished($request, $id, false);
    }

    #[Route('/challenges/{id}', name: 'api_admin_challenge_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteChallenge(Request $request, int $id): JsonResponse
    {
        if (!$this->authSession->isAdmin($request)) {
            return $this->adminRequired();
        }

        try {
            if (!$this->challenges->deleteChallengeSetForAdmin($id)) {
                return $this->json(['success' => false, 'error' => 'Challenge set not found.'], Response::HTTP_NOT_FOUND);
            }

            return $this->json(['success' => true]);
        } catch (Exception $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while deleting challenge set.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/comments/{id}', name: 'api_admin_comment_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteComment(Request $request, int $id): JsonResponse
    {
        if (!$this->authSession->isAdmin($request)) {
            return $this->adminRequired();
        }

        try {
            if (!$this->challenges->deleteCommentForAdmin($id)) {
                return $this->json(['success' => false, 'error' => 'Comment not found.'], Response::HTTP_NOT_FOUND);
            }

            return $this->json(['success' => true]);
        } catch (Exception $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while deleting comment.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/users', name: 'api_admin_users', methods: ['GET'])]
    public function users(Request $request): JsonResponse
    {
        if (!$this->authSession->isAdmin($request)) {
            return $this->adminRequired();
        }

        return $this->json([
            'success' => true,
            'users' => $this->users->listForAdmin(),
        ]);
    }

    private function setPublished(Request $request, int $id, bool $published): JsonResponse
    {
        if (!$this->authSession->isAdmin($request)) {
            return $this->adminRequired();
        }

        try {
            if (!$this->challenges->setChallengeSetPublishedForAdmin($id, $published)) {
                return $this->json(['success' => false, 'error' => 'Challenge set not found.'], Response::HTTP_NOT_FOUND);
            }

            return $this->json(['success' => true, 'published' => $published]);
        } catch (Exception $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while updating publish status.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function adminRequired(): JsonResponse
    {
        return $this->json(['success' => false, 'error' => 'Admin login required.'], Response::HTTP_FORBIDDEN);
    }
}
