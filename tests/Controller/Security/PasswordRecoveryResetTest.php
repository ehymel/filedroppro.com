<?php

namespace App\Tests\Controller\Security;

use App\Entity\Client;
use App\Entity\Document;
use App\Entity\DocumentKey;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserKey;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Covers the recovery-code escrow recovery on the canonical forgot-password
 * flow (PasswordResetController::executeReset).
 *
 * When an admin forgets their password, resetting regenerates their keypair and
 * — without recovery — leaves the tenant escrow key and their DocumentKeys
 * wrapped under the lost old key (access bricked). Supplying the recovery code
 * lets the browser re-wrap the escrow key + document keys under the new key.
 *
 * The browser-side WebCrypto can't run in PHPUnit, so these tests post
 * representative payloads and assert the SERVER persistence/behavior.
 */
class PasswordRecoveryResetTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->set(S3Client::class, new RecoveryResetFakeS3Client());
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAdminResetWithRecoveryCodeRestoresEscrowAndDocumentAccess(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createAdmin($tenant);
        $document = $this->createDocumentWithAdminKey($tenant, $admin, 'stale-admin-hex');
        $adminId = $admin->id->toString();
        $tenantId = $tenant->id->toString();
        $documentId = $document->id->toString();

        $csrf = $this->beginReset($admin->email);
        $this->client->request('POST', '/user/password/reset/reset-token', [
            '_token' => $csrf,
            'new_password' => 'BrandNewPass123',
            'new_public_key' => 'regenerated-admin-public-key',
            'new_encrypted_private_key' => 'regenerated-admin-encrypted-private-key',
            // Recovery payload produced by the browser from the recovery code:
            'new_wrapped_tenant_private_key' => 're-wrapped-tenant-key-under-new-admin-key',
            're_keyed_map' => json_encode([$documentId => 'recovered-admin-hex']),
        ]);
        $this->assertResponseRedirects('/login');

        $this->em->clear();

        // Escrow key is re-established under the new admin key.
        $this->assertSame(
            're-wrapped-tenant-key-under-new-admin-key',
            $this->em->find(Tenant::class, $tenantId)->wrappedTenantPrivateKey
        );

        // Admin stays active and their document key is the freshly re-wrapped one.
        $refreshedAdmin = $this->em->find(User::class, $adminId);
        $this->assertSame(User::STATUS_ACTIVE, $refreshedAdmin->status);
        $refreshedDoc = $this->em->find(Document::class, $documentId);
        $documentKey = $this->em->getRepository(DocumentKey::class)
            ->findOneBy(['document' => $refreshedDoc, 'user' => $refreshedAdmin]);
        $this->assertSame('recovered-admin-hex', $documentKey->wrappedKeyHex);

        // The admin can read decryption metadata again, with the recovered key.
        $this->client->loginUser($refreshedAdmin);
        $this->client->request('GET', '/internal/documents/crypto-metadata/' . $documentId);
        $this->assertResponseIsSuccessful();
        $this->assertSame('recovered-admin-hex', json_decode((string) $this->client->getResponse()->getContent(), true)['wrappedKeyHex']);
    }

    public function testAdminResetWithoutRecoveryLeavesEscrowAndKeysStale(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createAdmin($tenant);
        $document = $this->createDocumentWithAdminKey($tenant, $admin, 'stale-admin-hex');
        $tenantId = $tenant->id->toString();
        $documentId = $document->id->toString();
        $originalEscrow = $tenant->wrappedTenantPrivateKey;

        $csrf = $this->beginReset($admin->email);
        $this->client->request('POST', '/user/password/reset/reset-token', [
            '_token' => $csrf,
            'new_password' => 'BrandNewPass123',
            'new_public_key' => 'regenerated-admin-public-key',
            'new_encrypted_private_key' => 'regenerated-admin-encrypted-private-key',
            // No recovery payload.
        ]);
        $this->assertResponseRedirects('/login');

        $this->em->clear();

        // Escrow key untouched and the document key is still the stale one:
        // the admin's regenerated key cannot use it — access is NOT restored.
        $this->assertSame($originalEscrow, $this->em->find(Tenant::class, $tenantId)->wrappedTenantPrivateKey);
        $refreshedDoc = $this->em->find(Document::class, $documentId);
        $documentKey = $this->em->getRepository(DocumentKey::class)->findOneBy(['document' => $refreshedDoc]);
        $this->assertSame('stale-admin-hex', $documentKey->wrappedKeyHex);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** Seeds the reset session token and returns a valid CSRF token for the POST. */
    private function beginReset(string $email): string
    {
        $session = static::getContainer()->get('session.factory')->createSession();
        $session->set('pwd_reset_token_reset-token', ['email' => $email, 'expires_at' => time() + 3600]);
        $session->save();
        $this->client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));

        $crawler = $this->client->request('GET', '/user/password/reset/reset-token');
        $this->assertResponseIsSuccessful();

        return (string) $crawler->filter('input[name="_token"]')->attr('value');
    }

    private function createTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->firmName = 'Firm ' . uniqid();
        $tenant->status = 'active';
        $tenant->subscriptionPlan = 'pro';
        $tenant->tenantPublicKey = 'tenant-public-key';
        $tenant->wrappedTenantPrivateKey = 'wrapped-under-old-admin-key';
        $tenant->recoveryWrappedPrivateKey = 'recovery-envelope';
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function createAdmin(Tenant $tenant): User
    {
        $user = new User();
        $user->email = 'admin_' . uniqid() . '@example.com';
        $user->firstName = 'Admin';
        $user->lastName = 'User';
        $user->roles = ['ROLE_ADMIN'];
        $user->tenant = $tenant;
        $user->password = 'hashed-password';
        $user->status = User::STATUS_ACTIVE;
        $user->isActivated = true;
        $this->em->persist($user);

        $userKey = new UserKey();
        $userKey->publicKey = 'admin-public-key';
        $userKey->encryptedPrivateKey = 'admin-encrypted-private-key';
        $user->userKey = $userKey;
        $this->em->persist($userKey);

        $this->em->flush();

        return $user;
    }

    private function createDocumentWithAdminKey(Tenant $tenant, User $admin, string $wrappedKeyHex): Document
    {
        $client = new Client();
        $client->tenant = $tenant;
        $client->clientName = 'Client ' . uniqid();
        $this->em->persist($client);

        $document = new Document();
        $document->client = $client;
        $document->filePath = 'vault/' . uniqid() . '.enc';
        $document->originalFileName = 'report.pdf';
        $document->fileSize = '2048';
        $document->iv = 'iv-value';
        $document->wrappedEscrowKeyHex = 'escrow-envelope-hex';
        $client->documents->add($document);
        $this->em->persist($document);

        $documentKey = new DocumentKey();
        $documentKey->document = $document;
        $documentKey->user = $admin;
        $documentKey->wrappedKeyHex = $wrappedKeyHex;
        $this->em->persist($documentKey);

        $this->em->flush();

        return $document;
    }
}

/** Bare S3 stand-in so DocumentViewerController can be constructed. */
class RecoveryResetFakeS3Client extends S3Client
{
    public function __construct()
    {
        // Intentionally do not call parent::__construct().
    }
}
