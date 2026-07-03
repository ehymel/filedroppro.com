<?php

namespace App\Controller\TenantAdmin;

use App\Entity\Client;
use App\Repository\ClientRepository;
use Random\RandomException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/internal/clients', name: 'internal_client_')]
#[IsGranted('ROLE_ADMIN')]
class ClientController extends AbstractController
{
    public function __construct(private readonly ClientRepository $clientRepository) {}

    /**
     * @throws TransportExceptionInterface
     * @throws RandomException
     */
    #[Route(path: '/', name: 'list')]
    public function list(Request $request): Response
    {
        $tenant = $this->getUser()->tenant;
        if (!$tenant) {
            $this->addFlash('danger', 'You must be a tenant administrator to access this page.');
            return $this->redirectToRoute('unauthorized');
        }

        $template = $request->query->get('ajax') ? '_client_list.html.twig' : 'client_manage.html.twig';

        return $this->render('internal/'.$template, [
            'clients' => $this->clientRepository->findAll(),
            'tenant' => $tenant,
        ]);
    }

    #[Route(path: '/update-name/{id}', name: 'update_name', methods: ['POST'])]
    public function updateName(Client $client, Request $request): JsonResponse
    {
        if ($client->tenant !== $this->getUser()->tenant) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $client->clientName = $data['clientName'] ?? '';

        $this->clientRepository->save($client, true);

        return new JsonResponse(['success' => true, 'clientName' => $client->clientName]);
    }

    #[Route(path: '/delete/{id}', name: 'delete')]
    public function delete(Client $client): Response
    {
        if ($client->tenant !== $this->getUser()->tenant) {
            $this->addFlash('danger', 'You must be a tenant administrator to access this page.');
            return $this->redirectToRoute('unauthorized');
        }

        $this->clientRepository->remove($client, true);

        $this->addFlash('success', 'Client deleted successfully.');

        return $this->redirectToRoute('internal_client_list');
    }
}
