<?php

namespace Drupal\commerce_shipstation\Event;

final class ShipStationEvents {
  /**
   * Name of the event fired when an order has been exported to ShipStation.
   *
   * @Event
   *
   * @see \Drupal\commerce_shipstation\Event\ShipStationOrderExportedEvent
   *
   * @var string
   */
  const ORDER_EXPORTED = 'commerce_shipstation.order_exported';

}