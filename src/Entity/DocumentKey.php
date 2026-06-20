<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The Key-Wrapping junction entity.
 * Storing a unique wrapped symmetric key (K_sym encrypted with User's K_pub) for each authorized user.
 */
#[ORM\Entity]
#[ORM\Table(name: 'document_keys')]
#[ORM\UniqueConstraint(name: 'uniq_doc_user', columns: ['document_id', 'user_id'])]
class DocumentKey
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private(set) ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class, inversedBy: 'documentKeys')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Document $document = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?User $user = null;

    /**
     * The file's symmetric AES-GCM key encrypted with the associated User's asymmetric Public Key.
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    public ?string $wrappedKeyHex = null;
}
