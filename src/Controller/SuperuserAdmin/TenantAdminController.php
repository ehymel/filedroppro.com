<?php

declare(strict_types=1);

namespace App\Controller\SuperuserAdmin;

use App\Repository\TenantRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/tenant', name: 'admin_tenant_')]
#[IsGranted('ROLE_SUPERUSER')]
class TenantAdminController extends AbstractController
{
    public function __construct(private readonly TenantRepository $tenantRepository) {}

    #[Route(path: '/', name: 'list')]
    public function index(): Response
    {
        $tenants = $this->tenantRepository->findAll();

        $totalBytes = $documentCount = [];
        foreach ($tenants as $tenant) {
            $totalBytes[$tenant->id->toString()] = 0;
            $documentCount[$tenant->id->toString()] = 0;

            foreach($tenant->clients as $client) {
                foreach($client->documents as $document) {
                    $documentCount[$tenant->id->toString()]++;
                    $totalBytes[$tenant->id->toString()] += $document->fileSize;
                }
            }
        }

        return $this->render('admin/tenant/manage.html.twig', [
            'tenants' => $tenants,
            'totalBytes' => $totalBytes,
            'documentCount' => $documentCount,
        ]);
    }
}
