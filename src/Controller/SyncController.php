<?php

declare(strict_types=1);

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/sync')]
class SyncController extends AbstractController
{
    public function __construct(
        private readonly Stopwatch $stopwatch,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws TransportExceptionInterface
     */
    #[Route('/http-symfony')]
    public function httpSymfony(): JsonResponse
    {
        $baseEvent = $this->stopwatch->start('http_requests');

        $urls = [
            'https://microsoftedge.github.io/Demos/json-dummy-data/invalid-url', // This will cause an exception
            'https://microsoftedge.github.io/Demos/json-dummy-data/5MB.json',
            'https://microsoftedge.github.io/Demos/json-dummy-data/1MB.json',
            'https://microsoftedge.github.io/Demos/json-dummy-data/512KB.json',
        ];

        foreach ($urls as $url) {
            try {
                $this->httpClient->request('GET', $url)->toArray();
            } catch (\Exception $e) {
                $this->logger->error(
                    'Error fetching URL: {url}, Error: {error}',
                    [
                        'url' => $url,
                        'error' => $e->getMessage(),
                    ]
                );
            }
        }

        // Stop the main stopwatch event
        $baseEvent->stop();

        return $this->json([
            'message' => 'All HTTP requests completed successfully.',
            'total_execution_time' => self::toHumanReadable($baseEvent->getDuration()),
        ]);
    }

    private static function toHumanReadable(float|int $duration): string
    {
        if ($duration < 1000) {
            return $duration.' ms';
        }
        if ($duration < 60000) {
            return round($duration / 1000, 2).' s';
        }

        return round($duration / 60000, 2).' min';
    }
}
