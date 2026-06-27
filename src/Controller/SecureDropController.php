<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\Document;
use App\Entity\DocumentKey;
use App\Entity\DropRequest;
use App\Entity\Tenant;
use App\Entity\User;
use App\Form\FileDropFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Manages public, zero-login secure document drops for external clients.
 */
#[Route('/drop', name: 'drop_')]
class SecureDropController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * Renders the public file drop interface for a specific Tenant.
     */
    #[Route('/{joinCode}', name: 'portal', methods: ['GET'])]
    public function dropPortal(string $joinCode, Request $request): Response
    {
        $form = $this->createForm(FileDropFormType::class);

        // 1. Locate the target Tenant
        $tenant = $this->em->getRepository(Tenant::class)->findOneBy([
            'joinCode' => strtoupper(trim($joinCode))
        ]);

        if (!$tenant) {
            throw $this->createNotFoundException('The requested secure drop zone does not exist.');
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

            $form->get('senderName')->setData($dropRequest->clientName);
            $form->get('senderEmail')->setData($dropRequest->clientEmail);
        }

        return $this->render('drop/upload.html.twig', [
            'tenant' => $tenant,
            'form' => $form,
            'recipientKeys' => $recipientKeys,
            'joinCode' => $joinCode,
            'dropRequest' => $dropRequest
        ]);
    }

    /**
     * Accepts encrypted raw binary file chunks, maps envelope keys, and registers records.
     */
    #[Route('/upload/{joinCode}', name: 'upload', methods: ['POST'])]
    public function handleUpload(string $joinCode, Request $request): JsonResponse
    {
        $tenant = $this->em->getRepository(Tenant::class)->findOneBy([
            'joinCode' => strtoupper(trim($joinCode))
        ]);

        if (!$tenant) {
            return new JsonResponse(['error' => 'Invalid drop destination.'], Response::HTTP_NOT_FOUND);
        }

        // 1. Extract post parameters
        $senderName = $request->request->get('senderName');
        $senderEmail = $request->request->get('senderEmail');
        $iv = $request->request->get('iv');
        $wrappedKeysJson = $request->request->get('wrappedKeys'); // Maps userId -> wrappedKeyHex
        $reqToken = $request->request->get('reqToken');

        /** @var UploadedFile|null $file */
        $file = $request->files->get('encryptedFile');

        if (!$senderName || !$senderEmail || !$iv || !$wrappedKeysJson || !$file) {
            return new JsonResponse(['error' => 'Missing required security parameters.'], Response::HTTP_BAD_REQUEST);
        }

        $wrappedKeys = json_decode($wrappedKeysJson, true);
        if (!is_array($wrappedKeys)) {
            return new JsonResponse(['error' => 'Invalid cryptographic mapping.'], Response::HTTP_BAD_REQUEST);
        }

        // 2. Automatically associate or create a Client entity for this sender
        // Ensures standard SaaS organizational structure remains completely clean
        $client = $this->em->getRepository(Client::class)->findOneBy([
            'tenant' => $tenant,
            'clientName' => sprintf('Drop Box: %s', $senderName)
        ]);

        if (!$client) {
            $client = new Client();
            $client->tenant = $tenant;
            $client->clientName = sprintf('Drop Box: %s', $senderName);
            $this->em->persist($client);
        }

        // 3. Persist the encrypted binary to our secure storage volume
        $secureDirectory = $this->getParameter('kernel.project_dir') . '/var/secure_uploads';
        $safeFilename = Uuid::v4()->toString() . '.enc';

        try {
            $file->move($secureDirectory, $safeFilename);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Failed to write encrypted payload to disk.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // 4. Create the Document meta-record
        $document = new Document();
        $document->client = $client;
        $document->filePath = $safeFilename;
        $document->iv = $iv;
        $this->em->persist($document);

        // 5. Build individual DocumentKeys for each recipient
        foreach ($wrappedKeys as $userId => $wrappedKeyHex) {
            /** @var User $user */
            $user = $this->em->getRepository(User::class)->find($userId);
            if ($user && $user->tenant === $tenant) {
                $documentKey = new DocumentKey();
                $documentKey->document = $document;
                $documentKey->user = $user;
                $documentKey->wrappedKeyHex = $wrappedKeyHex;

                $this->em->persist($documentKey);

                // Automatically assign the user to have permanent access to this auto-created Client
                $client->addUser($user);
            }
        }

        // 6. Update Drop Request Status if linked
        if ($reqToken) {
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
            'message' => 'Files securely encrypted and successfully delivered to your providers!'
        ]);
    }
}
