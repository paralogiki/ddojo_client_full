<?php

namespace App\Controller;

use App\Service\DeviceConfig;
use App\Service\Autostart;
use ErrorException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class SetupController extends AbstractController
{
    private $deviceConfig;

    function __construct(DeviceConfig $deviceConfig)
    {
        $this->deviceConfig = $deviceConfig;
    }

    /**
     * @Route("/setup", name="app_setup")
     */
    public function index(Request $request)
    {
        if ($request->isMethod('POST')) {
            $was_setup = $this->deviceConfig->isSetup();
            if ($request->request->get('display_id') && $request->request->get('api_token')) {
                $display_id = $request->request->get('display_id');
                $api_token = $request->request->get('api_token');
                // TODO move this to some kind of Class
                $opts = [
                    'http' => [
                        'header' => 'X-AUTH-TOKEN: ' . $api_token,
                    ]
                ];
                $resource_context = stream_context_create($opts);
                try {
                    $display_config = file_get_contents('https://www.displaydojo.com/client/v1/config/' . $display_id, null, $resource_context);
                    $display_config = json_decode($display_config, true);
                    $valid_display = true;
                } catch (ErrorException $e) {
                    $valid_display = false;
                    $display_config = [];
                }
                if (isset($display_config['status']) && $display_config['status'] == 'error') {
                  $this->addFlash('error', $display_config['message'] ?? 'Server error.');
                  return $this->redirectToRoute('app_setup');
                }
                if ($valid_display) {
                    $this->deviceConfig->setConfig($display_config);
                    $this->deviceConfig->setDisplayId($display_id);
                    $this->deviceConfig->setApiToken($api_token);
                    $this->deviceConfig->setDisplayKey($display_config['display_key'] ?? null);
                    $success = $this->deviceConfig->isSetup();
                    if ($success) {
                        $write_config = $this->deviceConfig->updateConfig();
                        if (!$write_config) {
                            $this->addFlash('error', 'Failed to write to config file: ' . $this->deviceConfig->getConfigFile());
                            return $this->redirectToRoute('app_setup');
                        } else {
                            # TODO move this somewhere else
                            # TODO needs to be using a template to file in project_dir
                            $desktopFile = $this->getParameter('kernel.project_dir') . '/contrib/ddojo.pi.desktop';
                            $desktopSetupFile = $this->getParameter('kernel.project_dir') . '/contrib/ddojosetup.pi.desktop';
                            if (file_exists($desktopFile) && file_exists('/home/pi/Desktop')) {
                              copy($desktopFile, '/home/pi/Desktop/ddojo.desktop');
                            }
                            if (file_exists($desktopSetupFile) && file_exists('/home/pi/Desktop')) {
                              copy($desktopSetupFile, '/home/pi/Desktop/ddojosetup.desktop');
                            }
                            $this->addFlash('success', 'Your display device has been validated, you may continue setting up the client.');
                            if (!$was_setup) return $this->redirectToRoute('app_settings');
                        }
                    }
                } else {
                    $this->addFlash('error', 'Unable to verify your device, please try again.');
                }
            }
        }
        return $this->render('setup/index.html.twig', [
            'controller_name' => 'SetupController',
            'display_id' => $this->deviceConfig->getDisplayId(),
            'api_token' => $this->deviceConfig->getApiToken(),
            'is_setup' => $this->deviceConfig->isSetup(),
        ]);
    }

    /**
    * @Route("/settings", name="app_settings")
    */
    public function settings(Request $request)
    {
        if (!$this->deviceConfig->isSetup()) {
            $this->addFlash('error', 'Unable to change settings, please setup device first.');
            return $this->redirectToRoute('app_setup');
        }
        $config = $this->deviceConfig->getConfig();
        $allow_autoupdate = 1;
        $allow_xset = 1;
        $allow_autostart = 1;
        if (isset($config['allow_autoupdate'])) {
            $allow_autoupdate = $config['allow_autoupdate'];
        }
        if (isset($config['allow_xset'])) {
            $allow_xset = $config['allow_xset'];
        }
        if (isset($config['allow_autostart'])) {
            $allow_autostart = $config['allow_autostart'];
        }
        if ($request->isMethod('POST')) {
            $settings_changed = 0;
            $allow_autoupdate_new_settings = (int)($request->request->get('allow_autoupdate') == 'on' ?? 0);
            $allow_autoupdate_previous_settings = (int)($config['allow_autoupdate'] ?? 0);
            $this->deviceConfig->setConfig(['allow_autoupdate' => $allow_autoupdate_new_settings]);
            if (!isset($config['allow_autoupdate']) || $allow_autoupdate_previous_settings !== $allow_autoupdate_new_settings) {
              $settings_changed = 1;
              $crontabEtc = '/etc/cron.d/ddojo';
              if ($allow_autoupdate_new_settings) {
                if (!file_exists($crontabEtc)) {
                  $crontabFile = $this->getParameter('kernel.project_dir') . '/contrib/ddojo.crontab';
                  if (file_exists($crontabFile)) {
                    exec('sudo cp ' . $crontabFile . ' ' . $crontabEtc);
                  }
                }
                $this->addFlash('success', 'Auto-Update has been enabled');
              } else {
                if (file_exists($crontabEtc)) {
                  exec('sudo rm ' . $crontabEtc);
                }
                $this->addFlash('success', 'Auto-Update has been disabled');
              }
            }
            $crontabEtcCrashCheck = '/etc/cron.d/ddojocrashcheck';
            if (!file_exists($crontabEtcCrashCheck)) {
              $crontabFileCrashCheck = $this->getParameter('kernel.project_dir') . '/contrib/ddojocrashcheck.crontab';
              if (file_exists($crontabFileCrashCheck)) {
                exec('sudo cp ' . $crontabFileCrashCheck . ' ' . $crontabEtcCrashCheck);
              }
            }
            $allow_xset_new_settings = (int)($request->request->get('allow_xset') == 'on' ?? 0);
            $allow_xset_previous_settings = (int)($config['allow_xset'] ?? 0);
            $this->deviceConfig->setConfig(['allow_xset' => $allow_xset_new_settings]);
            if (!isset($config['allow_xset']) || $allow_xset_previous_settings !== $allow_xset_new_settings) $settings_changed = 1;
            $allow_autostart_new_settings = (int)($request->request->get('allow_autostart') == 'on' ?? 0);
            $allow_autostart_previous_settings = (int)($config['allow_autostart'] ?? 0);
            $this->deviceConfig->setConfig(['allow_autostart' => $allow_autostart_new_settings]);
            if (!isset($config['allow_autostart']) || $allow_autostart_previous_settings !== $allow_autostart_new_settings) {
              $settings_changed = 1;
              $autostart = new Autostart($this->deviceConfig, $this->getParameter('kernel.project_dir'));
              $autostartInit = $autostart->init();
              if ($autostartInit) {
                if ($allow_autostart_new_settings) {
                  $result = $autostart->setupAutostart();
                  if (!$result) {
                      $this->addFlash('error', 'Error during setup = ' . $autostart->getLastError());
                  } else {
                    $this->addFlash('success', 'Auto-Start has been enabled, please test this by restarting');
                  }
                } else {
                  $result = $autostart->disableAutostart();
                  if (!$result) {
                      $this->addFlash('error', 'Error during setup = ' . $autostart->getLastError());
                  } else {
                    $this->addFlash('success', 'Auto-Start has been disabled');
                  }
                }
              } else {
                  $this->addFlash('error', 'Error during setup = ' . $autostart->getLastError());
              }
            }
            if ($settings_changed) {
                $this->deviceConfig->updateConfig();
                $this->addFlash('success', 'Device settings have been updated.');
                return $this->redirectToRoute('app_home');
            } else {
                $this->addFlash('info', 'Device settings did not change.');
                return $this->redirectToRoute('app_home');
            }
        }
        return $this->render('setup/settings.html.twig', [
            'controller_name' => 'SetupController',
            'display_config' => $this->deviceConfig->getConfig(),
            'allow_autoupdate' => $allow_autoupdate,
            'allow_xset' => $allow_xset,
            'allow_autostart' => $allow_autostart,
        ]);
    }

}
