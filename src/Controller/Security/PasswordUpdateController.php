<?php

namespace App\Controller\Security;

use App\Entity\User;
use App\Entity\UserKey;
use App\Form\User\UserPasswordResetForm;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class PasswordUpdateController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em) {}

    /**
     * Authenticated Change Password Ceremony (Safe Re-encryption).
     */
    #[Route('/internal/profile/change-password', name: 'app_profile_change_password', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function changePassword(Request $request, UserPasswordHasherInterface $passwordHasher): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $userKey = $user->userKey;

        $form = $this->createForm(UserPasswordResetForm::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $formData = $form->getData();
            $newPassword = $formData['newPassword'] ?? null;
            $newEncPrivateKeyPayload = $request->request->get('new_encrypted_private_key');

            if (empty($newPassword) || empty($newEncPrivateKeyPayload)) {
                $this->addFlash('danger', 'Cryptographic payload update was rejected. Form submission aborted.');
                return $this->redirectToRoute('app_profile_change_password');
            }

            // 1. Hash and save the login password using Argon2id/Standard Hasher
            $hashedPassword = $passwordHasher->hashPassword($user, $newPassword);
            $user->password = $hashedPassword;

            // 2. Overwrite the encrypted private key envelope with the newly encrypted version
            if ($userKey) {
                $userKey->encryptedPrivateKey = $newEncPrivateKeyPayload;
            }

            $this->em->flush();

            $this->addFlash('success', 'Your account password and security keys have been successfully updated!');
            return $this->redirectToRoute('app_profile_change_password');
        }

        return $this->render('user/password_change.html.twig', [
            'encryptedPrivateKey' => $userKey?->encryptedPrivateKey,
            'form' => $form,
        ]);
    }

    /**
     * Unauthenticated Forgot Password Reset (Generates New Identity & Sets Pending).
     * Note: In production, the reset token validation should match your standard password reset token system.
     */
    #[Route('/reset-password/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function resetForgottenPassword(
        string $token,
        Request $request,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        // Find the user mapped to this reset token. (Mock lookup for demonstration purposes)
        // In your real system, match this to your Token entity or ResetPassword database lookup.
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $request->request->get('email') ?: $request->query->get('email')]);

        if (!$user) {
            $this->addFlash('danger', 'Invalid or expired password reset request parameters.');
            return $this->redirectToRoute('security_login');
        }

        if ($request->isMethod('POST')) {
            $submittedToken = $request->request->get('_token');
            if (!$this->isCsrfTokenValid('reset_forgotten_password', $submittedToken)) {
                $this->addFlash('danger', 'Invalid security token.');
                return $this->redirectToRoute('app_reset_password', ['token' => $token, 'email' => $user->getEmail()]);
            }

            $newPassword = $request->request->get('new_password');
            $newPublicKey = $request->request->get('new_public_key');
            $newEncPrivateKeyPayload = $request->request->get('new_encrypted_private_key');

            if (empty($newPassword) || empty($newPublicKey) || empty($newEncPrivateKeyPayload)) {
                $this->addFlash('danger', 'New cryptographic credentials could not be verified. Please try again.');
                return $this->redirectToRoute('app_reset_password', ['token' => $token, 'email' => $user->getEmail()]);
            }

            // 1. Hash the new password and update login authentication
            $hashedPassword = $passwordHasher->hashPassword($user, $newPassword);
            $user->setPassword($hashedPassword);

            // 2. Overwrite the entire cryptographic identity in the database
            $userKey = $user->getUserKey();
            if (!$userKey) {
                $userKey = new UserKey();
                $userKey->user = $user;
                $this->em->persist($userKey);
            }
            $userKey->publicKey = $newPublicKey;
            $userKey->encryptedPrivateKey = $newEncPrivateKeyPayload;

            // 3. Crucial Security Step: Revoke active status and require Admin Key Sync approval
            // Except for single-user Tenant Creators who act as their own authority, staff users must be re-keyed.
            if (in_array('ROLE_ADMIN', $user->getRoles())) {
                $user->setStatus('active'); // Admins remain active but must be warned they lost past files
            } else {
                $user->setStatus('pending_approval'); // Lock staff account for admin key sync
            }

            $this->em->flush();

            if ($user->getStatus() === 'pending_approval') {
                $this->addFlash('success', 'Your password has been reset successfully. Because your cryptographic keys were regenerated, you must wait for an administrator to approve your account and synchronize historical documents before logging in.');
            } else {
                $this->addFlash('success', 'Password reset successfully! You can now log in, but please note that historical document keys are unrecoverable without an admin key-sync action.');
            }

            return $this->redirectToRoute('security_login');
        }

        return $this->render('user/password_reset.html.twig', [
            'token' => $token,
            'userEmail' => $user->getEmail()
        ]);
    }
}
