<?php

/*
 * @file
 * Definition of Drupal\field\FieldInfo.
 */

namespace Drupal\field;

use Drupal\Core\Cache\CacheBackendInterface;

/**
 * Provides field and instance definitions for the current runtime environment.
 *
 * A Drupal\field\FieldInfo object is created and statically persisted through
 * the request by the field_info_cache() function. The object properties act as
 * a "static cache" of fields and instances definitions.
 *
 * The preferred way to access definitions is through the getBundleInstances()
 * method, which keeps cache entries per bundle, storing both fields and
 * instances for a given bundle. Fields used in multiple bundles are duplicated
 * in several cache entries, and are merged into a single list in the memory
 * cache. Cache entries are loaded for bundles as a whole, optimizing memory
 * and CPU usage for the most common pattern of iterating over all instances of
 * a bundle rather than accessing a single instance.
 *
 * The getFields() and getInstances() methods, which return all existing field
 * and instance definitions, are kept mainly for backwards compatibility, and
 * should be avoided when possible, since they load and persist in memory a
 * potentially large array of information. In many cases, the lightweight
 * getFieldMap() method should be preferred.
 */
class FieldInfo {

  /**
   * Lightweight map of fields across entity types and bundles.
   *
   * @var array
   */
  protected $fieldMap;

  /**
   * List of $field structures keyed by ID. Includes deleted fields.
   *
   * @var array
   */
  protected $fieldsById = array();

  /**
   * Mapping of field names to the ID of the corresponding non-deleted field.
   *
   * @var array
   */
  protected $fieldIdsByName = array();

  /**
   * Whether $fieldsById contains all field definitions or a subset.
   *
   * @var bool
   */
  protected $loadedAllFields = FALSE;

  /**
   * Separately tracks requested field names or IDs that do not exists.
   *
   * @var array
   */
  protected $unknownFields = array();

  /**
   * Instance definitions by bundle.
   *
   * @var array
   */
  protected $bundleInstances = array();

  /**
   * Whether $bundleInstances contains all instances definitions or a subset.
   *
   * @var bool
   */
  protected $loadedAllInstances = FALSE;

  /**
   * Separately tracks requested bundles that are empty (or do not exist).
   *
   * @var array
   */
  protected $emptyBundles = array();

  /**
   * Extra fields by bundle.
   *
   * @var array
   */
  protected $bundleExtraFields = array();

  /**
   * Clears the "static" and persistent caches.
   */
  public function flush() {
    $this->fieldMap = NULL;

    $this->fieldsById = array();
    $this->fieldIdsByName = array();
    $this->loadedAllFields = FALSE;
    $this->unknownFields = array();

    $this->bundleInstances = array();
    $this->loadedAllInstances = FALSE;
    $this->emptyBundles = array();

    $this->bundleExtraFields = array();

    cache('field')->invalidateTags(array('field_info' => TRUE));
  }

  /**
   * Collects a lightweight map of fields across bundles.
   *
   * @return
   *   An array keyed by field name. Each value is an array with two entries:
   *   - type: The field type.
   *   - bundles: The bundles in which the field appears, as an array with
   *     entity types as keys and the array of bundle names as values.
   */
  public function getFieldMap() {
    // Read from the "static" cache.
    if ($this->fieldMap !== NULL) {
      return $this->fieldMap;
    }

    // Read from persistent cache.
    if ($cached = cache('field')->get('field_info:field_map')) {
      $map = $cached->data;

      // Save in "static" cache.
      $this->fieldMap = $map;

      return $map;
    }

    $map = array();

    $query = db_select('field_config_instance', 'fci');
    $query->join('field_config', 'fc', 'fc.id = fci.field_id');
    $query->fields('fc', array('type'));
    $query->fields('fci', array('field_name', 'entity_type', 'bundle'))
      ->condition('fc.active', 1)
      ->condition('fc.storage_active', 1)
      ->condition('fc.deleted', 0)
      ->condition('fci.deleted', 0);
    foreach ($query->execute() as $row) {
      $map[$row->field_name]['bundles'][$row->entity_type][] = $row->bundle;
      $map[$row->field_name]['type'] = $row->type;
    }

    // Save in "static" and persistent caches.
    $this->fieldMap = $map;
    cache('field')->set('field_info:field_map', $map, CacheBackendInterface::CACHE_PERMANENT, array('field_info' => TRUE));

    return $map;
  }

  /**
   * Returns all active fields, including deleted ones.
   *
   * @return
   *   An array of field definitions, keyed by field ID.
   */
  public function getFields() {
    // Read from the "static" cache.
    if ($this->loadedAllFields) {
      return $this->fieldsById;
    }

    // Read from persistent cache.
    if ($cached = cache('field')->get('field_info:fields')) {
      $this->fieldsById = $cached->data;
    }
    else {
      // Collect and prepare fields.
      foreach (field_read_fields(array(), array('include_deleted' => TRUE)) as $field) {
        $this->fieldsById[$field['id']] = $this->prepareField($field);
      }

      // Store in persistent cache.
      cache('field')->set('field_info:fields', $this->fieldsById, CacheBackendInterface::CACHE_PERMANENT, array('field_info' => TRUE));
    }

    // Fill the name/ID map.
    foreach ($this->fieldsById as $field) {
      if (!$field['deleted']) {
        $this->fieldIdsByName[$field['field_name']] = $field['id'];
      }
    }

    $this->loadedAllFields = TRUE;

    return $this->fieldsById;
  }

  /**
   * Retrieves all active, non-deleted instances definitions.
   *
   * This method does not read from nor populate the "static" and persistent
   * caches.
   *
   * @param $entity_type
   *   (optional) The entity type.
   *
   * @return
   *   If $entity_type is not set, all instances keyed by entity type and bundle
   *   name. If $entity_type is set, all instances for that entity type, keyed
   *   by bundle name.
   */
  public function getInstances($entity_type = NULL) {
    // If the full list is not present in "static" cache yet.
    if (!$this->loadedAllInstances) {

      // Read from persistent cache.
      if ($cached = cache('field')->get('field_info:instances')) {
        $this->bundleInstances = $cached->data;
      }
      else {
        // Collect and prepare instances.

        // We also need to populate the static field cache, since it will not
        // be set by subsequent getBundleInstances() calls.
        $this->getFields();

        foreach (field_read_instances() as $instance) {
          $field = $this->getField($instance['field_name']);
          $instance = $this->prepareInstance($instance, $field['type']);
          $this->bundleInstances[$instance['entity_type']][$instance['bundle']][$instance['field_name']] = new FieldInstance($instance);
        }

        // Store in persistent cache.
        cache('field')->set('field_info:instances', $this->bundleInstances, CacheBackendInterface::CACHE_PERMANENT, array('field_info' => TRUE));
      }

      $this->loadedAllInstances = TRUE;
    }

    if (isset($entity_type)) {
      return isset($this->bundleInstances[$entity_type]) ? $this->bundleInstances[$entity_type] : array();
    }
    else {
      return $this->bundleInstances;
    }
  }

  /**
   * Returns a field definition from a field name.
   *
   * This method only retrieves active, non-deleted fields.
   *
   * @param $field_name
   *   The field name.
   *
   * @return
   *   The field definition, or NULL if no field was found.
   */
  public function getField($field_name) {
    // Read from the "static" cache.
    if (isset($this->fieldIdsByName[$field_name])) {
      $field_id = $this->fieldIdsByName[$field_name];
      return $this->fieldsById[$field_id];
    }
    if (isset($this->unknownFields[$field_name])) {
      return;
    }

    // Do not check the (large) persistent cache, but read the definition.

    // Cache miss: read from definition.
    if ($field = field_read_field(array('field_name' => $field_name))) {
      $field = $this->prepareField($field);

      // Save in the "static" cache.
      $this->fieldsById[$field['id']] = $field;
      $this->fieldIdsByName[$field['field_name']] = $field['id'];

      return $field;
    }
    else {
      $this->unknownFields[$field_name] = TRUE;
    }
  }

  /**
   * Returns a field definition from a field ID.
   *
   * This method only retrieves active fields, deleted or not.
   *
   * @param $field_id
   *   The field ID.
   *
   * @return
   *   The field definition, or NULL if no field was found.
   */
  public function getFieldById($field_id) {
    // Read from the "static" cache.
    if (isset($this->fieldsById[$field_id])) {
      return $this->fieldsById[$field_id];
    }
    if (isset($this->unknownFields[$field_id])) {
      return;
    }

    // No persistent cache, fields are only persistently cached as part of a
    // bundle.

    // Cache miss: read from definition.
    if ($fields = field_read_fields(array('id' => $field_id), array('include_deleted' => TRUE))) {
      $field = current($fields);
      $field = $this->prepareField($field);

      // Store in the static cache.
      $this->fieldsById[$field['id']] = $field;
      if (!$field['deleted']) {
        $this->fieldIdsByName[$field['field_name']] = $field['id'];
      }

      return $field;
    }
    else {
      $this->unknownFields[$field_id] = TRUE;
    }
  }

  /**
   * Retrieves the instances for a bundle.
   *
   * The function also populates the corresponding field definitions in the
   * "static" cache.
   *
   * @param $entity_type
   *   The entity type.
   * @param $bundle
   *   The bundle name.
   *
   * @return
   *   The array of instance definitions, keyed by field name.
   */
  public function getBundleInstances($entity_type, $bundle) {
    // Read from the "static" cache.
    if (isset($this->bundleInstances[$entity_type][$bundle])) {
      return $this->bundleInstances[$entity_type][$bundle];
    }
    if (isset($this->emptyBundles[$entity_type][$bundle])) {
      return array();
    }

    // Read from the persistent cache.
    if ($cached = cache('field')->get("field_info:bundle:$entity_type:$bundle")) {
      $info = $cached->data;

      // Extract the field definitions and save them in the "static" cache.
      foreach ($info['fields'] as $field) {
        if (!isset($this->fieldsById[$field['id']])) {
          $this->fieldsById[$field['id']] = $field;
          if (!$field['deleted']) {
            $this->fieldIdsByName[$field['field_name']] = $field['id'];
          }
        }
      }
      unset($info['fields']);

      // Save in the "static" cache.
      $this->bundleInstances[$entity_type][$bundle] = $info['instances'];

      return $info['instances'];
    }

    // Cache miss: collect from the definitions.

    $instances = array();

    // Collect the fields in the bundle.
    $params = array('entity_type' => $entity_type, 'bundle' => $bundle);
    $fields = field_read_fields($params);

    // This iterates on non-deleted instances, so deleted fields are kept out of
    // the persistent caches.
    foreach (field_read_instances($params) as $instance) {
      $field = $fields[$instance['field_name']];

      $instance = $this->prepareInstance($instance, $field['type']);
      $instances[$field['field_name']] = new FieldInstance($instance);

      // If the field is not in our global "static" list yet, add it.
      if (!isset($this->fieldsById[$field['id']])) {
        $field = $this->prepareField($field);

        $this->fieldsById[$field['id']] = $field;
        $this->fieldIdsByName[$field['field_name']] = $field['id'];
      }
    }

    // Store in the 'static' cache'. Empty (or non-existent) bundles are stored
    // separately, so that they do not pollute the global list returned by
    // getInstances().
    if ($instances) {
      $this->bundleInstances[$entity_type][$bundle] = $instances;
    }
    else {
      $this->emptyBundles[$entity_type][$bundle] = TRUE;
    }

    // The persistent cache additionally contains the definitions of the fields
    // involved in the bundle.
    $cache = array(
      'instances' => $instances,
      'fields' => array()
    );
    foreach ($instances as $instance) {
      $cache['fields'][] = $this->fieldsById[$instance['field_id']];
    }
    cache('field')->set("field_info:bundle:$entity_type:$bundle", $cache, CacheBackendInterface::CACHE_PERMANENT, array('field_info' => TRUE));

    return $instances;
  }

  /**
   * Retrieves the "extra fields" for a bundle.
   *
   * @param $entity_type
   *   The entity type.
   * @param $bundle
   *   The bundle name.
   *
   * @return
   *   The array of extra fields.
   */
  public function getBundleExtraFields($entity_type, $bundle) {
    // Read from the "static" cache.
    if (isset($this->bundleExtraFields[$entity_type][$bundle])) {
      return $this->bundleExtraFields[$entity_type][$bundle];
    }

    // Read from the persistent cache.
    if ($cached = cache('field')->get("field_info:bundle_extra:$entity_type:$bundle")) {
      $this->bundleExtraFields[$entity_type][$bundle] = $cached->data;
      return $this->bundleExtraFields[$entity_type][$bundle];
    }

    // Cache miss: read from hook_field_extra_fields(). Note: given the current
    // shape of the hook, we have no other way than collecting extra fields on
    // all bundles.
    $info = array();
    $extra = module_invoke_all('field_extra_fields');
    drupal_alter('field_extra_fields', $extra);
    // Merge in saved settings.
    if (isset($extra[$entity_type][$bundle])) {
      $info = $this->prepareExtraFields($extra[$entity_type][$bundle], $entity_type, $bundle);
    }

    // Store in the 'static' and persistent caches.
    $this->bundleExtraFields[$entity_type][$bundle] = $info;
    cache('field')->set("field_info:bundle_extra:$entity_type:$bundle", $info, CacheBackendInterface::CACHE_PERMANENT, array('field_info' => TRUE));

    return $this->bundleExtraFields[$entity_type][$bundle];
  }

  /**
   * Prepares a field definition for the current run-time context.
   *
   * @param $field
   *   The raw field structure as read from the database.
   *
   * @return
   *   The field definition completed for the current runtime context.
   */
  public function prepareField($field) {
    // Make sure all expected field settings are present.
    $field['settings'] += field_info_field_settings($field['type']);
    $field['storage']['settings'] += field_info_storage_settings($field['storage']['type']);

    // Add storage details.
    $details = (array) module_invoke($field['storage']['module'], 'field_storage_details', $field);
    drupal_alter('field_storage_details', $details, $field);
    $field['storage']['details'] = $details;

    // Populate the list of bundles using the field.
    $field['bundles'] = array();
    if (!$field['deleted']) {
      $map = $this->getFieldMap();
      if (isset($map[$field['field_name']])) {
        $field['bundles'] = $map[$field['field_name']]['bundles'];
      }
    }

    return $field;
  }

  /**
   * Prepares an instance definition for the current run-time context.
   *
   * @param $instance
   *   The raw instance structure as read from the database.
   * @param $field_type
   *   The field type.
   *
   * @return
   *   The field instance array completed for the current runtime context.
   */
  public function prepareInstance($instance, $field_type) {
    // Make sure all expected instance settings are present.
    $instance['settings'] += field_info_instance_settings($field_type);

    // Set a default value for the instance.
    if (field_behaviors_widget('default value', $instance) == FIELD_BEHAVIOR_DEFAULT && !isset($instance['default_value'])) {
      $instance['default_value'] = NULL;
    }

    return $instance;
  }

  /**
   * Prepares 'extra fields' for the current run-time context.
   *
   * @param $extra_fields
   *   The array of extra fields, as collected in hook_field_extra_fields().
   * @param $entity_type
   *   The entity type.
   * @param $bundle
   *   The bundle name.
   *
   * @return
   *   The list of extra fields completed for the current runtime context.
   */
  public function prepareExtraFields($extra_fields, $entity_type, $bundle) {
    $entity_type_info = entity_get_info($entity_type);
    $bundle_settings = field_bundle_settings($entity_type, $bundle);
    $extra_fields += array('form' => array(), 'display' => array());

    $result = array();
    // Extra fields in forms.
    foreach ($extra_fields['form'] as $name => $field_data) {
      $settings = isset($bundle_settings['extra_fields']['form'][$name]) ? $bundle_settings['extra_fields']['form'][$name] : array();
      if (isset($settings['weight'])) {
        $field_data['weight'] = $settings['weight'];
      }
      $result['form'][$name] = $field_data;
    }

    // Extra fields in displayed entities.
    $data = $extra_fields['display'];
    foreach ($extra_fields['display'] as $name => $field_data) {
      $settings = isset($bundle_settings['extra_fields']['display'][$name]) ? $bundle_settings['extra_fields']['display'][$name] : array();
      $view_modes = array_merge(array('default'), array_keys($entity_type_info['view modes']));
      foreach ($view_modes as $view_mode) {
        if (isset($settings[$view_mode])) {
          $field_data['display'][$view_mode] = $settings[$view_mode];
        }
        else {
          $field_data['display'][$view_mode] = array(
            'weight' => $field_data['weight'],
            'visible' => isset($field_data['visible']) ? $field_data['visible'] : TRUE,
          );
        }
      }
      unset($field_data['weight']);
      unset($field_data['visible']);
      $result['display'][$name] = $field_data;
    }

    return $result;
  }
}
