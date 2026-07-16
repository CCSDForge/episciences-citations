<?php

namespace App\Controller;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Shared CAS-user / document authorization helpers, mixed into controllers that
 * gate an action by the current user's rights on a given Episciences document.
 *
 * Requires the using class to extend Symfony's AbstractController (for
 * `$this->container`) and to expose a `$episciences` property of type
 * `App\Services\Episciences`.
 */
trait DocumentAccessTrait
{
    /**
     * @return array<string, mixed>
     */
    private function getUserAttributes(): array
    {
        return $this->container->get('security.token_storage')->getToken()->getAttributes();
    }

    /**
     * @throws ClientExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    private function isAuthorizeForApp(int $docId): bool
    {
        return $this->episciences->getRightUser((string) $docId,
            $this->container->get('security.token_storage')->getToken()->getAttributes()['UID']);
    }
}
