<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\Document;
use App\Entity\DocumentKey;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/drop/documents', name: 'drop_documents_')]
#[IsGranted('ROLE_USER')]
class DocumentViewerController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em) {}

    /**
     * Renders the master dashboard listing clients and their encrypted documents.
     */
    #[Route('/', name: 'dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        // Fetch clients belonging to this tenant
        // Note: Our MultiTenant SQL Filter automatically enforces tenant boundaries here.
        return $this->render('document_viewer/dashboard.html.twig', [
            'clients' => $this->em->getRepository(Client::class)->findBy([], ['clientName' => 'ASC']),
            'encryptedPrivateKey' => $this->getUser()->userKey?->encryptedPrivateKey
        ]);
    }

    /**
     * Securely streams the raw, encrypted .enc binary block to the browser.
     */
    #[Route('/download-payload/{id}', name: 'payload', methods: ['GET'])]
    public function downloadPayload(Document $document): Response
    {
        // Security Check: Verify that this document belongs to the active user's tenant
        if ($document->client->tenant !== $this->getUser()->tenant) {
            throw $this->createAccessDeniedException('Unauthorized tenant metadata matching block.');
        }

        $secureDirectory = $this->getParameter('kernel.project_dir') . '/var/secure_uploads';
        $filePath = $secureDirectory . '/' . $document->filePath;

        if (!file_exists($filePath)) {
            throw $this->createNotFoundException('The requested encrypted file payload could not be located on disk.');
        }

        // Return the raw encrypted file payload directly as a binary response stream
        return new BinaryFileResponse($filePath);
    }

    /**
     * API Endpoint yielding the specific initialization vector and wrapped key block for a user.
     */
    #[Route('/crypto-metadata/{id}', name: 'metadata', methods: ['GET'])]
    public function getCryptoMetadata(Document $document): JsonResponse
    {
        // Locate the singular wrapped envelope key matching both this document and this user
        $documentKey = $this->em->getRepository(DocumentKey::class)->findOneBy([
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
}
