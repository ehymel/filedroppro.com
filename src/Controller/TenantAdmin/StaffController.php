<?php

namespace App\Controller\TenantAdmin;

use App\Entity\Invitation;
use App\Form\InvitationFormType;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use Random\RandomException;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/internal/staff', name: 'internal_staff_')]
#[IsGranted('ROLE_ADMIN')]
class StaffController extends AbstractController
{
    public function __construct(private readonly InvitationRepository $invitationRepository, private readonly MailerInterface $mailer, private readonly UserRepository $userRepository) {}

    /**
     * @throws TransportExceptionInterface
     * @throws RandomException
     */
    #[Route('/', name: 'list')]
    public function index(Request $request): Response
    {
        $tenant = $this->getUser()->tenant;
        if (!$tenant) {
            $this->addFlash('danger', 'You must be a tenant administrator to access this page.');
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

            $this->addFlash('info', 'Invitation sent successfully.');

            // Guard 1: Check if a registered user with this email already exists in the database
            $existingUser = $this->userRepository->findOneByEmail($email);

            if ($existingUser) {
                $this->addFlash('danger', sprintf('A registered user with the email "%s" already exists on the platform.', $email));
                if ($request->query->get('ajax') || $request->isXmlHttpRequest()) {
                    return $this->render('internal/_invitation_form.html.twig', [
                        'form' => $form,
                    ], new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
                }
                return $this->redirectToRoute('internal_staff_list');
            }

            // Guard 2: Check if there's already an active, unused invitation outstanding for this email
            $existingInvitation = $this->invitationRepository->findOneBy([
                'email' => $email,
                'used' => false,
            ]);

            if ($existingInvitation && $existingInvitation->expiresAt > new \DateTimeImmutable()) {
                $this->addFlash('danger', sprintf('An active invitation is already outstanding for %s.', $email));
                if ($request->query->get('ajax') || $request->isXmlHttpRequest()) {
                    return $this->render('internal/_invitation_form.html.twig', [
                        'form' => $form,
                    ], new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
                }
                return $this->redirectToRoute('internal_staff_list');
            }

            $invitation->token = 'inv_' . bin2hex(random_bytes(32));
            $invitation->expiresAt = new \DateTimeImmutable('+48 hours');
            $invitation->used = false;

            $this->invitationRepository->save($invitation, true);
            $this->sendInvitationEmail($invitation);

//            if ($request->request->get('ajax')) {
//                return new Response(null, Response::HTTP_NO_CONTENT);
//            }

            return $this->redirectToRoute('internal_staff_list');
        }

        return $this->render('internal/invitation_manage.html.twig', [
            'invitations' => $this->invitationRepository->findAllSortedByExpiresAt(),
            'tenant' => $tenant,
            'form' => $form,
        ]);
    }

    /**
     * @throws TransportExceptionInterface
     * @throws RandomException
     */
    #[Route('/invite', name: 'invite', methods: ['GET', 'POST'])]
    public function invite(Request $request): Response
    {
        $tenant = $this->getUser()->tenant;
        if (!$tenant) {
            $this->addFlash('danger', 'You must be a tenant administrator to access this page.');
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

            $this->addFlash('info', 'Invitation sent successfully.');

            // Guard 1: Check if a registered user with this email already exists in the database
            $existingUser = $this->userRepository->findOneByEmail($email);

            if ($existingUser) {
                $form->get('email')->addError(
                    new FormError('A registered user with the email "%s" already exists on the platform.', $email)
                );
                return new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Guard 2: Check if there's already an active, unused invitation outstanding for this email
            $existingInvitation = $this->invitationRepository->findOneBy([
                'email' => $email,
                'used' => false,
            ]);

            if ($existingInvitation && $existingInvitation->expiresAt > new \DateTimeImmutable()) {
                $this->addFlash('danger', sprintf('An active invitation is already outstanding for %s.', $email));
                if ($request->query->get('ajax') || $request->isXmlHttpRequest()) {
                    return $this->render('internal/_invitation_form.html.twig', [
                        'form' => $form,
                    ], new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
                }
                return $this->redirectToRoute('internal_staff_list');
            }

            $invitation->token = 'inv_' . bin2hex(random_bytes(32));
            $invitation->expiresAt = new \DateTimeImmutable('+48 hours');
            $invitation->used = false;

            $this->invitationRepository->save($invitation, true);
            $this->sendInvitationEmail($invitation);

            if ($request->request->get('ajax')) {
                return new Response(null, Response::HTTP_NO_CONTENT);
            }

            return $this->redirectToRoute('internal_staff_list');
        }

        return $this->render('internal/_invitation_form.html.twig', [
            'form' => $form
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

    #[Route('/revoke/{id}', name: 'revoke', methods: ['POST'])]
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
