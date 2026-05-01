<?php

namespace App\Security;

use L3\Bundle\CasGuardBundle\Entity\CasUserInterface;
use L3\Bundle\CasGuardBundle\Event\CasAuthenticationFailureEvent;
use Symfony\Component\Form\Exception\InvalidConfigurationException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Wraps the L3 CAS authenticator with Symfony 7.4 compatibility fixes:
 *
 * - supports() uses hasSession() to avoid SessionNotFoundException
 * - authenticate() calls setNoClearTicketsFromUrl() so phpCAS does not do
 *   the "clean URL" redirect (which called exit() and killed the process)
 * - CAS_GracefullTerminationException::throwInsteadOfExiting() converts
 *   any remaining phpCAS exit() calls into catchable exceptions
 * - When phpCAS wants to redirect the user to CAS, onAuthenticationFailure()
 *   returns a proper Symfony RedirectResponse instead of calling exit()
 */
class CasAuthenticator extends AbstractAuthenticator
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {
    }

    public function supports(Request $request): ?bool
    {
        if (!$request->hasSession()) {
            return null;
        }

        $session = $request->getSession();

        if ($session->has('impersonate_token')) {
            return false;
        }

        // Return true (authenticate immediately) only when a CAS ticket is present.
        // Otherwise return null so Symfony uses lazy evaluation: authenticate() is
        // still called when the token is actually needed (e.g. access_control check)
        // but we avoid running phpCAS on every single request up front.
        return $request->query->has('ticket') ? true : null;
    }

    public function authenticate(Request $request): Passport
    {
        // Make phpCAS throw CAS_GracefullTerminationException instead of calling
        // exit(), so Symfony can catch and handle redirects properly.
        \CAS_GracefullTerminationException::throwInsteadOfExiting();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        \phpCAS::setLogger();
        \phpCAS::setVerbose(false);

        \phpCAS::client(
            CAS_VERSION_2_0,
            $this->getParam('host'),
            $this->getParam('port'),
            $this->getParam('path') ?? '',
            $this->getParam('casServiceBaseUrl') ?? $request->getSchemeAndHttpHost(),
            true
        );

        // Prevent phpCAS from doing the "clean URL" redirect after ticket validation.
        // Without this, phpCAS calls header(Location:...) + exit() after a successful
        // ticket validation, which terminates the PHP process before Symfony can respond.
        \phpCAS::setNoClearTicketsFromUrl();

        if (is_bool($this->getParam('ca')) && $this->getParam('ca') === false) {
            \phpCAS::setNoCasServerValidation();
        } else {
            \phpCAS::setCasServerCACert($this->getParam('ca'));
        }

        if ($this->getParam('handleLogoutRequest')) {
            if ($request->request->has('logoutRequest')) {
                $this->handleLogoutRequest($request->request->get('logoutRequest'));
            }
            \phpCAS::handleLogoutRequests(true);
        } else {
            \phpCAS::handleLogoutRequests(false);
        }

        try {
            $user = $this->resolveUser();
        } catch (\CAS_GracefullTerminationException $e) {
            // phpCAS wants to redirect to the CAS server for authentication.
            // Extract the URL it set via header() and delegate to onAuthenticationFailure().
            foreach (headers_list() as $header) {
                if (stripos($header, 'Location:') === 0) {
                    $request->attributes->set('_cas_redirect_url', trim(substr($header, 9)));
                    header_remove('Location');
                    break;
                }
            }
            throw new AuthenticationException('CAS authentication required', 0, $e);
        }

        return new SelfValidatingPassport(new UserBadge($user));
    }

    private function resolveUser(): string
    {
        if ($this->getParam('gateway')) {
            if ($this->getParam('force')) {
                \phpCAS::forceAuthentication();
                return \phpCAS::getUser();
            }

            return \phpCAS::checkAuthentication() ? \phpCAS::getUser() : '__NO_USER__';
        }

        if ($this->getParam('force')) {
            \phpCAS::forceAuthentication();
            return \phpCAS::getUser();
        }

        return \phpCAS::isAuthenticated() ? \phpCAS::getUser() : '__NO_USER__';
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        if (\phpCAS::isSessionAuthenticated()) {
            $token->setAttributes(\phpCAS::getAttributes());
            $user = $token->getUser();
            if ($user instanceof CasUserInterface) {
                $user->setCasAttributes(\phpCAS::getAttributes());
            }
        }

        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        if ($url = $request->attributes->get('_cas_redirect_url')) {
            $request->attributes->remove('_cas_redirect_url');
            return new RedirectResponse($url);
        }

        $message = strtr($exception->getMessageKey(), $exception->getMessageData());
        $defaultResponse = new Response($message, Response::HTTP_FORBIDDEN);

        $event = new CasAuthenticationFailureEvent($request, $exception, $defaultResponse);
        $this->eventDispatcher->dispatch($event, CasAuthenticationFailureEvent::POST_MESSAGE);

        return $event->getResponse();
    }

    private function handleLogoutRequest(string $logoutRequest): void
    {
        $open = '<samlp:SessionIndex>';
        $close = '</samlp:SessionIndex>';

        $begin = strpos($logoutRequest, $open);
        $end = strpos($logoutRequest, $close, $begin);
        $sessionId = substr($logoutRequest, $begin + strlen($open), $end - strlen($close) - $begin + 1);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        session_id($sessionId);
        session_destroy();
    }

    private function getParam(string $key): mixed
    {
        if (!array_key_exists($key, $this->config)) {
            throw new InvalidConfigurationException('l3_cas_guard.' . $key . ' is not defined');
        }
        return $this->config[$key];
    }
}
