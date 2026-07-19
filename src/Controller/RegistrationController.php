<?php

namespace App\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserKey;
use App\Entity\Invitation;
use App\Form\User\RegistrationFormType;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\TenantNotificationService;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class RegistrationController extends AbstractController
{
    #[Route(path: '/register', name: 'register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        EntityManagerInterface $entityManager,
        TenantNotificationService $tenantNotificationService,
        MailerInterface $mailer
    ): Response {
        $isNewTenant = false;
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
                if ($invitation->expiresAt <= new DateTimeImmutable()) {
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

        // Manually bind the dynamic Escrow Key fields onto the form configuration block
        // Only if it's a new tenant registration (NOT an invitation)
        if (!$hasInvitation && $request->isMethod('POST')) {
            $registrationData = $request->request->all();
            $registrationMode = $registrationData['registration_form']['registrationMode'] ?? null;
            if ($registrationMode === 'new') {
                $form->add('tenantPublicKey', HiddenType::class, ['mapped' => false]);
                $form->add('wrappedTenantPrivateKey', HiddenType::class, ['mapped' => false]);
            }
        }

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // --- 2. Process Tenant & Validation Paths ---
            if ($hasInvitation && $invitation) {
                $tenant = $invitation->tenant;
                $user->tenant = $tenant;
                $user->status = User::STATUS_PENDING; // Requires security synchronization by an admin
                $invitation->used = true;
                $user->roles = ['ROLE_USER'];
            } else {
                $mode = $form->get('registrationMode')->getData();

                if ($mode === 'new') {
                    $isNewTenant = true;
                    $tenant = new Tenant();
                    $tenant->firmName = $form->get('firmName')->getData();
                    $tenant->status = 'active';

                    // Start the card-free 14-day trial at creation so the
                    // subscription window is defined from day one (matches
                    // BillingController::subscribe's trial path). Without this the
                    // trial has no end date and the billing dashboard renders it
                    // as already expired.
                    $tenant->subscriptionPlan = 'trial';
                    $tenant->currentPeriodEnd = new \DateTimeImmutable('+14 days');
                    $tenant->cancelAtPeriodEnd = false;

                    // Enforce Institutional Escrow Properties on the new Tenant
                    $tenantPublicKey = $form->get('tenantPublicKey')->getData();
                    $wrappedTenantPrivateKey = $form->get('wrappedTenantPrivateKey')->getData();
                    // Second custody path: escrow key wrapped under the admin's
                    // one-time recovery code (lets a locked-out admin recover).
                    $recoveryWrappedPrivateKey = $form->get('recoveryWrappedPrivateKey')->getData();

                    if ($tenantPublicKey && $wrappedTenantPrivateKey && $recoveryWrappedPrivateKey) {
                        $tenant->tenantPublicKey = $tenantPublicKey;
                        $tenant->wrappedTenantPrivateKey = $wrappedTenantPrivateKey;
                        $tenant->recoveryWrappedPrivateKey = $recoveryWrappedPrivateKey;
                    } else {
                        $this->addFlash('danger', 'Escrow Key pair generation was interrupted. Tenant configuration aborted.');
                        return $this->redirectToRoute('register');
                    }

                    // Generate a unique 12-character uppercase Join Code for the new firm
                    $uniqueJoinCode = 'TX-' . strtoupper(bin2hex(random_bytes(4)));
                    $tenant->joinCode = $uniqueJoinCode;
                    $entityManager->persist($tenant);

                    $user->tenant = $tenant;
                    $user->status = User::STATUS_ACTIVE; // Admins are active by default on creation
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
                    $user->status = User::STATUS_PENDING; // Requires existing admin verification
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

            if ($isNewTenant) {
                $tenantNotificationService->notifySuperusersOfNewTenant($tenant, $user);
            }

            // Dispatch the Day 1 Welcome Onboarding Email if a new organization/tenant is initialized
            if (isset($mode) && $mode === 'new') {
                try {
                    $loginUrl = $this->generateUrl('security_login', [], UrlGeneratorInterface::ABSOLUTE_URL);
                    $billingUrl = $this->generateUrl('internal_billing_dashboard', [], UrlGeneratorInterface::ABSOLUTE_URL);
                    $trialEnd = $tenant->getCurrentPeriodEnd();

                    $message = new TemplatedEmail()
                        ->from(new Address('onboarding@filedroppro.com', 'FileDrop Pro Onboarding'))
                        ->to($user->email)
                        ->subject("Welcome to FileDrop Pro: Let's set up your secure drop link (no passwords required)")
                        ->htmlTemplate('emails/onboarding/day1.html.twig')
                        ->context([
                            'recipient_name' => $user->firstName.' '.$user->lastName,
                            'trial_end_date' => $tenant->currentPeriodEnd->format('Y-m-d'),
                            'firm_name' => $tenant->firmName,
                            'login_url' => $loginUrl,
                            'billing_url' => $billingUrl,
                        ]);

                    $mailer->send($message);
                } catch (\Exception $e) {
                    // Fail silently or log error internally so that registration UX is never interrupted by SMTP issues
                }
            }

            if ($user->status === User::STATUS_PENDING) {
                $this->addFlash('success', 'Account registered! For security, an administrator must now synchronize your cryptographic keys before you can access documents.');
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
