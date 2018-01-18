<?php

namespace Drupal\commerce_shipstation\Controller;

use Drupal\commerce_shipstation\ShipStation;
use Drupal\Core\Controller\ControllerBase;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ShipStationEndpointController extends ControllerBase {
  /**
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $watchdog;

  /**
   * Shipstation Configuration
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $ssConfig;

  /**
   * Shipstation Service
   *
   * @var \Drupal\commerce_shipstation\ShipStation
   */
  protected $shipstation;

  /**
   * Constructs a new controller.
   *
   * @param \Drupal\commerce_shipstation\ShipStation $shipstationService
   *   Constructs a new Shipstation Service.
   */
  public function __construct(ShipStation $shipstationService) {
    $this->watchdog = $this->getLogger('commerce_shipstation');
    $this->ssConfig = $this->config('commerce_shipstation.shipstation_config');
    $this->shipstation = $shipstationService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('commerce_shipstation.shipstation_service')
    );
  }

  /**
   * establish a service endpoint for shipstation to communicate with
   *
   * @TODO: implement logging
   */
  public function shipstationEndpointRequest() {

    \Drupal::service('page_cache_kill_switch')->trigger();
    $logging = $this->ssConfig->get('commerce_shipstation_logging');

    // Log each request to the endpoint if logging is enabled.
    if ($this->ssConfig->get('commerce_shipstation_logging')) {
      $request_vars = $_GET;
      // Obfuscate the sensitive data before logging the request.
      $request_vars['SS-UserName'] = '******';
      $request_vars['SS-Password'] = '******';
      $request_vars['auth_key'] = '*****';
      $this->watchdog->log(LogLevel::INFO,'ShipStation request: !get', array('!get' => var_export($request_vars, TRUE)));
    }

    // Authenticate the request before proceeding.
    if ($this->shipstation->endpointAuthenticate()) {
      // If ShipStation is authenticated, run the call based on the action it defines.
      $response = new Response();
      switch ($_GET['action']) {
        case 'export':
          $xml = $this->shipstation->exportOrders();
          $response->headers->set('Content-type', 'application/xml');
          $response->setContent($xml);
          return $response;
          break;

        case 'shipnotify':
          $msg = $this->shipstation->requestShipNotify();
          $response->setContent($msg);
          return $response;
          break;

        default:
          drupal_set_message(t('The ShipStation request action is invalid'), 'error');
          $this->watchdog->log(LogLevel::ERROR, 'Invalid request action received from ShipStation. Enable or check request logging for more information');
          throw new AccessDeniedHttpException();
      }
    }
  }

}