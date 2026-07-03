<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\DropRequest;
use App\Form\DropRequestFormType;
use App\Repository\ClientRepository;
use App\Repository\DropRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/internal/requests', name: 'internal_requests_')]
#[IsGranted('ROLE_USER')]
class DropRequestController extends AbstractController
{
    public function __construct(private readonly DropRequestRepository $dropRequestRepository, private readonly MailerInterface $mailer) {}

    #[Route(path: '/', name: 'list')]
    public function list(Request $request): Response
    {
        $tenant = $this->getUser()->tenant;

        if (!$tenant) {
            $this->addFlash('danger', 'You must be associated with this tenant to access this page.');
            return $this->redirectToRoute('unauthorized');
        }

        $template = $request->query->get('ajax') ? '_drop_request_list.html.twig' : 'drop_request_manage.html.twig';

        return $this->render('internal/'.$template, [
            'requests' => $this->dropRequestRepository->findAllSortedByCreatedAt(),
            'tenant' => $tenant,
        ]);
    }

    #[Route(path: '/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, ClientRepository $clientRepository): Response
    {
        $tenant = $this->getUser()->tenant;
        if (!$tenant) {
            if ($request->isXmlHttpRequest() || $request->query->get('ajax')) {
                return new Response('You must be a associated with this tenant to access this page.', Response::HTTP_FORBIDDEN);
            }
            $this->addFlash('danger', 'You must be a associated with this tenant to access this page.');
            return $this->redirectToRoute('unauthorized');
        }

        $dropRequest = new DropRequest();
        $dropRequest->tenant = $tenant;

        // convenience to pre-fill client name
        if ($request->isMethod('GET') && $request->query->get('client_id')) {
            $client = $clientRepository->findOneBy(['id' => $request->query->get('client_id')]);
            $dropRequest->clientName = $client?->clientName;
        }

        $form = $this->createForm(DropRequestFormType::class, $dropRequest);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $dropRequest = $form->getData();
            $dropRequest->token = 'req_' . bin2hex(random_bytes(24));

            $this->dropRequestRepository->save($dropRequest, true);

            if ($this->dispatchRequestEmail($dropRequest)) {
                $this->addFlash('success', sprintf('Secure upload link generated and sent to %s.', $dropRequest->clientEmail));
            } else {
                $this->addFlash('danger', 'The request was saved, but the email failed to send. You can try resending it below.');
            }

            if ($request->request->get('ajax')) {
                return new Response(null, Response::HTTP_NO_CONTENT);
            }
        }

        return $this->render('internal/_drop_request_form.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/resend/{id}', name: 'resend', methods: ['POST'])]
    public function resend(DropRequest $dropRequest, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('resend_request_' . $dropRequest->id->toString(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid security token.');
            return $this->redirectToRoute('internal_requests_list');
        }

        if ($this->dispatchRequestEmail($dropRequest)) {
            $this->addFlash('success', sprintf('The request email has been successfully resent to %s.', $dropRequest->clientEmail));
        } else {
            $this->addFlash('danger', 'Failed to resend the email notification.');
        }

        return $this->redirectToRoute('internal_requests_list');
    }

    #[Route('/revoke/{id}', name: 'revoke', methods: ['POST'])]
    public function revoke(DropRequest $dropRequest, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('revoke_request_' . $dropRequest->id->toString(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid security token.');
            return $this->redirectToRoute('internal_requests_list');
        }

        $dropRequest->status = 'revoked';
        $this->dropRequestRepository->save($dropRequest, true);

        $this->addFlash('success', 'The secure request link has been revoked. The client can no longer use it.');

        return $this->redirectToRoute('internal_requests_list');
    }

    private function dispatchRequestEmail(DropRequest $dropRequest): bool
    {
        $tenant = $dropRequest->tenant;
        $sender = $dropRequest->createdBy;

        $dropUrl = $this->generateUrl(
            'drop_portal',
            [
                'joinCode' => $tenant->joinCode,
                'req' => $dropRequest->token
            ],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $emailPayload = new TemplatedEmail()
//            ->from($sender->email)
            ->to($dropRequest->clientEmail)
            ->subject(sprintf('Secure file request from %s', $tenant->firmName))
            ->html($this->renderView('emails/file_request.html.twig', [
                'clientName' => $dropRequest->clientName,
                'firmName' => $tenant->firmName,
                'senderName' => $sender->name.' ('.$sender->email.')',
                'instructions' => $dropRequest->instructions,
                'dropUrl' => $dropUrl
            ]));

        try {
            $this->mailer->send($emailPayload);
            return true;
        } catch (\Exception $e) {
//            $this->addFlash('danger', $e->getCode() . ': ' . $e->getMessage());
            return false;
        }
    }
}
