<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the tamper-proof unsubscribe link carried in the footer of every onboarding email.
 *
 * The link is signed with the application secret rather than backed by a stored token, so
 * there is no token table to provision, expire or clean up. Links are deliberately given no
 * expiry: recipients act on old email, and an unsubscribe that silently stops working is
 * worse than one that stays valid.
 */
readonly class UnsubscribeLinkGenerator
{
    public function __construct(
        private UrlGeneratorInterface $router,
        private UriSigner $uriSigner
    ) {}

    public function generate(User $user): string
    {
        return $this->uriSigner->sign(
            $this->router->generate(
                'email_unsubscribe',
                ['id' => $user->id],
                UrlGeneratorInterface::ABSOLUTE_URL
            )
        );
    }
}
