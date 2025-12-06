<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SubmissionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SubmissionRepository::class)]
#[ORM\Table(name: 'submissions')]
#[ORM\Index(columns: ['installation_id'], name: 'idx_submissions_installation')]
class Submission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'installation_id', type: Types::TEXT)]
    private string $installationId;

    #[ORM\Column(name: 'device_count')]
    private int $deviceCount;

    #[ORM\Column(name: 'submitted_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $submittedAt = null;

    #[ORM\Column(name: 'ip_hash', type: Types::TEXT, nullable: true)]
    private ?string $ipHash = null;

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

    public function getDeviceCount(): int
    {
        return $this->deviceCount;
    }

    public function setDeviceCount(int $deviceCount): static
    {
        $this->deviceCount = $deviceCount;

        return $this;
    }

    public function getSubmittedAt(): ?\DateTimeInterface
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(?\DateTimeInterface $submittedAt): static
    {
        $this->submittedAt = $submittedAt;

        return $this;
    }

    public function getIpHash(): ?string
    {
        return $this->ipHash;
    }

    public function setIpHash(?string $ipHash): static
    {
        $this->ipHash = $ipHash;

        return $this;
    }
}
