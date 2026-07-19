<?php

namespace App\Service;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

readonly class TenantNotificationService
{
    public function __construct(
        private UserRepository  $userRepository,
        private MailerInterface $mailer,
        private LoggerInterface $logger
    ) {
    }

    public function notifySuperusersOfNewTenant(Tenant $tenant, User $creator): void
    {
        try {
            $superusers = $this->userRepository->findSuperusers();

            if (empty($superusers)) {
                $this->logger->info('No superusers found to notify of new tenant creation.');
                return;
            }

            foreach ($superusers as $superuser) {
                try {
                    $email = new TemplatedEmail()
                        ->to($superuser->email)
                        ->subject('New Tenant Created: ' . $tenant->firmName)
                        ->htmlTemplate('emails/new_tenant_notification.html.twig')
                        ->context([
                            'tenant' => $tenant,
                            'creator' => $creator,
                        ]);

                    $this->mailer->send($email);
                } catch (TransportExceptionInterface $e) {
                    $this->logger->error(sprintf(
                        'Failed to send tenant creation notification email to superuser %s: %s',
                        $superuser->email,
                        $e->getMessage()
                    ));
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('An error occurred during superuser tenant creation notification: ' . $e->getMessage());
        }
    }
}
