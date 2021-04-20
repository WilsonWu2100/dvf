<?php

namespace Drupal\dvf\Plugin\Visualisation\Style;

use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\dvf\ConfigurablePluginTrait;
use Drupal\dvf\DvfHelpers;
use Drupal\dvf\Plugin\VisualisationInterface;
use Drupal\dvf\Plugin\VisualisationStyleInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Render\FormattableMarkup;

/**
 * Provides a base class for VisualisationStyle plugins.
 */
abstract class VisualisationStyleBase extends PluginBase implements VisualisationStyleInterface, ContainerFactoryPluginInterface {

  use ConfigurablePluginTrait;

  /**
   * The visualisation.
   *
   * @var \Drupal\dvf\Plugin\VisualisationInterface
   */
  protected $visualisation;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * DVF Helpers.
   *
   * @var \Drupal\dvf\DvfHelpers
   */
  protected $dvfHelpers;

  /**
   * The Messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new VisualisationStyleBase.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\dvf\Plugin\VisualisationInterface $visualisation
   *   The visualisation context in which the plugin will run.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\dvf\DvfHelpers $dvf_helpers
   *   The DVF helpers.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The Messenger service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    VisualisationInterface $visualisation = NULL,
    ModuleHandlerInterface $module_handler,
    DvfHelpers $dvf_helpers,
    MessengerInterface $messenger
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->visualisation = $visualisation;
    $this->moduleHandler = $module_handler;
    $this->dvfHelpers = $dvf_helpers;
    $this->messenger = $messenger;
  }

  /**
   * Creates an instance of the plugin.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container to pull out services used in the plugin.
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\dvf\Plugin\VisualisationInterface $visualisation
   *   The visualisation context in which the plugin will run.
   *
   * @return static
   *   Returns an instance of this plugin.
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
    VisualisationInterface $visualisation = NULL
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $visualisation,
      $container->get('module_handler'),
      $container->get('dvf.helpers'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration() {
    return NestedArray::mergeDeep($this->defaultConfiguration(), $this->configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration) {
    $this->configuration = $configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'data' => [
        'fields' => [],
        'field_labels' => '',
        'split_field' => '',
        'cache_expiry' => '',
        'column_overrides' => [],
        'data_filters' => [],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $form['#after_build'][] = [get_called_class(), 'afterBuildSettingsForm'];
    $form['#attached']['library'][] = 'dvf/dvfAdmin';

    $form['data'] = [
      '#type' => 'details',
      '#title' => $this->t('Data settings'),
      '#tree' => TRUE,
    ];

    $form['data']['fields'] = [
      '#type' => 'select',
      '#title' => $this->t('Fields'),
      '#description' => $this->t('What fields to include in the visualisation. Select at least one field to display its data. A field is typically a column in a CSV. @help',
        ['@help' => $this->dvfHelpers->getHelpPageLink('keys')]),
      '#options' => $this->getSourceFieldOptions(),
      '#multiple' => TRUE,
      '#size' => 5,
      '#default_value' => $this->config('data', 'fields'),
    ];

    $form['data']['field_labels'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Field label overrides'),
      '#description' => $this->t('Optionally override one or more field labels. Add one original_label|new_label per line and separate with a pipe. @help',
        ['@help' => $this->dvfHelpers->getHelpPageLink('label-overrides')]),
      '#rows' => 2,
      '#default_value' => $this->config('data', 'field_labels'),
      '#placeholder' => 'Old label|New label',
    ];

    $form['data']['split_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Split field'),
      '#description' => $this->t('Optionally split into multiple visualisations based on the value of this field. A new visualisation will be made for each unique value in this field. @help',
        ['@help' => $this->dvfHelpers->getHelpPageLink('split')]),
      '#options' => $this->getSourceFieldOptions(),
      '#empty_option' => $this->t('- None -'),
      '#empty_value' => '',
      '#default_value' => $this->config('data', 'split_field'),
    ];

    $form['data']['cache_expiry'] = [
      '#type' => 'select',
      '#title' => $this->t('Cache expiry'),
      '#description' => $this->t('How long the results for this dataset will be cached.'),
      '#options' => $this->getCacheOptions(),
      '#default_value' => $this->config('data', 'cache_expiry'),
    ];

    $column_override_examples = [
      'type|line', 'color|#000000', 'legend|hide', 'style|dashed', 'weight|20', 'class|hide-points',
    ];
    $form['data']['column_overrides'] = [
      '#prefix' => '<div id="column-overrides">',
      '#suffix' => '</div>',
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
      '#type' => 'details',
      '#title' => t('Column/Group overrides'),
      '#description' => '<p>' . t('Optionally override a style for a specific column, add one key|value per line and separate key value with a pipe. @help.<br />Examples: <strong>@examples</strong>.',
        [
          '@examples' => new FormattableMarkup(implode('</strong> or <strong>', $column_override_examples), []),
          '@help' => $this->dvfHelpers->getHelpPageLink('column-overrides'),
        ]) . '</p>',
    ];

    foreach ($this->getColumnOverrideValues() as $override) {
      $form['data']['column_overrides'][$override] = [
        '#type' => 'textarea',
        '#rows' => 2,
        '#title' => $override,
        '#default_value' => $this->config('data', 'column_overrides', $override),
      ];
    }

    $form['data']['data_filters'] = [
      '#prefix' => '<div>',
      '#suffix' => '</div>',
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
      '#type' => 'details',
      '#title' => t('CKAN data filters'),
      '#description' => t('Filters can be used to refine/reduce the records returned from the CKAN datasource. @help',
        ['@help' => $this->dvfHelpers->getHelpPageLink('data-filters')]),
    ];

    $form['data']['data_filters']['q'] = [
      '#type' => 'textfield',
      '#title' => t('Full text query'),
      '#description' => t('Optionally query entire dataset for any string value.'),
      '#default_value' => $this->config('data', 'data_filters', 'q'),
    ];

    $form['data']['data_filters']['filters'] = [
      '#type' => 'textfield',
      '#title' => t('Filters'),
      '#description' => t('Filter on key/value dictionary. For example: {"code": "4000", "year": "2016"} or {"year": ["2014", "2015", "2015"]}. Case sensitive.'),
      '#default_value' => $this->config('data', 'data_filters', 'filters'),
    ];

    return $form;
  }

  /**
   * Gets the options array for caching.
   *
   * @return array
   *   The options array.
   */
  protected function getCacheOptions() {

    return [
      '_global_default' => 'Global default',
      '0' => 'No cache',
      '1800' => '30 minutes',
      '3600' => '1 hour',
      '21600' => '6 hours',
      '86400' => '1 day',
      '604800' => '1 week',
      '2592000' => '1 month',
      '15552000' => '6 months',
    ];
  }

  /**
   * Settings form #after_build callback.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   The updated form element.
   */
  public static function afterBuildSettingsForm(array $element, FormStateInterface $form_state) {
    return $element;
  }

  /**
   * Gets the list of source field options.
   *
   * @return array
   *   The source field options.
   */
  protected function getSourceFieldOptions() {
    $fields = $this->visualisation->getSourcePlugin()->getFields();
    $options = array_map('\Drupal\Component\Utility\Html::escape', $fields);

    return !empty($options) ? $options : [];
  }

  /**
   * Gets the source field values.
   *
   * @param string $field_id
   *   The field ID.
   *
   * @return array
   *   The source field values.
   */
  protected function getSourceFieldValues($field_id) {
    $values = [];

    foreach ($this->getSourceRecords() as $group_records) {
      foreach ($group_records as $record) {
        if (property_exists($record, $field_id)) {
          $values[] = $record->{$field_id};
        }
      }
    }

    return $values;
  }

  /**
   * Gets the source records.
   *
   * @return array
   *   An array of source records.
   */
  public function getSourceRecords() {
    $records = [];

    foreach ($this->getVisualisation()->data() as $record) {
      if ($this->splitField() && property_exists($record, $this->splitField())) {
        $records[$record->{$this->splitField()}][] = $record;
      }
      else {
        $records['all'][] = $record;
      }
    }

    return $records;
  }

  /**
   * Gets the fields.
   *
   * @return array
   *   The fields.
   */
  protected function fields() {
    return array_filter($this->config('data', 'fields'));
  }

  /**
   * Gets the field labels.
   *
   * @return array
   *   The field labels.
   */
  protected function fieldLabels() {
    $labels = array_intersect_key($this->getSourceFieldOptions(), $this->fields());

    $label_overrides = $this->config('data', 'field_labels');
    $label_overrides = preg_split('/\r\n|[\r\n]/', $label_overrides);

    foreach ($label_overrides as $label_override) {
      $label_parts = explode('|', trim($label_override), 2);

      if (count($label_parts) === 2 && array_key_exists($label_parts[0], $labels)) {
        $labels[$label_parts[0]] = $label_parts[1];
      }
    }

    return $labels;
  }

  /**
   * Gets the original field labels without any overrides applied.
   *
   * @return array
   *   The unique field labels.
   */
  protected function fieldLabelsOriginal() {
    $labels = $this->getSourceFieldOptions();

    if (!empty($labels['_id'])) {
      unset($labels['_id']);
    }

    return array_keys($labels);
  }

  /**
   * Gets a field label.
   *
   * @param string $field_id
   *   The field ID.
   *
   * @return string
   *   The field label.
   */
  protected function fieldLabel($field_id) {
    $label = '';
    $labels = $this->fieldLabels();

    if (array_key_exists($field_id, $labels)) {
      $label = $labels[$field_id];
    }

    return $label;
  }

  /**
   * Gets the split field.
   *
   * @return string
   *   The split field.
   */
  protected function splitField() {
    return $this->config('data', 'split_field');
  }

  /**
   * {@inheritdoc}
   */
  public function getVisualisation() {
    return $this->visualisation;
  }

  /**
   * {@inheritdoc}
   */
  public function getDatasetDownloadUri(EntityInterface $entity, array $dvf_field_types = ['dvf_url', 'dvf_file']) {
    $field_definitions = $entity->getFieldDefinitions();
    $dvf_field_values = [];
    $dvf_file_uri = '';

    foreach ($field_definitions as $field_definition) {
      if (in_array($field_definition->getType(), $dvf_field_types)) {

        $field_name = $field_definition->get('field_name');

        if (!empty($entity->get($field_name)->first())) {
          $dvf_field_values = $entity->get($field_name)->first()->getValue();
        }
      }
    }

    if (!empty($dvf_field_values['uri'])) {
      $dvf_file_uri = $dvf_field_values['uri'];
    }

    // Check to see if we have a valid URI.
    return $this->isValidDownloadUri($dvf_file_uri);
  }

  /**
   * {@inheritdoc}
   */
  public function isValidDownloadUri($uri) {
    return (UrlHelper::isValid($uri) || filter_var($uri, FILTER_VALIDATE_URL)) ? $uri : FALSE;
  }

  /**
   * Returns the column override values to make a form array.
   *
   * @return array
   *   The array of column override values.
   */
  protected function getColumnOverrideValues() {

    if ($this->config('axis', 'x', 'x_axis_grouping') === 'values' && $this->config('axis', 'x', 'tick', 'values', 'field')) {
      $x_tick_field = $this->config('axis', 'x', 'tick', 'values', 'field');
      return array_map(function ($e) use ($x_tick_field) {
        return $e->{$x_tick_field};
      }, $this->getVisualisation()->data());
    }
    else {
      return $this->fieldLabelsOriginal();
    }
  }

  /**
   * Checks to see if columns are numeric.
   *
   * @param array $array
   *   The array of column values.
   *
   * @return bool
   *   True if numeric, false if not.
   */
  public function columnsAreNumeric(array $array) {
    $array = reset($array);
    array_shift($array);

    if (count($array) === count(array_filter($array, 'is_numeric'))) {
      return TRUE;
    }

    return FALSE;
  }

}
