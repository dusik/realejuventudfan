<?php

/**
 * @file
 * Definition of Drupal\field_sql_storage\FieldSqlStorageTest.
 */

namespace Drupal\field_sql_storage\Tests;

use Drupal\Core\Database\Database;
use Drupal\field\FieldException;
use Drupal\simpletest\WebTestBase;
use Exception;
use PDO;
/**
 * Tests field storage.
 *
 * Field_sql_storage.module implements the default back-end storage plugin
 * for the Field Strage API.
 */
class FieldSqlStorageTest extends WebTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('field_sql_storage', 'field', 'field_test', 'text', 'number');

  public static function getInfo() {
    return array(
      'name'  => 'Field SQL Storage tests',
      'description'  => "Test Field SQL Storage module.",
      'group' => 'Field API'
    );
  }

  function setUp() {
    parent::setUp();

    $this->field_name = strtolower($this->randomName());
    $this->field = array('field_name' => $this->field_name, 'type' => 'test_field', 'cardinality' => 4);
    $this->field = field_create_field($this->field);
    $this->instance = array(
      'field_name' => $this->field_name,
      'entity_type' => 'test_entity',
      'bundle' => 'test_bundle'
    );
    $this->instance = field_create_instance($this->instance);
    $this->table = _field_sql_storage_tablename($this->field);
    $this->revision_table = _field_sql_storage_revision_tablename($this->field);

  }

  /**
   * Uses the mysql tables and records to verify
   * field_load_revision works correctly.
   */
  function testFieldAttachLoad() {
    $entity_type = 'test_entity';
    $eid = 0;
    $langcode = LANGUAGE_NOT_SPECIFIED;

    $columns = array('entity_type', 'entity_id', 'revision_id', 'delta', 'langcode', $this->field_name . '_value');

    // Insert data for four revisions to the field revisions table
    $query = db_insert($this->revision_table)->fields($columns);
    for ($evid = 0; $evid < 4; ++$evid) {
      $values[$evid] = array();
      // Note: we insert one extra value ('<=' instead of '<').
      for ($delta = 0; $delta <= $this->field['cardinality']; $delta++) {
        $value = mt_rand(1, 127);
        $values[$evid][] = $value;
        $query->values(array($entity_type, $eid, $evid, $delta, $langcode, $value));
      }
    }
    $query->execute();

    // Insert data for the "most current revision" into the field table
    $query = db_insert($this->table)->fields($columns);
    foreach ($values[0] as $delta => $value) {
      $query->values(array($entity_type, $eid, 0, $delta, $langcode, $value));
    }
    $query->execute();

    // Load the "most current revision"
    $entity = field_test_create_entity($eid, 0, $this->instance['bundle']);
    field_attach_load($entity_type, array($eid => $entity));
    foreach ($values[0] as $delta => $value) {
      if ($delta < $this->field['cardinality']) {
        $this->assertEqual($entity->{$this->field_name}[$langcode][$delta]['value'], $value, "Value $delta is loaded correctly for current revision");
      }
      else {
        $this->assertFalse(array_key_exists($delta, $entity->{$this->field_name}[$langcode]), "No extraneous value gets loaded for current revision.");
      }
    }

    // Load every revision
    for ($evid = 0; $evid < 4; ++$evid) {
      $entity = field_test_create_entity($eid, $evid, $this->instance['bundle']);
      field_attach_load_revision($entity_type, array($eid => $entity));
      foreach ($values[$evid] as $delta => $value) {
        if ($delta < $this->field['cardinality']) {
          $this->assertEqual($entity->{$this->field_name}[$langcode][$delta]['value'], $value, "Value $delta for revision $evid is loaded correctly");
        }
        else {
          $this->assertFalse(array_key_exists($delta, $entity->{$this->field_name}[$langcode]), "No extraneous value gets loaded for revision $evid.");
        }
      }
    }

    // Add a translation in an unavailable language code and verify it is not
    // loaded.
    $eid = $evid = 1;
    $unavailable_langcode = 'xx';
    $entity = field_test_create_entity($eid, $evid, $this->instance['bundle']);
    $values = array($entity_type, $eid, $evid, 0, $unavailable_langcode, mt_rand(1, 127));
    db_insert($this->table)->fields($columns)->values($values)->execute();
    db_insert($this->revision_table)->fields($columns)->values($values)->execute();
    field_attach_load($entity_type, array($eid => $entity));
    $this->assertFalse(array_key_exists($unavailable_langcode, $entity->{$this->field_name}), 'Field translation in an unavailable language ignored');
  }

  /**
   * Reads mysql to verify correct data is
   * written when using insert and update.
   */
  function testFieldAttachInsertAndUpdate() {
    $entity_type = 'test_entity';
    $entity = field_test_create_entity(0, 0, $this->instance['bundle']);
    $langcode = LANGUAGE_NOT_SPECIFIED;

    // Test insert.
    $values = array();
    // Note: we try to insert one extra value ('<=' instead of '<').
    // TODO : test empty values filtering and "compression" (store consecutive deltas).
    for ($delta = 0; $delta <= $this->field['cardinality']; $delta++) {
      $values[$delta]['value'] = mt_rand(1, 127);
    }
    $entity->{$this->field_name}[$langcode] = $rev_values[0] = $values;
    field_attach_insert($entity_type, $entity);

    $rows = db_select($this->table, 't')->fields('t')->execute()->fetchAllAssoc('delta', PDO::FETCH_ASSOC);
    foreach ($values as $delta => $value) {
      if ($delta < $this->field['cardinality']) {
        $this->assertEqual($rows[$delta][$this->field_name . '_value'], $value['value'], t("Value $delta is inserted correctly"));
      }
      else {
        $this->assertFalse(array_key_exists($delta, $rows), "No extraneous value gets inserted.");
      }
    }

    // Test update.
    $entity = field_test_create_entity(0, 1, $this->instance['bundle']);
    $values = array();
    // Note: we try to update one extra value ('<=' instead of '<').
    for ($delta = 0; $delta <= $this->field['cardinality']; $delta++) {
      $values[$delta]['value'] = mt_rand(1, 127);
    }
    $entity->{$this->field_name}[$langcode] = $rev_values[1] = $values;
    field_attach_update($entity_type, $entity);
    $rows = db_select($this->table, 't')->fields('t')->execute()->fetchAllAssoc('delta', PDO::FETCH_ASSOC);
    foreach ($values as $delta => $value) {
      if ($delta < $this->field['cardinality']) {
        $this->assertEqual($rows[$delta][$this->field_name . '_value'], $value['value'], t("Value $delta is updated correctly"));
      }
      else {
        $this->assertFalse(array_key_exists($delta, $rows), "No extraneous value gets updated.");
      }
    }

    // Check that data for both revisions are in the revision table.
    // We make sure each value is stored correctly, then unset it.
    // When an entire revision's values are unset (remembering that we
    // put one extra value in $values per revision), unset the entire
    // revision. Then, if $rev_values is empty at the end, all
    // revision data was found.
    $results = db_select($this->revision_table, 't')->fields('t')->execute();
    foreach ($results as $row) {
      $this->assertEqual($row->{$this->field_name . '_value'}, $rev_values[$row->revision_id][$row->delta]['value'], "Value {$row->delta} for revision {$row->revision_id} stored correctly");
      unset($rev_values[$row->revision_id][$row->delta]);
      if (count($rev_values[$row->revision_id]) == 1) {
        unset($rev_values[$row->revision_id]);
      }
    }
    $this->assertTrue(empty($rev_values), "All values for all revisions are stored in revision table {$this->revision_table}");

    // Check that update leaves the field data untouched if
    // $entity->{$field_name} is absent.
    unset($entity->{$this->field_name});
    field_attach_update($entity_type, $entity);
    $rows = db_select($this->table, 't')->fields('t')->execute()->fetchAllAssoc('delta', PDO::FETCH_ASSOC);
    foreach ($values as $delta => $value) {
      if ($delta < $this->field['cardinality']) {
        $this->assertEqual($rows[$delta][$this->field_name . '_value'], $value['value'], t("Update with no field_name entry leaves value $delta untouched"));
      }
    }

    // Check that update with an empty $entity->$field_name empties the field.
    $entity->{$this->field_name} = NULL;
    field_attach_update($entity_type, $entity);
    $rows = db_select($this->table, 't')->fields('t')->execute()->fetchAllAssoc('delta', PDO::FETCH_ASSOC);
    $this->assertEqual(count($rows), 0, t("Update with an empty field_name entry empties the field."));
  }

  /**
   * Tests insert and update with missing or NULL fields.
   */
  function testFieldAttachSaveMissingData() {
    $entity_type = 'test_entity';
    $entity = field_test_create_entity(0, 0, $this->instance['bundle']);
    $langcode = LANGUAGE_NOT_SPECIFIED;

    // Insert: Field is missing
    field_attach_insert($entity_type, $entity);
    $count = db_select($this->table)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEqual($count, 0, 'Missing field results in no inserts');

    // Insert: Field is NULL
    $entity->{$this->field_name} = NULL;
    field_attach_insert($entity_type, $entity);
    $count = db_select($this->table)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEqual($count, 0, 'NULL field results in no inserts');

    // Add some real data
    $entity->{$this->field_name}[$langcode] = array(0 => array('value' => 1));
    field_attach_insert($entity_type, $entity);
    $count = db_select($this->table)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEqual($count, 1, 'Field data saved');

    // Update: Field is missing. Data should survive.
    unset($entity->{$this->field_name});
    field_attach_update($entity_type, $entity);
    $count = db_select($this->table)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEqual($count, 1, 'Missing field leaves data in table');

    // Update: Field is NULL. Data should be wiped.
    $entity->{$this->field_name} = NULL;
    field_attach_update($entity_type, $entity);
    $count = db_select($this->table)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEqual($count, 0, 'NULL field leaves no data in table');

    // Add a translation in an unavailable language.
    $unavailable_langcode = 'xx';
    db_insert($this->table)
      ->fields(array('entity_type', 'bundle', 'deleted', 'entity_id', 'revision_id', 'delta', 'langcode'))
      ->values(array($entity_type, $this->instance['bundle'], 0, 0, 0, 0, $unavailable_langcode))
      ->execute();
    $count = db_select($this->table)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEqual($count, 1, 'Field translation in an unavailable language saved.');

    // Again add some real data.
    $entity->{$this->field_name}[$langcode] = array(0 => array('value' => 1));
    field_attach_insert($entity_type, $entity);
    $count = db_select($this->table)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEqual($count, 2, 'Field data saved.');

    // Update: Field translation is missing but field is not empty. Translation
    // data should survive.
    $entity->{$this->field_name}[$unavailable_langcode] = array(mt_rand(1, 127));
    unset($entity->{$this->field_name}[$langcode]);
    field_attach_update($entity_type, $entity);
    $count = db_select($this->table)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEqual($count, 2, 'Missing field translation leaves data in table.');

    // Update: Field translation is NULL but field is not empty. Translation
    // data should be wiped.
    $entity->{$this->field_name}[$langcode] = NULL;
    field_attach_update($entity_type, $entity);
    $count = db_select($this->table)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEqual($count, 1, 'NULL field translation is wiped.');
  }

  /**
   * Test trying to update a field with data.
   */
  function testUpdateFieldSchemaWithData() {
    // Create a decimal 5.2 field and add some data.
    $field = array('field_name' => 'decimal52', 'type' => 'number_decimal', 'settings' => array('precision' => 5, 'scale' => 2));
    $field = field_create_field($field);
    $instance = array('field_name' => 'decimal52', 'entity_type' => 'test_entity', 'bundle' => 'test_bundle');
    $instance = field_create_instance($instance);
    $entity = field_test_create_entity(0, 0, $instance['bundle']);
    $entity->decimal52[LANGUAGE_NOT_SPECIFIED][0]['value'] = '1.235';
    field_attach_insert('test_entity', $entity);

    // Attempt to update the field in a way that would work without data.
    $field['settings']['scale'] = 3;
    try {
      field_update_field($field);
      $this->fail(t('Cannot update field schema with data.'));
    }
    catch (FieldException $e) {
      $this->pass(t('Cannot update field schema with data.'));
    }
  }

  /**
   * Test that failure to create fields is handled gracefully.
   */
  function testFieldUpdateFailure() {
    // Create a text field.
    $field = array('field_name' => 'test_text', 'type' => 'text', 'settings' => array('max_length' => 255));
    $field = field_create_field($field);

    // Attempt to update the field in a way that would break the storage.
    $prior_field = $field;
    $field['settings']['max_length'] = -1;
    try {
      field_update_field($field);
      $this->fail(t('Update succeeded.'));
    }
    catch (Exception $e) {
      $this->pass(t('Update properly failed.'));
    }

    // Ensure that the field tables are still there.
    foreach (_field_sql_storage_schema($prior_field) as $table_name => $table_info) {
      $this->assertTrue(db_table_exists($table_name), t('Table %table exists.', array('%table' => $table_name)));
    }
  }

  /**
   * Test adding and removing indexes while data is present.
   */
  function testFieldUpdateIndexesWithData() {

    // Create a decimal field.
    $field_name = 'testfield';
    $field = array('field_name' => $field_name, 'type' => 'text');
    $field = field_create_field($field);
    $instance = array('field_name' => $field_name, 'entity_type' => 'test_entity', 'bundle' => 'test_bundle');
    $instance = field_create_instance($instance);
    $tables = array(_field_sql_storage_tablename($field), _field_sql_storage_revision_tablename($field));

    // Verify the indexes we will create do not exist yet.
    foreach ($tables as $table) {
      $this->assertFalse(Database::getConnection()->schema()->indexExists($table, 'value'), t("No index named value exists in $table"));
      $this->assertFalse(Database::getConnection()->schema()->indexExists($table, 'value_format'), t("No index named value_format exists in $table"));
    }

    // Add data so the table cannot be dropped.
    $entity = field_test_create_entity(0, 0, $instance['bundle']);
    $entity->{$field_name}[LANGUAGE_NOT_SPECIFIED][0]['value'] = 'field data';
    field_attach_insert('test_entity', $entity);

    // Add an index
    $field = array('field_name' => $field_name, 'indexes' => array('value' => array(array('value', 255))));
    field_update_field($field);
    foreach ($tables as $table) {
      $this->assertTrue(Database::getConnection()->schema()->indexExists($table, "{$field_name}_value"), t("Index on value created in $table"));
    }

    // Add a different index, removing the existing custom one.
    $field = array('field_name' => $field_name, 'indexes' => array('value_format' => array(array('value', 127), array('format', 127))));
    field_update_field($field);
    foreach ($tables as $table) {
      $this->assertTrue(Database::getConnection()->schema()->indexExists($table, "{$field_name}_value_format"), t("Index on value_format created in $table"));
      $this->assertFalse(Database::getConnection()->schema()->indexExists($table, "{$field_name}_value"), t("Index on value removed in $table"));
    }

    // Verify that the tables were not dropped.
    $entity = field_test_create_entity(0, 0, $instance['bundle']);
    field_attach_load('test_entity', array(0 => $entity));
    $this->assertEqual($entity->{$field_name}[LANGUAGE_NOT_SPECIFIED][0]['value'], 'field data', t("Index changes performed without dropping the tables"));
  }

  /**
   * Test the storage details.
   */
  function testFieldStorageDetails() {
    $current = _field_sql_storage_tablename($this->field);
    $revision = _field_sql_storage_revision_tablename($this->field);

    // Retrieve the field and instance with field_info so the storage details are attached.
    $field = field_info_field($this->field['field_name']);
    $instance = field_info_instance($this->instance['entity_type'], $this->instance['field_name'], $this->instance['bundle']);

    // The storage details are indexed by a storage engine type.
    $this->assertTrue(array_key_exists('sql', $field['storage']['details']), 'The storage type is SQL.');

    // The SQL details are indexed by table name.
    $details = $field['storage']['details']['sql'];
    $this->assertTrue(array_key_exists($current, $details[FIELD_LOAD_CURRENT]), 'Table name is available in the instance array.');
    $this->assertTrue(array_key_exists($revision, $details[FIELD_LOAD_REVISION]), 'Revision table name is available in the instance array.');

    // Test current and revision storage details together because the columns
    // are the same.
    foreach ((array) $this->field['columns'] as $column_name => $attributes) {
      $storage_column_name = _field_sql_storage_columnname($this->field['field_name'], $column_name);
      $this->assertEqual($details[FIELD_LOAD_CURRENT][$current][$column_name], $storage_column_name, t('Column name %value matches the definition in %bin.', array('%value' => $column_name, '%bin' => $current)));
      $this->assertEqual($details[FIELD_LOAD_REVISION][$revision][$column_name], $storage_column_name, t('Column name %value matches the definition in %bin.', array('%value' => $column_name, '%bin' => $revision)));
    }
  }

  /**
   * Test foreign key support.
   */
  function testFieldSqlStorageForeignKeys() {
    // Create a decimal field.
    $field_name = 'testfield';
    $field = array('field_name' => $field_name, 'type' => 'text');
    $field = field_create_field($field);
    // Retrieve the field and instance with field_info and verify the foreign
    // keys are in place.
    $field = field_info_field($field_name);
    $this->assertEqual($field['foreign keys']['format']['table'], 'filter_format', 'Foreign key table name preserved through CRUD');
    $this->assertEqual($field['foreign keys']['format']['columns']['format'], 'format', 'Foreign key column name preserved through CRUD');
    // Now grab the SQL schema and verify that too.
    $schema = drupal_get_schema(_field_sql_storage_tablename($field));
    $this->assertEqual(count($schema['foreign keys']), 1, t("There is 1 foreign key in the schema"));
    $foreign_key = reset($schema['foreign keys']);
    $filter_column = _field_sql_storage_columnname($field['field_name'], 'format');
    $this->assertEqual($foreign_key['table'], 'filter_format', 'Foreign key table name preserved in the schema');
    $this->assertEqual($foreign_key['columns'][$filter_column], 'format', 'Foreign key column name preserved in the schema');
  }
}
