<?php

namespace App\Command;

use App\Entity\Tenant;
use App\Entity\User;
use App\Service\StripeBillingService;
use App\Service\UnsubscribeLinkGenerator;
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

#[AsCommand(
    name: 'app:send-onboarding-emails',
    description: 'Scans trial workspaces and dispatches automated lifecycle onboarding emails via Mailtrap.'
)]
class SendOnboardingEmailsCommand extends Command
{
    /** Standard card-free trial length, in days. */
    private const TRIAL_LENGTH_DAYS = 14;

    /** The terminal milestone, sent once the trial has actually lapsed. */
    private const MILESTONE_EXPIRED = 14;

    /**
     * How long after expiry we are still willing to send the "trial expired" email.
     * Bounds the catch-up window so a first run (or a run after a long outage) cannot
     * blast workspaces whose trials lapsed months ago.
     */
    private const EXPIRED_GRACE_DAYS = 7;

    /**
     * Drip milestones keyed by trial days elapsed, in ascending key order.
     * Day 1 is not listed here: it is sent on signup by RegistrationController.
     */
    private const MILESTONES = [
        3 => [
            'label' => 'Day 4',
            'subject' => "Are portal password resets draining your billable hours? Let’s calculate the math.",
            'template' => 'emails/onboarding/day4.html.twig',
        ],
        7 => [
            'label' => 'Day 8',
            'subject' => "The Cryptographic Shield: How zero-knowledge architecture protects your practice",
            'template' => 'emails/onboarding/day8.html.twig',
        ],
        11 => [
            'label' => 'Day 11',
            'subject' => "Your FileDrop Pro free trial is ending in 3 days. Here is what happens next.",
            'template' => 'emails/onboarding/day11.html.twig',
        ],
        self::MILESTONE_EXPIRED => [
            'label' => 'Trial Expired',
            'subject' => "Trial Expired: Your secure drop link is offline. Here is how to restore access.",
            'template' => 'emails/onboarding/day15.html.twig',
        ],
    ];

    private SymfonyStyle $io;
    private int $emailsSent = 0;
    private string $loginUrl;
    private string $billingUrl;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StripeBillingService $stripeBillingService,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $router,
        private readonly UnsubscribeLinkGenerator $unsubscribeLinkGenerator
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->io->title('E2EE Portal Onboarding Email Automation Engine');

        $now = new \DateTimeImmutable('today');
        $this->emailsSent = 0;

        $this->loginUrl = $this->router->generate('security_login', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->billingUrl = $this->router->generate('internal_billing_dashboard', [], UrlGeneratorInterface::ABSOLUTE_URL);

        // Fetch all active tenants currently running under the card-free "trial" plan
        $tenants = $this->em->getRepository(Tenant::class)->findBy([
            'subscriptionPlan' => 'trial',
            'status' => 'active'
        ]);

        if (empty($tenants)) {
            $this->io->info('No workspaces are currently registered under active free trial programs.');
        } else {
            /** @var Tenant[] $tenants */
            foreach ($tenants as $tenant) {
                $this->stripeBillingService->syncSubscriptionStatus($tenant);
                $trialEnd = $tenant->currentPeriodEnd;
                if (!$trialEnd || $now > $trialEnd || $tenant->subscriptionPlan !== 'trial') {
                    continue;
                }

                // Standard trials last 14 days. Calculate days elapsed:
                // If there are 14 days remaining, elapsed = 0 (Day 1)
                // If there are 11 days remaining, elapsed = 3 (Day 4)
                // If there are 7 days remaining, elapsed = 7 (Day 8)
                // If there are 3 days remaining, elapsed = 11 (Day 11)
                $daysRemaining = $now->diff($trialEnd)->days;
                $daysElapsed = self::TRIAL_LENGTH_DAYS - $daysRemaining;

                $lastSent = $this->resolveLastSent($tenant, $daysElapsed);

                $this->io->text(sprintf(
                    'Checking Workspace: "%s" | Trial Ends: %s | Days Remaining: %d | Days Elapsed: %d | Last Milestone Sent: %s',
                    $tenant->firmName,
                    $trialEnd->format('Y-m-d'),
                    $daysRemaining,
                    $daysElapsed,
                    $lastSent === null ? 'none' : (self::MILESTONES[$lastSent]['label'] ?? $lastSent)
                ));

                // Pick the newest milestone this workspace has reached but not yet been sent.
                // Matching on "reached" rather than on an exact day means a missed run is
                // caught up by the next one, and re-running today sends nothing further.
                $milestone = $this->selectMilestone($daysElapsed, $lastSent);

                if ($milestone === null) {
                    continue;
                }

                $this->reportSkippedMilestones($tenant, $daysElapsed, $lastSent, $milestone);
                $this->dispatchMilestone($tenant, $milestone);
            }
        }

        // look for just-suspended trialists
        $tenants = $this->em->getRepository(Tenant::class)->findBy([
            'subscriptionPlan' => 'trial',
            'status' => 'suspended'
        ]);

        if (empty($tenants)) {
            $this->io->info('No workspaces are recently expired.');
        } else {
            /** @var Tenant[] $tenants */
            foreach ($tenants as $tenant) {
                $trialEnd = $tenant->currentPeriodEnd;
                if (!$trialEnd || $now < $trialEnd) {
                    continue;
                }

                // Already told them the trial lapsed; nothing further to send.
                if (($tenant->lastOnboardingDaySent ?? -1) >= self::MILESTONE_EXPIRED) {
                    continue;
                }

                // $daysExpired is 0 on the day after the trial lapsed, because
                // currentPeriodEnd is normalised to 23:59:59 and $now is midnight.
                $daysExpired = $trialEnd->diff($now)->days;

                // Catch up if a run was missed, but never reach back indefinitely.
                if ($daysExpired > self::EXPIRED_GRACE_DAYS) {
                    continue;
                }

                $this->io->text(sprintf(
                    'Checking Workspace: "%s" | Trial Ended: %s (%d day(s) ago)',
                    $tenant->firmName,
                    $trialEnd->format('Y-m-d'),
                    $daysExpired + 1
                ));

                $this->dispatchMilestone($tenant, self::MILESTONE_EXPIRED);
            }
        }

        $this->io->success(sprintf('Lifecycle run completed. Total onboarding emails dispatched: %d', $this->emailsSent));
        return Command::SUCCESS;
    }

    /**
     * Reads the tenant's stored progress, discarding it if it cannot belong to the
     * current trial. A milestone can never legitimately sit ahead of the days elapsed,
     * so a higher value means the workspace started a fresh trial after a previous one
     * and the drip should begin again.
     */
    private function resolveLastSent(Tenant $tenant, int $daysElapsed): ?int
    {
        $lastSent = $tenant->lastOnboardingDaySent;

        if ($lastSent !== null && $lastSent > $daysElapsed) {
            $this->io->text(sprintf(
                'Workspace "%s" is on a new trial (stored milestone %d is ahead of day %d). Restarting the drip.',
                $tenant->firmName,
                $lastSent,
                $daysElapsed
            ));

            $tenant->lastOnboardingDaySent = null;
            $this->em->flush();

            return null;
        }

        return $lastSent;
    }

    /**
     * The highest drip milestone the workspace has reached and not yet been sent,
     * or null when it is up to date. The expiry milestone is handled separately,
     * as it is driven by suspension rather than by trial progress.
     */
    private function selectMilestone(int $daysElapsed, ?int $lastSent): ?int
    {
        $floor = $lastSent ?? -1;
        $selected = null;

        foreach (array_keys(self::MILESTONES) as $milestone) {
            if ($milestone === self::MILESTONE_EXPIRED) {
                continue;
            }

            if ($milestone <= $daysElapsed && $milestone > $floor) {
                $selected = $milestone;
            }
        }

        return $selected;
    }

    /**
     * When runs are missed, only the newest milestone is sent, so the workspace is not
     * hit with several stale drip emails at once. Say which ones were passed over.
     */
    private function reportSkippedMilestones(Tenant $tenant, int $daysElapsed, ?int $lastSent, int $selected): void
    {
        $floor = $lastSent ?? -1;
        $skipped = [];

        foreach (self::MILESTONES as $milestone => $config) {
            if ($milestone === self::MILESTONE_EXPIRED) {
                continue;
            }

            if ($milestone <= $daysElapsed && $milestone > $floor && $milestone < $selected) {
                $skipped[] = $config['label'];
            }
        }

        if ($skipped !== []) {
            $this->io->warning(sprintf(
                'Workspace "%s" is behind: skipping stale milestone(s) %s and sending %s instead.',
                $tenant->firmName,
                implode(', ', $skipped),
                self::MILESTONES[$selected]['label']
            ));
        }
    }

    /**
     * Sends a milestone and records it, so a repeat run the same day is a no-op.
     * Progress is flushed per workspace, so an interrupted run does not resend what it
     * already delivered.
     *
     * Three outcomes have to be told apart. A milestone every recipient has opted out of
     * still counts as done — leaving the pointer alone would re-evaluate, and re-warn about,
     * that workspace on every run from now on. A milestone that failed to send, or that has
     * nobody to send to yet, must stay unrecorded so the next run retries it.
     */
    private function dispatchMilestone(Tenant $tenant, int $milestone): void
    {
        $config = self::MILESTONES[$milestone] ?? null;

        if ($config === null) {
            return;
        }

        $admins = $this->findAdmins($tenant);

        if ($admins === []) {
            $this->io->warning(sprintf('Unable to find an active Administrator for workspace "%s". Skipping.', $tenant->firmName));
            return;
        }

        $recipients = array_filter($admins, static fn (User $user) => $user->onboardingUnsubscribedAt === null);

        if ($recipients === []) {
            $this->io->text(sprintf(
                'Every administrator of workspace "%s" has unsubscribed. Marking %s as handled.',
                $tenant->firmName,
                $config['label']
            ));

            $tenant->lastOnboardingDaySent = $milestone;
            $this->em->flush();

            return;
        }

        if (!$this->sendEmail($tenant, $config, $recipients)) {
            return;
        }

        $tenant->lastOnboardingDaySent = $milestone;
        $this->em->flush();
    }

    /**
     * The active administrators of a workspace, opted out or not.
     *
     * @return User[]
     */
    private function findAdmins(Tenant $tenant): array
    {
        /** @var User[] $tenantUsers */
        $tenantUsers = $this->em->getRepository(User::class)->findActiveForTenant($tenant);

        // Filter to find the Administrator(s) possessing ROLE_ADMIN; include ROLE_SUPERUSER for testing purposes
        return array_filter($tenantUsers, function (User $user) {
            $adminRoles = ['ROLE_ADMIN', 'ROLE_SUPERUSER'];
            return !empty(array_intersect($adminRoles, $user->getRoles()));
        });
    }

    /**
     * @param array{label: string, subject: string, template: string} $config
     * @param User[] $recipients already filtered to those still subscribed
     *
     * @return bool whether at least one administrator was successfully emailed
     */
    private function sendEmail(Tenant $tenant, array $config, array $recipients): bool
    {
        $sent = false;

        foreach($recipients as $targetAdmin) {
            try {
                // Per recipient, not hoisted like the login/billing URLs: the signature
                // covers this administrator's id so it can only opt themselves out.
                $unsubscribeUrl = $this->unsubscribeLinkGenerator->generate($targetAdmin);

                $message = new TemplatedEmail()
                    ->from(new Address('onboarding@filedroppro.com', 'FileDrop Pro Onboarding'))
                    ->to($targetAdmin->email)
                    ->subject($config['subject'])
                    ->htmlTemplate($config['template'])
                    ->context([
                        'recipient_name' => $targetAdmin->firstName.' '.$targetAdmin->lastName,
                        'trial_end_date' => $tenant->currentPeriodEnd->format('Y-m-d'),
                        'firm_name' => $tenant->firmName,
                        'login_url' => $this->loginUrl,
                        'billing_url' => $this->billingUrl,
                        'unsubscribe_url' => $unsubscribeUrl,
                    ]);

                // RFC 8058 one-click unsubscribe, so Gmail and Yahoo surface a native
                // Unsubscribe control next to the sender instead of routing complaints to spam.
                $headers = $message->getHeaders();
                $headers->addTextHeader('List-Unsubscribe', sprintf('<%s>', $unsubscribeUrl));
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

                $this->mailer->send($message);
                $this->emailsSent++;
                $sent = true;

                $this->io->success(sprintf('Sent %s email to %s (%s)', $config['label'], $targetAdmin->email, $tenant->firmName));
            } catch (\Exception|TransportExceptionInterface $e) {
                $this->io->error(sprintf('Failed to send %s email to %s (%s): %s', $config['label'], $targetAdmin->email, $tenant->firmName, $e->getMessage()));
            }
        }

        return $sent;
    }
}
