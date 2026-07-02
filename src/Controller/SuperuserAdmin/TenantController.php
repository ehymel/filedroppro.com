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
class TenantController extends AbstractController
{
    public function __construct(private readonly TenantRepository $tenantRepository) {}

    #[Route(path: '/', name: 'list')]
    public function index(): Response
    {
        return $this->render('admin/tenant/manage.html.twig', [
            'tenants' => $this->tenantRepository->findAll(),
        ]);
    }
}
