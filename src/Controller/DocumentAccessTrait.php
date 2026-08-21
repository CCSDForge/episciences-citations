<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\Episciences;
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
 * `$this->container`). Takes the Episciences service as an explicit parameter
 * rather than reading it off `$this` so each host controller's own field stays
 * visibly used within its own file (a cross-file `$this->episciences` read here
 * previously tripped Sonar's unused-private-field check, php:S1068).
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
    private function isAuthorizeForApp(Episciences $episciences, int $docId): bool
    {
        return $episciences->getRightUser((string) $docId,
            (string) $this->container->get('security.token_storage')->getToken()->getAttributes()['UID']);
    }
}
