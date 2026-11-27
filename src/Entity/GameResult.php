<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GameResultRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GameResultRepository::class)]
#[ORM\Table(name: 'GameResults')]
class GameResult
{
    public const MODE_LEUVEN = 'leuven_challenge';
    public const MODE_WORLD = 'world_challenge';
    public const MODE_DUEL = 'duel_mode';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $mode = '';

    #[ORM\Column(name: 'challenge_set_id', nullable: true)]
    private ?int $challengeSetId = null;

    #[ORM\Column(name: 'user_id')]
    private int $userId = 0;

    public const SCORE_PER_ROUND = 1000;

    #[ORM\Column(name: 'total_score')]
    private int $totalScore = 0;

    #[ORM\Column(name: 'round_count')]
    private int $roundCount = 0;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, GameResultRound>
     */
    #[ORM\OneToMany(targetEntity: GameResultRound::class, mappedBy: 'gameResult', cascade: ['persist'], orphanRemoval: true)]
    private Collection $rounds;

    public function __construct()
    {
        $this->rounds = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setMode(string $mode): static
    {
        $this->mode = $mode;

        return $this;
    }

    public function getChallengeSetId(): ?int
    {
        return $this->challengeSetId;
    }

    public function setChallengeSetId(?int $challengeSetId): static
    {
        $this->challengeSetId = $challengeSetId;

        return $this;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function setUserId(int $userId): static
    {
        $this->userId = $userId;

        return $this;
    }

    public function getTotalScore(): int
    {
        return $this->totalScore;
    }

    public function setTotalScore(int $totalScore): static
    {
        $this->totalScore = $totalScore;

        return $this;
    }

    public function getMaxScore(): int
    {
        return $this->roundCount * self::SCORE_PER_ROUND;
    }

    public function getRoundCount(): int
    {
        return $this->roundCount;
    }

    public function setRoundCount(int $roundCount): static
    {
        $this->roundCount = $roundCount;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * @return Collection<int, GameResultRound>
     */
    public function getRounds(): Collection
    {
        return $this->rounds;
    }

    public function addRound(GameResultRound $round): static
    {
        if (!$this->rounds->contains($round)) {
            $this->rounds->add($round);
            $round->setGameResult($this);
        }

        return $this;
    }
}
