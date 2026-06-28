<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\TenantRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/tenant', name: 'admin_tenant_')]
class TenantController extends AbstractController
{
    public function __construct(private readonly TenantRepository $tenantRepository) {}

    #[Route('/', name: 'list')]
    public function index(): Response
    {
        return $this->render('admin/tenant/manage.html.twig', [
            'tenants' => $this->tenantRepository->findAll(),
        ]);
    }
}
