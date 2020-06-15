<?php

namespace App\Controller;

use App\Service\DeviceConfig;
use ErrorException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class InitController extends AbstractController
{
    private $deviceConfig;

    function __construct(DeviceConfig $deviceConfig)
    {
        $this->deviceConfig = $deviceConfig;
    }

    /**
     * @Route("/init", name="init")
     */
    public function index()
    {
        return $this->render('init/index.html.twig', [
            'controller_name' => 'InitController',
        ]);
    }

    /**
     * @Route("/init/checkInternet", name="init_check_internet")
     */
    public function checkInternet()
    {
        $internetIsGood = false;
        try {
            $testUrl = file_get_contents('https://www.displaydojo.com/client/v1/version');
            $testArray = json_decode($testUrl, true);
            if (isset($testArray['version'])) {
                $internetIsGood = true;
            }
        } catch (ErrorException $e) {
            // log ErrorException
        }
        if ($internetIsGood) {
            return new JsonResponse(['status' => 'ok', 'message' => 'Have internet', 'currentVersion' => $testArray['version']]);
        }
        return new JsonResponse(['status' => 'no', 'message' => 'No internet']);
    }

    /**
     * @Route("/init/checkClientVersion", name="init_check_client_version")
     */
    public function checkClientVersion()
    {
        $version = $this->getParameter('app.clientVersion');
        return new JsonResponse(['status' => 'ok', 'message' => 'Version ' . $version, 'version' => $version]);
    }

    /**
     * @Route("/init/checkDisplaySetup", name="init_check_display_setup")
     */
    public function checkDisplaySetup()
    {
        if ($this->deviceConfig->isSetup()) {
            return new JsonResponse(['status' => 'ok', 'message' => 'Display is setup']);
        }
        return new JsonResponse(['status' => 'no', 'message' => 'Display is not setup']);
    }

    /**
     * @Route("/init/setupWifi", name="init_setup_wifi")
     */
    public function setupWifi()
    {
        return $this->render('init/setupWifi.html.twig', [
            'controller_name' => 'InitController',
        ]);
    }

    /**
     * @Route("/init/scanWifi", name="init_scan_wifi")
     */
    public function scanWifi()
    {
        $response = ['status' => 'ok', 'networks' => ['foo', 'bar']];
        return New JsonResponse($response);
    }

}
