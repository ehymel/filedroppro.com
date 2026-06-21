<?php

namespace App\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserKey;
use App\Entity\Invitation;
use App\Form\User\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        EntityManagerInterface $entityManager
    ): Response {
        $token = $request->query->get('token');
        $hasInvitation = false;
        $tenantName = '';
        $invitation = null;

        if ($token) {
            $invitation = $entityManager->getRepository(Invitation::class)->findOneBy([
                'token' => $token,
                'used' => false
            ]);

            if ($invitation && $invitation->getExpiresAt() > new \DateTimeImmutable()) {
                $hasInvitation = true;
                $tenantName = $invitation->getTenant()->getFirmName();
            }
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user, [
            'has_invitation' => $hasInvitation,
        ]);

        if ($hasInvitation && $invitation) {
            $form->get('email')->setData($invitation->getEmail());
        }

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // 1. Process Tenant setup depending on the selected registration path
            if ($hasInvitation && $invitation) {
                $tenant = $invitation->getTenant();
                $user->tenant = $tenant;
                $user->status = 'active'; // Approved by invitation
                $invitation->setUsed(true);
                $user->roles = ['ROLE_USER'];
            } else {
                $mode = $form->get('registrationMode')->getData();

                if ($mode === 'new') {
                    $tenant = new Tenant();
                    $tenant->firmName = $form->get('firmName')->getData();
                    $tenant->status = 'active';

                    // Generate a unique 12-character uppercase Join Code for the new firm
                    $uniqueJoinCode = 'TX-' . strtoupper(bin2hex(random_bytes(4)));
                    $tenant->joinCode = $uniqueJoinCode;
                    $entityManager->persist($tenant);

                    $user->tenant = $tenant;
                    $user->status = 'active'; // Admins are active by default on creation
                    $user->roles = ['ROLE_ADMIN'];
                } else {
                    $joinCode = strtoupper(trim((string)$form->get('joinCode')->getData()));
                    $tenant = $entityManager->getRepository(Tenant::class)->findOneBy([
                        'joinCode' => $joinCode
                    ]);

                    // Anti-Bruteforce timing decoy and blind response for missing join codes
                    if (!$tenant) {
                        usleep(random_int(500000, 1500000));
                        $this->addFlash('success', 'If a matching company was found, your request has been dispatched to administrators.');
                        return $this->redirectToRoute('security_login');
                    }

                    $user->tenant = $tenant;
                    $user->status = 'pending_approval'; // Requires existing admin verification
                    $user->roles = ['ROLE_USER'];
                }
            }

            // 2. Extract and bind the secure E2EE keys generated in-browser via Javascript
            $publicKey = $form->get('publicKey')->getData();
            $encryptedPrivateKey = $form->get('encryptedPrivateKey')->getData();

            if ($publicKey && $encryptedPrivateKey) {
                $userKey = new UserKey();
                $userKey->publicKey = $publicKey;
                $userKey->encryptedPrivateKey = $encryptedPrivateKey;
                $userKey->user = $user;

                $user->userKey = $userKey;
                $entityManager->persist($userKey);
            } else {
                $this->addFlash('danger', 'Cryptographic key generation was interrupted in your browser. Account creation aborted.');
                return $this->redirectToRoute('register', $token ? ['token' => $token] : []);
            }

            // 3. Hash password and persist user
            $user->password = $userPasswordHasher->hashPassword($user, $form->get('plainPassword')->getData());

            $entityManager->persist($user);
            $entityManager->flush();

            if (!$hasInvitation && isset($mode) && $mode === 'join') {
                $this->addFlash('success', 'Your request to join the organization has been dispatched to administrators for approval.');
                return $this->redirectToRoute('security_login');
            }

            $this->addFlash('success', 'Account registered successfully! Please log in to initialize your secure session.');
            return $this->redirectToRoute('security_login');
        }

        return $this->render('user/register.html.twig', [
            'registrationForm' => $form,
            'hasInvitation' => $hasInvitation,
            'tenantName' => $tenantName,
        ]);
    }
}
