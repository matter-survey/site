<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InstallationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InstallationRepository::class)]
#[ORM\Table(name: 'installations')]
class Installation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'installation_id', type: Types::TEXT, unique: true)]
    private string $installationId;

    #[ORM\Column(name: 'first_seen', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $firstSeen = null;

    #[ORM\Column(name: 'last_seen', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastSeen = null;

    #[ORM\Column(name: 'submission_count')]
    private int $submissionCount = 1;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInstallationId(): string
    {
        return $this->installationId;
    }

    public function setInstallationId(string $installationId): static
    {
        $this->installationId = $installationId;

        return $this;
    }

    public function getFirstSeen(): ?\DateTimeInterface
    {
        return $this->firstSeen;
    }

    public function setFirstSeen(?\DateTimeInterface $firstSeen): static
    {
        $this->firstSeen = $firstSeen;

        return $this;
    }

    public function getLastSeen(): ?\DateTimeInterface
    {
        return $this->lastSeen;
    }

    public function setLastSeen(?\DateTimeInterface $lastSeen): static
    {
        $this->lastSeen = $lastSeen;

        return $this;
    }

    public function getSubmissionCount(): int
    {
        return $this->submissionCount;
    }

    public function setSubmissionCount(int $submissionCount): static
    {
        $this->submissionCount = $submissionCount;

        return $this;
    }

    public function incrementSubmissionCount(): static
    {
        ++$this->submissionCount;

        return $this;
    }
}
