<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'GameResultRounds')]
#[ORM\UniqueConstraint(name: 'game_result_round_unique', columns: ['game_result_id', 'round_number'])]
class GameResultRound
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: GameResult::class, inversedBy: 'rounds')]
    #[ORM\JoinColumn(name: 'game_result_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?GameResult $gameResult = null;

    #[ORM\Column(name: 'location_id', nullable: true)]
    private ?int $locationId = null;

    #[ORM\Column(name: 'round_number')]
    private int $roundNumber = 0;

    #[ORM\Column(name: 'distance_meters', type: Types::FLOAT, nullable: true)]
    private ?float $distanceMeters = null;

    #[ORM\Column]
    private int $score = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGameResult(): ?GameResult
    {
        return $this->gameResult;
    }

    public function setGameResult(?GameResult $gameResult): static
    {
        $this->gameResult = $gameResult;

        return $this;
    }

    public function getLocationId(): ?int
    {
        return $this->locationId;
    }

    public function setLocationId(?int $locationId): static
    {
        $this->locationId = $locationId;

        return $this;
    }

    public function getRoundNumber(): int
    {
        return $this->roundNumber;
    }

    public function setRoundNumber(int $roundNumber): static
    {
        $this->roundNumber = $roundNumber;

        return $this;
    }

    public function getDistanceMeters(): ?float
    {
        return $this->distanceMeters;
    }

    public function setDistanceMeters(?float $distanceMeters): static
    {
        $this->distanceMeters = $distanceMeters;

        return $this;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function setScore(int $score): static
    {
        $this->score = $score;

        return $this;
    }
}
