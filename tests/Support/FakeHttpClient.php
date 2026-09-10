<?php

declare(strict_types=1);

namespace K2gl\TokenStatusList\Tests\Support;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * PSR-18 stub: a map of URI → response (or exception to throw), recording
 * every request it receives.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    /**
     * @param array<string, ResponseInterface|Throwable> $responses
     */
    public function __construct(private readonly array $responses) {}

    public static function statusListResponse(string $compact, string $contentType = 'application/statuslist+jwt'): Response
    {
        return new Response(200, ['Content-Type' => $contentType], $compact);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $uri = (string) $request->getUri();
        $response = $this->responses[$uri] ?? new RuntimeException(sprintf('Unexpected request to %s.', $uri));

        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }

    /**
     * @return list<RequestInterface>
     */
    public function requests(): array
    {
        return $this->requests;
    }
}
