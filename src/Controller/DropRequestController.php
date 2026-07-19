<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\Document;
use App\Entity\DropRequest;
use App\Form\DropRequestFormType;
use App\Repository\ClientRepository;
use App\Repository\DropRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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

        $template = $request->query->get('ajax') ? '_list.html.twig' : 'manage.html.twig';

        return $this->render('internal/drop_request/'.$template, [
            'requests' => $this->dropRequestRepository->findAllSortedByCreatedAt(),
            'tenant' => $tenant,
        ]);
    }

    #[Route(path: '/new', name: 'new', methods: ['GET', 'POST'])]
    #[IsGranted('CREATE_FILE_REQUEST')]
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

        return $this->render('internal/drop_request/_form.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/resend/{id}', name: 'resend', methods: ['POST'])]
    #[IsGranted('CREATE_FILE_REQUEST')]
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

    #[Route(path: '/soft_delete/{id}', name: 'soft_delete', methods: ['POST'])]
    public function softDelete(DropRequest $dropRequest, Request $request): Response
    {
        // Security Check: Verify that this document belongs to the active user's tenant
        if ($dropRequest->tenant !== $this->getUser()->tenant) {
            $this->addFlash('danger', 'You are not authorized to access this document.');
            return $this->redirectToRoute('unauthorized');
        }

        if (!$this->isCsrfTokenValid('delete_request_' . $dropRequest->id->toString(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid security token.');
            return $this->redirectToRoute('internal_requests_list');
        }

        $dropRequest->deletedAt = new \DateTimeImmutable();
        $dropRequest->deletedBy = $this->getUser();
        $this->dropRequestRepository->save($dropRequest, true);

        $this->addFlash('success', 'Drop request deleted successfully.');

        return $this->redirectToRoute('internal_requests_list');
    }

    #[Route('/delete/{id}', name: 'delete', methods: ['POST'])]
    public function delete(DropRequest $dropRequest, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete_request_' . $dropRequest->id->toString(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid security token.');
            return $this->redirectToRoute('internal_requests_list');
        }

        $this->dropRequestRepository->remove($dropRequest, true);

        $this->addFlash('success', 'The secure request link has been permanently deleted.');

        return $this->redirectToRoute('internal_requests_list');
    }


    #[Route(path: '/update-instructions/{id}', name: 'update_instructions', methods: ['POST'])]
    public function updateInstructions(DropRequest $dropRequest, Request $request): JsonResponse
    {
        if ($dropRequest->tenant !== $this->getUser()->tenant) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $dropRequest->instructions = $data['instructions'] ?? '';

        $this->dropRequestRepository->save($dropRequest, true);

        return new JsonResponse(['success' => true, 'instructions' => $dropRequest->instructions]);
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

        $email = new TemplatedEmail()
            ->to($dropRequest->clientEmail)
            ->subject(sprintf('Secure file request from %s', $tenant->firmName))
            ->htmlTemplate('emails/file_request.html.twig')
            ->context([
                'clientName' => $dropRequest->clientName,
                'firmName' => $tenant->firmName,
                'senderName' => $sender->name.' ('.$sender->email.')',
                'instructions' => $dropRequest->instructions,
                'dropUrl' => $dropUrl
            ]);

        try {
            $this->mailer->send($email);
            return true;
        } catch (\Exception $e) {
            $this->addFlash('danger', $e->getCode() . ': ' . $e->getMessage());
            return false;
        }
    }
}
