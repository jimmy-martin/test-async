<?php

declare(strict_types=1);

namespace App\Controller;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\HttpStatus;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;
use function Amp\Future\awaitAll;

#[Route('/async')]
class AsyncController extends AbstractController
{
    public function __construct(
        private readonly Stopwatch $stopwatch,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/tasks')]
    public function index(): JsonResponse
    {
        $event = $this->stopwatch->start('async_tasks');

        $futures = [
            async(
                function () {
                    $this->logger->info('Starting task 1');
                    $event = $this->stopwatch->start('task1', 'async_tasks');
                    delay(5); // Simulate a long-running task
                    $event->stop();
                    $this->logger->info('Task 1 completed');
                }
            ),
            async(
                function () {
                    $this->logger->info('Starting task 2');
                    $event = $this->stopwatch->start('task2', 'async_tasks');
                    delay(3); // Simulate a long-running task
                    $event->stop();
                    $this->logger->info('Task 2 completed');
                }
            ),
            async(
                function () {
                    $this->logger->info('Starting task 3');
                    $event = $this->stopwatch->start('task3', 'async_tasks');
                    delay(2); // Simulate a long-running task
                    $event->stop();
                    $this->logger->info('Task 3 completed');
                }
            ),
            async(
                function () {
                    $this->logger->info('Starting task 4');
                    $event = $this->stopwatch->start('task4', 'async_tasks');
                    $event->stop();
                    $this->logger->info('Task 4 completed');
                }
            ),
        ];

        await($futures);

        // Stop the main stopwatch event
        $event->stop();

        return $this->json([
            'message' => 'All async tasks completed successfully.',
            'tasks' => [
                'task1' => self::toHumanReadable($this->stopwatch->getEvent('task1')->getDuration()),
                'task2' => self::toHumanReadable($this->stopwatch->getEvent('task2')->getDuration()),
                'task3' => self::toHumanReadable($this->stopwatch->getEvent('task3')->getDuration()),
                'task4' => self::toHumanReadable($this->stopwatch->getEvent('task4')->getDuration()),
            ],
            'total_execution_time' => self::toHumanReadable($event->getDuration()),
        ]);
    }

    #[Route('/http-amp')]
    public function httpAmp(): JsonResponse
    {
        $httpClient = HttpClientBuilder::buildDefault();

        $urls = [
            'https://microsoftedge.github.io/Demos/json-dummy-data/invalid-url', // This will cause an exception
            'https://microsoftedge.github.io/Demos/json-dummy-data/1MB.json',
            'https://microsoftedge.github.io/Demos/json-dummy-data/5MB.json',
            'https://microsoftedge.github.io/Demos/json-dummy-data/512KB.json',
        ];

        $requestHandler = function (string $url) use ($httpClient) {
            $event = $this->stopwatch->start(basename($url), 'http_requests');
            $response = $httpClient->request(new Request($url));

            if (!HttpStatus::isSuccessful($response->getStatus())) {
                $event->stop();

                throw new \RuntimeException(
                    \sprintf('HTTP request to %s failed with status %d', $url, $response->getStatus())
                );
            }

            $content = $response->getBody()->buffer();
            $event->stop();

            return $content;
        };

        $futures = [];

        foreach ($urls as $url) {
            $futures[] = async(static fn () => $requestHandler($url));
        }

        $baseEvent = $this->stopwatch->start('main.http_requests');
        $responses = awaitAll($futures);
        $errors = $responses[0] ?? [];

        $baseEvent->stop();

        return $this->json([
            'message' => 'All HTTP requests completed successfully.',
            'total_execution_time' => self::toHumanReadable($baseEvent->getDuration()),
            'requests' => array_map(
                fn ($url) => [
                    'url' => $url,
                    'execution_time' => self::toHumanReadable($this->stopwatch->getEvent(basename($url))->getDuration()),
                ],
                $urls
            ),
            'errors_count' => \count($errors),
        ]);
    }

    /**
     * @throws TransportExceptionInterface
     */
    #[Route('/http-symfony')]
    public function httpSymfony(): JsonResponse
    {
        $urls = [
            'https://microsoftedge.github.io/Demos/json-dummy-data/invalid-url', // This will cause an exception
            'https://microsoftedge.github.io/Demos/json-dummy-data/5MB.json',
            'https://microsoftedge.github.io/Demos/json-dummy-data/1MB.json',
            'https://microsoftedge.github.io/Demos/json-dummy-data/512KB.json',
        ];

        $responses = [];

        foreach ($urls as $url) {
            $response = $this->httpClient->request('GET', $url);
            $responses[$url] = $response;
        }

        $httpClientEvent = $this->stopwatch->start('requests', 'http_client');
        foreach ($responses as $url => $response) {
            try {
                $response->toArray();
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Error fetching URL: {url}, Error: {error}',
                    [
                        'url' => $url,
                        'error' => $e->getMessage(),
                    ]
                );
            }
        }
        $httpClientEvent->stop();

        return $this->json([
            'message' => 'All HTTP requests completed successfully.',
            'total_http_client_request_time' => self::toHumanReadable($httpClientEvent->getDuration()),
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
