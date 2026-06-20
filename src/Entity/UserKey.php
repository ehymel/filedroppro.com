<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Stores the asymmetric key material for a user.
 * The private key is encrypted client-side using a key derived from the user's password (K_master).
 */
#[ORM\Entity]
class UserKey
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private(set) ?Uuid $id = null;

    #[ORM\OneToOne(targetEntity: User::class, inversedBy: 'userKey')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?User $user = null;

    /**
     * The public key (K_pub), stored unencrypted in PEM format.
     * Anyone can fetch this to encrypt file keys for this user.
     */
    #[ORM\Column(type: Types::TEXT)]
    public ?string $publicKey = null;

    /**
     * The private key (K_priv), encrypted locally in-browser using K_master.
     * The server cannot read this block.
     */
    #[ORM\Column(type: Types::TEXT)]
    public ?string $encryptedPrivateKey = null;
}
