<?php

namespace App\Service;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ImageProcessingService
{
    private HttpClientInterface $httpClient;
    private string $publicDir;

    public function __construct(string $projectDir)
    {
        $this->httpClient = HttpClient::create();
        $this->publicDir = $projectDir . '/public';
        
        // Создаем каталог для обработанных изображений
        $processedDir = $this->publicDir . '/processed-images';
        if (!is_dir($processedDir)) {
            mkdir($processedDir, 0755, true);
        }
    }

    public function processImagesFromUrl(string $url, int $minWidth, int $minHeight, string $overlayText): array
    {
        // Получаем HTML страницы
        $response = $this->httpClient->request('GET', $url);
        $htmlContent = $response->getContent();
        
        // Парсим HTML и находим изображения
        $crawler = new Crawler($htmlContent);
        $imageUrls = [];
        
        $crawler->filter('img')->each(function (Crawler $node) use (&$imageUrls, $url) {
            $src = $node->attr('src');
            if ($src) {
                // Преобразуем относительные URL в абсолютные
                if (strpos($src, 'http') !== 0) {
                    $parsedUrl = parse_url($url);
                    $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
                    if (strpos($src, '/') === 0) {
                        $src = $baseUrl . $src;
                    } else {
                        $src = $baseUrl . '/' . $src;
                    }
                }
                $imageUrls[] = $src;
            }
        });

        $processedImages = [];
        
        foreach ($imageUrls as $imageUrl) {
            try {
                $processedImage = $this->processImage($imageUrl, $minWidth, $minHeight, $overlayText);
                if ($processedImage) {
                    $processedImages[] = $processedImage;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return $processedImages;
    }

    private function processImage(string $imageUrl, int $minWidth, int $minHeight, string $overlayText): ?array
    {
        $response = $this->httpClient->request('GET', $imageUrl);
        $imageContent = $response->getContent();
        
        // Создаем временный файл
        $tempFile = tempnam(sys_get_temp_dir(), 'img_');
        file_put_contents($tempFile, $imageContent);
        
        // Проверяем размеры изображения
        $imageInfo = getimagesize($tempFile);
        if (!$imageInfo || $imageInfo[0] < $minWidth || $imageInfo[1] < $minHeight) {
            unlink($tempFile);
            return null;
        }
        
        // Создаем GD изображение
        $originalImage = $this->createImageFromFile($tempFile, $imageInfo[2]);
        if (!$originalImage) {
            unlink($tempFile);
            return null;
        }
        
        $processedImage = $this->resizeAndCropImage($originalImage, $imageInfo[0], $imageInfo[1]);
        
        if (!empty($overlayText)) {
            $this->addTextToImage($processedImage, $overlayText);
        }
        
        // Сохраняем обработанное изображение
        $filename = uniqid('processed_') . '.jpg';
        $outputPath = $this->publicDir . '/processed-images/' . $filename;
        imagejpeg($processedImage, $outputPath, 90);
        
        imagedestroy($originalImage);
        imagedestroy($processedImage);
        unlink($tempFile);
        
        return [
            'filename' => $filename,
            'path' => '/processed-images/' . $filename,
            'original_url' => $imageUrl,
            'overlay_text' => $overlayText,
            'created_at' => date('Y-m-d H:i:s')
        ];
    }

    private function createImageFromFile(string $filepath, int $imageType)
    {
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                return imagecreatefromjpeg($filepath);
            case IMAGETYPE_PNG:
                return imagecreatefrompng($filepath);
            case IMAGETYPE_GIF:
                return imagecreatefromgif($filepath);
            case IMAGETYPE_WEBP:
                return imagecreatefromwebp($filepath);
            default:
                return false;
        }
    }

    private function resizeAndCropImage($originalImage, int $originalWidth, int $originalHeight)
    {
        $targetSize = 200;
        
        $ratio = $targetSize / $originalHeight;
        $newWidth = (int)($originalWidth * $ratio);
        $newHeight = $targetSize;
        
        $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($resizedImage, $originalImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
        
        $finalImage = imagecreatetruecolor($targetSize, $targetSize);
        
        $offsetX = max(0, ($newWidth - $targetSize) / 2);
        
        imagecopy($finalImage, $resizedImage, 0, 0, $offsetX, 0, min($newWidth, $targetSize), $targetSize);
        
        imagedestroy($resizedImage);
        
        return $finalImage;
    }

    private function addTextToImage($image, string $text): void
    {
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        
        $fontSize = 5;
        
        $textWidth = strlen($text) * imagefontwidth($fontSize);
        $textHeight = imagefontheight($fontSize);
        
        $x = (int)((200 - $textWidth) / 2);
        $y = (int)((200 - $textHeight) / 2);
        
        imagestring($image, $fontSize, $x + 1, $y + 1, $text, $black);
        imagestring($image, $fontSize, $x, $y, $text, $white);
    }

    public function getProcessedImages(): array
    {
        $processedDir = $this->publicDir . '/processed-images';
        $images = [];
        
        if (is_dir($processedDir)) {
            $files = glob($processedDir . '/*.jpg');
            foreach ($files as $file) {
                $filename = basename($file);
                $images[] = [
                    'filename' => $filename,
                    'path' => '/processed-images/' . $filename,
                    'created_at' => date('Y-m-d H:i:s', filemtime($file))
                ];
            }
            
            usort($images, function($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });
        }
        
        return $images;
    }
} 