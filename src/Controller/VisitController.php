<?php

namespace App\Controller;

use App\Service\VisitorCounter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** A page was seen by a browser running its JavaScript (public/js/visit.js): one more visitor, once per address. */
class VisitController extends AbstractController
{
    #[Route('/api/visit', name: 'api_visit', methods: ['POST'])]
    public function visit(Request $request, VisitorCounter $visitors): JsonResponse
    {
        // only the script of the pages sends it (a form or a link can't add this header)
        if ($request->headers->get('X-Sonic-Visit') !== '1' || VisitorCounter::isRobot($request->headers->get('User-Agent'))) {
            return new JsonResponse(['counted' => false], 202);
        }
        // a private address is the proxy's (the visitor's own one did not reach the site): not counted, the
        // browser asks again on its next page
        $address = $request->getClientIp();
        if ($address === null || !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return new JsonResponse(['counted' => false], 202);
        }
        $counted = $visitors->visit($address);
        $response = new JsonResponse(['counted' => $counted, 'visitors' => $visitors->count()]);
        $response->setPrivate();
        return $response;
    }
}
