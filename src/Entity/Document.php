<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Represents the encrypted physical file stored in your cloud storage (S3/Block volume).
 */
#[ORM\Entity]
class Document extends MappedSuperclassBase
{
    public function __construct()
    {
        $this->documentKeys = new ArrayCollection();
    }

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private(set) ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Client::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(nullable: false)]
    public ?Client $client = null;

    /**
     * Points to the raw, E2E encrypted payload on your block storage / S3 buckets.
     */
    #[ORM\Column(length: 512)]
    #[Assert\NotBlank]
    public ?string $filePath = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $originalFileName = null;

    /**
     * The initialization vector (IV) used in AES-GCM encryption.
     * This is a non-secret required to kick off the client-side decryption ceremony.
     */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    public ?string $iv = null;

    /**
     * Holds the wrapped keys for each authorized staff member.
     */
    #[ORM\OneToMany(targetEntity: DocumentKey::class, mappedBy: 'document', cascade: ['persist', 'remove'])]
    public Collection $documentKeys;
}
