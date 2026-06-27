<?php

namespace App\Controller\Security;

use App\Entity\User;
use Erkens\Security\TwoFactorTextBundle\Generator\CodeGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/2fa/', name: '2fa_')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class TwoFAController extends AbstractController
{
    #[Route('manage', name: 'manage')]
    public function manage(): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        return $this->render('security/2fa_manage.html.twig', [
            'user' => $currentUser,
        ]);
    }

    #[Route('email/resend', name: 'email_resend')]
    public function resendAuthEmail(CodeGeneratorInterface $codeGenerator): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $codeGenerator->reSend($user);
        $this->addFlash('success', 'Authentication code re-sent.');

        return $this->redirectToRoute('2fa_login');
    }
}
