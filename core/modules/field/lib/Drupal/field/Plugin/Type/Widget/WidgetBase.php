<?php

/**
 * @file
 * Definition of Drupal\field\Plugin\Type\Widget\WidgetBase.
 */

namespace Drupal\field\Plugin\Type\Widget;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityInterface;
use Drupal\field\Plugin\PluginSettingsBase;
use Drupal\field\Plugin\Core\Entity\FieldInstance;

/**
 * Base class for 'Field widget' plugin implementations.
 */
abstract class WidgetBase extends PluginSettingsBase implements WidgetInterface {

  /**
   * The field definition.
   *
   * @var array
   */
  protected $field;

  /**
   * The field instance definition.
   *
   * @var \Drupal\field\Plugin\Core\Entity\FieldInstance
   */
  protected $instance;

  /**
   * The widget settings.
   *
   * @var array
   */
  protected $settings;

  /**
   * The widget weight.
   *
   * @var int
   */
  protected $weight;

  /**
   * Constructs a WidgetBase object.
   *
   * @param array $plugin_id
   *   The plugin_id for the widget.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\field\Plugin\Core\Entity\FieldInstance $instance
   *   The field instance to which the widget is associated.
   * @param array $settings
   *   The widget settings.
   * @param int $weight
   *   The widget weight.
   */
  public function __construct($plugin_id, array $plugin_definition, FieldInstance $instance, array $settings, $weight) {
    parent::__construct(array(), $plugin_id, $plugin_definition);

    $this->instance = $instance;
    $this->field = field_info_field($instance['field_name']);
    $this->settings = $settings;
    $this->weight = $weight;
  }

  /**
   * Implements Drupal\field\Plugin\Type\Widget\WidgetInterface::form().
   */
  public function form(EntityInterface $entity, $langcode, array $items, array &$form, array &$form_state, $get_delta = NULL) {
    $field = $this->field;
    $instance = $this->instance;
    $field_name = $field['field_name'];

    $parents = $form['#parents'];

    $addition = array(
      $field_name => array(),
    );

    // Store field information in $form_state.
    if (!field_form_get_state($parents, $field_name, $langcode, $form_state)) {
      $field_state = array(
          'field' => $field,
          'instance' => $instance,
          'items_count' => count($items),
          'array_parents' => array(),
          'errors' => array(),
      );
      field_form_set_state($parents, $field_name, $langcode, $form_state, $field_state);
    }

    // Collect widget elements.
    $elements = array();

    // If the widget is handling multiple values (e.g Options), or if we are
    // displaying an individual element, just get a single form element and make
    // it the $delta value.
    $definition = $this->getDefinition();
    if (isset($get_delta) || $definition['multiple_values']) {
      $delta = isset($get_delta) ? $get_delta : 0;
      $element = array(
        '#title' => check_plain($instance['label']),
        '#description' => field_filter_xss(token_replace($instance['description'])),
      );
      $element = $this->formSingleElement($entity, $items, $delta, $langcode, $element, $form, $form_state);

      if ($element) {
        if (isset($get_delta)) {
          // If we are processing a specific delta value for a field where the
          // field module handles multiples, set the delta in the result.
          $elements[$delta] = $element;
        }
        else {
          // For fields that handle their own processing, we cannot make
          // assumptions about how the field is structured, just merge in the
          // returned element.
          $elements = $element;
        }
      }
    }
    // If the widget does not handle multiple values itself, (and we are not
    // displaying an individual element), process the multiple value form.
    else {
      $elements = $this->formMultipleElements($entity, $items, $langcode, $form, $form_state);
    }

    // Also aid in theming of field widgets by rendering a classified
    // container.
    $addition[$field_name] = array(
      '#type' => 'container',
      '#attributes' => array(
        'class' => array(
          'field-type-' . drupal_html_class($field['type']),
          'field-name-' . drupal_html_class($field_name),
          'field-widget-' . drupal_html_class($this->getPluginId()),
        ),
      ),
      '#weight' => $this->weight,
    );

    // Populate the 'array_parents' information in $form_state['field'] after
    // the form is built, so that we catch changes in the form structure performed
    // in alter() hooks.
    $elements['#after_build'][] = 'field_form_element_after_build';
    $elements['#field_name'] = $field_name;
    $elements['#language'] = $langcode;
    $elements['#field_parents'] = $parents;

    $addition[$field_name] += array(
      '#tree' => TRUE,
      // The '#language' key can be used to access the field's form element
      // when $langcode is unknown.
      '#language' => $langcode,
      $langcode => $elements,
      '#access' => field_access('edit', $field, $entity->entityType(), $entity),
    );

    return $addition;
  }

  /**
   * Special handling to create form elements for multiple values.
   *
   * Handles generic features for multiple fields:
   * - number of widgets
   * - AHAH-'add more' button
   * - table display and drag-n-drop value reordering
   */
  protected function formMultipleElements(EntityInterface $entity, array $items, $langcode, array &$form, array &$form_state) {
    $field = $this->field;
    $instance = $this->instance;
    $field_name = $field['field_name'];

    $parents = $form['#parents'];

    // Determine the number of widgets to display.
    switch ($field['cardinality']) {
      case FIELD_CARDINALITY_UNLIMITED:
        $field_state = field_form_get_state($parents, $field_name, $langcode, $form_state);
        $max = $field_state['items_count'];
        $is_multiple = TRUE;
        break;

      default:
        $max = $field['cardinality'] - 1;
        $is_multiple = ($field['cardinality'] > 1);
        break;
    }

    $id_prefix = implode('-', array_merge($parents, array($field_name)));
    $wrapper_id = drupal_html_id($id_prefix . '-add-more-wrapper');

    $title = check_plain($instance['label']);
    $description = field_filter_xss(token_replace($instance['description']));

    $elements = array();

    for ($delta = 0; $delta <= $max; $delta++) {
      // For multiple fields, title and description are handled by the wrapping
      // table.
      $element = array(
        '#title' => $is_multiple ? '' : $title,
        '#description' => $is_multiple ? '' : $description,
      );
      $element = $this->formSingleElement($entity, $items, $delta, $langcode, $element, $form, $form_state);

      if ($element) {
        // Input field for the delta (drag-n-drop reordering).
        if ($is_multiple) {
          // We name the element '_weight' to avoid clashing with elements
          // defined by widget.
          $element['_weight'] = array(
            '#type' => 'weight',
            '#title' => t('Weight for row @number', array('@number' => $delta + 1)),
            '#title_display' => 'invisible',
            // Note: this 'delta' is the FAPI #type 'weight' element's property.
            '#delta' => $max,
            '#default_value' => isset($items[$delta]['_weight']) ? $items[$delta]['_weight'] : $delta,
            '#weight' => 100,
          );
        }

        $elements[$delta] = $element;
      }
    }

    if ($elements) {
      $elements += array(
        '#theme' => 'field_multiple_value_form',
        '#field_name' => $field['field_name'],
        '#cardinality' => $field['cardinality'],
        '#required' => $instance['required'],
        '#title' => $title,
        '#description' => $description,
        '#prefix' => '<div id="' . $wrapper_id . '">',
        '#suffix' => '</div>',
        '#max_delta' => $max,
      );

      // Add 'add more' button, if not working with a programmed form.
      if ($field['cardinality'] == FIELD_CARDINALITY_UNLIMITED && empty($form_state['programmed'])) {
        $elements['add_more'] = array(
          '#type' => 'submit',
          '#name' => strtr($id_prefix, '-', '_') . '_add_more',
          '#value' => t('Add another item'),
          '#attributes' => array('class' => array('field-add-more-submit')),
          '#limit_validation_errors' => array(array_merge($parents, array($field_name, $langcode))),
          '#submit' => array('field_add_more_submit'),
          '#ajax' => array(
              'callback' => 'field_add_more_js',
              'wrapper' => $wrapper_id,
              'effect' => 'fade',
          ),
        );
      }
    }

    return $elements;
  }

  /**
   * Generates the form element for a single copy of the widget.
   */
  protected function formSingleElement(EntityInterface $entity, array $items, $delta, $langcode, array $element, array &$form, array &$form_state) {
    $instance = $this->instance;
    $field = $this->field;

    $element += array(
      '#entity_type' => $entity->entityType(),
      '#bundle' => $entity->bundle(),
      '#entity' => $entity,
      '#field_name' => $field['field_name'],
      '#language' => $langcode,
      '#field_parents' => $form['#parents'],
      '#columns' => array_keys($field['columns']),
      // Only the first widget should be required.
      '#required' => $delta == 0 && $instance['required'],
      '#delta' => $delta,
      '#weight' => $delta,
    );

    $element = $this->formElement($items, $delta, $element, $langcode, $form, $form_state);

    if ($element) {
      // Allow modules to alter the field widget form element.
      $context = array(
        'form' => $form,
        'field' => $field,
        'instance' => $instance,
        'langcode' => $langcode,
        'items' => $items,
        'delta' => $delta,
        'default' => !empty($entity->field_ui_default_value),
      );
      drupal_alter(array('field_widget_form', 'field_widget_' . $this->getPluginId() . '_form'), $element, $form_state, $context);
    }

    return $element;
  }

  /**
   * Implements Drupal\field\Plugin\Type\Widget\WidgetInterface::extractFormValues().
   */
  public function extractFormValues(EntityInterface $entity, $langcode, array &$items, array $form, array &$form_state) {
    $field_name = $this->field['field_name'];

    // Extract the values from $form_state['values'].
    $path = array_merge($form['#parents'], array($field_name, $langcode));
    $key_exists = NULL;
    $values = NestedArray::getValue($form_state['values'], $path, $key_exists);

    if ($key_exists) {
      // Remove the 'value' of the 'add more' button.
      unset($values['add_more']);

      // Let the widget turn the submitted values into actual field values.
      // Make sure the '_weight' entries are persisted in the process.
      $weights = array();
      if (isset($values[0]['_weight'])) {
        foreach ($values as $delta => $value) {
          $weights[$delta] = $value['_weight'];
        }
      }
      $items = $this->massageFormValues($values, $form, $form_state);

      foreach ($items as $delta => &$item) {
        // Put back the weight.
        if (isset($weights[$delta])) {
          $item['_weight'] = $weights[$delta];
        }
        // The tasks below are going to reshuffle deltas. Keep track of the
        // original deltas for correct reporting of errors in flagErrors().
        $item['_original_delta'] = $delta;
      }

      // Account for drag-n-drop reordering.
      $this->sortItems($items);

      // Remove empty values.
      $items = _field_filter_items($this->field, $items);

      // Put delta mapping in $form_state, so that flagErrors() can use it.
      $field_state = field_form_get_state($form['#parents'], $field_name, $langcode, $form_state);
      foreach ($items as $delta => &$item) {
        $field_state['original_deltas'][$delta] = $item['_original_delta'];
        unset($item['_original_delta']);
      }
      field_form_set_state($form['#parents'], $field_name, $langcode, $form_state, $field_state);
    }
  }

  /**
   * Implements Drupal\field\Plugin\Type\Widget\WidgetInterface::flagErrors().
   */
  public function flagErrors(EntityInterface $entity, $langcode, array $items, array $form, array &$form_state) {
    $field_name = $this->field['field_name'];

    $field_state = field_form_get_state($form['#parents'], $field_name, $langcode, $form_state);

    if (!empty($field_state['errors'])) {
      // Locate the correct element in the the form.
      $element = NestedArray::getValue($form_state['complete_form'], $field_state['array_parents']);

      // Only set errors if the element is accessible.
      if (!isset($element['#access']) || $element['#access']) {
        $definition = $this->getDefinition();
        $is_multiple = $definition['multiple_values'];

        foreach ($field_state['errors'] as $delta => $delta_errors) {
          // For a multiple-value widget, pass all errors to the main widget.
          // For single-value widgets, pass errors by delta.
          if ($is_multiple) {
            $delta_element = $element;
          }
          else {
            $original_delta = $field_state['original_deltas'][$delta];
            $delta_element = $element[$original_delta];
          }
          foreach ($delta_errors as $error) {
            $error_element = $this->errorElement($delta_element, $error, $form, $form_state);
            form_error($error_element, $error['message']);
          }
        }
        // Reinitialize the errors list for the next submit.
        $field_state['errors'] = array();
        field_form_set_state($form['#parents'], $field_name, $langcode, $form_state, $field_state);
      }
    }
  }

  /**
   * Implements Drupal\field\Plugin\Type\Widget\WidgetInterface::settingsForm().
   */
  public function settingsForm(array $form, array &$form_state) {
    return array();
  }

  /**
   * Implements Drupal\field\Plugin\Type\Widget\WidgetInterface::errorElement().
   */
  public function errorElement(array $element, array $error, array $form, array &$form_state) {
    return $element;
  }

  /**
   * Implements Drupal\field\Plugin\Type\Widget\WidgetInterface::massageFormValues()
   */
  public function massageFormValues(array $values, array $form, array &$form_state) {
    return $values;
  }

  /**
   * Sorts submitted field values according to drag-n-drop reordering.
   *
   * @param array $items
   *   The field values.
   */
  protected function sortItems(array &$items) {
    $is_multiple = ($this->field['cardinality'] == FIELD_CARDINALITY_UNLIMITED) || ($this->field['cardinality'] > 1);
    if ($is_multiple && isset($items[0]['_weight'])) {
      usort($items, function ($a, $b) {
        $a_weight = (is_array($a) ? $a['_weight'] : 0);
        $b_weight = (is_array($b) ? $b['_weight'] : 0);
        return $a_weight - $b_weight;
      });
      // Remove the '_weight' entries.
      foreach ($items as $delta => &$item) {
        if (is_array($item)) {
          unset($item['_weight']);
        }
      }
    }
  }

}
