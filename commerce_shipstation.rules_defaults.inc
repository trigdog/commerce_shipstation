<?php
/**
 * @file
 *
 * Provides default rules for Commerce Shipstation.
 */

/**
 * Implements hook_default_rules_configuration().
 */
function commerce_shipstation_default_rules_configuration() {
  $items = array();

  $items['commerce_shipstation_order_fulfilment_success'] = entity_import('rules_config', '{ "commerce_shipstation_order_fulfilment_success" : {
      "LABEL" : "Order has been fulfilled",
      "PLUGIN" : "reaction rule",
      "OWNER" : "rules",
      "TAGS" : [ "Commerce ShipStation" ],
      "REQUIRES" : [ "rules", "commerce_order", "commerce_shipstation" ],
      "ON" : { "commerce_shipstation_order_success" : [] },
      "IF" : [
        { "data_is" : { "data" : [ "commerce-order:type" ], "value" : "commerce_order" } }
      ],
      "DO" : [
        { "data_set" : {
            "data" : [ "commerce-order:commerce-tracking-number" ],
            "value" : [ "tracking-number" ]
          }
        },
        { "data_set" : {
            "data" : [ "commerce-order:commerce-shipment-carrier" ],
            "value" : [ "carrier" ]
          }
        },
        { "data_set" : {
            "data" : [ "commerce-order:commerce-shipment-service" ],
            "value" : [ "service" ]
          }
        },
        { "commerce_order_update_status" : { "commerce_order" : [ "commerce_order" ], "order_status" : "completed" } }
      ]
    }
  }');

  return $items;
}
