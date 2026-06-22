<?php

namespace App\Controller;

use App\Entity\DropRequest;
use App\Entity\User;
use App\Form\DropRequestFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/internal/requests', name: 'internal_requests_')]
#[IsGranted('ROLE_USER')]
class DropRequestController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private MailerInterface $mailer
    ) {}

    #[Route('/', name: 'manage', methods: ['GET', 'POST'])]
    public function manage(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $user->tenant;

        // 1. Handle New Request Form
        $dropRequest = new DropRequest();
        $form = $this->createForm(DropRequestFormType::class, $dropRequest);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $dropRequest->tenant = $tenant;
            $dropRequest->requestedBy = $user;
            // Generate a secure random token for the custom drop link
            $dropRequest->token = 'req_' . bin2hex(random_bytes(24));

            $this->em->persist($dropRequest);
            $this->em->flush();

            if ($this->dispatchRequestEmail($dropRequest)) {
                $this->addFlash('success', sprintf('Secure upload link generated and sent to %s.', $dropRequest->clientEmail));
            } else {
                $this->addFlash('danger', 'The request was saved, but the email failed to send. You can try resending it below.');
            }

            return $this->redirectToRoute('internal_requests_manage');
        }

        // 2. Fetch all outstanding requests for this Tenant
        $requests = $this->em->getRepository(DropRequest::class)->findBy(
            [],
            ['createdAt' => 'DESC']
        );

        return $this->render('secure_drop/request_manage.html.twig', [
            'form' => $form->createView(),
            'requests' => $requests,
            'tenant' => $tenant,
        ]);
    }

    #[Route('/resend/{id}', name: 'resend', methods: ['POST'])]
    public function resend(DropRequest $dropRequest, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('resend_request_' . $dropRequest->id->toString(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid security token.');
            return $this->redirectToRoute('internal_requests_manage');
        }

        if ($this->dispatchRequestEmail($dropRequest)) {
            $this->addFlash('success', sprintf('The request email has been successfully resent to %s.', $dropRequest->clientEmail));
        } else {
            $this->addFlash('danger', 'Failed to resend the email notification.');
        }

        return $this->redirectToRoute('internal_requests_manage');
    }

    #[Route('/revoke/{id}', name: 'revoke', methods: ['POST'])]
    public function revoke(DropRequest $dropRequest, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('revoke_request_' . $dropRequest->id->toString(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid security token.');
            return $this->redirectToRoute('internal_requests_manage');
        }

        $dropRequest->status = 'revoked';
        $this->em->flush();

        $this->addFlash('success', 'The secure request link has been revoked. The client can no longer use it.');

        return $this->redirectToRoute('internal_requests_manage');
    }

    private function dispatchRequestEmail(DropRequest $dropRequest): bool
    {
        $tenant = $dropRequest->tenant;
        $sender = $dropRequest->requestedBy;

        $dropUrl = $this->generateUrl(
            'app_secure_drop_portal',
            [
                'joinCode' => $tenant->joinCode,
                'req' => $dropRequest->token // Pass the tracker token in query string
            ],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $emailPayload = new Email()
            ->from($sender->email)
            ->to($dropRequest->clientEmail)
            ->subject(sprintf('Secure file request from %s', $tenant->firmName))
            ->html($this->renderView('emails/file_request.html.twig', [
                'clientName' => $dropRequest->clientName,
                'firmName' => $tenant->firmName,
                'senderName' => $sender->email,
                'instructions' => $dropRequest->instructions,
                'dropUrl' => $dropUrl
            ]));

        try {
            $this->mailer->send($emailPayload);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
