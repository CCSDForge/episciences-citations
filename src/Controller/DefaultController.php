<?php

namespace App\Controller;

use App\Services\Episciences;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DefaultController extends AbstractController
{
    public function __construct(private Episciences $episciences,private RequestStack $requestStack)
    {
    }
    /**
     * @param Request $request
     * @return RedirectResponse
     */
    #[Route('/login', name: 'login')]
    public function login(Request $request,LoggerInterface $logger) : RedirectResponse {

        $target = urlencode($this->getParameter('cas_login_target'));
        $url = 'https://'
            . $this->getParameter('cas_host') . $this->getParameter('cas_path')
            . '/login?service=';
        $bibExportAsked = ($request->get('exportbib') === "1") ? urlencode('&exportbib=1'): "";
        $journalUrl = $this->loadHttpsOrHttp($request->get('url'));
        $logger->info('page CAS');
        $logger->info("journal_url",[$journalUrl]);
        $logger->info("url complete",[$url . $target . '/force?url='.$journalUrl.$bibExportAsked]);
        return $this->redirect($url . $target . '/force?url='.$journalUrl.$bibExportAsked);
    }

    /**
     * @return void
     */
    #[Route('/logout', name: 'logout')]
    public function logout() {
        if (($this->getParameter('cas_logout_target') !== null) && (!empty($this->getParameter('cas_logout_target')))) {
            \phpCAS::logoutWithRedirectService($this->getParameter('cas_logout_target'));
        } else {
            \phpCAS::logout();
        }
    }

    #[Route('/force', name: 'force')]
    public function force(Request $request, LoggerInterface $logger) {
        $logger->notice('force page');
        $logger->info('cas_gateway',[$this->getParameter("cas_gateway")]);
        $logger->info('session before gateway',[$_SESSION]);
        $logger->info("USER INFO AFTER FORCE", [$this->container->get('security.token_storage')->getToken()->getAttributes()]);
        if ($this->getParameter("cas_gateway")) {
            if (!isset($_SESSION)) {
                session_start();
            }

            session_destroy();
        }

        $logger->info('SESSION',[$_SESSION]);
        $option = ['url'=> $request->get('url'), 'exportbib' => $request->get('exportbib')];
        $this->setSessionEpiUrlPdf($request,$request->get('url'));
        return $this->redirect($this->generateUrl('app_extract',$option));
    }

    /**
     * @param Request $request
     * @return Response
     */
    #[Route(path: ['en' => '/', 'fr' => '/fr'], name: 'index')]
    public function index(Request $request, LoggerInterface $logger) : Response
    {
        $logger->info("USER INFO", [$this->container->get('security.token_storage')->getToken()->getAttributes()]);
        $logger->info('ROLE CAS',[$this->getUser()->getRoles()]);
        $logger->info('index page',[$_SESSION]);
        return $this->render('base.html.twig', []);
    }

    /**
     * @param string $url
     * @return string
     */
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
    private function setSessionEpiUrlPdf(Request $request, $url){
        $session = $request->getSession();
        $session->set('EpiPdfUrltoExtract', '');
        $session->set('EpiPdfUrltoExtract',$url);
    }
}