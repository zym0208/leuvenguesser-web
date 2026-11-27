<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;

final class AuthSessionManager
{
    private const USER_ID_KEY = 'lg_user_id';

    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    public function login(Request $request, User $user): void
    {
        $request->getSession()->migrate(true);
        $request->getSession()->set(self::USER_ID_KEY, $user->getId());
    }

    public function logout(Request $request): void
    {
        $request->getSession()->remove(self::USER_ID_KEY);
    }

    public function currentUser(Request $request): ?User
    {
        $userId = $request->getSession()->get(self::USER_ID_KEY);
        if (!is_numeric($userId) || (int) $userId < 1) {
            return null;
        }

        return $this->users->find((int) $userId);
    }

    public function isAdmin(Request $request): bool
    {
        return $this->currentUser($request)?->isAdmin() ?? false;
    }
}
