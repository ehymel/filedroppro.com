<?php

namespace App\Tests\Service;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\TenantNotificationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;

class TenantNotificationServiceTest extends TestCase
{
    public function testNotifySuperusersOfNewTenantNoSuperusers(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $mailer = $this->createMock(MailerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $userRepository->expects($this->once())
            ->method('findSuperusers')
            ->willReturn([]);

        $logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('No superusers found'));

        $mailer->expects($this->never())
            ->method('send');

        $service = new TenantNotificationService($userRepository, $mailer, $logger);

        $tenant = new Tenant();
        $tenant->firmName = 'Test Firm';
        $tenant->joinCode = 'TX-123456';

        $creator = new User();
        $creator->firstName = 'John';
        $creator->lastName = 'Doe';
        $creator->email = 'creator@example.com';

        $service->notifySuperusersOfNewTenant($tenant, $creator);
    }

    public function testNotifySuperusersOfNewTenantSendsEmails(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $mailer = $this->createMock(MailerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $superuser1 = new User();
        $superuser1->email = 'super1@example.com';

        $superuser2 = new User();
        $superuser2->email = 'super2@example.com';

        $userRepository->expects($this->once())
            ->method('findSuperusers')
            ->willReturn([$superuser1, $superuser2]);

        $mailer->expects($this->exactly(2))
            ->method('send')
            ->with($this->callback(function ($email) {
                if (!$email instanceof TemplatedEmail) {
                    return false;
                }
                
                $recipient = $email->getTo()[0]->getAddress();
                
                $hasValidRecipient = in_array($recipient, ['super1@example.com', 'super2@example.com'], true);
                $hasValidSubject = $email->getSubject() === 'New Tenant Created: Test Firm';
                $hasValidTemplate = $email->getHtmlTemplate() === 'emails/new_tenant_notification.html.twig';
                
                $context = $email->getContext();
                $hasValidContext = isset($context['tenant']) && isset($context['creator']);

                return $hasValidRecipient && $hasValidSubject && $hasValidTemplate && $hasValidContext;
            }));

        $service = new TenantNotificationService($userRepository, $mailer, $logger);

        $tenant = new Tenant();
        $tenant->firmName = 'Test Firm';
        $tenant->joinCode = 'TX-123456';

        $creator = new User();
        $creator->firstName = 'John';
        $creator->lastName = 'Doe';
        $creator->email = 'creator@example.com';

        $service->notifySuperusersOfNewTenant($tenant, $creator);
    }

    public function testNotifySuperusersOfNewTenantHandlesMailerExceptions(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $mailer = $this->createMock(MailerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $superuser = new User();
        $superuser->email = 'super@example.com';

        $userRepository->expects($this->once())
            ->method('findSuperusers')
            ->willReturn([$superuser]);

        $mailer->expects($this->once())
            ->method('send')
            ->willThrowException(new \Symfony\Component\Mailer\Exception\TransportException('Connection failed'));

        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Failed to send tenant creation notification email'));

        $service = new TenantNotificationService($userRepository, $mailer, $logger);

        $tenant = new Tenant();
        $tenant->firmName = 'Test Firm';
        $tenant->joinCode = 'TX-123456';

        $creator = new User();
        $creator->firstName = 'John';
        $creator->lastName = 'Doe';
        $creator->email = 'creator@example.com';

        $service->notifySuperusersOfNewTenant($tenant, $creator);
    }
}