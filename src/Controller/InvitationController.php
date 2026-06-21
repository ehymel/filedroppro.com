<?php

namespace App\Controller;

use App\Entity\Invitation;
use App\Entity\User;
use App\Form\InvitationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Handles the secure administrative invitation workflow.
 * Locked down strictly to Tenant Administrators.
 */
#[Route('/admin/invitation', name: 'admin_invitation_')]
#[IsGranted('ROLE_ADMIN')]
class InvitationController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly MailerInterface $mailer) {}

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

            // Check if there's already an active, unused invitation outstanding for this email
            $existingInvitation = $this->em->getRepository(Invitation::class)->findOneBy([
                'email' => $email,
                'used' => false,
            ]);

            if ($existingInvitation && $existingInvitation->getExpiresAt() > new \DateTimeImmutable()) {
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

            $this->em->persist($invitation);
            $this->em->flush();

            // Construct the absolute registration URL to send to the user
            $registrationUrl = $this->generateUrl(
                'register',
                ['token' => $token],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

            $templatedEmail = new TemplatedEmail()
                ->to($email)
                ->subject('FileDrop Pro Portal Invitation')
                ->htmlTemplate('emails/user_invitation.html.twig')
                ->context([
                    'registrationUrl' => $registrationUrl,
                ]);
            $this->mailer->send($templatedEmail);

            $this->addFlash('success', sprintf('Invitation created successfully for %s!', $email));

            // Store the link temporarily in the session flash so the admin can copy-paste it directly if needed for testing
            $this->addFlash('invitation_link', $registrationUrl);

            return $this->redirectToRoute('admin_invitation_list');
        }

        // 2. Fetch outstanding invitations
        // Thanks to our custom TenantFilter, this list automatically isolates to invitations matching $tenant!
        $invitations = $this->em->getRepository(Invitation::class)->findBy(
            [],
            ['expiresAt' => 'DESC']
        );

        return $this->render('admin/invitation/manage.html.twig', [
            'invitationForm' => $form->createView(),
            'invitations' => $invitations,
            'tenant' => $tenant,
        ]);
    }

    #[Route('/revoke/{id}', name: 'revoke', methods: ['POST'])]
    public function revoke(Invitation $invitation, Request $request): Response
    {
        // Enforce CSRF security token validation
        if ($this->isCsrfTokenValid('revoke_invitation_' . $invitation->id->toString(), $request->request->get('_token'))) {
            // Delete the invitation from the database
            $this->em->remove($invitation);
            $this->em->flush();

            $this->addFlash('success', 'Invitation has been successfully revoked.');
        } else {
            $this->addFlash('danger', 'Invalid security token. Revocation failed.');
        }

        return $this->redirectToRoute('admin_invitation_list');
    }
}
