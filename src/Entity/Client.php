<?php

namespace App\Entity;

use App\Repository\ClientRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Represents a Client (or Patient) of the firm.
 * Access is controlled on a multi-user level through the assigned staff users.
 */
#[ORM\Entity(repositoryClass: ClientRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_client_tenant_name', columns: ['tenant_id', 'client_name'])]
class Client extends MappedSuperclassBase
{
    public function __construct()
    {
        $this->users = new ArrayCollection();
        $this->documents = new ArrayCollection();
    }

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private(set) ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class, inversedBy: 'clients')]
    #[ORM\JoinColumn(nullable: false)]
    public ?Tenant $tenant = null;

    #[ORM\Column]
    #[Assert\NotBlank]
    public ?string $clientName = null;

    /**
     * Access Control List: Maps which internal Users (Staff) can access files for this client.
     */
    #[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'clients')]
    #[ORM\JoinTable(name: 'client_access')]
    public Collection $users;

    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'client', cascade: ['persist', 'remove'])]
    public Collection $documents;

    public function addUser(User $user): self
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
        }
        return $this;
    }

    public function removeUser(User $user): self
    {
        $this->users->removeElement($user);
        return $this;
    }
}
