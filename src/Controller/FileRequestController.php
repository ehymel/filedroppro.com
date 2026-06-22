<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\DropRequestFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/internal/request-file', name: 'internal_')]
#[IsGranted('ROLE_USER')]
class FileRequestController extends AbstractController
{
    #[Route('', name: 'request_file', methods: ['GET', 'POST'])]
    public function requestFile(Request $request, MailerInterface $mailer): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $user->tenant;

        $form = $this->createForm(DropRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            // Generate the absolute URL to your public zero-login secure drop zone
            $dropUrl = $this->generateUrl(
                'app_secure_drop_portal',
                ['joinCode' => $tenant->joinCode],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

            // Construct the E2EE compliance disclaimer and mail copy
            $emailPayload = new Email()
                ->from($user->email)
                ->to($data['clientEmail'])
                ->subject(sprintf('Secure file request from %s', $tenant->firmName))
                ->html($this->renderView('emails/file_request.html.twig', [
                    'clientName' => $data['clientName'],
                    'firmName' => $tenant->firmName,
                    'senderName' => $user->email, // Fall back to email if profile names aren't set
                    'customMessage' => $data['customMessage'],
                    'dropUrl' => $dropUrl
                ]));

            try {
                $mailer->send($emailPayload);
                $this->addFlash('success', sprintf('Secure upload invitation successfully sent to %s.', $data['clientEmail']));
            } catch (\Exception $e) {
                $this->addFlash('danger', 'Failed to dispatch notification email via local transport mechanisms.');
            }

            return $this->redirectToRoute('internal_request_file');
        }

        return $this->render('secure_drop/request_manage.html.twig', [
            'form' => $form->createView(),
            'tenant' => $tenant,
        ]);
    }
}
