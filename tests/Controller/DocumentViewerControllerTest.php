<?php

namespace App\Tests\Controller;

use App\Entity\Client;
use App\Entity\Document;
use App\Entity\DocumentKey;
use App\Entity\Tenant;
use App\Entity\User;
use Aws\Result;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Psr7\Utils;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for {@see \App\Controller\DocumentViewerController}.
 *
 * These are driven through the real HTTP kernel (the controller is tightly
 * bound to the Symfony Form/Security stack, the multi-tenant Doctrine filter
 * and HTTP redirects) following the pattern used by
 * {@see \App\Tests\Controller\RegistrationControllerTest}: each test runs
 * inside a DB transaction rolled back in tearDown(), with reboot disabled so
 * the request and the test share a single kernel, DB connection and (crucially)
 * one EntityManager identity map.
 *
 * The real AWS S3 client is swapped for an in-memory fake so no network calls
 * are made and the getObject/deleteObject behaviour can be controlled.
 */
class DocumentViewerControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private FakeS3Client $s3;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Keep one kernel (DB connection + EntityManager) alive across the
        // multiple requests each test issues, so the outer transaction wraps
        // the controller's flush() and managed fixtures stay in the identity
        // map (the tenant SQL filter would otherwise hide foreign clients).
        $this->client->disableReboot();

        // Replace the real S3 client with an in-memory fake.
        $this->s3 = new FakeS3Client();
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
    // dashboard()
    // ---------------------------------------------------------------------

    public function testDashboardRedirectsWhenUserHasNoTenant(): void
    {
        $user = $this->createUser($this->createTenant());
        $user->tenant = null;
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/internal/documents/');

        $this->assertResponseRedirects('/unauthorized');
    }

    public function testDashboardShowsOnlyClientsOfTheCurrentTenant(): void
    {
        $tenantA = $this->createTenant();
        $userA = $this->createUser($tenantA);
        $ownClient = $this->createClientEntity($tenantA, 'Acme Widgets ' . uniqid());

        $tenantB = $this->createTenant();
        $foreignClient = $this->createClientEntity($tenantB, 'Foreign Corp ' . uniqid());

        $this->client->loginUser($userA);
        $crawler = $this->client->request('GET', '/internal/documents/');

        $this->assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        $this->assertStringContainsString($ownClient->clientName, $body);
        $this->assertStringNotContainsString(
            $foreignClient->clientName,
            $body,
            'The multi-tenant filter must hide another firm\'s clients.'
        );
    }

    // ---------------------------------------------------------------------
    // getCryptoMetadata()
    // ---------------------------------------------------------------------

    public function testCryptoMetadataIsForbiddenWithoutADocumentKey(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $doc = $this->createDocument($this->createClientEntity($tenant));

        $this->client->loginUser($user);
        $this->client->request('GET', '/internal/documents/crypto-metadata/' . $doc->id);

        $this->assertResponseStatusCodeSame(403);
        $data = $this->json();
        $this->assertArrayHasKey('error', $data);
    }

    public function testCryptoMetadataReturnsKeyMaterialForAuthorizedUser(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $doc = $this->createDocument($this->createClientEntity($tenant), [
            'originalFileName' => 'quarterly.pdf',
            'iv' => 'iv-abc-123',
        ]);
        $this->createDocumentKey($doc, $user, 'wrapped-key-hex-xyz');

        $this->client->loginUser($user);
        $this->client->request('GET', '/internal/documents/crypto-metadata/' . $doc->id);

        $this->assertResponseIsSuccessful();
        $data = $this->json();
        $this->assertSame('iv-abc-123', $data['iv']);
        $this->assertSame('wrapped-key-hex-xyz', $data['wrappedKeyHex']);
        $this->assertSame('quarterly.pdf', $data['originalFileName']);
        $this->assertSame('pdf', $data['originalExtension']);
    }

    // ---------------------------------------------------------------------
    // downloadPayload()
    // ---------------------------------------------------------------------

    public function testDownloadPayloadStreamsEncryptedFileFromS3(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $doc = $this->createDocument($this->createClientEntity($tenant));

        $this->client->loginUser($user);
        $this->client->request('GET', '/internal/documents/download-payload/' . $doc->id);

        $this->assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertStringContainsString($doc->filePath, (string) $response->headers->get('Content-Disposition'));

        // The controller streams straight from S3; assert it fetched the right
        // object. (The body is a StreamedResponse callback already consumed by
        // the kernel, so we verify the S3 interaction rather than re-stream it.)
        $this->assertSame($doc->filePath, $this->s3->lastGetKey);
    }

    public function testDownloadPayloadIsDeniedForInactiveSubscription(): void
    {
        // 'unpaid' is denied by SubscriptionVoter but (unlike 'suspended') is
        // not intercepted/redirected by the TenantFilterConfigurator.
        $tenant = $this->createTenant('unpaid');
        $user = $this->createUser($tenant);
        $doc = $this->createDocument($this->createClientEntity($tenant));

        $this->client->loginUser($user);
        $this->client->request('GET', '/internal/documents/download-payload/' . $doc->id);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testDownloadPayloadReturns404WhenS3RetrievalFails(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $doc = $this->createDocument($this->createClientEntity($tenant));

        $this->s3->throwOnGet = new \RuntimeException('S3 unavailable');

        $this->client->loginUser($user);
        $this->client->request('GET', '/internal/documents/download-payload/' . $doc->id);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDownloadPayloadRejectsAForeignTenantsDocument(): void
    {
        $tenantA = $this->createTenant();
        $userA = $this->createUser($tenantA);

        $tenantB = $this->createTenant();
        $foreignDoc = $this->createDocument($this->createClientEntity($tenantB));

        $this->client->loginUser($userA);
        $this->client->request('GET', '/internal/documents/download-payload/' . $foreignDoc->id);

        $this->assertResponseRedirects('/unauthorized');
    }

    // ---------------------------------------------------------------------
    // updateNote()
    // ---------------------------------------------------------------------

    public function testUpdateNotePersistsTheNote(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $doc = $this->createDocument($this->createClientEntity($tenant));

        $this->client->loginUser($user);
        $this->postJson('/internal/documents/update-note/' . $doc->id, ['note' => 'Reviewed and approved']);

        $this->assertResponseIsSuccessful();
        $data = $this->json();
        $this->assertTrue($data['success']);
        $this->assertSame('Reviewed and approved', $data['note']);

        $this->em->refresh($doc);
        $this->assertSame('Reviewed and approved', $doc->note);
    }

    public function testUpdateNoteIsForbiddenForAForeignTenantsDocument(): void
    {
        $tenantA = $this->createTenant();
        $userA = $this->createUser($tenantA);

        $tenantB = $this->createTenant();
        $foreignDoc = $this->createDocument($this->createClientEntity($tenantB));

        $this->client->loginUser($userA);
        $this->postJson('/internal/documents/update-note/' . $foreignDoc->id, ['note' => 'hijack']);

        $this->assertResponseStatusCodeSame(403);
        $this->assertSame('Unauthorized', $this->json()['error']);
    }

    // ---------------------------------------------------------------------
    // softDelete() / delete() / restore()  — CSRF-protected
    // ---------------------------------------------------------------------

    public function testSoftDeleteRejectsAnInvalidCsrfToken(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $doc = $this->createDocument($this->createClientEntity($tenant));

        $this->client->loginUser($user);
        $this->client->request('POST', '/internal/documents/soft_delete/' . $doc->id, ['_token' => 'not-a-valid-token']);

        $this->assertResponseRedirects('/internal/requests/');
        $this->em->refresh($doc);
        $this->assertNull($doc->deletedAt, 'A rejected CSRF request must not delete the document.');
    }

    public function testSoftDeleteMarksTheDocumentDeleted(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $doc = $this->createDocument($this->createClientEntity($tenant));

        $id = $doc->id->toString();
        $userId = $user->id->toString();

        $this->client->loginUser($user);
        $token = $this->csrfTokenFromDashboard('/documents/soft_delete/' . $doc->id);
        $this->client->request('POST', '/internal/documents/soft_delete/' . $doc->id, ['_token' => $token]);

        $this->assertResponseRedirects('/internal/documents/');

        $this->em->clear();
        $fresh = $this->em->find(Document::class, $id);
        $this->assertNotNull($fresh->deletedAt);
        $this->assertNotNull($fresh->deletedBy);
        $this->assertSame($userId, $fresh->deletedBy->id->toString());
    }

    public function testDeletePermanentlyRemovesDocumentAndS3Object(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        // Permanent delete/restore controls only render for already soft-deleted docs.
        $doc = $this->createDocument($this->createClientEntity($tenant), [
            'deletedAt' => new \DateTimeImmutable('-1 day'),
            'deletedBy' => $user,
        ]);
        $filePath = $doc->filePath;
        $id = $doc->id->toString();

        $this->client->loginUser($user);
        $token = $this->csrfTokenFromDashboard('/documents/delete/' . $doc->id);
        $this->client->request('POST', '/internal/documents/delete/' . $doc->id, ['_token' => $token]);

        $this->assertResponseRedirects('/internal/documents/');
        $this->assertContains($filePath, $this->s3->deletedKeys, 'The encrypted object should be deleted from S3.');

        $this->em->clear();
        $this->assertNull($this->em->find(Document::class, $id), 'The document record should be gone.');
    }

    public function testDeleteKeepsDocumentWhenS3DeletionFails(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $doc = $this->createDocument($this->createClientEntity($tenant), [
            'deletedAt' => new \DateTimeImmutable('-1 day'),
            'deletedBy' => $user,
        ]);
        $id = $doc->id->toString();

        $this->s3->throwOnDelete = new \RuntimeException('S3 unavailable');

        $this->client->loginUser($user);
        $token = $this->csrfTokenFromDashboard('/documents/delete/' . $doc->id);
        $this->client->request('POST', '/internal/documents/delete/' . $doc->id, ['_token' => $token]);

        $this->assertResponseRedirects('/internal/documents/');

        $this->em->clear();
        $this->assertNotNull(
            $this->em->find(Document::class, $id),
            'If S3 deletion fails the metadata record must be retained.'
        );
    }

    public function testRestoreClearsDeletionMetadata(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $doc = $this->createDocument($this->createClientEntity($tenant), [
            'deletedAt' => new \DateTimeImmutable('-1 day'),
            'deletedBy' => $user,
        ]);

        $id = $doc->id->toString();

        $this->client->loginUser($user);
        $token = $this->csrfTokenFromDashboard('/documents/restore/' . $doc->id);
        $this->client->request('POST', '/internal/documents/restore/' . $doc->id, ['_token' => $token]);

        $this->assertResponseRedirects('/internal/documents/');

        $this->em->clear();
        $fresh = $this->em->find(Document::class, $id);
        $this->assertNull($fresh->deletedAt);
        $this->assertNull($fresh->deletedBy);
    }

    // ---------------------------------------------------------------------
    // Fixtures & helpers
    // ---------------------------------------------------------------------

    private function createTenant(string $status = 'active', string $plan = 'pro'): Tenant
    {
        $tenant = new Tenant();
        $tenant->firmName = 'Firm ' . uniqid();
        $tenant->status = $status;
        $tenant->subscriptionPlan = $plan;
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function createUser(Tenant $tenant): User
    {
        $user = new User();
        $user->email = 'user_' . uniqid() . '@example.com';
        $user->firstName = 'Test';
        $user->lastName = 'User';
        $user->roles = ['ROLE_USER'];
        $user->tenant = $tenant;
        $user->password = 'hashed-password';
        $user->status = User::STATUS_ACTIVE;
        $user->isActivated = true;
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createClientEntity(Tenant $tenant, ?string $name = null): Client
    {
        $client = new Client();
        $client->tenant = $tenant;
        $client->clientName = $name ?? ('Client ' . uniqid());
        $this->em->persist($client);
        $this->em->flush();

        return $client;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createDocument(Client $client, array $overrides = []): Document
    {
        $document = new Document();
        $document->client = $client;
        $document->filePath = $overrides['filePath'] ?? ('vault/' . uniqid() . '.enc');
        $document->originalFileName = $overrides['originalFileName'] ?? 'report.pdf';
        $document->fileSize = (string) ($overrides['fileSize'] ?? 2048);
        $document->iv = $overrides['iv'] ?? 'iv-default-value';
        if (array_key_exists('deletedAt', $overrides)) {
            $document->deletedAt = $overrides['deletedAt'];
        }
        if (array_key_exists('deletedBy', $overrides)) {
            $document->deletedBy = $overrides['deletedBy'];
        }
        // Keep the inverse side in sync so the dashboard (which iterates
        // $client->documents) sees this document without an em->clear().
        $client->documents->add($document);
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    private function createDocumentKey(Document $document, User $user, string $wrappedKeyHex): DocumentKey
    {
        $key = new DocumentKey();
        $key->document = $document;
        $key->user = $user;
        $key->wrappedKeyHex = $wrappedKeyHex;
        $this->em->persist($key);
        $this->em->flush();

        return $key;
    }

    /**
     * Loads the dashboard (which renders the stateful CSRF tokens as data
     * attributes) and returns the token for the action button whose target URL
     * contains $urlNeedle. Because the token is generated inside the same
     * session the client carries, the subsequent POST validates against it —
     * exactly as it would in the browser.
     */
    private function csrfTokenFromDashboard(string $urlNeedle): string
    {
        $crawler = $this->client->request('GET', '/internal/documents/');
        $this->assertResponseIsSuccessful();

        $node = $crawler->filter('[data-sweetalert-delete-url-value*="' . $urlNeedle . '"]');
        $this->assertGreaterThan(0, $node->count(), 'No action button found for ' . $urlNeedle);

        return (string) $node->attr('data-sweetalert-csrf-token-value');
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
 * In-memory stand-in for the AWS S3 client. getObject/deleteObject are magic
 * (__call) methods on the real client, so defining them here as concrete
 * methods overrides them; the parent constructor is skipped so no AWS
 * configuration/credentials are required.
 */
class FakeS3Client extends S3Client
{
    public string $payload = 'ENCRYPTED-PAYLOAD-BYTES';
    public ?string $lastGetKey = null;
    /** @var array<int, string|null> */
    public array $deletedKeys = [];
    public ?\Throwable $throwOnGet = null;
    public ?\Throwable $throwOnDelete = null;

    public function __construct()
    {
        // Intentionally do not call parent::__construct().
    }

    public function getObject(array $args = [])
    {
        if ($this->throwOnGet) {
            throw $this->throwOnGet;
        }
        $this->lastGetKey = $args['Key'] ?? null;

        return new Result(['Body' => Utils::streamFor($this->payload)]);
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
