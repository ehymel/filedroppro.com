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

/**
 * Confirms that resetting a forgotten password (which regenerates the user's
 * E2EE keypair) locks a non-admin account to 'pending_approval' and blocks
 * document decryption until an admin re-syncs their keys.
 *
 * NOTE on the authenticated account-settings "change password" flow
 * (PasswordUpdateController::changePassword): that path is a deliberate "Safe
 * Re-encryption" — it keeps the SAME keypair (only re-encrypts the private key
 * under the new password), so the user's DocumentKeys stay valid and they
 * remain 'active' with uninterrupted access. It therefore does NOT set
 * 'pending_approval'. Coverage for that path is intentionally omitted here
 * pending a product decision (see the conversation): asserting it sets pending
 * would contradict the current, cryptographically-sound design.
 */
class PasswordChangeStatusTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        // DocumentViewerController requires an S3 client to be constructed even
        // for the (S3-free) crypto-metadata endpoint used to assert the block.
        static::getContainer()->set(S3Client::class, new PwStatusFakeS3Client());

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

    public function testForgotPasswordResetSetsPendingApprovalAndBlocksDocumentAccess(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, ['ROLE_USER'], User::STATUS_ACTIVE);
        $document = $this->createDocumentWithKey($tenant, $user);
        $userId = $user->id->toString();
        $documentId = $document->id->toString();
        $email = $user->email;

        // 1. Load the reset form to obtain a valid (stateful) CSRF token.
        $crawler = $this->client->request('GET', '/reset-password/reset-token-123?email=' . urlencode($email));
        $this->assertResponseIsSuccessful();
        $csrfToken = $crawler->filter('input[name="_token"]')->attr('value');

        // 2. Submit the reset. The browser regenerates a fresh keypair and
        //    re-encrypts the private key under the new password.
        $this->client->request('POST', '/reset-password/reset-token-123', [
            '_token' => $csrfToken,
            'email' => $email,
            'new_password' => 'BrandNewPass123',
            'new_public_key' => 'regenerated-public-key',
            'new_encrypted_private_key' => 'regenerated-encrypted-private-key',
        ]);
        $this->assertResponseRedirects('/login');

        // 3. The account is locked to pending_approval with a regenerated key.
        $this->em->clear();
        $refreshed = $this->em->find(User::class, $userId);
        $this->assertSame(User::STATUS_PENDING, $refreshed->status);
        $this->assertSame('regenerated-public-key', $refreshed->userKey->publicKey);

        // 4. The user can no longer decrypt/download documents.
        $this->client->loginUser($refreshed);
        $this->client->request('GET', '/internal/documents/crypto-metadata/' . $documentId);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testForgotPasswordResetKeepsAdminActive(): void
    {
        // Tenant creators / admins act as their own authority and stay active.
        $tenant = $this->createTenant();
        $admin = $this->createUser($tenant, ['ROLE_ADMIN'], User::STATUS_ACTIVE);
        $adminId = $admin->id->toString();
        $email = $admin->email;

        $crawler = $this->client->request('GET', '/reset-password/reset-token-123?email=' . urlencode($email));
        $this->assertResponseIsSuccessful();
        $csrfToken = $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/reset-password/reset-token-123', [
            '_token' => $csrfToken,
            'email' => $email,
            'new_password' => 'BrandNewPass123',
            'new_public_key' => 'regenerated-public-key',
            'new_encrypted_private_key' => 'regenerated-encrypted-private-key',
        ]);
        $this->assertResponseRedirects('/login');

        $this->em->clear();
        $this->assertSame(User::STATUS_ACTIVE, $this->em->find(User::class, $adminId)->status);
    }

    public function testAccountSettingsChangePasswordKeepsUserActiveAndRetainsAccess(): void
    {
        // The authenticated "change password" flow is a Safe Re-encryption: it
        // keeps the SAME keypair (only re-encrypts the private key under the new
        // password), so the user stays 'active' and never loses document access.
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, ['ROLE_USER'], User::STATUS_ACTIVE);
        $document = $this->createDocumentWithKey($tenant, $user);
        $userId = $user->id->toString();
        $documentId = $document->id->toString();

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/internal/profile/change-password');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Re-encrypt & Update Password')->form();
        $form['user_password_reset_form[currentPassword]'] = 'irrelevant-not-verified';
        $form['user_password_reset_form[newPassword]'] = 'NewSecurePass123';
        $form['user_password_reset_form[confirmPassword]'] = 'NewSecurePass123';
        $values = $form->getPhpValues();
        // The browser re-encrypts the private key under the new password and
        // posts it alongside the form (not a mapped form field).
        $values['new_encrypted_private_key'] = 're-encrypted-under-new-password';
        $this->client->request('POST', $form->getUri(), $values);

        $this->assertResponseRedirects('/internal/profile/change-password');

        // Status is untouched and the public key is unchanged (same identity),
        // so existing DocumentKeys remain valid.
        $this->em->clear();
        $refreshed = $this->em->find(User::class, $userId);
        $this->assertSame(User::STATUS_ACTIVE, $refreshed->status, 'A routine password change must not lock the account.');
        $this->assertSame('user-public-key', $refreshed->userKey->publicKey, 'The keypair must be unchanged.');
        $this->assertSame('re-encrypted-under-new-password', $refreshed->userKey->encryptedPrivateKey);

        // The user still has document access (re-login: changing the password
        // deauthenticates the existing session).
        $this->client->loginUser($refreshed);
        $this->client->request('GET', '/internal/documents/crypto-metadata/' . $documentId);
        $this->assertResponseIsSuccessful();
        $this->assertSame('wrapped-hex-original', $this->json()['wrappedKeyHex']);
    }

    // ---------------------------------------------------------------------
    // Fixtures & helpers
    // ---------------------------------------------------------------------

    private function createTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->firmName = 'Firm ' . uniqid();
        $tenant->status = 'active';
        $tenant->subscriptionPlan = 'pro';
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    /**
     * @param array<int, string> $roles
     */
    private function createUser(Tenant $tenant, array $roles, string $status): User
    {
        $user = new User();
        $user->email = 'user_' . uniqid() . '@example.com';
        $user->firstName = 'Test';
        $user->lastName = 'User';
        $user->roles = $roles;
        $user->tenant = $tenant;
        $user->password = 'hashed-password';
        $user->status = $status;
        $user->isActivated = true;
        $this->em->persist($user);

        $userKey = new UserKey();
        $userKey->publicKey = 'user-public-key';
        $userKey->encryptedPrivateKey = 'user-encrypted-private-key';
        $user->userKey = $userKey;
        $this->em->persist($userKey);

        $this->em->flush();

        return $user;
    }

    private function createDocumentWithKey(Tenant $tenant, User $user): Document
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
        $client->documents->add($document);
        $this->em->persist($document);

        $documentKey = new DocumentKey();
        $documentKey->document = $document;
        $documentKey->user = $user;
        $documentKey->wrappedKeyHex = 'wrapped-hex-original';
        $this->em->persist($documentKey);

        $this->em->flush();

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}

/**
 * Bare S3 stand-in: only needed so DocumentViewerController can be constructed.
 */
class PwStatusFakeS3Client extends S3Client
{
    public function __construct()
    {
        // Intentionally do not call parent::__construct().
    }
}
