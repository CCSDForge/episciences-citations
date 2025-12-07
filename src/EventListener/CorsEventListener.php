<?php

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * EventListener to handle CORS on API endpoints
 *
 * Checks request origin and adds appropriate CORS headers
 * to responses for routes starting with /visualize-citations
 */
class CorsEventListener implements EventSubscriberInterface
{
    public function __construct(
        private string $corsSite,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Registers subscribed events
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 9], // Before RouterListener (priority 8)
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
        ];
    }

    /**
     * Validates CORS on incoming requests
     * Blocks requests with invalid origin
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // Apply CORS only to API routes
        if (!str_starts_with($path, '/visualize-citations')) {
            return;
        }

        // Handle OPTIONS requests (preflight)
        if ($request->getMethod() === 'OPTIONS') {
            $response = new Response('', Response::HTTP_NO_CONTENT);
            $this->addCorsHeaders($response, $request);
            $event->setResponse($response);
            return;
        }

        // Validate CORS origin
        if (!$this->isValidCorsOrigin($request)) {
            $this->logger->alert('FORBIDDEN CORS origin', [
                'origin' => $request->headers->get('origin', ''),
                'host' => $request->headers->get('host', ''),
                'path' => $path
            ]);

            $response = new JsonResponse(
                ['status' => Response::HTTP_FORBIDDEN, 'message' => 'Forbidden'],
                Response::HTTP_FORBIDDEN
            );
            $event->setResponse($response);
        }
    }

    /**
     * Adds CORS headers to responses
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // Apply CORS only to API routes
        if (!str_starts_with($path, '/visualize-citations')) {
            return;
        }

        $response = $event->getResponse();
        $this->addCorsHeaders($response, $request);
    }

    /**
     * Checks if the request origin is valid
     */
    private function isValidCorsOrigin($request): bool
    {
        // Ensure values are non-null strings (PHP 8.2 strict)
        $origin = (string) ($request->headers->get('origin') ?? '');
        $host = (string) ($request->headers->get('host') ?? '');

        $this->logger->info('Checking CORS', [
            'origin' => $origin,
            'host' => $host
        ]);

        // Check that corsSite is not empty
        if (empty($this->corsSite)) {
            $this->logger->warning('CORS site parameter is empty');
            return false;
        }

        $corsPattern = '/' . preg_quote($this->corsSite, '/') . '$/';

        // Ensure strings are not empty before preg_match (PHP 8.2)
        return ($origin !== '' && preg_match($corsPattern, $origin) === 1)
            || ($host !== '' && preg_match($corsPattern, $host) === 1);
    }

    /**
     * Adds CORS headers to the response
     */
    private function addCorsHeaders(Response $response, $request): void
    {
        // Ensure origin is a non-null string (PHP 8.2 strict)
        $origin = (string) ($request->headers->get('origin') ?? '');

        if ($origin !== '') {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');
            $response->headers->set('Access-Control-Max-Age', '3600');
        }
    }
}
