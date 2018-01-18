<?php

namespace Drupal\commerce_shipstation\Event;

use Symfony\Component\EventDispatcher\Event;

class ShipStationOrderExportedEvent extends Event {
  /**
   * Commerce Order Entity.
   *
   * @var \Drupal\commerce_order\Entity\OrderInterface $order
   */
  protected $order;

  /**
   * Constructs an ordered exported event object.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   */
  public function __construct($order) {
    $this->order = $order;
  }

  /**
   * Get the exported order.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   */
  public function getOrder() {
    return $this->order;
  }

}