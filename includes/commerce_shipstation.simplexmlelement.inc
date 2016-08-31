<?php
/**
 * @file
 * Extends SimpleXMLElement for Commerce ShipStation.
 */

/**
 * Wrapper class to ease XML CDATA using.
 */
class CommerceShipStationSimpleXMLElement extends SimpleXMLElement {
  /**
   * Add CDATA segment.
   *
   * @param $cdata_text
   */
  public function addCData($cdata_text) {
    $node = dom_import_simplexml($this);
    $no = $node->ownerDocument;
    $node->appendChild($no->createCDATASection($cdata_text));
  }
}
