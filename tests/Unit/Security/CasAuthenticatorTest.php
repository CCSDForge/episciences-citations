<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\CasAuthenticator;
use L3\Bundle\CasGuardBundle\Event\CasAuthenticationFailureEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Exception\InvalidConfigurationException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class CasAuthenticatorTest extends TestCase
{
    #[Test]
    public function testSupportsReturnsNullWhenRequestHasNoSession(): void
    {
        $authenticator = $this->createAuthenticator();
        $request = Request::create('/');

        $this->assertNull($authenticator->supports($request));
    }

    #[Test]
    public function testSupportsReturnsFalseWhenImpersonateTokenIsPresent(): void
    {
        $authenticator = $this->createAuthenticator();
        $request = Request::create('/');
        $session = new Session(new MockArraySessionStorage());
        $session->set('impersonate_token', 'some-token');
        $request->setSession($session);

        $this->assertFalse($authenticator->supports($request));
    }

    #[Test]
    public function testSupportsReturnsTrueWhenTicketQueryParamIsPresent(): void
    {
        $authenticator = $this->createAuthenticator();
        $request = Request::create('/?ticket=ST-12345');
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $this->assertTrue($authenticator->supports($request));
    }

    #[Test]
    public function testSupportsReturnsNullWhenNoImpersonateTokenAndNoTicket(): void
    {
        $authenticator = $this->createAuthenticator();
        $request = Request::create('/');
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $this->assertNull($authenticator->supports($request));
    }

    #[Test]
    public function testOnAuthenticationFailureRedirectsAndClearsAttributeWhenRedirectUrlIsSet(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $authenticator = $this->createAuthenticator([], $dispatcher);
        $request = Request::create('/');
        $request->attributes->set('_cas_redirect_url', 'https://cas.example.org/login');

        $response = $authenticator->onAuthenticationFailure($request, new AuthenticationException('CAS authentication required'));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('https://cas.example.org/login', $response->getTargetUrl());
        $this->assertFalse($request->attributes->has('_cas_redirect_url'));
    }

    #[Test]
    public function testOnAuthenticationFailureDispatchesEventAndReturnsDefaultForbiddenResponse(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                $this->isInstanceOf(CasAuthenticationFailureEvent::class),
                CasAuthenticationFailureEvent::POST_MESSAGE
            )
            ->willReturnArgument(0);

        $authenticator = $this->createAuthenticator([], $dispatcher);
        $request = Request::create('/');
        $exception = new AuthenticationException('CAS authentication required');

        $response = $authenticator->onAuthenticationFailure($request, $exception);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    #[Test]
    public function testOnAuthenticationFailureReturnsResponseMutatedByListener(): void
    {
        $mutatedResponse = new Response('custom body', Response::HTTP_OK);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (CasAuthenticationFailureEvent $event) use ($mutatedResponse) {
                $event->setResponse($mutatedResponse);
                return $event;
            });

        $authenticator = $this->createAuthenticator([], $dispatcher);
        $request = Request::create('/');
        $exception = new AuthenticationException('CAS authentication required');

        $response = $authenticator->onAuthenticationFailure($request, $exception);

        $this->assertSame($mutatedResponse, $response);
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    #[Test]
    public function testGetParamReturnsConfiguredValue(): void
    {
        $authenticator = $this->createAuthenticator(['host' => 'cas.example.org']);

        $method = new \ReflectionMethod($authenticator, 'getParam');
        $value = $method->invoke($authenticator, 'host');

        $this->assertSame('cas.example.org', $value);
    }

    #[Test]
    public function testGetParamThrowsWhenKeyIsMissing(): void
    {
        $authenticator = $this->createAuthenticator([]);

        $method = new \ReflectionMethod($authenticator, 'getParam');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('l3_cas_guard.host is not defined');

        $method->invoke($authenticator, 'host');
    }

    #[Test]
    public function testHandleLogoutRequestDestroysTheSessionMatchingTheGivenIndex(): void
    {
        $logoutRequest = '<samlp:LogoutRequest><samlp:SessionIndex>ABC123-valid,session</samlp:SessionIndex></samlp:LogoutRequest>';

        $capturedSessionId = $this->runHandleLogoutRequestInSubprocess($logoutRequest);

        // Proves session_id($sessionId) was actually applied *before* session_start(),
        // so the session that PHP started (and then destroyed) is the one CAS asked
        // to log out - not some unrelated auto-generated session id.
        $this->assertSame('ABC123-valid,session', $capturedSessionId);
    }

    #[Test]
    public function testHandleLogoutRequestIgnoresMalformedSessionIndex(): void
    {
        $logoutRequest = '<samlp:LogoutRequest><samlp:SessionIndex><script>alert(1)</script></samlp:SessionIndex></samlp:LogoutRequest>';

        $capturedSessionId = $this->runHandleLogoutRequestInSubprocess($logoutRequest);

        // The malformed value is rejected by the regex before session_id()/session_start()/
        // session_destroy() are ever reached, so no session save-handler call happens at all.
        $this->assertNull($capturedSessionId);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createAuthenticator(array $config = [], ?EventDispatcherInterface $dispatcher = null): CasAuthenticator
    {
        return new CasAuthenticator($config, $dispatcher ?? $this->createStub(EventDispatcherInterface::class));
    }

    /**
     * Runs CasAuthenticator::handleLogoutRequest() in a fresh, isolated PHP process
     * with a custom session save handler that records the session id it is asked to
     * open/destroy, then returns that captured id (or null if the save handler was
     * never invoked).
     *
     * A subprocess is required because this test suite's process has phpCAS's
     * composer "files" autoload eagerly require CAS.php on boot, which emits PHP
     * deprecation notices to stdout before any test runs. In the CLI SAPI, any
     * output at all makes headers_sent() permanently true for the rest of the
     * process, which silently prevents session_id() from ever changing the active
     * session id in-process. Running in a subprocess that buffers its own output
     * with ob_start() before requiring the autoloader avoids that and lets
     * session_id() actually take effect, so the assertions reflect real behavior.
     */
    private function runHandleLogoutRequestInSubprocess(string $logoutRequest): ?string
    {
        $autoloadPath = var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true);
        $scriptTemplate = <<<'PHP'
            <?php
            ob_start();
            require %s;

            $logoutRequest = base64_decode((string) getenv('CAS_TEST_LOGOUT_REQUEST'));

            $handler = new class implements \SessionHandlerInterface {
                public ?string $capturedId = null;
                public function open(string $path, string $name): bool { return true; }
                public function close(): bool { return true; }
                public function read(string $id): string { $this->capturedId = $id; return ''; }
                public function write(string $id, string $data): bool { return true; }
                public function destroy(string $id): bool { $this->capturedId = $id; return true; }
                public function gc(int $max_lifetime): int|false { return 0; }
            };
            session_set_save_handler($handler, true);

            $dispatcher = new class implements \Symfony\Contracts\EventDispatcher\EventDispatcherInterface {
                public function dispatch(object $event, ?string $eventName = null): object
                {
                    return $event;
                }
            };
            $authenticator = new \App\Security\CasAuthenticator([], $dispatcher);

            $method = new ReflectionMethod($authenticator, 'handleLogoutRequest');
            $method->invoke($authenticator, $logoutRequest);

            ob_end_clean();
            echo 'CAPTURED_IS_NULL=' . ($handler->capturedId === null ? '1' : '0') . \PHP_EOL;
            echo 'CAPTURED_VALUE=' . ($handler->capturedId ?? '') . \PHP_EOL;
            PHP;

        $script = sprintf($scriptTemplate, $autoloadPath);
        $scriptPath = tempnam(sys_get_temp_dir(), 'cas_logout_') . '.php';
        file_put_contents($scriptPath, $script);

        try {
            $command = sprintf(
                'CAS_TEST_LOGOUT_REQUEST=%s php %s 2>&1',
                escapeshellarg(base64_encode($logoutRequest)),
                escapeshellarg($scriptPath)
            );
            $output = (string) shell_exec($command);
        } finally {
            unlink($scriptPath);
        }

        if (!preg_match('/CAPTURED_IS_NULL=([01])/', $output, $nullMatch)
            || !preg_match('/CAPTURED_VALUE=(.*)/', $output, $valueMatch)
        ) {
            $this->fail('Subprocess did not produce the expected output: ' . $output);
        }

        return $nullMatch[1] === '1' ? null : $valueMatch[1];
    }
}
