<?php

/**
 * @file
 * Hooks provided by the Commerce ShipStation module.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alter fetched order to export.
 *
 * You may want to add fields, load additional data or even remove some of them.
 *
 * @param array $orders
 *   An array of loaded orders.
 * @param array $context
 *   An array of context values to base the alter routines on.
 *   start_date integer Start date timestamp.
 *   end_date integer End date timestamp.
 *   page integer Current page requested by ShipStation.
 *   page_size integer Amount of items for a given page.
 */
function hook_commerce_shipstation_export_orders_alter(&$orders, $context) {
  foreach ($orders as $order) {
    // Log order processing to 3-part app.
  }
}

/**
 * Alter order export XML.
 *
 * 3-part modules may add features we don't implement in the module out-of-box.
 *
 * @param SimpleXMLExtended $order_xml
 *   Order XML representation.
 * @param object $order
 *   Commerce Order entity object.
 */
function hook_commerce_shipstation_order_xml_alter(&$order_xml, $order) {
  if ($order->changed < strtotime('when I was young')) {
    $order_wrapper = entity_metadata_wrapper('commerce_order', $order);

    commerce_shipstation_addcdata(
      $order_xml,
      array(
        '#cdata' => array(
          'TheFieldWeDontImplementYet' => $order_wrapper->field_your_custom_field->value(),
        ),
      )
    );
  }
}

/**
 * Alter line item export XML.
 *
 * 3-part modules may add features we don't implement in the module out-of-box.
 *
 * @param SimpleXMLExtended $line_item_xml
 *   Line item XML representation.
 * @param object $line_item
 *   Commerce Order entity object.
 */
function hook_commerce_shipstation_line_item_xml_alter(&$line_item_xml, $line_item) {
  if ($line_item->qty > 100) {
    $line_item_wrapper = entity_metadata_wrapper('commerce_line_item', $line_item);

    commerce_shipstation_addcdata(
      $line_item_xml,
      array(
        '#cdata' => array(
          'TheFieldWeDontImplementYet' => $line_item_wrapper->field_your_custom_field->value(),
        ),
      )
    );
  }
}

/**
 * @} End of "addtogroup hooks".
 */
