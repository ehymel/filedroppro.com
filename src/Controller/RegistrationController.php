<?php

namespace App\Controller;

use App\Entity\Invitation;
use App\Entity\Tenant;
use App\Entity\User;
use App\Form\User\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    /**
     * Handles the secure multi-path tenant registration process.
     */
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    #[RateLimit(limiter: 'registrationLimiter')]
    public function register(Request $request, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $entityManager): Response {
        $token = $request->query->get('token');
        $invitation = null;
        $tenant = null;

        // Route A: Verify secure single-use invitation token
        if ($token) {
            /** @var Invitation $invitation */
            $invitation = $entityManager->getRepository(Invitation::class)->findOneBy([
                'token' => $token,
                'used' => false
            ]);

            // Ensure invitation exists and has not expired
            if ($invitation && $invitation->expiresAt > new \DateTimeImmutable()) {
                $tenant = $invitation->tenant;
            } else {
                $this->addFlash('danger', 'This secure invitation link is invalid or has expired.');
                return $this->redirectToRoute('app_register'); // Strip bad token and reload form
            }
        }

        $user = new User();
        if ($invitation) {
            $user->email = $invitation->email; // Force invite email
        }

        // Initialize the form, dynamically passing options based on whether we have an active invitation payload
        $form = $this->createForm(RegistrationFormType::class, $user, [
            'has_invitation' => (bool)$invitation
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Hash the raw client-side password before storing
            $plainPassword = $form->get('plainPassword')->getData();
            $user->password = $passwordHasher->hashPassword($user, $plainPassword);

            if ($invitation) {
                // Scenario 1: User registering via secure invitation link
                $user->tenant = $tenant;
                $user->roles = ['ROLE_USER'];

                // Active on creation as they have been pre-screened and invited by an Admin
                $user->status = 'active';
                $invitation->used = true;

                $entityManager->persist($user);
                $this->addFlash('success', 'Your account has been securely set up! You can now log in.');

            } else {
                $mode = $form->get('registrationMode')->getData();

                if ($mode === 'new') {
                    // Scenario 2: Register a new primary Tenant (The Firm)
                    $firmName = $form->get('firmName')->getData();

                    $newTenant = new Tenant();
                    $newTenant->firmName = $firmName;
                    $newTenant->status = 'active';

                    // Generate a high-entropy secure unique Join Code for out-of-band requests
                    $secureRandomCode = strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
                    $newTenant->joinCode = 'TX-' . $secureRandomCode; // Prefix TX for region

                    $user->tenant = $newTenant;
                    $user->roles = ['ROLE_ADMIN']; // The creator of the tenant defaults to administrator
                    $user->status = 'active';

                    $entityManager->persist($newTenant);
                    $entityManager->persist($user);

                    $this->addFlash('success', sprintf('Firm registration successful! Your Secure Organization Join Code is: %s. Keep this safe!', $newTenant->joinCode));

                } elseif ($mode === 'join') {
                    // Scenario 3: Requesting to join an existing Tenant using a blind lookup
                    $joinCode = trim((string) $form->get('joinCode')->getData());

                    $targetTenant = $entityManager->getRepository(Tenant::class)->findOneBy([
                        'joinCode' => $joinCode
                    ]);

                    // Double-Blind Feedback Pattern: Prevent database timing scans and user mapping
                    // We introduce a standardized delay to secure the response timing
                    usleep(random_int(100000, 400000)); // Sleep between 100ms - 400ms

                    if (!$targetTenant) {
                        // Deliver a generic success message so malicious actors cannot verify if a join code is valid
                        $this->addFlash('success', 'Registration submitted. If a matching organization was found, your join request is now awaiting administrator approval.');
                        return $this->redirectToRoute('security_login');
                    }

                    $user->tenant = $targetTenant;
                    $user->roles = ['ROLE_USER'];

                    // Crucial: Must be manually approved by the tenant admin before they can access files or fetch public keys
                    $user->status = 'pending_approval';

                    $entityManager->persist($user);

                    $this->addFlash('success', 'Registration submitted. Your account is now awaiting administrator approval.');
                }
            }

            $entityManager->flush();
            return $this->redirectToRoute('security_login'); // Direct to login for standard Web Crypto initialization
        }

        return $this->render('user/register.html.twig', [
            'registrationForm' => $form->createView(),
            'hasInvitation' => (bool)$invitation,
            'tenantName' => $tenant?->firmName
        ]);
    }
}
