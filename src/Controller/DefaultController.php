<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\Episciences;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DefaultController extends AbstractController
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    #[Route('/login', name: 'login')]
    public function login(Request $request) : RedirectResponse {

        $target = urlencode($this->getParameter('cas_login_target'));
        $url = 'https://'
            . $this->getParameter('cas_host') . $this->getParameter('cas_path')
            . '/login?service=';
        $bibExportAsked = ($request->query->get('exportbib') === "1") ? urlencode('&exportbib=1'): "";
        $journalUrl = $this->loadHttpsOrHttp($request->query->get('url') ?? '');
        $this->logger->info('page CAS');
        $this->logger->info("journal_url",[$journalUrl]);
        $this->logger->info("url complete",[$url . $target . '/force?url='.$journalUrl.$bibExportAsked]);
        return $this->redirect($url . $target . '/force?url='.$journalUrl.$bibExportAsked);
    }

    /**
     * phpCAS::logout()/logoutWithRedirectService() normally halts the request by
     * redirecting to the CAS server. That requires the phpCAS client to have been
     * initialized earlier in the request (done lazily by CasAuthenticator when the
     * security context is actually consulted). If /logout is hit without that ever
     * happening — e.g. a bookmarked or directly-typed logout URL — phpCAS throws
     * CAS_OutOfSequenceBeforeClientException, which used to bubble up as an
     * unhandled 500. We now catch that and fall back to a local redirect instead.
     */
    #[Route('/logout', name: 'logout')]
    public function logout(Request $request): RedirectResponse
    {
        try {
            if (!empty($this->getParameter('cas_logout_target'))) {
                \phpCAS::logoutWithRedirectService($this->getParameter('cas_logout_target'));
            } else {
                \phpCAS::logout();
            }
        } catch (\Throwable $e) {
            $this->logger->error('CAS logout failed, falling back to local redirect', ['exception' => $e->getMessage()]);
        }

        if ($request->hasSession()) {
            $request->getSession()->invalidate();
        }

        return $this->redirectToRoute('index');
    }

    #[Route('/force', name: 'force')]
    public function force(Request $request): RedirectResponse
    {
        $this->logger->notice('force page');
        $this->logger->info('cas_gateway',[$this->getParameter("cas_gateway")]);
        $this->logger->info('session before gateway',[$_SESSION]);
        if ($this->getParameter("cas_gateway")) {
            $request->getSession()->invalidate();
        }

        $url = $request->query->get('url') ?? '';
        $option = ['url' => $url, 'exportbib' => $request->query->get('exportbib')];
        $this->setSessionEpiUrlPdf($request, $url);
        $this->setSessionModal($request);
        return $this->redirectToRoute('app_extract', $option);
    }

    #[Route(path: ['en' => '/', 'fr' => '/fr'], name: 'index')]
    public function index(): Response
    {
        return $this->render('base.html.twig', []);
    }

    private function loadHttpsOrHttp(string $url): string
    {

        if ($this->getParameter('force_https') === true) {
            if (preg_match("/^(http:\/\/)/",$url)) {
                return str_replace('http','https',$url);
            }

            if (preg_match("/^(https:\/\/)/",$url)) {
                return $url;
            }
        }
        return $url;
    }

    /**
     * @param $url
     * @return void
     * In case with need to reextract in the app we have infos of which one to extract
     */
    private function setSessionEpiUrlPdf(Request $request, bool|float|int|string $url): void
    {
        $session = $request->getSession();
        $session->set('EpiPdfUrltoExtract', '');
        $session->set('EpiPdfUrltoExtract',$url);
    }

    private function setSessionModal(Request $request): void
    {
        $session = $request->getSession();
        $session->set('isAlreadyopenModal',0);
    }
}
