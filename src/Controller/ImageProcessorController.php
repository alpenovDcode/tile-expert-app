<?php

namespace App\Controller;

use App\Service\ImageProcessingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ImageProcessorController extends AbstractController
{
    private ImageProcessingService $imageProcessingService;

    public function __construct(ImageProcessingService $imageProcessingService)
    {
        $this->imageProcessingService = $imageProcessingService;
    }

    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        // Получаем уже обработанные изображения
        $processedImages = $this->imageProcessingService->getProcessedImages();
        
        return $this->render('image_processor/index.html.twig', [
            'processed_images' => $processedImages,
        ]);
    }

    #[Route('/process-images', name: 'app_process_images', methods: ['POST'])]
    public function processImages(Request $request): JsonResponse
    {
        $url = $request->request->get('url');
        $minWidth = (int) $request->request->get('min_width');
        $minHeight = (int) $request->request->get('min_height');
        $overlayText = $request->request->get('overlay_text');

        if (empty($url) || $minWidth <= 0 || $minHeight <= 0) {
            return $this->json([
                'success' => false,
                'message' => 'Неверные параметры запроса'
            ]);
        }

        try {
            $processedImages = $this->imageProcessingService->processImagesFromUrl(
                $url,
                $minWidth,
                $minHeight,
                $overlayText
            );

            return $this->json([
                'success' => true,
                'images' => $processedImages,
                'message' => 'Обработано изображений: ' . count($processedImages)
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
} 