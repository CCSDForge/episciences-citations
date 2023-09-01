<?php

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DefaultController extends AbstractController
{
    /**
     * @param Request $request
     * @return RedirectResponse
     */
    #[Route('/login', name: 'login')]
    public function login(Request $request,LoggerInterface $logger) : RedirectResponse {

        $target = urlencode($this->loadHttpsOrHttp($this->getParameter('cas_login_target')));
        $url = 'https://'
            . $this->getParameter('cas_host') . $this->getParameter('cas_path')
            . '/login?service=';
        $journalUrl = $this->loadHttpsOrHttp($request->get('url'));
        $logger->info('page CAS');
        $logger->info("journal_url",[$journalUrl]);
        $logger->info("url complete",[$url . $target . '/force?url='.$journalUrl]);
        return $this->redirect($url . $target . '/force?url='.$journalUrl);
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

    /**
     * @param Request $request
     * @return RedirectResponse
     */
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
        return $this->redirect($this->generateUrl('app_extract',['url'=>$request->get('url')]));
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
}