<?php

namespace App\Controller;

#use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use App\Service\DeviceConfig;
use ErrorException;
use Symfony\Component\HttpFoundation\Response;
#use Symfony\Component\HttpKernel\KernelInterface;
#use Symfony\Component\Console\Input\ArrayInput;
#use Symfony\Component\Console\Output\BufferedOutput;

class HomeController extends AbstractController
{
    private $deviceConfig;

    function __construct(DeviceConfig $deviceConfig)
    {
        $this->deviceConfig = $deviceConfig;
    }

    /**
     * @Route("/", name="app_home")
     */
    public function index()
    {
        if (!$this->deviceConfig->isSetup()) {
            return $this->redirectToRoute('app_setup');
        }
        return $this->render('home/index.html.twig', [
            'controller_name' => 'HomeController',
            'device_config' => $this->deviceConfig->getConfig(),
        ]);
    }

    /**
     * @Route("/launch", name="app_launch")
     */
    public function launch()
    {
        if (!$this->deviceConfig->isSetup()) {
            return $this->redirectToRoute('app_setup');
        }
        $opts = [
            'http' => [
                'header' => 'X-AUTH-TOKEN: ' . $this->deviceConfig->getApiToken(),
            ]
        ];
        $config = $this->deviceConfig->getConfig();
        if (empty($config['display_url'])) {
            $this->addFlash('error', 'Display URL is missing from the config file');
            return $this->redirectToRoute('app_home');
        }
        if (empty($config['display_key'])) {
            $this->addFlash('error', 'Display KEY is missing from the config file');
            return $this->redirectToRoute('app_home');
        }
        $resource_context = stream_context_create($opts);
        $contents = '';
        try {
            $contents = file_get_contents($config['display_url'], null, $resource_context);
        } catch (ErrorException $e) {
        }
        if (empty($contents)) {
            $this->addFlash('error', 'Unable to get Display URL from server.');
            return $this->redirectToRoute('app_home');
        }
        if ($config['allow_xset'] ?? false) {
            # Make chromium full screen
            # Don't need xdotool anymore already launching in full screen
            #exec('xdotool windowactivate --sync $(xdotool search --onlyvisible --class chromium-browser | tail -1) key F11');
            # Disable screen going black and screen turn off
            putenv('DISPLAY=:0');
            exec('xset s noblank');
            exec('xset s noexposure');
            exec('xset s off');
            exec('xset s 0 0');
            # Disable Energy Star which would also turn off screen
            exec('xset -dpms');
        }
        # Inject socket.io client
        $contents = str_replace('</body>', "<script>var _roomName = '" . $config['display_key'] . "';</script>\n" . '<script src="https://www.displaydojo.com:3000/socket.io/socket.io.js"></script><script src="https://www.displaydojo.com:3000/client.js"></script>', $contents);
        return new Response($contents);
    }

    /**
     * @Route("/netchk", name="app_netchk")
     */
    public function netchk()
    {
        $haveNet = false;
        try {
            $response = file_get_contents('https://www.displaydojo.com/client/v1/netchk');
            $response = json_decode($response, true);
            if (isset($response['netchk']) && $response['netchk'] == 'ok') $haveNet = true;
        } catch (ErrorException $e) {
            $haveNet = false;
        }
        return $this->json(['netchk' => ($haveNet ? 'ok' : 'no')]);
    }

    /**
     * @Route("/launchafternet", name="app_launchafternet")
     */
    public function launchafternet()
    {
        if (!$this->deviceConfig->isSetup()) {
            return $this->redirectToRoute('app_setup');
        }
        return $this->render('home/launchafternet.html.twig', [
            'controller_name' => 'HomeController',
            'device_config' => $this->deviceConfig->getConfig(),
        ]);
    }

    /**
     * @Route("/doscreenshot", name="app_doscreenshot")
     */
    public function doscreenshot()
    {
        $deviceConfig = new DeviceConfig();
        if (!$deviceConfig->isSetup()) {
          return $this->json(['status' => 'error', 'Device not setup.']);
        }
        //$application = new Application($kernel);
        //$application->setAutoExit(false);
        //$input = new ArrayInput([
        //  'command' => 'ddojo:screenshot',
        //]);
        //$output = new BufferedOutput();
        //$application->run($input, $output);
        //$content = $output->fetch();
        //dd($content);
        //return new Reponse($content);
        $displayId = (int)$deviceConfig->getDisplayId();

        $ssFile = '/tmp/' . time() . '-' . $displayId . '.jpg';
        $cmd = exec('/usr/bin/scrot ' . $ssFile);
        if (!file_exists($ssFile)) {
          return $this->json(['status' => 'error', sprintf("File '%s' was not found.", $ssFile)]);
        }

        $boundary = '----------------' . microtime(true);
        $headers = 'X-AUTH-TOKEN: ' . $deviceConfig->getApiToken() . "\r\n";
        $headers .= 'Content-Type: multipart/form-data; boundary=' . $boundary;
        $ssFileContents = file_get_contents($ssFile);
        #unlink($ssFile);
        $content  = '--' . $boundary . "\r\n" .
                    'Content-Disposition: form-data; name="uploadfile"; filename="ss.jpg"' . "\r\n" .
                    'Content-Type: image/jpeg' . "\r\n\r\n" .
                    $ssFileContents . "\r\n";
        $content .= '--' . $boundary . "\r\n" .
                    'Content-Disposition: form-data; name="displayId"' . "\r\n\r\n" .
                    $displayId . "\r\n";
        $content .= '--' . $boundary . "--\r\n";
        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => $headers,
                'content' => $content,
            ]
        ];
        $resource_context = stream_context_create($opts);
        # TODO think about moving this somewhere
        $uploadUrl = 'https://www.displaydojo.com/client/v1/screenshot';
        $contents = '';
        try {
            $contents = file_get_contents($uploadUrl, null, $resource_context);
        } catch (ErrorException $e) {
        }
        if (empty($contents)) {
          return $this->json(['status' => 'error', 'Empty server response']);
        }
        $contents = json_decode($contents, true);
        if (!isset($contents['status'])) {
          return $this->json(['status' => 'error', 'No status returned']);
        }
        if (!isset($contents['url'])) {
          return $this->json(['status' => 'error', 'No url returned']);
        }
        return $this->json(['status' => 'success', 'url' => $contents['url']]);
    }

    /**
     * @Route("/restartclient", name="app_restartclient")
     */
    public function restartclient()
    {
        if (!$this->deviceConfig->isSetup()) {
            return $this->redirectToRoute('app_setup');
        }
        $project_dir = $this->getParameter('kernel.project_dir');
        exec('/usr/bin/sudo /sbin/shutdown -r +1');
        return $this->render('home/restartclient.html.twig', [
            'controller_name' => 'HomeController',
            'device_config' => $this->deviceConfig->getConfig(),
        ]);
    }

    /**
     * @Route("/shutdownclient", name="app_shutdownclient")
     */
    public function shutdownclient()
    {
        if (!$this->deviceConfig->isSetup()) {
            return $this->redirectToRoute('app_setup');
        }
        $project_dir = $this->getParameter('kernel.project_dir');
        exec('/usr/bin/sudo /sbin/shutdown -h +1');
        return $this->render('home/shutdownclient.html.twig', [
            'controller_name' => 'HomeController',
            'device_config' => $this->deviceConfig->getConfig(),
        ]);
    }

}
