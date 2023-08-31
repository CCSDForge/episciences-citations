<?php

namespace App\Controller;

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
    public function login(Request $request) : RedirectResponse {

        $target = urlencode($this->getParameter('cas_login_target'));
        $url = 'https://'
            . $this->getParameter('cas_host') . $this->getParameter('cas_path')
            . '/login?service=';
        $journalUrl = $this->loadHttpsOrHttp($request->get('url'));
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
    public function force(Request $request) {
        if ($this->getParameter("cas_gateway")) {
            if (!isset($_SESSION)) {
                session_start();
            }

            session_destroy();
        }

        return $this->redirect($this->generateUrl('app_extract',['url'=>$request->get('url')]));
    }

    /**
     * @param Request $request
     * @return Response
     */
    #[Route(path: ['en' => '/', 'fr' => '/fr'], name: 'index')]
    public function index(Request $request) : Response
    {
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