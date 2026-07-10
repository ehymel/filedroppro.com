<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DefaultController extends AbstractController
{
    #[Route(path: '/', name: 'homepage')]
    public function index(): Response
    {
        if ($this->isGranted('ROLE_USER')) {
            return $this->redirectToRoute('internal_documents_dashboard');
        }

        return $this->render('main/homepage.html.twig');
    }

    #[Route(path: '/unauthorized', name: 'unauthorized')]
    public function unauthorized(): Response
    {
        if ($this->isGranted('ROLE_SUPERUSER')) {
            return $this->redirectToRoute('login_redirect');
        }

        return $this->render('security/unauthorized.html.twig');
    }

    #[Route(path: '/terms', name: 'terms')]
    public function terms(): Response
    {
        return $this->render('main/terms.html.twig');
    }

    #[Route(path: '/privacy', name: 'privacy')]
    public function privacy(): Response
    {
        return $this->render('main/privacy.html.twig');
    }

    #[Route(path: '/support', name: 'support')]
    public function support(): Response
    {
        return $this->render('main/support.html.twig');
    }
}
