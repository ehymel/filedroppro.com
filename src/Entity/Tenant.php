<?php

namespace App\Entity;

use App\Repository\TenantRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The Tenant represents the overall Firm (the business entity).
 * All queries in the SaaS database should be filtered by Tenant to ensure strict multi-tenant isolation.
 */
#[ORM\Entity(repositoryClass: TenantRepository::class)]
class Tenant extends MappedSuperclassBase
{
    public function __construct()
    {
        $this->users = new ArrayCollection();
        $this->clients = new ArrayCollection();
    }

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private(set) ?Uuid $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    public ?string $firmName = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    public ?string $status = 'active'; // active, suspended, trial, etc.

    #[ORM\OneToMany(targetEntity: User::class, mappedBy: 'tenant', cascade: ['persist', 'remove'])]
    public Collection $users;

    #[ORM\OneToMany(targetEntity: Client::class, mappedBy: 'tenant', cascade: ['persist', 'remove'])]
    public Collection $clients;

    #[ORM\Column(length: 64, unique: true, nullable: true)]
    public ?string $joinCode = null; // e.g. "TX-LAW-1092"
}
