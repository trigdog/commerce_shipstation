<?php

namespace Drupal\commerce_shipstation\Form;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * ShipStation Admin Form
 *
 * @package Drupal\commerce_shipstation\Form
 */
class ShipStationAdminForm extends ConfigFormBase
{
  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames()
  {
    return [
        'commerce_shipstation.shipstation_config',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'shipstation_admin_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    // get shipstation configuration
    $ss_config = $this->config('commerce_shipstation.shipstation_config');

    // Get list of order states per defined workflow
    $workflow_manager = \Drupal::service('plugin.manager.workflow');
    $workflows = $workflow_manager->getGroupedLabels('commerce_order');
    $states = [];
    foreach ($workflows['Order'] as $workflow_id => $workflow_value) {
      $workflow = $workflow_manager->getDefinition($workflow_id);
      foreach ($workflow['states'] as $state_id => $state_value) {
        if (!isset($states[$state_id])) {
          $states[$state_id] = $state_value['label'];
        }
      }
    }

    // TODO: Do we need to Break it down into Services?
    // TODO: Warning: Invalid argument supplied for foreach()
    // Get Shipping Methods
    $shipMethods = \Drupal::service('plugin.manager.commerce_shipping_method')->getDefinitions();
    if (empty($shipMethods)) {
      $form['commerce_shipstation_error_message'] = [
          '#markup' => $this->t('You\'ll need at least one shipping method module turned on. e.g., Commerce Flatrate shipping'),
      ];

      return $form;
    }
    else {
      foreach ($shipMethods as $key => $value) {
        $options[$key] = (string) $value['label'];
      }
    }

    // shipstation username
    $form['commerce_shipstation_username'] = [
        '#type' => 'textfield',
        '#title' => $this->t('ShipStation Username'),
        '#required' => TRUE,
        '#default_value' => $ss_config->get('commerce_shipstation_username'),
        '#description' => $this->t('Create a username for request authentication. This is NOT your ShipStation account username.'),
    ];

    // shipstation password
    $form['commerce_shipstation_password'] = [
        '#type' => 'password',
        '#title' => $this->t('ShipStation Password'),
        '#required' => TRUE,
        '#default_value' => $ss_config->get('commerce_shipstation_password'),
        '#attributes' => ['autocomplete' => 'off'],
        '#description' => $this->t('Create a password for request authentication. This is NOT your ShipStation account password.'),
    ];

    // shipstation logging
    $form['commerce_shipstation_logging'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Log requests to ShipStation'),
        '#description' => $this->t('If this is set, all API requests to ShipStation will be logged to Drupal watchdog.'),
        '#default_value' => $ss_config->get('commerce_shipstation_logging'),
    ];

    // ShipStation reload.
    $form['commerce_shipstation_reload'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Reload all orders to ShipStation'),
        '#description' => $this->t('If this is set, on API endpoint request all orders will be returned.'),
        '#default_value' => $ss_config->get('commerce_shipstation_reload'),
    ];

    // ShipStation alternate authentication.
    $form['commerce_shipstation_alternate_auth'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Alternate Authentication'),
        '#description' => $this->t('Use this field if your web server uses CGI to run PHP.'),
        '#default_value' => $ss_config->get('commerce_shipstation_alternate_auth'),
    ];

    // ShipStation export paging.
    $form['commerce_shipstation_export_paging'] = [
        '#type' => 'select',
        '#title' => $this->t('Number of Records to Export per Page'),
        '#description' => t('Sets the number of orders to send to ShipStation at a time. Change this setting if you experience import timeouts.'),
        '#options' => array(20 => 20, 50 => 50, 75 => 75, 100 => 100, 150 => 150),
        '#default_value' => $ss_config->get('commerce_shipstation_export_paging'),
    ];

    // Select phone number field.
    $form['commerce_shipstation_billing_phone_number_field'] = [
        '#type' => 'select',
        '#title' => $this->t('Field for billing phone number'),
        '#required' => FALSE,
        '#description' => $this->t('Select the field you are using for phone numbers in order data here.'),
        '#options' => $this->loadFieldOptions('profile', 'customer'),
        '#default_value' => $ss_config->get('commerce_shipstation_billing_phone_number_field'),
    ];

    // Select phone number field.
    $form['commerce_shipstation_shipping_phone_number_field'] = [
        '#type' => 'select',
        '#title' => $this->t('Field for shipping phone number'),
        '#required' => FALSE,
        '#description' => $this->t('Select the field you are using for phone numbers in order data here.'),
        '#options' => $this->loadFieldOptions('profile', 'customer'),
        '#default_value' => $ss_config->get('commerce_shipstation_shipping_phone_number_field'),
    ];

    // Product bundle field to import.
    $form['commerce_shipstation_bundle_field'] = [
        '#type' => 'select',
        '#title' => $this->t('Field used for bundled products'),
        '#required' => FALSE,
        '#description' => $this->t('Set this if you are using an Entity Reference field on line items to create a product bundle. This will ensure that your bundled products are imported by ShipStation.'),
        '#options' => $this->loadFieldOptions('commerce_order_item'),
        '#default_value' => $ss_config->get('commerce_shipstation_bundle_field'),
    ];

    // Order notes to import.
    $form['commerce_shipstation_order_notes_field'] = [
        '#type' => 'select',
        '#title' => $this->t('Field used for admin-facing order notes'),
        '#required' => FALSE,
        '#description' => $this->t('Choose a field you use for admin order notes (attached to Commerce Order entity).'),
        '#options' => $this->loadFieldOptions('commerce_order'),
        '#default_value' => $ss_config->get('commerce_shipstation_order_notes_field'),
    ];

    // Customer notes to import.
    $form['commerce_shipstation_customer_notes_field'] = [
        '#type' => 'select',
        '#title' => $this->t('Field used for customer-facing order notes'),
        '#required' => FALSE,
        '#description' => $this->t('Choose a field you use for customer shipping notes (attached to Commerce Order - Shipping Information entity).'),
        '#options' => $this->loadFieldOptions('profile', 'customer'),
        '#default_value' => $ss_config->get('commerce_shipstation_customer_notes_field'),
    ];

    // Product images to import.
    $product_fields = $this->loadFieldOptions('commerce_product');
    unset($product_fields['commerce_product.variations']);
    $variation_fields = $this->loadFieldOptions('commerce_product_variation');
    $form['commerce_shipstation_product_images_field'] = [
        '#type' => 'select',
        '#title' => $this->t('Field used for product images'),
        '#required' => FALSE,
        '#description' => $this->t('Choose a field you use for product images.'),
        '#options' => array_merge($product_fields, $variation_fields),
        '#default_value' => $ss_config->get('commerce_shipstation_product_images_field'),
    ];

    /* TODO: Implement once commerce_giftwrap is ported
    // Gift Wrapping package.
    if (module_exists('commerce_giftwrap')) {
      $carriers = _commerce_shipstation_get_carriers();
      if (!empty($carriers)) {
        $packages_options = array('' => t('None'));
        foreach ($carriers as $carrier) {
          $packages = _commerce_shipstation_get_packages($carrier->code);
          foreach ($packages as $package) {
            $packages_options[$package->code] = $carrier->name . ' - ' . $package->name;
          }
        }

        asort($packages_options);

        $form['commerce_shipstation_giftwrapping_package'] = [
            '#type' => 'select',
            '#title' => t('Gift Wrapping Package'),
            '#options' => $packages_options,
            '#default_value' => variable_get('commerce_shipstation_giftwrapping_package', ''),
        ];
      }
    }
    */

    // ShipStation order export status.
    $form['commerce_shipstation_export_status'] = [
        '#type' => 'select',
        '#title' => t('Order Status to Export into ShipStation'),
        '#required' => TRUE,
        '#options' => $states,
        '#default_value' => $ss_config->get('commerce_shipstation_export_status'),
        '#multiple' => TRUE,
    ];

    // ShipStation available shipping methods.
    $form['commerce_shipstation_exposed_shipping_methods'] = [
        '#type' => 'checkboxes',
        '#title' => t('Shipping Methods Available to ShipStation'),
        '#required' => TRUE,
      // May need to be drupal_map_assoc.
        '#options' => $options,
        '#default_value' => $ss_config->get('commerce_shipstation_exposed_shipping_methods'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state)
  {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    parent::submitForm($form, $form_state);

    $config = $this->config('commerce_shipstation.shipstation_config');
    $config->delete();
    $values = $form_state->cleanValues()->getValues();
    unset($values['actions']);
    // TODO: Process password field
    foreach ($values as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();
  }

  /**
   * Builds a list of all fields available on the site.
   */
  private function loadFieldOptions($entity_type, $bundle = NULL)
  {
    /** @var \Drupal\Core\Entity\EntityFieldManager $fieldManager */
    $fieldManager = \Drupal::service('entity_field.manager');
    /** @var EntityTypeBundleInfoInterface $bundles */
    $bundles = \Drupal::service('entity_type.bundle.info')->getBundleInfo($entity_type);
    $fields = [];
    if ($bundle) {
      $fields = $fieldManager->getFieldDefinitions($entity_type, $bundle);
    }
    // Loop through each bundle and build the instance list.
    else {
      foreach ($bundles as $bundle_id => $bundle_value) {
        $instance = $fieldManager->getFieldDefinitions($entity_type, $bundle_id);
        foreach ($instance as $field_id => $field_value) {
          if (!isset($fields[$field_id])) {
            $fields[$field_id] = $field_value;
          }
        }
      }
    }

    $options = ['none' => t('None')];
    if (!empty($fields)) {
      /** @var BaseFieldDefinition $field */
      foreach ($fields as $field) {
        $field_name = (string) $field->getLabel();
        $bundle_name = $field->getTargetEntityTypeId();

        $options[$entity_type . '.' . $field->getName()] = t('@bundle: @field',
          [
            '@bundle' => $bundle_name,
            '@field' => $field_name
          ]);
      }
    }

    asort($options);
    return $options;
  }
}