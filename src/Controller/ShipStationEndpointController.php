<?php

namespace Drupal\commerce_shipstation\Controller;

use Drupal\commerce_shipstation\ShipStation;
use Drupal\Core\Controller\ControllerBase;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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
   * Establish a service endpoint for shipstation to communicate with
   *
   * @TODO: implement logging
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   */
  public function shipstationEndpointRequest(Request $request) {

    \Drupal::service('page_cache_kill_switch')->trigger();
    $logging = $this->ssConfig->get('commerce_shipstation_logging');

    // Log each request to the endpoint if logging is enabled.
    if ($this->ssConfig->get('commerce_shipstation_logging')) {
      $request_vars = $request->query->all();
      // Obfuscate the sensitive data before logging the request.
      $request_vars['SS-UserName'] = '******';
      $request_vars['SS-Password'] = '******';
      $request_vars['auth_key'] = '*****';
      $this->watchdog->log(LogLevel::INFO,'ShipStation request: @get', array('@get' => var_export($request_vars, TRUE)));
    }

    // Authenticate the request before proceeding.
    if ($this->shipstation->endpointAuthenticate($request->query->get('auth_key'))) {
      // If ShipStation is authenticated, run the call based on the action it defines.
      $response = new Response();
      switch ($request->query->get('action')) {
        case ShipStation::EXPORT_ACTION:
          $start_date = $request->query->get('start_date');
          $end_date = $request->query->get('end_date');
          $page = $request->query->get('page');
          $xml = $this->shipstation->exportOrders($start_date, $end_date, $page);

          $response->headers->set('Content-type', 'application/xml');
          $response->setContent($xml);
          return $response;
          break;

        case ShipStation::SHIPNOTIFY_ACTION:
          $order_number = $request->query->get('order_number');
          $tracking_number = $request->query->get('tracking_number');
          $carrier = $request->query->get('carrier');
          $ship_date = $request->query->get('ship_date');
          $msg = $this->shipstation->requestShipNotify($order_number, $tracking_number, $carrier, $ship_date);
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