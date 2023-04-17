<?php

namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DefaultController extends AbstractController
{
    #[Route('/login', name: 'login')]
    public function login(Request $request) {
        $session = $request->getSession();
        $session->set('docId', $request->query->get('docid'));
        $session->set('rvCode',  $request->query->get('rvcode'));
        $target = urlencode($this->getParameter('cas_login_target'));
        $url = 'https://'
            . $this->getParameter('cas_host')
            . '/login?service=';
        return $this->redirect($url . $target . '/force');
    }
    #[Route('/logout', name: 'logout')]
    public function logout() {
        if (($this->getParameter('cas_logout_target') !== null) && (!empty($this->getParameter('cas_logout_target')))) {
            \phpCAS::logoutWithRedirectService($this->getParameter('cas_logout_target'));
        } else {
            \phpCAS::logout();
        }
    }

    #[Route('/force', name: 'force')]
    public function force(Request $request) {
        $session = $request->getSession();
        if ($this->getParameter("cas_gateway")) {
            if (!isset($_SESSION)) {
                session_start();
            }

            session_destroy();
        }

        return $this->redirect($this->generateUrl('app_extract',['docId'=>$session->get('docId'),'rvCode'=>$session->get('rvCode')]));
    }


//    /**
//     * @Route("/", name="index")
//     */
//    public function index(Request $request) : Response
//    {
//        dd($attributes = $this->container->get('security.token_storage')->getToken()->getAttributes());
//        return $this->render('base.html.twig', []);
//    }
}