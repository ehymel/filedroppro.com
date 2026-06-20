<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\MappedSuperclass]
class MappedSuperclassBase
{
    #[ORM\Column(nullable: true)]
    #[Gedmo\Timestampable(on: 'create')]
    public ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    #[Gedmo\Timestampable(on: 'update')]
    public ?\DateTimeImmutable $modifiedAt = null {
        set(?\DateTimeImmutable $value) {
            $this->modifiedAt = $value ?? new \DateTimeImmutable();
        }
    }

    #[ORM\ManyToOne]
    #[Gedmo\Blameable(on: 'create')]
    public ?User $createdBy = null;

    #[ORM\ManyToOne]
    #[Gedmo\Blameable(on: 'update')]
    public ?User $modifiedBy = null;
}
