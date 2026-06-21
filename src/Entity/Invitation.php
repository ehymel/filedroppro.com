<?php

namespace App\Entity;

use App\Repository\InvitationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: InvitationRepository::class)]
class Invitation extends MappedSuperclassBase
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private(set) ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Tenant $tenant = null;

    #[ORM\Column(length: 180)]
    public ?string $email = null;

    #[ORM\Column(length: 80, unique: true)]
    public ?string $token = null;

    #[ORM\Column]
    public ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column]
    public bool $used = false;
}
