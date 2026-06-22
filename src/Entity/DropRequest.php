<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Tracks secure file drop requests sent to external clients.
 */
#[ORM\Entity]
class DropRequest extends MappedSuperclassBase
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private(set) ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?User $requestedBy = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    public ?string $clientName = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    public ?string $clientEmail = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $instructions = null;

    /**
     * The unique cryptographic token appended to the URL to map the upload back to this request.
     */
    #[ORM\Column(length: 80, unique: true)]
    public ?string $token = null;

    #[ORM\Column(length: 20)]
    public string $status = 'pending'; // pending, fulfilled, revoked
}
