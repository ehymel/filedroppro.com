<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\Document;
use App\Entity\DocumentKey;
use App\Repository\ClientRepository;
use App\Repository\DocumentKeyRepository;
use App\Repository\DocumentRepository;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/internal/documents', name: 'internal_documents_')]
#[IsGranted('ROLE_USER')]
class DocumentViewerController extends AbstractController
{
    public function __construct(private readonly DocumentRepository $documentRepository,
                                private readonly S3Client $s3Client,
                                #[Autowire(param: 'env(AWS_S3_BUCKET)')] private readonly string $s3BucketName) {}

    #[Route(path: '/', name: 'dashboard', methods: ['GET'])]
    public function dashboard(ClientRepository $clientRepository): Response
    {
        if (!$this->getUser()->tenant) {
            $this->addFlash('danger', 'You must be a tenant administrator to access this page.');
            return $this->redirectToRoute('unauthorized');
        }

        $clients = $clientRepository->findBy([], ['clientName' => 'ASC']);
        $totalBytes = [];
        foreach ($clients as $client) {
            $totalBytes[$client->id->toString()] = 0;
            foreach($client->documents as $document) {
                $totalBytes[$client->id->toString()] += $document->fileSize;
            }
        }

        // Fetch clients belonging to this tenant
        // Note: Our MultiTenant SQL Filter automatically enforces tenant boundaries here.
        return $this->render('internal/document/dashboard.html.twig', [
            'clients' => $clients,
            'encryptedPrivateKey' => $this->getUser()->userKey?->encryptedPrivateKey,
            'storageBytesByClient' => $totalBytes,
        ]);
    }

    /**
     * Securely streams the raw, encrypted .enc binary block from AWS S3 directly to the browser.
     */
    #[Route(path: '/download-payload/{id}', name: 'payload', methods: ['GET'])]
    public function downloadPayload(Document $document): Response
    {
        // Security Check: Verify that this document belongs to the active user's tenant
        if ($document->client->tenant !== $this->getUser()->tenant) {
            throw $this->createAccessDeniedException('Unauthorized tenant metadata matching block.');
        }

        try {
            // Retrieve the encrypted object data stream directly from AWS S3
            $result = $this->s3Client->getObject([
                'Bucket' => $this->s3BucketName,
                'Key' => $document->filePath,
            ]);

            // Stream the S3 resource directly back to Symfony's output buffer
            $response = new StreamedResponse(function () use ($result) {
                $stream = $result['Body'];
                while (!$stream->eof()) {
                    echo $stream->read(8192); // Stream in memory-safe 8KB chunks
                    flush();
                }
            });

            // Set appropriate headers to stream as binary file attachment
            $response->headers->set('Content-Type', 'application/octet-stream');
            $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $document->filePath));

            return $response;

        } catch (\Exception $e) {
            throw $this->createNotFoundException('The requested encrypted file payload could not be retrieved from secure S3 storage.');
        }
    }

    /**
     * API Endpoint yielding the specific initialization vector and wrapped key block for a user.
     */
    #[Route(path: '/crypto-metadata/{id}', name: 'metadata', methods: ['GET'])]
    public function getCryptoMetadata(Document $document, DocumentKeyRepository $documentKeyRepository): JsonResponse
    {
        $documentKey = $documentKeyRepository->findOneBy([
            'document' => $document,
            'user' => $this->getUser()
        ]);

        if (!$documentKey) {
            return new JsonResponse(['error' => 'Access Denied: You do not possess a cryptographic path to unseal this document.'], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse([
            'iv' => $document->iv,
            'wrappedKeyHex' => $documentKey->wrappedKeyHex,
            'originalFileName' => $document->originalFileName, // Push filename!
            'originalExtension' => pathinfo($document->originalFileName ?? $document->filePath, PATHINFO_EXTENSION),
        ]);
    }

    #[Route(path: '/delete/{id}', name: 'delete', methods: ['POST', 'DELETE'])]
    public function delete(Document $document): Response
    {
        // Security Check: Verify that this document belongs to the active user's tenant
        if ($document->client->tenant !== $this->getUser()->tenant) {
            throw $this->createAccessDeniedException('Unauthorized tenant metadata matching block.');
        }

        // Remove the encrypted object from AWS S3 before deleting the metadata record
        try {
            $this->s3Client->deleteObject([
                'Bucket' => $this->s3BucketName,
                'Key' => $document->filePath,
            ]);
        } catch (\Exception $e) {
            $this->addFlash('danger', 'The document could not be removed from secure S3 storage. Please try again.');
            return $this->redirectToRoute('internal_documents_dashboard');
        }

        $this->documentRepository->remove($document, true);

        $this->addFlash('success', 'Document deleted successfully.');

        return $this->redirectToRoute('internal_documents_dashboard');
    }

    #[Route(path: '/update-note/{id}', name: 'update_note', methods: ['POST'])]
    public function updateNote(Document $document, Request $request): JsonResponse
    {
        if ($document->client->tenant !== $this->getUser()->tenant) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $document->note = $data['note'] ?? '';

        $this->documentRepository->save($document, true);

        return new JsonResponse(['success' => true, 'note' => $document->note]);
    }
}
