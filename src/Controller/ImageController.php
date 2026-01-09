<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ImageController extends AbstractController
{
    #[Route('/images/products/{filename}', name: 'serve_product_image', methods: ['GET'])]
    public function serveProductImage(string $filename): Response
    {
        $filePath = $this->getParameter('kernel.project_dir') . '/public/uploads/products/' . $filename;
        
        if (!file_exists($filePath)) {
            throw $this->createNotFoundException('Image not found');
        }
        
        $response = new BinaryFileResponse($filePath);
        $response->headers->set('Content-Type', mime_content_type($filePath));
        $response->headers->set('Cache-Control', 'public, max-age=31536000');
        
        return $response;
    }

    #[Route('/images/sellers/{filename}', name: 'serve_seller_avatar', methods: ['GET'])]
    public function serveSellerAvatar(string $filename): Response
    {
        $filePath = $this->getParameter('kernel.project_dir') . '/public/uploads/sellers/' . $filename;
        
        if (!file_exists($filePath)) {
            throw $this->createNotFoundException('Avatar not found');
        }
        
        $response = new BinaryFileResponse($filePath);
        $response->headers->set('Content-Type', mime_content_type($filePath));
        $response->headers->set('Cache-Control', 'public, max-age=31536000');
        
        return $response;
    }
}
