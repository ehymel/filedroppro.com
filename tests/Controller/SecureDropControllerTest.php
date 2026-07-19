<?php

namespace App\Tests\Controller;

use App\Entity\Client;
use App\Entity\Document;
use App\Entity\DocumentKey;
use App\Entity\DropRequest;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserKey;
use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for {@see \App\Controller\SecureDropController} — the public,
 * zero-login secure drop portal for external clients.
 *
 * Follows the repo's controller-test conventions: a DB transaction rolled back
 * in tearDown(), reboot disabled so the request shares the test's kernel / DB
 * connection / EntityManager, and the AWS S3 client swapped for an in-memory
 * fake (presign + delete) so no network calls are made.
 */
class SecureDropControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private FakeDropS3Client $s3;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->s3 = new FakeDropS3Client();
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
    // explainer()
    // ---------------------------------------------------------------------

    public function testExplainerPageRenders(): void
    {
        $this->client->request('GET', '/drop/');
        $this->assertResponseIsSuccessful();
    }

    // ---------------------------------------------------------------------
    // portal()
    // ---------------------------------------------------------------------

    public function testPortalTellsLoggedInUsersToLogOut(): void
    {
        $tenant = $this->createTenant('TX-LOGGEDIN');
        $user = $this->createActiveUserWithKey($tenant);

        $this->client->loginUser($user);
        $this->client->request('GET', '/drop/' . $tenant->joinCode);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.alert-danger', 'log out');
    }

    public function testPortalShowsErrorForUnknownJoinCode(): void
    {
        $this->client->request('GET', '/drop/TX-DOESNOTEXIST');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.alert-danger', 'does not exist');
    }

    public function testPortalShowsOfflineWhenNoRecipientKeysConfigured(): void
    {
        // Tenant exists but has no active staff with a public key.
        $tenant = $this->createTenant('TX-NOKEYS');

        $this->client->request('GET', '/drop/' . $tenant->joinCode);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.alert-danger', 'temporarily offline');
    }

    public function testPortalWithoutValidDropRequestShowsError(): void
    {
        $tenant = $this->createTenant('TX-NOREQ');
        $this->createActiveUserWithKey($tenant);

        // No ?req= token supplied at all.
        $this->client->request('GET', '/drop/' . $tenant->joinCode);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.alert-danger', 'not a valid drop request');
    }

    public function testPortalRendersUploadFormForValidDropRequest(): void
    {
        $tenant = $this->createTenant('TX-VALID');
        $staff = $this->createActiveUserWithKey($tenant);
        $dropRequest = $this->createDropRequest($tenant, 'Jordan Client', 'jordan@example.com', $staff);

        $this->client->request('GET', '/drop/' . $tenant->joinCode . '?req=' . $dropRequest->token);

        $this->assertResponseIsSuccessful();
        $body = $this->client->getResponse()->getContent();
        $this->assertStringContainsString($tenant->firmName, $body);
        $this->assertStringContainsString('Jordan Client', $body, 'The form should be prefilled from the drop request.');
    }

    public function testPortalJoinCodeLookupIsCaseInsensitive(): void
    {
        $tenant = $this->createTenant('TX-CASE');
        $staff = $this->createActiveUserWithKey($tenant);
        $dropRequest = $this->createDropRequest($tenant, 'Casey Client', 'casey@example.com', $staff);

        // Lower-case + surrounding whitespace still resolves the tenant.
        $this->client->request('GET', '/drop/' . strtolower($tenant->joinCode) . '?req=' . $dropRequest->token);

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString($tenant->firmName, $this->client->getResponse()->getContent());
    }

    // ---------------------------------------------------------------------
    // generatePresignedUrl()
    // ---------------------------------------------------------------------

    public function testPresignReturnsNotFoundForUnknownTenant(): void
    {
        $this->postJson('/drop/presign/TX-NOPE', ['filename' => 'secret.pdf']);

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame('Invalid drop destination.', $this->json()['error']);
    }

    public function testPresignRequiresFilename(): void
    {
        $tenant = $this->createTenant('TX-PRESIGN1');

        $this->postJson('/drop/presign/' . $tenant->joinCode, []);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('Missing filename parameter.', $this->json()['error']);
    }

    public function testPresignReturnsUploadUrlAndKey(): void
    {
        $tenant = $this->createTenant('TX-PRESIGN2');

        $this->postJson('/drop/presign/' . $tenant->joinCode, ['filename' => 'secret.pdf']);

        $this->assertResponseIsSuccessful();
        $data = $this->json();
        $this->assertSame($this->s3->presignedUrl, $data['uploadUrl']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.enc$/', $data['s3Key']);
    }

    // ---------------------------------------------------------------------
    // finalizeUpload()
    // ---------------------------------------------------------------------

    public function testFinalizeReturnsNotFoundForUnknownTenant(): void
    {
        $this->postJson('/drop/finalize/TX-NOPE', ['senderName' => 'A']);

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame('Invalid drop destination.', $this->json()['error']);
    }

    public function testFinalizeRejectsMissingMetadata(): void
    {
        $tenant = $this->createTenant('TX-FIN1');

        // Missing iv / wrappedKeys / s3Key / etc.
        $this->postJson('/drop/finalize/' . $tenant->joinCode, [
            'senderName' => 'A',
            'senderEmail' => 'a@example.com',
        ]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('Missing required metadata parameters.', $this->json()['error']);
    }

    public function testFinalizePersistsDocumentKeysAndFulfilsRequest(): void
    {
        $tenant = $this->createTenant('TX-FIN2');
        $staff = $this->createActiveUserWithKey($tenant);
        $dropRequest = $this->createDropRequest($tenant, 'Dana Client', 'dana@example.com', $staff);
        $s3Key = '11111111-1111-4111-8111-111111111111.enc';

        $this->postJson('/drop/finalize/' . $tenant->joinCode, [
            'senderName' => 'Dana Client',
            'senderEmail' => 'dana@example.com',
            'iv' => 'iv-hex-value',
            'wrappedKeys' => [
                'tenant_escrow' => 'escrow-wrapped-hex',
                $staff->id->toString() => 'user-wrapped-hex',
            ],
            's3Key' => $s3Key,
            'originalFileName' => 'contract.pdf',
            'fileSize' => 4096,
            'reqToken' => $dropRequest->token,
        ]);

        $this->assertResponseIsSuccessful();
        $data = $this->json();
        $this->assertTrue($data['success']);
        $documentId = $data['documentId'];

        $this->em->clear();

        $document = $this->em->find(Document::class, $documentId);
        $this->assertNotNull($document);
        $this->assertSame($s3Key, $document->filePath);
        $this->assertSame('iv-hex-value', $document->iv);
        $this->assertSame('contract.pdf', $document->originalFileName);
        $this->assertSame('4096', (string) $document->fileSize);
        $this->assertSame('escrow-wrapped-hex', $document->wrappedEscrowKeyHex);

        // The personal wrapped key was mapped to the active staff member.
        $documentKey = $this->em->getRepository(DocumentKey::class)->findOneBy(['document' => $documentId]);
        $this->assertNotNull($documentKey);
        $this->assertSame('user-wrapped-hex', $documentKey->wrappedKeyHex);
        $this->assertSame($staff->id->toString(), $documentKey->user->id->toString());

        // A client profile was upserted for the sender within the tenant.
        $clientProfile = $this->em->getRepository(Client::class)->findOneBy([
            'tenant' => $tenant->id->toString(),
            'clientName' => 'Dana Client',
        ]);
        $this->assertNotNull($clientProfile);

        // The originating drop request is marked fulfilled.
        $refreshedRequest = $this->em->find(DropRequest::class, $dropRequest->id->toString());
        $this->assertSame('fulfilled', $refreshedRequest->status);
    }

    // ---------------------------------------------------------------------
    // rename()
    // ---------------------------------------------------------------------

    public function testRenameReturnsNotFoundForUnknownDocument(): void
    {
        $this->postJson('/drop/rename/' . $this->randomUuid(), ['originalFileName' => 'x.pdf']);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testRenameRequiresNewName(): void
    {
        $document = $this->createDocument($this->createTenant('TX-REN1'));

        $this->postJson('/drop/rename/' . $document->id, []);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('Missing new filename.', $this->json()['error']);
    }

    public function testRenameUpdatesOriginalFileName(): void
    {
        $document = $this->createDocument($this->createTenant('TX-REN2'));
        $id = $document->id->toString();

        $this->postJson('/drop/rename/' . $document->id, ['originalFileName' => 'renamed.pdf']);

        $this->assertResponseIsSuccessful();
        $this->assertSame('renamed.pdf', $this->json()['originalFileName']);

        $this->em->clear();
        $this->assertSame('renamed.pdf', $this->em->find(Document::class, $id)->originalFileName);
    }

    // ---------------------------------------------------------------------
    // delete()
    // ---------------------------------------------------------------------

    public function testDeleteReturnsNotFoundForUnknownDocument(): void
    {
        $this->client->request('DELETE', '/drop/delete/' . $this->randomUuid());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteRemovesDocumentFromS3AndDatabase(): void
    {
        $document = $this->createDocument($this->createTenant('TX-DEL1'));
        $filePath = $document->filePath;
        $id = $document->id->toString();

        $this->client->request('DELETE', '/drop/delete/' . $document->id);

        $this->assertResponseIsSuccessful();
        $this->assertTrue($this->json()['success']);
        $this->assertContains($filePath, $this->s3->deletedKeys);

        $this->em->clear();
        $this->assertNull($this->em->find(Document::class, $id));
    }

    public function testDeleteReturnsServerErrorWhenS3Fails(): void
    {
        $document = $this->createDocument($this->createTenant('TX-DEL2'));
        $id = $document->id->toString();
        $this->s3->throwOnDelete = new \RuntimeException('S3 unavailable');

        $this->client->request('DELETE', '/drop/delete/' . $document->id);

        $this->assertResponseStatusCodeSame(500);
        $this->assertStringContainsString('Could not delete file', $this->json()['error']);

        // The record must survive a failed S3 deletion.
        $this->em->clear();
        $this->assertNotNull($this->em->find(Document::class, $id));
    }

    // ---------------------------------------------------------------------
    // Fixtures & helpers
    // ---------------------------------------------------------------------

    private function createTenant(string $joinCode): Tenant
    {
        $tenant = new Tenant();
        $tenant->firmName = 'Firm ' . uniqid();
        $tenant->status = 'active';
        // Join codes are looked up upper-cased; keep fixtures unique per test.
        $tenant->joinCode = strtoupper($joinCode) . '-' . strtoupper(bin2hex(random_bytes(3)));
        $tenant->tenantPublicKey = 'tenant-public-key';
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function createActiveUserWithKey(Tenant $tenant): User
    {
        $user = new User();
        $user->email = 'staff_' . uniqid() . '@example.com';
        $user->firstName = 'Staff';
        $user->lastName = 'Member';
        $user->roles = ['ROLE_USER'];
        $user->tenant = $tenant;
        $user->password = 'hashed-password';
        $user->status = 'active';
        $user->isActivated = true;
        $this->em->persist($user);

        $userKey = new UserKey();
        $userKey->publicKey = 'staff-public-key';
        $userKey->encryptedPrivateKey = 'staff-encrypted-private-key';
        $user->userKey = $userKey; // setter wires the owning side
        $this->em->persist($userKey);

        $this->em->flush();

        return $user;
    }

    private function createDropRequest(Tenant $tenant, string $name, string $email, User $createdBy): DropRequest
    {
        $dropRequest = new DropRequest();
        $dropRequest->tenant = $tenant;
        $dropRequest->clientName = $name;
        $dropRequest->clientEmail = $email;
        $dropRequest->token = 'req_' . bin2hex(random_bytes(16));
        $dropRequest->status = 'pending';
        $dropRequest->createdBy = $createdBy;
        $this->em->persist($dropRequest);
        $this->em->flush();

        return $dropRequest;
    }

    private function createDocument(Tenant $tenant): Document
    {
        $client = new Client();
        $client->tenant = $tenant;
        $client->clientName = 'Client ' . uniqid();
        $this->em->persist($client);

        $document = new Document();
        $document->client = $client;
        $document->filePath = 'vault/' . uniqid() . '.enc';
        $document->originalFileName = 'original.pdf';
        $document->fileSize = '1024';
        $document->iv = 'iv-default';
        $client->documents->add($document);
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    private function randomUuid(): string
    {
        return \Symfony\Component\Uid\Uuid::v4()->toString();
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
 * In-memory S3 stand-in for the secure drop flow. getCommand /
 * createPresignedRequest are concrete methods on the real client (matched
 * here); deleteObject is magic (__call) so it's defined concretely. The parent
 * constructor is skipped so no AWS configuration is required.
 */
class FakeDropS3Client extends S3Client
{
    public string $presignedUrl = 'https://s3.test/upload?X-Amz-Signature=fake';
    /** @var array<int, string|null> */
    public array $deletedKeys = [];
    public ?\Throwable $throwOnDelete = null;

    public function __construct()
    {
        // Intentionally do not call parent::__construct().
    }

    public function getCommand($name, array $args = []): CommandInterface
    {
        return new \Aws\Command($name, $args);
    }

    public function createPresignedRequest(CommandInterface $command, $expires, array $options = [])
    {
        return new Psr7Request('PUT', $this->presignedUrl);
    }

    public function deleteObject(array $args = [])
    {
        if ($this->throwOnDelete) {
            throw $this->throwOnDelete;
        }
        $this->deletedKeys[] = $args['Key'] ?? null;

        return new Result([]);
    }
}
