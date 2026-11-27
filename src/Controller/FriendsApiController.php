<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\FriendInvitationRepository;
use App\Repository\FriendRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/friends')]
final class FriendsApiController extends AbstractController
{
    public function __construct(
        private readonly FriendRepository $friends,
        private readonly FriendInvitationRepository $invitations,
        private readonly UserRepository $users,
    ) {
    }

    #[Route('', name: 'api_friends_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $userId = $this->requireUserId($request);
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        try {
            return $this->json([
                'success' => true,
                'friends' => $this->friends->listFriendsForUser($userId),
                'incomingInvitations' => $this->invitations->listIncoming($userId),
                'outgoingInvitations' => $this->invitations->listOutgoing($userId),
            ]);
        } catch (Exception $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while loading friends.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/notifications/count', name: 'api_friends_notifications_count', methods: ['GET'])]
    public function notificationCount(Request $request): JsonResponse
    {
        $userId = $this->requireUserId($request);
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        try {
            return $this->json([
                'success' => true,
                'count' => $this->invitations->countIncomingPending($userId),
            ]);
        } catch (Exception $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while loading notifications.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/search', name: 'api_friends_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $userId = $this->requireUserId($request);
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        $query = trim((string) $request->query->get('q', ''));

        try {
            return $this->json([
                'success' => true,
                'users' => $this->friends->searchCandidates($userId, $query),
            ]);
        } catch (Exception $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while searching users.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/suggestions', name: 'api_friends_suggestions', methods: ['GET'])]
    public function suggestions(Request $request): JsonResponse
    {
        $userId = $this->requireUserId($request);
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        try {
            return $this->json([
                'success' => true,
                'users' => $this->friends->suggestCandidates($userId),
            ]);
        } catch (Exception $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while loading suggestions.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/invitations', name: 'api_friends_invite', methods: ['POST'])]
    public function invite(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['success' => false, 'error' => 'Invalid JSON.'], Response::HTTP_BAD_REQUEST);
        }

        $userId = $payload['userId'] ?? null;
        if (!is_numeric($userId) || (int) $userId < 1) {
            return $this->json(['success' => false, 'error' => 'Missing or invalid user id.'], Response::HTTP_BAD_REQUEST);
        }
        $userId = (int) $userId;

        $friendId = $payload['friendId'] ?? null;
        if (!is_numeric($friendId) || (int) $friendId < 1) {
            return $this->json(['success' => false, 'error' => 'Missing or invalid friend id.'], Response::HTTP_BAD_REQUEST);
        }
        $friendId = (int) $friendId;

        if ($this->users->find($userId) === null || $this->users->find($friendId) === null) {
            return $this->json(['success' => false, 'error' => 'User not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($this->friends->areFriends($userId, $friendId)) {
            return $this->json(['success' => false, 'error' => 'You are already friends.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->invitations->sendInvitation($userId, $friendId);

            return $this->json(['success' => true]);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['success' => false, 'error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (Exception $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while sending invitation.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/invitations/{invitationId}/accept', name: 'api_friends_invite_accept', methods: ['POST'])]
    public function acceptInvitation(Request $request, int $invitationId): JsonResponse
    {
        $userId = $this->requireUserIdFromPayloadOrQuery($request);
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        try {
            $this->invitations->acceptInvitation($invitationId, $userId);

            return $this->json(['success' => true]);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['success' => false, 'error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (Exception $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while accepting invitation.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/invitations/{invitationId}', name: 'api_friends_invite_cancel', methods: ['DELETE'])]
    public function cancelInvitation(Request $request, int $invitationId): JsonResponse
    {
        $userId = $this->requireUserId($request);
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        try {
            if (!$this->invitations->cancelInvitation($invitationId, $userId)) {
                return $this->json(['success' => false, 'error' => 'Invitation not found.'], Response::HTTP_NOT_FOUND);
            }

            return $this->json(['success' => true]);
        } catch (Exception $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while cancelling invitation.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/{friendId}', name: 'api_friends_remove', methods: ['DELETE'], requirements: ['friendId' => '\d+'])]
    public function remove(Request $request, int $friendId): JsonResponse
    {
        $userId = $this->requireUserId($request);
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        try {
            if (!$this->friends->removeFriendship($userId, $friendId)) {
                return $this->json(['success' => false, 'error' => 'Friend not found.'], Response::HTTP_NOT_FOUND);
            }

            return $this->json(['success' => true]);
        } catch (Exception $exception) {
            return $this->json([
                'success' => false,
                'error' => 'Database error while removing friend.',
                'details' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function requireUserId(Request $request): int|JsonResponse
    {
        $userId = $request->query->get('userId');
        if (!is_numeric($userId) || (int) $userId < 1) {
            return $this->json(['success' => false, 'error' => 'Missing or invalid user id.'], Response::HTTP_BAD_REQUEST);
        }

        $userId = (int) $userId;
        if ($this->users->find($userId) === null) {
            return $this->json(['success' => false, 'error' => 'User not found.'], Response::HTTP_NOT_FOUND);
        }

        return $userId;
    }

    private function requireUserIdFromPayloadOrQuery(Request $request): int|JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $userId = is_array($payload) ? ($payload['userId'] ?? null) : null;
        $userId ??= $request->query->get('userId');

        if (!is_numeric($userId) || (int) $userId < 1) {
            return $this->json(['success' => false, 'error' => 'Missing or invalid user id.'], Response::HTTP_BAD_REQUEST);
        }

        $userId = (int) $userId;
        if ($this->users->find($userId) === null) {
            return $this->json(['success' => false, 'error' => 'User not found.'], Response::HTTP_NOT_FOUND);
        }

        return $userId;
    }
}
