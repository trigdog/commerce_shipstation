<?php

namespace Drupal\commerce_shipstation;

/**
 * Wrapper class to ease XML CDATA using.
 */
class ShipstationSimpleXMLElement extends \SimpleXMLElement {

  /**
   * Add CDATA segment.
   *
   * @param $cdata_text
   */
  public function addCdata($cdata_text) {
    $node = dom_import_simplexml($this);
    $no = $node->ownerDocument;
    $node->appendChild($no->createCDATASection($cdata_text));
  }

}
