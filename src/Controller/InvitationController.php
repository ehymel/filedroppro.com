<?php

namespace App\Controller;

use App\Entity\Invitation;
use App\Entity\User;
use App\Form\InvitationFormType;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use Random\RandomException;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Handles the secure administrative invitation workflow.
 * Locked down strictly to Tenant Administrators.
 */
#[Route('/admin/invitation', name: 'admin_invitation_')]
#[IsGranted('ROLE_ADMIN')]
class InvitationController extends AbstractController
{
    public function __construct(private readonly InvitationRepository $invitationRepository, private readonly MailerInterface $mailer, private readonly UserRepository $userRepository) {}

    /**
     * @throws TransportExceptionInterface
     * @throws RandomException
     */
    #[Route('/', name: 'list', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        /** @var User $admin */
        $admin = $this->getUser();
        $tenant = $admin->tenant;

        // 1. Configure the Invitation Form
        $form = $this->createForm(InvitationFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = $form->get('email')->getData();

            // Guard 1: Check if a registered user with this email already exists in the database
            $existingUser = $this->userRepository->findOneByEmail($email);

            if ($existingUser) {
                $this->addFlash('danger', sprintf('A registered user with the email "%s" already exists on the platform.', $email));
                return $this->redirectToRoute('admin_invitation_list');
            }

            // Guard 2: Check if there's already an active, unused invitation outstanding for this email
            $existingInvitation = $this->invitationRepository->findOneBy([
                'email' => $email,
                'used' => false,
            ]);

            if ($existingInvitation && $existingInvitation->expiresAt > new \DateTimeImmutable()) {
                $this->addFlash('danger', sprintf('An active invitation is already outstanding for %s.', $email));
                return $this->redirectToRoute('admin_invitation_list');
            }

            // Generate cryptographically secure invitation token
            $token = 'inv_' . bin2hex(random_bytes(32));

            $invitation = new Invitation();
            $invitation->tenant = $tenant;
            $invitation->email = $email;
            $invitation->token = $token;

            // Invitations expire in exactly 48 hours
            $invitation->expiresAt = new \DateTimeImmutable('+48 hours');
            $invitation->used = false;

            $this->invitationRepository->save($invitation, true);
            $this->sendInvitationEmail($invitation);

            return $this->redirectToRoute('admin_invitation_list');
        }

        // 2. Fetch outstanding invitations
        // Thanks to our custom TenantFilter, this list automatically isolates to invitations matching $tenant!
        $invitations = $this->invitationRepository->findAllSortedByExpiresAt();

        return $this->render('admin/invitation/manage.html.twig', [
            'invitationForm' => $form,
            'invitations' => $invitations,
            'tenant' => $tenant,
        ]);
    }

    /**
     * Renews an unused (pending or expired) invitation with a fresh 48-hour expiration.
     * @throws TransportExceptionInterface|RandomException
     */
    #[Route('/reinvite/{id}', name: 'reinvite', methods: ['POST'])]
    public function reinvite(Invitation $invitation, Request $request): Response
    {
        $tokenName = 'reinvite_invitation_' . $invitation->id->toString();
        if (!$this->isCsrfTokenValid($tokenName, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid security token. Invitation renewal aborted.');
            return $this->redirectToRoute('admin_invitation_list');
        }

        if ($invitation->used) {
            $this->addFlash('danger', 'This invitation has already been accepted. You cannot renew or resend it.');
            return $this->redirectToRoute('admin_invitation_list');
        }

        // Generate a new cryptographically secure token and bump expiration out another 48 hours
        $token = 'inv_' . bin2hex(random_bytes(32));
        $invitation->token = $token;
        $invitation->expiresAt = new \DateTimeImmutable('+48 hours');

        $this->invitationRepository->save($invitation, true);

        $this->addFlash('success', sprintf('The invitation link for %s has been updated and extended.', $invitation->email));

        $this->sendInvitationEmail($invitation);
        return $this->redirectToRoute('admin_invitation_list');
    }

    #[Route('/revoke/{id}', name: 'revoke', methods: ['POST'])]
    public function revoke(Invitation $invitation, Request $request): Response
    {
        if ($this->isCsrfTokenValid('revoke_invitation_' . $invitation->id->toString(), $request->request->get('_token'))) {
            $this->invitationRepository->remove($invitation, true);

            $this->addFlash('success', 'Invitation has been successfully revoked.');
        } else {
            $this->addFlash('danger', 'Invalid security token. Revocation failed.');
        }

        return $this->redirectToRoute('admin_invitation_list');
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

        $templatedEmail = new TemplatedEmail()
            ->to($invitation->email)
            ->subject('FileDrop Pro Portal Invitation')
            ->htmlTemplate('emails/user_invitation.html.twig')
            ->context(['registrationUrl' => $registrationUrl]);
        $this->mailer->send($templatedEmail);

        $this->addFlash('success', sprintf('Invitation successfully mailed to %s.', $invitation->email));

        // Store the link temporarily in the session flash so the admin can copy-paste it directly if needed for testing
        $this->addFlash('invitation_link', $registrationUrl);
    }
}
