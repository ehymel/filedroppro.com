<?php

namespace App\Controller\TenantAdmin;

use App\Entity\Document;
use App\Entity\DocumentKey;
use App\Entity\Invitation;
use App\Entity\User;
use App\Form\InvitationFormType;
use App\Repository\DocumentKeyRepository;
use App\Repository\DocumentRepository;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use Random\RandomException;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/internal/staff', name: 'internal_staff_')]
#[IsGranted('ROLE_ADMIN')]
class StaffController extends AbstractController
{
    public function __construct(private readonly InvitationRepository $invitationRepository,
                                private readonly MailerInterface      $mailer,
                                private readonly UserRepository       $userRepository,
                                private readonly DocumentRepository   $documentRepository) {}

    /**
     * @throws TransportExceptionInterface
     * @throws RandomException
     */
    #[Route(path: '/', name: 'list')]
    public function list(Request $request): Response
    {
        /** @var User $admin */
        $admin = $this->getUser();
        $tenant = $admin->tenant;
        if (!$tenant) {
            $this->addFlash('danger', 'You must be a tenant administrator to access this page.');
            return $this->redirectToRoute('unauthorized');
        }

        $template = $request->query->get('ajax') ? '_invitation_list.html.twig' : 'manage.html.twig';

        return $this->render('internal/staff/'.$template, [
            'tenant' => $tenant,
            'invitations' => $this->invitationRepository->findAllSortedByExpiresAt(),
            'pendingUsers' => $this->userRepository->findAllPending(),
            'adminEncryptedPrivateKey' => $admin->userKey?->encryptedPrivateKey,
            'tenantWrappedPrivateKey' => $tenant->wrappedTenantPrivateKey,
        ]);
    }

    /**
     * @throws TransportExceptionInterface
     * @throws RandomException
     */
    #[Route(path: '/invite', name: 'invite', methods: ['GET', 'POST'])]
    public function invite(Request $request): Response
    {
        $tenant = $this->getUser()->tenant;
        if (!$tenant) {
            $message = 'You must be a tenant administrator to access this page.';
            if ($request->isXmlHttpRequest() || $request->query->get('ajax')) {
                return new Response($message, Response::HTTP_FORBIDDEN);
            }
            $this->addFlash('danger', $message);
            return $this->redirectToRoute('unauthorized');
        }

        // 1. Configure the Invitation Form
        $invitation = new Invitation();
        $invitation->tenant = $tenant;

        $form = $this->createForm(InvitationFormType::class, $invitation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $invitation = $form->getData();
            $email = $invitation->email;

            // Guard 1: Check if a registered user with this email already exists in the database
            // We disable the tenant filter to check across all tenants
            $filters = $this->userRepository->getEntityManager()->getFilters();
            $tenantFilterEnabled = $filters->isEnabled('tenant_filter');
            if ($tenantFilterEnabled) {
                $filters->disable('tenant_filter');
            }

            $existingUser = $this->userRepository->findOneByEmail($email);

            // Guard 2: Check if there's already an active, unused invitation outstanding for this email
            $existingInvitation = $this->invitationRepository->findOneBy([
                'email' => $email,
                'used' => false,
            ]);

            // Re-enable the filter if it was enabled
            if ($tenantFilterEnabled) {
                $filters->enable('tenant_filter');
                // The filter configurator will have already set the parameter, but just in case
                // or if we want to be explicit, but usually we just want to restore state.
                // However, the SQLFilter state (parameters) is preserved when disabling/enabling.
            }

            if ($existingUser) {
                $form->get('email')->addError(
                    new FormError(sprintf('A registered user with the email "%s" already exists on the platform.', $email))
                );
                return $this->render('internal/staff/_invitation_form.html.twig', [
                    'form' => $form,
                ], new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            if ($existingInvitation && $existingInvitation->expiresAt > new \DateTimeImmutable()) {
                $form->get('email')->addError(
                    new FormError(sprintf('An active invitation is already outstanding for %s.', $email))
                );
                return $this->render('internal/staff/_invitation_form.html.twig', [
                    'form' => $form,
                ], new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $invitation->token = 'inv_' . bin2hex(random_bytes(32));
            $invitation->expiresAt = new \DateTimeImmutable('+48 hours');
            $invitation->used = false;

            $this->invitationRepository->save($invitation, true);
            $this->sendInvitationEmail($invitation);

            if ($request->request->get('ajax')) {
                return new Response(null, Response::HTTP_NO_CONTENT);
            }
        }

        return $this->render('internal/staff/_invitation_form.html.twig', [
            'form' => $form
        ]);
    }

    /**
     * Renews an unused (pending or expired) invitation with a fresh 48-hour expiration.
     * @throws TransportExceptionInterface|RandomException
     */
    #[Route(path: '/reinvite/{id}', name: 'reinvite', methods: ['POST'])]
    public function reinvite(Invitation $invitation, Request $request): Response
    {
        $tokenName = 'reinvite_invitation_' . $invitation->id->toString();
        if (!$this->isCsrfTokenValid($tokenName, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid security token. Invitation renewal aborted.');
            return $this->redirectToRoute('internal_staff_list');
        }

        if ($invitation->used) {
            $this->addFlash('danger', 'This invitation has already been accepted. You cannot renew or resend it.');
            return $this->redirectToRoute('internal_staff_list');
        }

        // Generate a new cryptographically secure token and bump expiration out another 48 hours
        $token = 'inv_' . bin2hex(random_bytes(32));
        $invitation->token = $token;
        $invitation->expiresAt = new \DateTimeImmutable('+48 hours');

        $this->invitationRepository->save($invitation, true);

        $this->addFlash('success', sprintf('The invitation link for %s has been updated and extended.', $invitation->email));

        $this->sendInvitationEmail($invitation);
        return $this->redirectToRoute('internal_staff_list');
    }

    #[Route(path: '/revoke/{id}', name: 'revoke', methods: ['POST'])]
    public function revoke(Invitation $invitation, Request $request): Response
    {
        if ($this->isCsrfTokenValid('revoke_invitation_' . $invitation->id->toString(), $request->request->get('_token'))) {
            $this->invitationRepository->remove($invitation, true);

            $this->addFlash('success', 'Invitation has been successfully revoked.');
        } else {
            $this->addFlash('danger', 'Invalid security token. Revocation failed.');
        }

        return $this->redirectToRoute('internal_staff_list');
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function sendInvitationEmail(Invitation $invitation): void
    {
        // Construct the absolute registration URL to send to the user
        $registrationUrl = $this->generateUrl(
            'register', ['token' => $invitation->token],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $email = new TemplatedEmail()
            ->to($invitation->email)
            ->subject('FileDrop Pro Portal Invitation')
            ->htmlTemplate('emails/user_invitation.html.twig')
            ->context(['registrationUrl' => $registrationUrl]);
        $this->mailer->send($email);

        $this->addFlash('success', sprintf('Invitation successfully mailed to %s.', $invitation->email));

        // Store the link temporarily in the session flash so the admin can copy-paste it directly if needed for testing
        $this->addFlash('invitation_link', $registrationUrl);
    }

    /**
     * API Endpoint: Yields the necessary cryptographic escrow envelopes and the target user's public key.
     */
    #[Route('/sync-data/{id}', name: 'approval_sync', methods: ['GET'])]
    public function getSyncData(User $pendingUser): JsonResponse
    {
        /** @var User $admin */
        $admin = $this->getUser();
        $tenant = $admin->tenant;

        if ($pendingUser->tenant !== $tenant) {
            return new JsonResponse(['error' => 'Access Denied: Tenant mismatch.'], Response::HTTP_FORBIDDEN);
        }

        $pendingUserKey = $pendingUser->userKey;
        if (!$pendingUserKey || !$pendingUserKey->publicKey) {
            return new JsonResponse(['error' => 'The target user has not generated a new public identity key.'], Response::HTTP_BAD_REQUEST);
        }

        // Fetch all documents belonging to this tenant that contain a Master
        // Escrow Envelope. Disable the tenant_filter and pin the tenant here:
        // its CAST(... AS UUID) comparison conflicts with an explicit binary
        // uuid parameter (no row satisfies both). The tenant uuid must be bound
        // with an explicit type, or Doctrine won't match the association id.
        $filters = $this->documentRepository->getEntityManager()->getFilters();
        $tenantFilterWasEnabled = $filters->isEnabled('tenant_filter');
        if ($tenantFilterWasEnabled) {
            $filters->disable('tenant_filter');
        }

        try {
            $documents = $this->documentRepository->createQueryBuilder('d')
                ->join('d.client', 'c')
                ->where('c.tenant = :tenant')
                ->andWhere('d.wrappedEscrowKeyHex IS NOT NULL')
                ->setParameter('tenant', $tenant->id->toString(), 'uuid')
                ->getQuery()
                ->getResult();
        } finally {
            if ($tenantFilterWasEnabled) {
                $filters->enable('tenant_filter');
            }
        }

        $escrowEnvelopes = [];
        /** @var Document $doc */
        foreach ($documents as $doc) {
            $escrowEnvelopes[] = [
                'documentId' => $doc->id->toString(),
                'wrappedEscrowKeyHex' => $doc->wrappedEscrowKeyHex
            ];
        }

        return new JsonResponse([
            'pendingUserPublicKey' => $pendingUserKey->publicKey,
            'wrappedTenantPrivateKey' => $tenant->wrappedTenantPrivateKey,
            'escrowEnvelopes' => $escrowEnvelopes
        ]);
    }

    /**
     * API Endpoint: Receives the re-wrapped key blocks, persists them, and activates the user account.
     */
    #[Route('/submit-sync/{id}', name: 'approval_submit', methods: ['POST'])]
    public function submitSync(User $pendingUser, Request $request, DocumentKeyRepository $documentKeyRepository): JsonResponse
    {
        /** @var User $admin */
        $admin = $this->getUser();
        $tenant = $admin->tenant;

        if ($pendingUser->tenant !== $tenant) {
            return new JsonResponse(['error' => 'Access Denied: Tenant mismatch.'], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        $reKeyedMap = $payload['reKeyedMap'] ?? null; // Map of [documentId => wrappedKeyHex]

        if ($reKeyedMap === null || !is_array($reKeyedMap)) {
            return new JsonResponse(['error' => 'Invalid or missing cryptographic key alignment map.'], Response::HTTP_BAD_REQUEST);
        }

        // Scope documents explicitly by tenant. The active tenant_filter's
        // CAST(... AS UUID) comparison conflicts with an explicit binary uuid
        // parameter (no row satisfies both), so we disable it here and pin the
        // tenant ourselves — the same pattern this controller uses in invite().
        // uuid params must also be bound with an explicit type: Doctrine does not
        // infer it for the JSON string id nor the tenant association id, and
        // without it the row never matches and the re-key loop silently no-ops.
        $filters = $this->documentRepository->getEntityManager()->getFilters();
        $tenantFilterWasEnabled = $filters->isEnabled('tenant_filter');
        if ($tenantFilterWasEnabled) {
            $filters->disable('tenant_filter');
        }

        try {
            foreach ($reKeyedMap as $docId => $wrappedKeyHex) {
                $document = $this->documentRepository->createQueryBuilder('d')
                    ->join('d.client', 'c')
                    ->where('d.id = :id')
                    ->andWhere('c.tenant = :tenant')
                    ->setParameter('id', $docId, 'uuid')
                    ->setParameter('tenant', $tenant->id->toString(), 'uuid')
                    ->getQuery()
                    ->getOneOrNullResult();

                if ($document) {
                    // Remove any stale/old wrapped keys for this specific document/user combination
                    $staleKey = $documentKeyRepository->findOneBy([
                        'document' => $document,
                        'user' => $pendingUser
                    ]);
                    if ($staleKey) {
                        $documentKeyRepository->remove($staleKey, true);
                    }

                    $newKey = new DocumentKey();
                    $newKey->document = $document;
                    $newKey->user = $pendingUser;
                    $newKey->wrappedKeyHex = $wrappedKeyHex;
                    $documentKeyRepository->save($newKey, true);
                }
            }
        } finally {
            if ($tenantFilterWasEnabled) {
                $filters->enable('tenant_filter');
            }
        }

        // Activate the user's account status
        $pendingUser->status = 'active';
        $this->userRepository->save($pendingUser, true);

        $this->addFlash('success', sprintf('Cryptographic keys successfully synchronized! User "%s" is now active with restored file access.', $pendingUser->email));

        return new JsonResponse([
            'success' => true,
            'message' => 'Escrow synchronization completed successfully.'
        ]);
    }
}
