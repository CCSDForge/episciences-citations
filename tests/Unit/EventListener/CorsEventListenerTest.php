<?php

namespace App\Tests\Unit\EventListener;

use App\EventListener\CorsEventListener;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class CorsEventListenerTest extends TestCase
{
    private CorsEventListener $listener;
    private LoggerInterface $logger;
    private string $corsSite = 'episciences.org';
    private HttpKernelInterface $kernel;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->kernel = $this->createMock(HttpKernelInterface::class);

        $this->listener = new CorsEventListener(
            $this->corsSite,
            $this->logger
        );
    }

    #[Test]
    public function testGetSubscribedEvents_ReturnsCorrectConfiguration(): void
    {
        // Act
        $events = CorsEventListener::getSubscribedEvents();

        // Assert
        $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
        $this->assertArrayHasKey(KernelEvents::RESPONSE, $events);

        // Vérifier la configuration REQUEST
        $this->assertEquals(['onKernelRequest', 9], $events[KernelEvents::REQUEST]);

        // Vérifier la configuration RESPONSE
        $this->assertEquals(['onKernelResponse', 0], $events[KernelEvents::RESPONSE]);
    }

    #[Test]
    public function testOnKernelRequest_NotMainRequest_DoesNothing(): void
    {
        // Arrange
        $request = new Request([], [], [], [], [], ['REQUEST_URI' => '/visualize-citations']);
        $event = new RequestEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::SUB_REQUEST  // Sous-requête
        );

        // Logger ne doit PAS être appelé
        $this->logger->expects($this->never())
            ->method('info');

        // Act
        $this->listener->onKernelRequest($event);

        // Assert - pas de réponse définie
        $this->assertNull($event->getResponse());
    }

    #[Test]
    public function testOnKernelRequest_NonApiRoute_DoesNothing(): void
    {
        // Arrange
        $request = new Request([], [], [], [], [], ['REQUEST_URI' => '/other-route']);
        $event = new RequestEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST
        );

        // Logger ne doit PAS être appelé
        $this->logger->expects($this->never())
            ->method('info');

        // Act
        $this->listener->onKernelRequest($event);

        // Assert - pas de réponse définie
        $this->assertNull($event->getResponse());
    }

    #[Test]
    public function testOnKernelRequest_OptionsRequest_ReturnsNoContent(): void
    {
        // Arrange
        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            [
                'REQUEST_URI' => '/visualize-citations/123',
                'REQUEST_METHOD' => 'OPTIONS',
                'HTTP_ORIGIN' => 'https://test.episciences.org'
            ]
        );
        $event = new RequestEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST
        );

        // Act
        $this->listener->onKernelRequest($event);

        // Assert
        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertEquals(Response::HTTP_NO_CONTENT, $response->getStatusCode());

        // Vérifier les headers CORS
        $this->assertEquals('https://test.episciences.org', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertEquals('GET, POST, OPTIONS', $response->headers->get('Access-Control-Allow-Methods'));
    }

    #[Test]
    public function testOnKernelRequest_ValidOrigin_AllowsRequest(): void
    {
        // Arrange
        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            [
                'REQUEST_URI' => '/visualize-citations/123',
                'REQUEST_METHOD' => 'GET',
                'HTTP_ORIGIN' => 'https://test.episciences.org'
            ]
        );
        $event = new RequestEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST
        );

        // Logger info doit être appelé
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Checking CORS', [
                'origin' => 'https://test.episciences.org',
                'host' => ''
            ]);

        // Logger alert ne doit PAS être appelé (origine valide)
        $this->logger->expects($this->never())
            ->method('alert');

        // Act
        $this->listener->onKernelRequest($event);

        // Assert - pas de réponse (requête autorisée)
        $this->assertNull($event->getResponse());
    }

    #[Test]
    public function testOnKernelRequest_InvalidOrigin_BlocksRequest(): void
    {
        // Arrange
        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            [
                'REQUEST_URI' => '/visualize-citations/123',
                'REQUEST_METHOD' => 'GET',
                'HTTP_ORIGIN' => 'https://malicious-site.com'
            ]
        );
        $event = new RequestEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST
        );

        // Logger alert doit être appelé
        $this->logger->expects($this->once())
            ->method('alert')
            ->with('FORBIDDEN CORS origin', [
                'origin' => 'https://malicious-site.com',
                'host' => '',
                'path' => '/visualize-citations/123'
            ]);

        // Act
        $this->listener->onKernelRequest($event);

        // Assert - réponse 403 Forbidden
        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertEquals(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertEquals(Response::HTTP_FORBIDDEN, $content['status']);
        $this->assertEquals('Forbidden', $content['message']);
    }

    #[Test]
    public function testOnKernelResponse_NotMainRequest_DoesNothing(): void
    {
        // Arrange
        $request = new Request([], [], [], [], [], ['REQUEST_URI' => '/visualize-citations']);
        $response = new Response();
        $event = new ResponseEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::SUB_REQUEST,
            $response
        );

        // Act
        $this->listener->onKernelResponse($event);

        // Assert - pas de headers CORS ajoutés
        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function testOnKernelResponse_NonApiRoute_DoesNotAddHeaders(): void
    {
        // Arrange
        $request = new Request([], [], [], [], [], ['REQUEST_URI' => '/other-route']);
        $response = new Response();
        $event = new ResponseEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response
        );

        // Act
        $this->listener->onKernelResponse($event);

        // Assert - pas de headers CORS ajoutés
        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function testOnKernelResponse_ApiRoute_AddsHeaders(): void
    {
        // Arrange
        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            [
                'REQUEST_URI' => '/visualize-citations/123',
                'HTTP_ORIGIN' => 'https://test.episciences.org'
            ]
        );
        $response = new Response();
        $event = new ResponseEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response
        );

        // Act
        $this->listener->onKernelResponse($event);

        // Assert - headers CORS ajoutés
        $this->assertEquals('https://test.episciences.org', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertEquals('GET, POST, OPTIONS', $response->headers->get('Access-Control-Allow-Methods'));
        $this->assertEquals('Content-Type, Authorization', $response->headers->get('Access-Control-Allow-Headers'));
        $this->assertEquals('3600', $response->headers->get('Access-Control-Max-Age'));
    }

    #[Test]
    public function testIsValidCorsOrigin_EmptyCorsSite_ReturnsFalse(): void
    {
        // Arrange - créer un nouveau logger pour ce test
        $logger = $this->createMock(LoggerInterface::class);

        // Logger warning doit être appelé
        $logger->expects($this->once())
            ->method('warning')
            ->with('CORS site parameter is empty');

        // Logger info doit être appelé d'abord
        $logger->expects($this->once())
            ->method('info')
            ->with('Checking CORS', $this->anything());

        // Logger alert doit être appelé (échec validation)
        $logger->expects($this->once())
            ->method('alert')
            ->with('FORBIDDEN CORS origin', $this->anything());

        // Créer listener avec corsSite vide
        $listenerEmptyCors = new CorsEventListener(
            '',  // corsSite vide
            $logger
        );

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            [
                'REQUEST_URI' => '/visualize-citations/123',
                'REQUEST_METHOD' => 'GET',
                'HTTP_ORIGIN' => 'https://test.episciences.org'
            ]
        );
        $event = new RequestEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST
        );

        // Act
        $listenerEmptyCors->onKernelRequest($event);

        // Assert - requête bloquée
        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertEquals(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    #[Test]
    public function testIsValidCorsOrigin_ValidHost_ReturnsTrue(): void
    {
        // Arrange
        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            [
                'REQUEST_URI' => '/visualize-citations/123',
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST' => 'api.episciences.org'  // Valide (se termine par corsSite)
            ]
        );
        $event = new RequestEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST
        );

        // Logger info doit être appelé
        $this->logger->expects($this->once())
            ->method('info');

        // Logger alert ne doit PAS être appelé (host valide)
        $this->logger->expects($this->never())
            ->method('alert');

        // Act
        $this->listener->onKernelRequest($event);

        // Assert - pas de réponse (requête autorisée)
        $this->assertNull($event->getResponse());
    }

    #[Test]
    public function testIsValidCorsOrigin_InvalidOriginAndHost_ReturnsFalse(): void
    {
        // Arrange
        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            [
                'REQUEST_URI' => '/visualize-citations/123',
                'REQUEST_METHOD' => 'GET',
                'HTTP_ORIGIN' => 'https://malicious.com',
                'HTTP_HOST' => 'evil.org'
            ]
        );
        $event = new RequestEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST
        );

        // Logger alert doit être appelé
        $this->logger->expects($this->once())
            ->method('alert')
            ->with('FORBIDDEN CORS origin', $this->anything());

        // Act
        $this->listener->onKernelRequest($event);

        // Assert - réponse 403
        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertEquals(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }
}
