<?php

namespace App\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserKey;
use App\Entity\Invitation;
use App\Form\User\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
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
        $user = new User();

        // --- 1. Robust Invitation Token Verification ---
        if ($token) {
            /** @var Invitation $invitation */
            $invitation = $entityManager->getRepository(Invitation::class)->findOneBy([
                'token' => $token,
                'used' => false
            ]);

            if ($invitation) {
                // Scenario A: Token already consumed
                if ($invitation->used) {
                    $this->addFlash('danger', 'This invitation link has already been used. If you have already created your account, please log in below.');
                    return $this->redirectToRoute('security_login');
                }

                // Scenario B: Token expired
                if ($invitation->expiresAt <= new \DateTimeImmutable()) {
                    $this->addFlash('danger', 'This invitation link has expired (tokens are valid for 48 hours). Please ask your administrator to issue a new invitation.');
                    return $this->redirectToRoute('security_login');
                }

                // Scenario C: Valid invitation token
                $hasInvitation = true;
                $tenantName = $invitation->tenant->firmName;
                $user->email = $invitation->email;
            } else {
                // Scenario D: Malicious/Fake token
                $this->addFlash('danger', 'The invitation token provided is invalid or has been revoked.');
                return $this->redirectToRoute('register');
            }
        }

        $form = $this->createForm(RegistrationFormType::class, $user, [
            'has_invitation' => $hasInvitation,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // --- 2. Process Tenant & Validation Paths ---
            if ($hasInvitation && $invitation) {
                $tenant = $invitation->tenant;
                $user->tenant = $tenant;
                $user->status = 'active'; // Approved by invitation
                $invitation->used = true;
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

                    // --- 3. Balanced Security Join Code Validation ---
                    if (!$tenant) {
                        // Apply a defensive timing penalty to mitigate brute-force scripting
                        usleep(random_int(400000, 800000));

                        $form->get('joinCode')->addError(
                            new FormError('The security join code entered is invalid or does not match an active organization.')
                        );

                        return $this->render('user/register.html.twig', [
                            'registrationForm' => $form,
                            'hasInvitation' => $hasInvitation,
                            'tenantName' => $tenantName,
                        ]);
                    }

                    $user->tenant = $tenant;
                    $user->status = 'pending_approval'; // Requires existing admin verification
                    $user->roles = ['ROLE_USER'];
                }
            }

            // --- 4. Validate and Bind E2EE Keys ---
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

            // --- 5. Hash Password & Persist Transaction ---
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
