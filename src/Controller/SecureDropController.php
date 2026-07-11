<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\Document;
use App\Entity\DocumentKey;
use App\Entity\DropRequest;
use App\Entity\Tenant;
use App\Entity\User;
use App\Form\FileDropFormType;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Manages public, zero-login secure document drops for external clients.
 */
#[Route(path: '/drop', name: 'drop_')]
class SecureDropController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em,
                                private readonly S3Client $s3Client,
                                #[Autowire(param: 'env(AWS_S3_BUCKET)')] private readonly string $s3BucketName) {}

    /**
     * Renders the public file drop interface for a specific Tenant.
     */
    #[Route(path: '/{joinCode}', name: 'portal')]
    public function portal(string $joinCode, Request $request): Response
    {
        // Insure no user is actually logged in
        if ($this->getUser()) {
            return $this->render('drop/error.html.twig', [
                'error' => 'You must log out to access this secure drop zone.'
            ]);
        }

        $form = $this->createForm(FileDropFormType::class);

        // 1. Locate the target Tenant
        $tenant = $this->em->getRepository(Tenant::class)->findOneBy([
            'joinCode' => strtoupper(trim($joinCode))
        ]);

        if (!$tenant) {
            return $this->render('drop/error.html.twig', [
                'error' => 'The requested secure drop zone does not exist.'
            ]);
        }

        // 2. Fetch all active administrative/internal staff users for this Tenant
        // We need their public keys so the browser can encrypt files specifically for them.
        /** @var User[] $users */
        $users = $this->em->getRepository(User::class)->findBy([
            'tenant' => $tenant,
            'status' => 'active'
        ]);

        $recipientKeys = [];
        foreach ($users as $user) {
            $userKey = $user->userKey;
            if ($userKey && $userKey->publicKey) {
                $recipientKeys[] = [
                    'userId' => $user->id->toString(),
                    'publicKey' => $userKey->publicKey,
                ];
            }
        }

        if (empty($recipientKeys)) {
            return $this->render('drop/error.html.twig', [
                'error' => 'This secure drop zone is temporarily offline. No security credentials have been configured.'
            ]);
        }

        // 3. Handle Drop Request Tracking Token
        $reqToken = $request->query->get('req');
        $dropRequest = null;

        if ($reqToken) {
            // Only populate the form if the request is still pending
            $dropRequest = $this->em->getRepository(DropRequest::class)->findOneBy([
                'token' => $reqToken,
                'tenant' => $tenant,
//                'status' => 'pending'
            ]);
        }

        if (empty($dropRequest)) {
            return $this->render('drop/error.html.twig', [
                'error' => 'This is not a valid drop request.'
            ]);
        }

        $form->get('senderName')->setData($dropRequest->clientName);
        $form->get('senderEmail')->setData($dropRequest->clientEmail);

        return $this->render('drop/upload.html.twig', [
            'tenant' => $tenant,
            'form' => $form,
            'recipientKeys' => $recipientKeys,
            'joinCode' => $joinCode,
            'dropRequest' => $dropRequest
        ]);
    }

    /**
     * Generates a secure AWS S3 Pre-signed URL for direct browser uploads.
     */
    #[Route('/presign/{joinCode}', name: 'presign', methods: ['POST'])]
    public function generatePresignedUrl(string $joinCode, Request $request): JsonResponse
    {
        $tenant = $this->em->getRepository(Tenant::class)->findOneBy([
            'joinCode' => strtoupper(trim($joinCode))
        ]);

        if (!$tenant) {
            return new JsonResponse(['error' => 'Invalid drop destination.'], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);
        $filename = $payload['filename'] ?? null;

        if (!$filename) {
            return new JsonResponse(['error' => 'Missing filename parameter.'], Response::HTTP_BAD_REQUEST);
        }

        // Generate a random UUID-based file path to prevent naming collisions and metadata leakage on S3
        $s3Key = Uuid::v4()->toString() . '.enc';

        // Prepare the AWS pre-signed PUT command
        $cmd = $this->s3Client->getCommand('PutObject', [
            'Bucket' => $this->s3BucketName,
            'Key' => $s3Key,
            'ContentType' => 'application/octet-stream',
        ]);

        // Url expires in 15 minutes
        $presignedRequest = $this->s3Client->createPresignedRequest($cmd, '+15 minutes');
        $presignedUrl = (string) $presignedRequest->getUri();

        return new JsonResponse([
            'uploadUrl' => $presignedUrl,
            's3Key' => $s3Key
        ]);
    }

    /**
     * Finalizes metadata in database after Uppy confirms a successful direct S3 upload.
     */
    #[Route('/finalize/{joinCode}', name: 'finalize', methods: ['POST'])]
    public function finalizeUpload(string $joinCode, Request $request): JsonResponse
    {
        $tenant = $this->em->getRepository(Tenant::class)->findOneBy([
            'joinCode' => strtoupper(trim($joinCode))
        ]);

        if (!$tenant) {
            return new JsonResponse(['error' => 'Invalid drop destination.'], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);
        $senderName = $payload['senderName'] ?? null;
        $senderEmail = $payload['senderEmail'] ?? null;
        $iv = $payload['iv'] ?? null;
        $wrappedKeys = $payload['wrappedKeys'] ?? null;
        $s3Key = $payload['s3Key'] ?? null;
        $originalFileName = $payload['originalFileName'] ?? null;
        $fileSize = $payload['fileSize'] ?? null;
        $reqToken = $payload['reqToken'] ?? null;

        if (!$senderName || !$senderEmail || !$iv || !$wrappedKeys || !$s3Key || !$originalFileName || $fileSize === null) {
            return new JsonResponse(['error' => 'Missing required metadata parameters.'], Response::HTTP_BAD_REQUEST);
        }

        // Map or generate Client profile. Uses a race-safe upsert because a client
        // dropping several files at once finalizes each in a separate parallel request.
        $client = $this->em->getRepository(Client::class)->findOrCreate($tenant, $senderName);

        // Construct Document record pointing directly to S3
        $document = new Document();
        $document->client = $client;
        $document->filePath = $s3Key; // The key on S3 acts as our path
        $document->iv = $iv;
        $document->originalFileName = $originalFileName;
        $document->fileSize = $fileSize;

        // --- Pattern 2: Process Institutional Escrow Wrapping Key ---
        if (isset($wrappedKeys['tenant_escrow'])) {
            $document->wrappedEscrowKeyHex = $wrappedKeys['tenant_escrow'];
            unset($wrappedKeys['tenant_escrow']); // Remove it so we don't try to loop it as a user record
        }

        $this->em->persist($document);

        // Map personal wrapped keys to specific active staff users
        foreach ($wrappedKeys as $userId => $wrappedKeyHex) {
            /** @var User $user */
            $user = $this->em->getRepository(User::class)->find($userId);
            if ($user && $user->tenant === $tenant) {
                $documentKey = new DocumentKey();
                $documentKey->document = $document;
                $documentKey->user = $user;
                $documentKey->wrappedKeyHex = $wrappedKeyHex;
                $this->em->persist($documentKey);

                $client->addUser($user);
            }
        }

        // Fulfill invitation if verified
        if ($reqToken) {
            /** @var DropRequest $dropRequest */
            $dropRequest = $this->em->getRepository(DropRequest::class)->findOneBy([
                'token' => $reqToken,
                'tenant' => $tenant
            ]);

            if ($dropRequest && $dropRequest->status === 'pending') {
                $dropRequest->status = 'fulfilled';
            }
        }

        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'documentId' => $document->id->toString(),
            'message' => 'Files securely encrypted and successfully uploaded'
        ]);
    }

    #[Route('/rename/{documentId}', name: 'rename', methods: ['POST'])]
    public function rename(string $documentId, Request $request): JsonResponse
    {
        $document = $this->em->getRepository(Document::class)->find($documentId);

        if (!$document) {
            return new JsonResponse(['error' => 'Document not found.'], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);
        $newName = $payload['originalFileName'] ?? null;

        if (!$newName) {
            return new JsonResponse(['error' => 'Missing new filename.'], Response::HTTP_BAD_REQUEST);
        }

        $document->originalFileName = $newName;
        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'originalFileName' => $document->originalFileName
        ]);
    }

    #[Route('/delete/{documentId}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $documentId): JsonResponse
    {
        $document = $this->em->getRepository(Document::class)->find($documentId);

        if (!$document) {
            return new JsonResponse(['error' => 'Document not found.'], Response::HTTP_NOT_FOUND);
        }

        try {
            // Remove from S3
            $this->s3Client->deleteObject([
                'Bucket' => $this->s3BucketName,
                'Key' => $document->filePath,
            ]);

            // Remove from database (cascades to DocumentKey)
            $this->em->remove($document);
            $this->em->flush();
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Could not delete file: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse(['success' => true]);
    }
}
