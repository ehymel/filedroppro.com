<?php

namespace App\Command;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

#[AsCommand(
    name: 'app:send-onboarding-emails',
    description: 'Scans trial workspaces and dispatches automated lifecycle onboarding emails via Mailtrap.'
)]
class SendOnboardingEmailsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $router
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('E2EE Portal Onboarding Email Automation Engine');

        // Fetch all active tenants currently running under the card-free "trial" plan
        $tenants = $this->em->getRepository(Tenant::class)->findBy([
            'subscriptionPlan' => 'trial',
            'status' => 'active'
        ]);

        if (empty($tenants)) {
            $io->info('No workspaces are currently registered under active free trial programs. Skipping dispatch.');
            return Command::SUCCESS;
        }

        $now = new \DateTimeImmutable('today');
        $emailsSent = 0;

        /** @var Tenant[] $tenants */
        foreach ($tenants as $tenant) {
            $trialEnd = $tenant->currentPeriodEnd;
            if (!$trialEnd) {
                continue;
            }

            // Standard trials last 14 days. Calculate days elapsed:
            // If there are 14 days remaining, elapsed = 0 (Day 1)
            // If there are 11 days remaining, elapsed = 3 (Day 4)
            // If there are 7 days remaining, elapsed = 7 (Day 8)
            // If there are 4 days remaining, elapsed = 10 (Day 11)
            // If there are 1 day remaining, elapsed = 13 (Day 14)
            $daysRemaining = $now->diff($trialEnd)->days;
            $daysElapsed = 14 - $daysRemaining;

            $io->text(sprintf(
                'Checking Workspace: "%s" | Trial Ends: %s | Days Remaining: %d | Days Elapsed: %d',
                $tenant->firmName,
                $trialEnd->format('Y-m-d'),
                $daysRemaining,
                $daysElapsed
            ));

            // Map the exact elapsed day milestones to their respective onboarding templates
            $emailSubject = null;
            $templateName = null;

            switch ($daysElapsed) {
                case 0: // Day 1: Send Immediately
                    $emailSubject = "Welcome to FileDrop Pro: Let's set up your secure drop link (no passwords required)";
                    $templateName = 'emails/onboarding/day1.html.twig';
                    break;
                case 3: // Day 4: ROI of Frictionless Intake
                    $emailSubject = "Are portal password resets draining your billable hours? Let’s calculate the math.";
                    $templateName = 'emails/onboarding/day4.html.twig';
                    break;
                case 7: // Day 8: Compliance & Security Vault Architecture
                    $emailSubject = "The Cryptographic Shield: How zero-knowledge architecture protects your practice";
                    $templateName = 'emails/onboarding/day8.html.twig';
                    break;
                case 10: // Day 11: Soft-Close Upgrade Warning
                    $emailSubject = "Your FileDrop Pro free trial is ending in 3 days. Here is what happens next.";
                    $templateName = 'emails/onboarding/day11.html.twig';
                    break;
                case 14: // Day 15: Hard-Close Trial Expired
                    $emailSubject = "Trial Expired: Your secure drop link is offline. Here is how to restore access.";
                    $templateName = 'emails/onboarding/day15.html.twig';
                    break;
            }

            if ($emailSubject && $templateName) {
                // Locate the primary Administrator of this workspace
                /** @var User[] $tenantUsers */
                $tenantUsers = $this->em->getRepository(User::class)->findActiveForTenant($tenant);

                // Filter to find the Administrator(s) possessing ROLE_ADMIN
                $targetAdmins = array_filter($tenantUsers, function (User $user) {
                    $adminRoles = ['ROLE_ADMIN', 'ROLE_SUPERUSER'];
                    return !empty(array_intersect($adminRoles, $user->getRoles()));
                });

                if (count($targetAdmins) < 1) {
                    $io->warning(sprintf('Unable to find an active Administrator for workspace "%s". Skipping.', $tenant->firmName));
                    continue;
                }

                // Generate system action route endpoints dynamically inside console commands
                $context = $this->router->getContext();
                $context->setHost('filedroppro.com');
                $context->setScheme('https');

                $loginUrl = $this->router->generate('security_login', [], UrlGeneratorInterface::ABSOLUTE_URL);
                $billingUrl = $this->router->generate('internal_billing_dashboard', [], UrlGeneratorInterface::ABSOLUTE_URL);

                try {
                    foreach($targetAdmins as $targetAdmin) {
                        $message = new TemplatedEmail()
                            ->from(new Address('onboarding@filedroppro.com', 'FileDrop Pro Onboarding'))
                            ->to($targetAdmin->email)
                            ->subject($emailSubject)
                            ->htmlTemplate($templateName)
                            ->context([
                                'recipient_name' => $targetAdmin->firstName.' '.$targetAdmin->lastName,
                                'trial_end_date' => $tenant->currentPeriodEnd->format('Y-m-d'),
                                'firm_name' => $tenant->firmName,
                                'login_url' => $loginUrl,
                                'billing_url' => $billingUrl,
                            ]);

                        $this->mailer->send($message);
                        $emailsSent++;

                        $io->success(sprintf('Sent Day %d email to %s (%s)', $daysElapsed + 1, $targetAdmin->email, $tenant->firmName));
                    }

                } catch (\Exception|TransportExceptionInterface $e) {
                    $io->error(sprintf('Failed to send Day %d email to %s: %s', $daysElapsed + 1, $targetAdmin->email, $e->getMessage()));
                }
            }
        }

        $io->success(sprintf('Lifecycle run completed. Total onboarding emails dispatched: %d', $emailsSent));
        return Command::SUCCESS;
    }
}
