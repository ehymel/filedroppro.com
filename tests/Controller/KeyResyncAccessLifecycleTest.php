<?php

namespace App\Tests\Controller;

use App\Entity\Client;
use App\Entity\Document;
use App\Entity\DocumentKey;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserKey;
use Aws\Result;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Psr7\Utils;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end lifecycle: a user changes their password (which regenerates their
 * E2EE keypair and locks the account to 'pending_approval'), which must block
 * document decryption + download; an admin then re-syncs the user's keys, after
 * which access is restored.
 *
 * The "cannot decrypt/download" step is enforced server-side by the key-sync
 * gate in DocumentViewerController::getCryptoMetadata() and downloadPayload()
 * (403 while user.status !== 'active'). The re-sync step is driven through the
 * real admin endpoint StaffController::submitSync().
 *
 * Follows the repo's controller-test conventions: transaction rolled back in
 * tearDown(), reboot disabled, and the AWS S3 client replaced with a fake.
 *
 * Note: the password reset itself is *simulated* by regenerating the keypair and
 * setting 'pending_approval' (mirroring PasswordResetController::executeReset for
 * a non-admin) — the scenario under test is decryption access across the key
 * lifecycle, not the reset form plumbing.
 */
class KeyResyncAccessLifecycleTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private KeyLifecycleFakeS3Client $s3;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->s3 = new KeyLifecycleFakeS3Client();
        static::getContainer()->set(S3Client::class, $this->s3);

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

    // ---------------------------------------------------------------------
    // Phase 1 — active user with synced keys has access
    // ---------------------------------------------------------------------

    public function testActiveUserWithKeyCanReadMetadataAndDownload(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, ['ROLE_USER'], User::STATUS_ACTIVE);
        $document = $this->createDocumentWithKey($tenant, $user, 'wrapped-hex-original');

        $this->client->loginUser($user);

        $this->client->request('GET', '/internal/documents/crypto-metadata/' . $document->id);
        $this->assertResponseIsSuccessful();
        $this->assertSame('wrapped-hex-original', $this->json()['wrappedKeyHex']);

        $this->client->request('GET', '/internal/documents/download-payload/' . $document->id);
        $this->assertResponseIsSuccessful();
    }

    // ---------------------------------------------------------------------
    // Phase 2 — password change de-syncs keys -> access blocked
    // ---------------------------------------------------------------------

    public function testMetadataIsDeniedAfterPasswordChangeDesyncsKeys(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, ['ROLE_USER'], User::STATUS_ACTIVE);
        $document = $this->createDocumentWithKey($tenant, $user, 'wrapped-hex-original');

        $user = $this->simulatePasswordResetKeyRegeneration($user);

        $this->client->loginUser($user);
        $this->client->request('GET', '/internal/documents/crypto-metadata/' . $document->id);

        $this->assertResponseStatusCodeSame(403);
        $this->assertStringContainsString('out of sync', $this->json()['error']);
    }

    public function testDownloadIsDeniedAfterPasswordChangeDesyncsKeys(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, ['ROLE_USER'], User::STATUS_ACTIVE);
        $document = $this->createDocumentWithKey($tenant, $user, 'wrapped-hex-original');

        $user = $this->simulatePasswordResetKeyRegeneration($user);

        $this->client->loginUser($user);
        $this->client->request('GET', '/internal/documents/download-payload/' . $document->id);

        $this->assertResponseStatusCodeSame(403);
        // The gate must fire before we ever reach out to S3 for the ciphertext.
        $this->assertSame(0, $this->s3->getObjectCalls);
    }

    // ---------------------------------------------------------------------
    // Phase 3 — admin re-sync restores access
    // ---------------------------------------------------------------------

    public function testAdminResyncReactivatesUserAndReplacesDocumentKey(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createUser($tenant, ['ROLE_ADMIN'], User::STATUS_ACTIVE);
        $user = $this->createUser($tenant, ['ROLE_USER'], User::STATUS_ACTIVE);
        $document = $this->createDocumentWithKey($tenant, $user, 'wrapped-hex-original');
        $userId = $user->id->toString();
        $documentId = $document->id->toString();

        $this->simulatePasswordResetKeyRegeneration($user);

        $this->client->loginUser($admin);
        $this->postJson('/internal/staff/submit-sync/' . $userId, [
            'reKeyedMap' => [$documentId => 'rewrapped-hex-for-new-key'],
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertTrue($this->json()['success']);

        $this->em->clear();

        // The user is reactivated.
        $refreshedUser = $this->em->find(User::class, $userId);
        $this->assertSame(User::STATUS_ACTIVE, $refreshedUser->status);

        // The stale wrapped key was replaced with the freshly re-wrapped one.
        $refreshedDocument = $this->em->find(Document::class, $documentId);
        $documentKey = $this->em->getRepository(DocumentKey::class)->findOneBy([
            'document' => $refreshedDocument,
            'user' => $refreshedUser,
        ]);
        $this->assertNotNull($documentKey);
        $this->assertSame('rewrapped-hex-for-new-key', $documentKey->wrappedKeyHex);
    }

    public function testAdminSyncDataReturnsEscrowEnvelopesForPendingUser(): void
    {
        $tenant = $this->createTenant();
        $tenant->wrappedTenantPrivateKey = 'wrapped-tenant-private-key';
        $this->em->flush();

        $admin = $this->createUser($tenant, ['ROLE_ADMIN'], User::STATUS_ACTIVE);
        $user = $this->createUser($tenant, ['ROLE_USER'], User::STATUS_ACTIVE);
        $document = $this->createDocumentWithKey($tenant, $user, 'wrapped-hex-original');
        $document->wrappedEscrowKeyHex = 'escrow-envelope-hex';
        $this->em->flush();
        $documentId = $document->id->toString();

        $user = $this->simulatePasswordResetKeyRegeneration($user);
        $userId = $user->id->toString();

        $this->client->loginUser($admin);
        $this->client->request('GET', '/internal/staff/sync-data/' . $userId);

        $this->assertResponseIsSuccessful();
        $data = $this->json();
        $this->assertSame('regenerated-public-key', $data['pendingUserPublicKey']);
        $this->assertSame('wrapped-tenant-private-key', $data['wrappedTenantPrivateKey']);
        $this->assertCount(1, $data['escrowEnvelopes'], 'The tenant escrow envelope must be returned for re-wrapping.');
        $this->assertSame($documentId, $data['escrowEnvelopes'][0]['documentId']);
        $this->assertSame('escrow-envelope-hex', $data['escrowEnvelopes'][0]['wrappedEscrowKeyHex']);
    }

    // ---------------------------------------------------------------------
    // Full lifecycle: access -> blocked -> re-synced -> access
    // ---------------------------------------------------------------------

    public function testUserRegainsDocumentAccessAfterAdminResync(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createUser($tenant, ['ROLE_ADMIN'], User::STATUS_ACTIVE);
        $user = $this->createUser($tenant, ['ROLE_USER'], User::STATUS_ACTIVE);
        $document = $this->createDocumentWithKey($tenant, $user, 'wrapped-hex-original');
        $userId = $user->id->toString();
        $documentId = $document->id->toString();

        // --- Phase 1: with synced keys the user can decrypt + download. ---
        $this->client->loginUser($user);
        $this->client->request('GET', '/internal/documents/crypto-metadata/' . $documentId);
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/internal/documents/download-payload/' . $documentId);
        $this->assertResponseIsSuccessful();

        // --- Phase 2: password change regenerates keys -> access blocked. ---
        $user = $this->simulatePasswordResetKeyRegeneration($user);
        $this->client->loginUser($user);
        $this->client->request('GET', '/internal/documents/crypto-metadata/' . $documentId);
        $this->assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/internal/documents/download-payload/' . $documentId);
        $this->assertResponseStatusCodeSame(403);

        // --- Phase 3: admin re-syncs the user's encryption keys. ---
        $this->client->loginUser($admin);
        $this->postJson('/internal/staff/submit-sync/' . $userId, [
            'reKeyedMap' => [$documentId => 'rewrapped-hex-for-new-key'],
        ]);
        $this->assertResponseIsSuccessful();

        // --- Phase 3 (verify): the user can decrypt + download again. ---
        $this->em->clear();
        $refreshedUser = $this->em->find(User::class, $userId);
        $this->client->loginUser($refreshedUser);

        $this->client->request('GET', '/internal/documents/crypto-metadata/' . $documentId);
        $this->assertResponseIsSuccessful();
        $this->assertSame('rewrapped-hex-for-new-key', $this->json()['wrappedKeyHex']);

        $this->client->request('GET', '/internal/documents/download-payload/' . $documentId);
        $this->assertResponseIsSuccessful();
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
        $user->userKey = $userKey; // setter wires the owning side
        $this->em->persist($userKey);

        $this->em->flush();

        return $user;
    }

    private function createDocumentWithKey(Tenant $tenant, User $user, string $wrappedKeyHex): Document
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
        $documentKey->wrappedKeyHex = $wrappedKeyHex;
        $this->em->persist($documentKey);

        $this->em->flush();

        return $document;
    }

    /**
     * Mirrors PasswordResetController::executeReset for a non-admin: a brand-new
     * keypair is written and the account is locked to 'pending_approval' until an
     * admin re-wraps the historical document keys.
     *
     * Re-fetches a managed instance first: after prior requests the passed
     * reference may be detached (the test client resets the EM between requests),
     * so flushing the original object would be a silent no-op. Returns the managed
     * (now pending) user for the caller to log in with.
     */
    private function simulatePasswordResetKeyRegeneration(User $user): User
    {
        $managed = $this->em->find(User::class, $user->id->toString());
        $managed->status = User::STATUS_PENDING;
        $managed->userKey->publicKey = 'regenerated-public-key';
        $managed->userKey->encryptedPrivateKey = 'regenerated-encrypted-private-key';
        $this->em->flush();

        return $managed;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postJson(string $uri, array $payload): void
    {
        $this->client->request(
            'POST',
            $uri,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($payload)
        );
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
 * Minimal in-memory S3 stand-in: downloadPayload only needs getObject to stream
 * the ciphertext. The parent constructor is skipped so no AWS config is needed.
 */
class KeyLifecycleFakeS3Client extends S3Client
{
    public int $getObjectCalls = 0;

    public function __construct()
    {
        // Intentionally do not call parent::__construct().
    }

    public function getObject(array $args = [])
    {
        $this->getObjectCalls++;

        return new Result(['Body' => Utils::streamFor('ENCRYPTED-PAYLOAD-BYTES')]);
    }
}
