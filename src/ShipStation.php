<?php

namespace Drupal\commerce_shipstation;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\image\Entity\ImageStyle;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Manage Shipstation API services.
 *
 * @package Drupal\commerce_shipstation
 */
class ShipStation {
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
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new Shipstation Service.
   *
   * @param \Psr\Log\LoggerInterface $watchdog
   *   Commerce Shipstation Logger Channel.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entityTypeManager, LoggerInterface $watchdog) {
    $this->watchdog = $watchdog;
    $this->ssConfig = $config_factory->get('commerce_shipstation.shipstation_config');
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Authorizes a ShipStation request.
   *
   * @return bool
   *   TRUE if authentication methods succeed, FALSE if they fail.
   */
  public function endpointAuthenticate() {
    $authorized = FALSE;
    $auth_key = $this->ssConfig->get('commerce_shipstation_alternate_auth');
    $username = $this->ssConfig->get('commerce_shipstation_username');
    $password = $this->ssConfig->get('commerce_shipstation_password');

    // Allow ShipStation to authenticate using an auth token.
    if (!empty($auth_key) && !empty($_GET['auth_key']) && $auth_key == $_GET['auth_key']) {
      return TRUE;
    }

    // Allow ShipStation to authenticate using basic auth.
    if (!empty($username) && !empty($password) && !empty($_SERVER['PHP_AUTH_USER']) && !empty($_SERVER['PHP_AUTH_PW'])) {
      if ($_SERVER['PHP_AUTH_USER'] == $username && ($_SERVER['PHP_AUTH_PW'] == $password || md5($_SERVER['PHP_AUTH_PW']) == $password)) {
        return TRUE;
      }
    }

    // If all authentication methods fail, return a 401.
    drupal_set_message(t('Error: Authentication failed. Please check your credentials and try again.'), 'error');
    $this->watchdog->log(LogLevel::ERROR, 'Error: Authentication failed when accepting request. Enable or check ShipStation request logging for more information.');
    throw new AccessDeniedHttpException("WWW-Authenticate: Basic realm =\"ShipStation XML API for Drupal Commerce");
  }

  /**
   * Identify orders to send back to shipstation.
   */
  public function exportOrders() {
    $timezone = new \DateTimeZone('UTC');
    $start_date = new \DateTime($_GET['start_date'], $timezone);
    $end_date = new \DateTime($_GET['end_date'], $timezone);
    $page = !empty($_GET['page']) ? intval($_GET['page']) : 0;
    $status = $this->ssConfig->get('commerce_shipstation_export_status');
    $page_size = $this->ssConfig->get('commerce_shipstation_export_paging');
    $start_page = $page > 0 ? $page - 1 : 0;
    //TODO: $shipping_services = commerce_shipping_services();
    $available_methods = $this->ssConfig->get('commerce_shipstation_exposed_shipping_methods');

    // Determine site-specific field reference fields.
    $field_billing_phone_number = $this->ssConfig->get('commerce_shipstation_billing_phone_number_field');
    $field_shipping_phone_number = $this->ssConfig->get('commerce_shipstation_shipping_phone_number_field');
    $field_order_notes = $this->ssConfig->get('commerce_shipstation_order_notes_field');
    $field_customer_notes = $this->ssConfig->get('commerce_shipstation_customer_notes_field');
    $field_product_images = $this->ssConfig->get('commerce_shipstation_product_images_field');
    $bundle_type = $this->ssConfig->get('commerce_shipstation_bundle_field');

    // Build a query to load orders matching our status.
    $query = \Drupal::entityQuery('commerce_order');
    $query->condition('state', array_keys($status), 'IN');

    // Limit our query by start date and end date unless we're
    // doing a full reload.
    if (!$this->ssConfig->get('commerce_shipstation_reload')) {
      $query->condition('changed', array($start_date->getTimestamp(), $end_date->getTimestamp()), 'BETWEEN');
    }

    // Add the range and re-run the query to get our records.
    $query->range($start_page * $page_size, $page_size);
    $results = $query->execute();

    // Execute the query without the range to get a count.
    $count_result = $query->count()->execute();

    // Instantiate a new XML object for our export.
    $output = new ShipstationSimpleXMLElement('<Orders></Orders>');

    /*TODO: Log the request information.
    if ($this->ssConfig->get('commerce_shipstation_logging')) {
      $message = 'Action:' . check_plain($_GET['action']);
      $message .= ' Orders: ' . (isset($results['commerce_order']) ? count($results['commerce_order']) : 0);
      $message .= ' Since: ' . format_date($start_date->getTimestamp(), 'short') . '(' . $start_date->getTimestamp() . ')';
      $message .= ' To: ' . format_date($end_date->getTimestamp(), 'short') . '(' . $end_date->getTimestamp() . ')';

      $this->watchdog->log('!message', array('!message' => $message));
    }*/

    if (isset($results)) {
      $orders = $this->entityTypeManager->getStorage('commerce_order')->loadMultiple(array_keys($results));

      // Allow other modules to alter the list of orders.
      $context = [
          'start_date' => $start_date->getTimestamp(),
          'end_date' => $end_date->getTimestamp(),
          'page' => $page,
          'page_size' => $page_size,
      ];
      \Drupal::moduleHandler()->alter('commerce_shipstation_export_orders', $orders, $context);

      $output['pages'] = ceil(count($count_result) / $page_size);

      /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
      foreach ($orders as $order) {
        try {
          /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $bill */
          $bill = $order->getBillingProfile()->get('address')->first();
        }
        catch (\Exception $ex) {
          $bill = FALSE;
        }

        try {
          if ($order->hasField('shipments') || !$order->get('shipments')->isEmpty()) {
            $shipments = $order->get('shipments')->getValue();
            /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
            $shipment = $this->entityTypeManager->getStorage('commerce_shipment')->load($shipments[0]['target_id']);
            /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $ship */
            $ship = $shipment->getShippingProfile()->get('address')->first();
          }
        }
        catch (\Exception $ex) {
          $ship = FALSE;
        }

        if (!$ship) {
          continue;
        }

        if ($this->ssConfig->get('commerce_shipstation_logging')) {
          $this->watchdog->log(LogLevel::INFO, '!message', array('!message' => 'Processing order ' . $order->id()));
        }

        // Load the shipping line items.
        $shipping_items = $shipment->getItems();
        // Determine the shipping service and shipping method for the order.
        if (!empty($shipping_items)) {
          try {
            $shipping_service = $shipment->getShippingService();
            /** @var \Drupal\commerce_shipping\Entity\ShippingMethodInterface $shipping_method */
            $shipping_method = $shipment->getShippingMethod();
          }
          catch (\Exception $ex) {
            $shipping_method = FALSE;
          }
        }
        else {
          // Do not proceed if a shipping line item does not exist.
          continue;
        }

        // Only process orders which have authorized shipping methods.
        if (!empty($shipping_method) && in_array($shipping_method->getName(), $available_methods)) {

          // Set up the xml schema.
          $order_xml = $output->addChild('Order');
          $order_date = $order->placed->value;

          /* TODO: is this needed still?
          // If there are payment transactions beyond the order creation date, use those.
          foreach (commerce_payment_transaction_load_multiple(array(), array('order_id' => $order->order_id)) as $transaction) {
            if ($transaction->status == COMMERCE_PAYMENT_STATUS_SUCCESS && $transaction->created > $order_date) {
              $order_date = $transaction->created;
            }
          }
          */

          $order_fields = [
              '#cdata' => [
                  'OrderNumber' => $order->getOrderNumber(),
                  'OrderStatus' => $order->getState()->getName(),
                  'ShippingMethod' => '',//!empty($shipping_line_item->data['shipping_service']['display_title']) ? $shipping_line_item->data['shipping_service']['display_title'] : t('Shipping'),
              ],
              '#other' => [
                  'OrderDate' => date(COMMERCE_SHIPSTATION_DATE_FORMAT, $order_date),
                  'LastModified' => date(COMMERCE_SHIPSTATION_DATE_FORMAT, $order->changed->value),
                  'OrderTotal' => $order->getTotalPrice()->getNumber(),
                  'ShippingAmount' => $shipment->getAmount()->getNumber(),
              ],
          ];

          if (strtolower($field_order_notes) != 'none') {
            try {
              $field_array = explode('.', $field_order_notes);
              $field_name = end($field_array);
              $order_fields['#cdata']['InternalNotes'] = $order->$field_name->value;
            }
            catch (\Exception $ex) {
              // No action needed if there are no order notes.
            }
          }

          if (strtolower($field_customer_notes) != 'none') {
            try {
              $field_array = explode('.', $field_customer_notes);
              $field_name = end($field_array);
              $order_fields['#cdata']['CustomerNotes'] = $shipment->getShippingProfile()->$field_name->value;
            }
            catch (\Exception $ex) {
              // No action needed if there are no customer notes.
            }
          }

          // Billing address.
          $customer = $order_xml->addChild('Customer');

          $customer_fields = [
              '#cdata' => [
                  'CustomerCode' => $order->getEmail(),
              ],
          ];
          $this->addCdata($customer, $customer_fields);

          // Billing info.
          $billing = $customer->addChild('BillTo');
          $billing_fields = [
              '#cdata' => [
                  'Name' => $bill ? $bill->getGivenName() . ' ' . $bill->getFamilyName() : '',
                  'Company' => $bill ? $bill->getOrganization() : '',
                  'Email' => $order->getEmail(),
              ],
          ];

          if (strtolower($field_billing_phone_number) != 'none') {
            try {
              $field_array = explode('.', $field_billing_phone_number);
              $field_name = end($field_array);
              $billing_fields['#cdata']['Phone'] = $order->getBillingProfile()->$field_name->value;
            }
            catch (\Exception $ex) {
              // No action needed if phone can't be added.
            }
          }
          $this->addCdata($billing, $billing_fields);

          // Shipping info.
          $shipping = $customer->addChild('ShipTo');
          $shipping_fields = [
              '#cdata' => [
                  'Name' => $ship->getGivenName() . ' ' . $ship->getFamilyName(),
                  'Company' => $ship->getOrganization(),
                  'Address1' => $ship->getAddressLine1(),
                  'Address2' => $ship->getAddressLine2(),
                  'City' => $ship->getLocality(),
                  'State' => $ship->getAdministrativeArea(),
                  'PostalCode' => $ship->getPostalCode(),
                  'Country' => $ship->getCountryCode(),
              ],
          ];
          if (strtolower($field_shipping_phone_number) != 'none') {
            try {
              $field_array = explode('.', $field_shipping_phone_number);
              $field_name = end($field_array);
              $shipping_fields['#cdata']['Phone'] = $shipment->getShippingProfile()->$field_name->value;
            }
            catch (\Exception $ex) {
              // No action necessary if phone can't be added.
            }
          }
          $this->addCdata($shipping, $shipping_fields);

          $line_items_xml = $order_xml->addChild('Items');
          $order_items = $order->getItems();

          // TODO: Add when commerce_giftwrap is ported
          /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
          /*foreach ($order_items as $id => $order_item) {

            // Legacy support for gift wrapping.
            if ($line_item_wrapper->type->value() == 'giftwrap') {
              $this->addCdata(
                $order_xml, [
                  '#other' => [
                    'Gift' => 'true',
                    'GiftMessage' => $line_item_wrapper->commerce_giftwrap_message->value(),
                  ],
                ]
              );

              // Gift Wrapping package.
              $gift_wrapping_package = $this->ssConfig->get('commerce_shipstation_giftwrapping_package');
              if (!empty($gift_wrapping_package)) {
                $this->addCdata(
                  $order_xml, [
                    '#other' => [
                      'packageCode' => $gift_wrapping_package,
                    ],
                  ]
                );
              }

              continue;
            }
          }
          */

          /** @var \Drupal\commerce_shipping\ShipmentItem $shipping_item */
          foreach ($shipping_items as $id => $shipping_item) {
            $weight_field = $shipping_item->getWeight();

            /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
            foreach($order_items as $order_item) {
              if ($order_item->id() === $shipping_item->getOrderItemId()) {
                /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $product_variation */
                $product_variation = $order_item->getPurchasedEntity();
                $product = $product_variation->getProduct();
                break;
              }
            }

            $line_item_xml = $line_items_xml->addChild('Item');
            $line_item_cdata = [
                'SKU' => $product_variation->getSku(),
                'Name' => $shipping_item->getTitle(),
            ];

            if (strtolower($field_product_images) != 'none') {
              try {
                $product_image = explode('.', $field_product_images);
                if (reset($product_image) === 'commerce_product_variation') {
                  $image = $product_variation->get(end($product_image))->entity;
                }
                else {
                  $image = $product->get(end($product_image));
                }

              }
              catch (\Exception $ex) {
                $image = FALSE;
              }

              if (!empty($image)) {
                // Use the delta 0 image if it's multi-valued.
                if (is_array($image)) {
                  $image = reset($image);
                }
                $line_item_cdata['ImageUrl'] = ImageStyle::load('thumbnail')->buildUrl(Url::fromUri($image->getFileUri(), ['absolute'=>TRUE])->toUriString());
              }
            }


            // TODO: Not sure if this is needed anymore
            // $unit_price = _commerce_shipstation_price_excluding_components($line_item_wrapper->commerce_unit_price->value(), array('discount', 'tax'));
            $line_item_fields = [
                '#cdata' => $line_item_cdata,
                '#other' => [
                    'Quantity' => (int) $shipping_item->getQuantity(),
                    'UnitPrice' => $product_variation->getPrice()->getNumber(),
                ],
            ];

            // Add the line item weight.
            if (!empty($weight_field) && !empty($weight_field->getNumber())) {
              try {
                $this->addWeight($line_item_fields['#other'], $weight_field->getNumber(), $weight_field->getUnit());
              }
              catch (\Exception $ex) {
                // The current item doesn't have a weight or we can't access it.
                if ($this->ssConfig->get('commerce_shipstation_logging')) {
                  $this->watchdog->log(LogLevel::WARNING, 'Unable to add weight for product id :product_id to shipstation export', array(':product_id' => $product->id()));
                }
              }
            }

            $this->addCdata($line_item_xml, $line_item_fields);

            // TODO: Finish this for bundles
            /*
            // Line item options.
            // If the product contains an entity reference field (e.g., for a product bundle).
            if (isset($line_item_wrapper->$bundle_type)) {
              foreach ($line_item_wrapper->$bundle_type as $bundle_item) {
                if ($bundle_item->type() == 'commerce_product') {
                  $line_item_xml = $line_items_xml->addChild('Item');

                  $unit_price = _commerce_shipstation_price_excluding_components($line_item_wrapper->commerce_unit_price->value(), array('discount', 'tax'));
                  $line_item_fields = array(
                      '#cdata' => array(
                          'SKU' => $bundle_item->sku->value(),
                          'Name' => $bundle_item->title->value(),
                      ),
                      '#other' => array(
                          'LineItemID' => $line_item_wrapper->line_item_id->value(),
                          'Quantity' => (int) $line_item_wrapper->quantity->value(),
                          'UnitPrice' => commerce_currency_amount_to_decimal($unit_price['amount'], $unit_price['currency_code']),
                      ),
                  );

                  // Add the bundle weight.
                  $bundle_weight_field = commerce_physical_entity_weight_field_name('commerce_product', $bundle_item->value());
                  if (!empty($bundle_weight_field) && !empty($bundle_item->{$bundle_weight_field})) {
                    commerce_shipstation_addweight($line_item_fields['#other'], $bundle_item->{$bundle_weight_field}->weight->value(), $bundle_item->{$bundle_weight_field}->unit->value());
                  }
                  commerce_shipstation_addcdata($line_item_xml, $line_item_fields);
                }
              }
            }


            // Alter line item XML.
            \Drupal::moduleHandler()->alter('commerce_shipstation_line_item_xml', $line_item_xml, $line_item);
            */
          }

          // Parse price component data for taxes and discounts.
          $commerce_adjustments = $order->collectAdjustments();
          /** @var \Drupal\commerce_order\Adjustment $adjustment */
          foreach ($commerce_adjustments as $adjustment) {

            // Skip shipping costs
            if ($adjustment->getType() === 'shipping') {
              continue;
            }



            // TODO: Finish up the tax adjustment
            /*
            // Append tax data to the response.
            if (isset($component['price']['data']['tax_rate'])) {
              if (!isset($order_fields['#cdata']['TaxAmount'])) {
                $order_fields['#cdata']['TaxAmount'] = 0;
              }
              $order_fields['#cdata']['TaxAmount'] += round(commerce_currency_amount_to_decimal($component['price']['amount'], $component['price']['currency_code']), 2);
            }
            */

            // Create line items for promotions/discounts.
            if ($adjustment->getType() === 'promotion') {
              $line_item_xml = $line_items_xml->addChild('Item');

              $line_item_cdata = [
                'SKU' => NULL,
                'Name' => $adjustment->getLabel(),
              ];

              $line_item_fields = [
                '#cdata' => $line_item_cdata,
                '#other' => [
                  'Quantity' => 1,
                  'UnitPrice' => $adjustment->getAmount()->getNumber(),
                  'Adjustment' => TRUE,
                ],
              ];

              $this->addCdata($line_item_xml, $line_item_fields);
            }

          }
          $test = '';
          $this->addCdata($order_xml, $order_fields);

          // Alter order XML.
          //TODO: drupal_alter('commerce_shipstation_order_xml', $order_xml, $order);

          // Notify rules that the order has been exported.
          //TODO: is this needed?  rules_invoke_event('commerce_shipstation_order_exported', $order);
        }
      }
    }

    // Return the XML data for ShipStation.
    $dom = dom_import_simplexml($output)->ownerDocument;
    $dom->formatOutput = TRUE;
    return $dom->saveXML();
  }

  /**
   * Callback for ShipStation shipnotify requests.
   */
  public function requestShipNotify() {
    $order_number = $_GET['order_number'];
    $tracking_number = $_GET['tracking_number'];
    $carrier = $_GET['carrier'];
    $service = $_GET['service'];
    $ship_date = $_GET['label_create_date'];

    // Order number and carrier are required fields for ShipStation and should
    // always be provided in a shipnotify call.
    if ($order_number && $carrier) {
      // Build a query to load orders matching our order number.
      $query = \Drupal::entityQuery('commerce_order');
      $query->condition('order_number', $order_number);
      $order_id = $query->execute();

      /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
      $order = $this->entityTypeManager->getStorage('commerce_order')->load($order_id);
      if (!empty($order) && $order->hasField('shipments') && !$order->get('shipments')->isEmpty()) {
        $shipments = $order->get('shipments')->getValue();
        /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
        $shipment = $this->entityTypeManager->getStorage('commerce_shipment')->load($shipments[0]['target_id']);
        $shipment->setTrackingCode($tracking_number);
        $shipment->setShippedTime($ship_date);
        //rules_invoke_event('commerce_shipstation_order_success', $commerce_order, $tracking_number, $carrier, $service);
      }
      else {
        watchdog('commerce_shipstation', 'Unable to load order @order_number for updating via the ShipStation shipnotify call.', array('@order_number' => $order_number), WATCHDOG_ERROR);
      }
    }
    else {
      print t('Error: missing order info.');
    }
  }

  /**
   * Helper function to add CDATA segments to XML file.
   *
   * @param $xml
   *
   * @param $data
   *
   */
  protected function addCdata($xml, $data) {
    if (isset($data['#cdata'])) {
      foreach ($data['#cdata'] as $field_name => $value) {
        $xml->{$field_name} = NULL;
        $xml->{$field_name}->addCdata($value);
      }
    }
    if (isset($data['#other'])) {
      foreach ($data['#other'] as $field_name => $value) {
        $xml->{$field_name} = $value;
      }
    }
  }

  /**
   * Process API request.
   *
   * @param $uri
   *
   *
   * @return mixed
   *   return the json decoded data

  protected function apiRequest($uri) {
    $uri = 'https://'
        . $this->ssConfig->get('commerce_shipstation_username')
        . ':' . $this->ssConfig->get('commerce_shipstation_password')
        . '@ssapi.shipstation.com' . $uri;
    $response = drupal_http_request($uri);

    return json_decode($response->data);
  }*/

  /**
   * Get list of shipment carriers available in ShipStation.
   *
   * @see http://docs.shipstation.apiary.io/#reference/carriers/list-carriers/list-carriers

  protected function getCarriers() {
    return $this->apiRequest('/carriers');
  }*/

  /**
   * Get list of shipment packages for a given carrier..
   *
   * @param $carrier
   *
   * @see http://docs.shipstation.apiary.io/#reference/carriers/list-packages/list-packages

  protected function getPackages($carrier) {
    return $this->apiRequest('/carriers/listpackages?carrierCode=' . urlencode($carrier));
  }*/


  /**
   * Helper function to format product weight.
   *
   * @param array $data
   *
   * @param string $weight
   *   The weight of the item
   *
   * @param string $weight_units
   *   The unit code for the weight
   *
   */
  protected function addWeight(&$data, $weight, $weight_units) {
    switch ($weight_units) {
      case 'g':
        $weight_units = 'Gram';
        break;

      case 'lb':
        $weight_units = 'Pounds';
        break;

      case 'oz':
        $weight_units = 'Ounces';
        break;

      case 'kg':
        $weight_units = 'Gram';
        $weight = 1000 * $weight;
        break;
    }
    $data['Weight'] = $weight;
    $data['WeightUnits'] = $weight_units;
  }

}