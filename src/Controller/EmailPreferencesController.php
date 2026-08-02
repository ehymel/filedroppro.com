<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Public, unauthenticated opt-out endpoints for the marketing onboarding drip.
 *
 * Authorisation comes entirely from the signature on the URL (see UnsubscribeLinkGenerator),
 * which is why these routes carry no firewall requirement and the POST performs no CSRF check:
 * a one-click unsubscribe issued by a mail provider under RFC 8058 arrives with no session and
 * no token. The signature covers the user id, so a recipient can only ever opt themselves out.
 *
 * The opt-out is deliberately split across two verbs. Email security scanners (Outlook
 * SafeLinks and friends) routinely prefetch links in messages, so a GET that mutated state
 * would unsubscribe people who never clicked anything.
 */
#[Route('/email', name: 'email_')]
class EmailPreferencesController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UriSigner $uriSigner
    ) {}

    /**
     * Step 1: confirmation page. Safe for link scanners to prefetch — changes nothing.
     */
    #[Route('/unsubscribe/{id}', name: 'unsubscribe', methods: ['GET'])]
    public function confirm(string $id, Request $request): Response
    {
        $user = $this->resolveSignedRecipient($id, $request);

        return $this->render('email_preferences/unsubscribe.html.twig', [
            'recipient_email' => $user->email,
            'already_unsubscribed' => $user->onboardingUnsubscribedAt !== null,
        ]);
    }

    /**
     * Step 2: perform the opt-out. Idempotent, so a repeat submission (or a provider
     * retrying a one-click POST) keeps the original timestamp rather than moving it.
     */
    #[Route('/unsubscribe/{id}', name: 'unsubscribe_confirm', methods: ['POST'])]
    public function unsubscribe(string $id, Request $request): Response
    {
        $user = $this->resolveSignedRecipient($id, $request);

        if ($user->onboardingUnsubscribedAt === null) {
            $user->onboardingUnsubscribedAt = new \DateTimeImmutable();
            $this->em->flush();
        }

        return $this->render('email_preferences/unsubscribed.html.twig', [
            'recipient_email' => $user->email,
        ]);
    }

    /**
     * Rejects anything without a valid signature, then loads the recipient.
     *
     * The lookup suspends tenant_filter so it behaves identically whether the visitor is
     * anonymous (filter off) or happens to be signed in to some other workspace (filter on,
     * and it would otherwise hide the row). suspend()/restore() rather than disable()/enable()
     * for the reason documented in UserAdminController: enable() builds a fresh filter
     * instance and loses the tenant_id set by TenantFilterConfigurator.
     */
    private function resolveSignedRecipient(string $id, Request $request): User
    {
        // AccessDeniedHttpException, not createAccessDeniedException(): the latter throws the
        // security component's exception, which the firewall turns into a login redirect for
        // anonymous visitors. Recipients are anonymous by definition, and the signature — not
        // the firewall — is what authorises them, so a bad link has to be a plain 403.
        if (!$this->uriSigner->checkRequest($request)) {
            throw new AccessDeniedHttpException('This unsubscribe link is invalid or has been altered.');
        }

        // Validate before handing it to Doctrine, so a malformed id is a 404 rather than a
        // 500 from the uuid type failing to convert.
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException('No such recipient.');
        }

        $filters = $this->em->getFilters();
        $wasEnabled = $filters->isEnabled('tenant_filter');
        if ($wasEnabled) {
            $filters->suspend('tenant_filter');
        }

        try {
            $user = $this->em->getRepository(User::class)->findOneBy(['id' => Uuid::fromString($id)]);
        } finally {
            if ($wasEnabled) {
                $filters->restore('tenant_filter');
            }
        }

        if (!$user) {
            throw $this->createNotFoundException('No such recipient.');
        }

        return $user;
    }
}
