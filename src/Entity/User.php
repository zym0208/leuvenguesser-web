<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'Users')]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 45)]
    private string $name = '';

    #[ORM\Column(name: 'hashpass', length: 255)]
    private string $hashpass = '';

    #[ORM\Column(options: ['default' => 0])]
    private int $leuven_score = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $challenge_score = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $duel_score = 0;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = ['ROLE_USER'];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getHashpass(): string
    {
        return $this->hashpass;
    }

    public function setHashpass(string $hashpass): static
    {
        $this->hashpass = $hashpass;

        return $this;
    }

    public function getLeuvenScore(): int
    {
        return $this->leuven_score;
    }

    public function setLeuvenScore(int $score): static
    {
        $this->leuven_score = $score;

        return $this;
    }

    public function getChallengeScore(): int
    {
        return $this->challenge_score;
    }

    public function setChallengeScore(int $score): static
    {
        $this->challenge_score = $score;

        return $this;
    }

    public function getDuelScore(): int
    {
        return $this->duel_score;
    }

    public function setDuelScore(int $score): static
    {
        $this->duel_score = $score;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = array_values(array_filter(
            array_map(static fn (mixed $role): string => is_string($role) ? strtoupper(trim($role)) : '', $this->roles),
            static fn (string $role): bool => $role !== ''
        ));

        if (!in_array('ROLE_USER', $roles, true)) {
            $roles[] = 'ROLE_USER';
        }

        return $roles;
    }

    /**
     * @param list<string>|array<int, mixed> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = array_values(array_unique(array_filter(
            array_map(static fn (mixed $role): string => is_string($role) ? strtoupper(trim($role)) : '', $roles),
            static fn (string $role): bool => $role !== ''
        )));

        if ($this->roles === []) {
            $this->roles = ['ROLE_USER'];
        }

        return $this;
    }

    public function isAdmin(): bool
    {
        return in_array('ROLE_ADMIN', $this->getRoles(), true);
    }
}
