<?php

/**
 * @file
 * Definition of Drupal\locale\StringDatabaseStorage.
 */

namespace Drupal\locale;

use Drupal\Core\Database\Database;
use Drupal\Core\Database\Connection;
use PDO;

/**
 * Defines the locale string class.
 *
 * This is the base class for SourceString and TranslationString.
 */
class StringDatabaseStorage implements StringStorageInterface {

  /**
   * The database connection.
   *
   * @var Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * Additional database connection options to use in queries.
   *
   * @var array
   */
  protected $options = array();

  /**
   * Constructs a new StringStorage controller.
   *
   * @param Drupal\Core\Database\Connection $connection
   *   A Database connection to use for reading and writing configuration data.
   * @param array $options
   *   (optional) Any additional database connection options to use in queries.
   */
  public function __construct(Connection $connection, array $options = array()) {
    $this->connection = $connection;
    $this->options = $options;
  }

  /**
   * Implements Drupal\locale\StringStorageInterface::getStrings().
   */
  public function getStrings(array $conditions = array(), array $options = array()) {
    return $this->dbStringLoad($conditions, $options, 'Drupal\locale\SourceString');
  }

  /**
   * Implements Drupal\locale\StringStorageInterface::getTranslations().
   */
  public function getTranslations(array $conditions = array(), array $options = array()) {
    return $this->dbStringLoad($conditions, array('translation' => TRUE) + $options, 'Drupal\locale\TranslationString');
  }

  /**
   * Implements Drupal\locale\StringStorageInterface::findString().
   */
  public function findString(array $conditions) {
    $string = $this->dbStringSelect($conditions)
    ->execute()
    ->fetchObject('Drupal\locale\SourceString');
    if ($string) {
      $string->setStorage($this);
    }
    return $string;
  }

  /**
   * Implements Drupal\locale\StringStorageInterface::findTranslation().
   */
  public function findTranslation(array $conditions) {
    $string = $this->dbStringSelect($conditions, array('translation' => TRUE))
    ->execute()
    ->fetchObject('Drupal\locale\TranslationString');
    if ($string) {
      $string->setStorage($this);
    }
    return $string;
  }

  /**
   * Implements Drupal\locale\StringStorageInterface::countStrings().
   */
  public function countStrings() {
    return $this->dbExecute("SELECT COUNT(*) FROM {locales_source}")->fetchField();
  }

  /**
   * Implements Drupal\locale\StringStorageInterface::countTranslations().
   */
  public function countTranslations() {
    return $this->dbExecute("SELECT t.language, COUNT(*) AS translated FROM {locales_source} s INNER JOIN {locales_target} t ON s.lid = t.lid GROUP BY t.language")->fetchAllKeyed();
  }

  /**
   * Implements Drupal\locale\StringStorageInterface::checkVersion().
   */
  public function checkVersion($string, $version) {
    if ($string->getId() && $string->getVersion() != $version) {
      $string->setVersion($version);
      $this->connection->update('locales_source', $this->options)
        ->condition('lid', $string->getId())
        ->fields(array('version' => $version))
        ->execute();
    }
  }

  /**
   * Implements Drupal\locale\StringStorageInterface::save().
   */
  public function save($string) {
    if ($string->isNew()) {
      $result = $this->dbStringInsert($string);
      if ($string->isSource() && $result) {
        // Only for source strings, we set the locale identifier.
        $string->setId($result);
      }
      $string->setStorage($this);
    }
    else {
      $this->dbStringUpdate($string);
    }
    return $this;
  }

  /**
   * Implements Drupal\locale\StringStorageInterface::delete().
   */
  public function delete($string) {
    if ($keys = $this->dbStringKeys($string)) {
      $this->dbDelete('locales_target', $keys)->execute();
      if ($string->isSource()) {
        $this->dbDelete('locales_source', $keys)->execute();
        $string->setId(NULL);
      }
    }
    else {
      throw new StringStorageException(format_string('The string cannot be deleted because it lacks some key fields: @string', array(
        '@string' => $string->getString()
      )));
    }
    return $this;
  }

  /**
   * Implements Drupal\locale\StringStorageInterface::deleteLanguage().
   */
  public function deleteStrings($conditions) {
    $lids = $this->dbStringSelect($conditions, array('fields' => array('lid')))->execute()->fetchCol();
    if ($lids) {
      $this->dbDelete('locales_target', array('lid' => $lids))->execute();
      $this->dbDelete('locales_source',  array('lid' => $lids))->execute();
    }
  }

  /**
   * Implements Drupal\locale\StringStorageInterface::deleteLanguage().
   */
  public function deleteTranslations($conditions) {
    $this->dbDelete('locales_target', $conditions)->execute();
  }

  /**
   * Implements Drupal\locale\StringStorageInterface::createString().
   */
  public function createString($values = array()) {
    return new SourceString($values + array('storage' => $this));
  }

  /**
   * Implements Drupal\locale\StringStorageInterface::createTranslation().
   */
  public function createTranslation($values = array()) {
    return new TranslationString($values + array(
      'storage' => $this,
      'is_new' => TRUE
    ));
  }

  /**
   * Gets table alias for field.
   *
   * @param string $field
   *   Field name to find the table alias for.
   *
   * @return string
   *   Either 's' or 't' depending on whether the field belongs to source or
   *   target table.
   */
  protected function dbFieldTable($field) {
    return in_array($field, array('language', 'translation', 'customized')) ? 't' : 's';
  }

  /**
   * Gets table name for storing string object.
   *
   * @param Drupal\locale\StringInterface $string
   *   The string object.
   *
   * @return string
   *   The table name.
   */
  protected function dbStringTable($string) {
    if ($string->isSource()) {
      return 'locales_source';
    }
    elseif ($string->isTranslation()) {
      return 'locales_target';
    }
  }

  /**
   * Gets keys values that are in a database table.
   *
   * @param Drupal\locale\StringInterface $string
   *   The string object.
   * @param string $table
   *   (optional) The table name.
   *
   * @return array
   *   Array with key fields if the string has all keys, or empty array if not.
   */
  protected function dbStringKeys($string, $table = NULL) {
    $table = $table ? $table : $this->dbStringTable($string);
    if ($table && $schema = drupal_get_schema($table)) {
      $keys = $schema['primary key'];
      $values = $string->getValues($keys);
      if (count($values) == count($keys)) {
        return $values;
      }
    }
    return NULL;
  }

  /**
   * Gets field values from a string object that are in the database table.
   *
   * @param Drupal\locale\StringInterface $string
   *   The string object.
   * @param string $table
   *   (optional) The table name.
   *
   * @return array
   *   Array with field values indexed by field name.
   */
  protected function dbStringValues($string, $table = NULL) {
    $table = $table ? $table : $this->dbStringTable($string);
    if ($table && $schema = drupal_get_schema($table)) {
      $fields = array_keys($schema['fields']);
      return $string->getValues($fields);
    }
    else {
      return array();
    }
  }

  /**
   * Sets default values from storage.
   *
   * @param Drupal\locale\StringInterface $string
   *   The string object.
   * @param string $table
   *   (optional) The table name.
   */
  protected function dbStringDefaults($string, $table = NULL) {
    $table = $table ? $table : $this->dbStringTable($string);
    if ($table && $schema = drupal_get_schema($table)) {
      $values = array();
      foreach ($schema['fields'] as $name => $info) {
        if (isset($info['default'])) {
          $values[$name] = $info['default'];
        }
      }
      $string->setValues($values, FALSE);
    }
  }

  /**
   * Loads multiple string objects.
   *
   * @param array $conditions
   *   Any of the conditions used by dbStringSelect().
   * @param array $options
   *   Any of the options used by dbStringSelect().
   * @param string $class
   *   Class name to use for fetching returned objects.
   *
   * @return array
   *   Array of objects of the class requested.
   */
  protected function dbStringLoad(array $conditions, array $options, $class) {
    $result = $this->dbStringSelect($conditions, $options)->execute();
    $result->setFetchMode(PDO::FETCH_CLASS, $class);
    $strings = $result->fetchAll();
    foreach ($strings as $string) {
      $string->setStorage($this);
    }
    return $strings;
  }

  /**
   * Builds a SELECT query with multiple conditions and fields.
   *
   * The query uses both 'locales_source' and 'locales_target' tables.
   * Note that by default, as we are selecting both translated and untranslated
   * strings target field's conditions will be modified to match NULL rows too.
   *
   * @param array $conditions
   *   An associative array with field => value conditions that may include
   *   NULL values. If a language condition is included it will be used for
   *   joining the 'locales_target' table.
   * @param array $options
   *   An associative array of additional options. It may contain any of the
   *   options used by Drupal\locale\StringStorageInterface::getStrings() and
   *   these additional ones:
   *   - 'translation', Whether to include translation fields too. Defaults to
   *     FALSE.
   * @return SelectQuery
   *   Query object with all the tables, fields and conditions.
   */
  protected function dbStringSelect(array $conditions, array $options = array()) {
    // Start building the query with source table and check whether we need to
    // join the target table too.
    $query = $this->connection->select('locales_source', 's', $this->options)
      ->fields('s');

    // Figure out how to join and translate some options into conditions.
    if (isset($conditions['translated'])) {
      // This is a meta-condition we need to translate into simple ones.
      if ($conditions['translated']) {
        // Select only translated strings.
        $join = 'innerJoin';
      }
      else {
        // Select only untranslated strings.
        $join = 'leftJoin';
        $conditions['translation'] = NULL;
      }
      unset($conditions['translated']);
    }
    else {
      $join = !empty($options['translation']) ? 'leftJoin' : FALSE;
    }

    if ($join) {
      if (isset($conditions['language'])) {
        // If we've got a language condition, we use it for the join.
        $query->$join('locales_target', 't', "t.lid = s.lid AND t.language = :langcode", array(
          ':langcode' => $conditions['language']
        ));
        unset($conditions['language']);
      }
      else {
        // Since we don't have a language, join with locale id only.
        $query->$join('locales_target', 't', "t.lid = s.lid");
      }
      if (!empty($options['translation'])) {
        // We cannot just add all fields because 'lid' may get null values.
        $query->fields('t', array('language', 'translation', 'customized'));
      }
    }

    // Add conditions for both tables.
    foreach ($conditions as $field => $value) {
      $table_alias = $this->dbFieldTable($field);
      $field_alias = $table_alias . '.' . $field;
      if (is_null($value)) {
        $query->isNull($field_alias);
      }
      elseif ($table_alias == 't' && $join === 'leftJoin') {
        // Conditions for target fields when doing an outer join only make
        // sense if we add also OR field IS NULL.
        $query->condition(db_or()
            ->condition($field_alias, $value)
            ->isNull($field_alias)
        );
      }
      else {
        $query->condition($field_alias, $value);
      }
    }

    // Process other options, string filter, query limit, etc...
    if (!empty($options['filters'])) {
      if (count($options['filters']) > 1) {
        $filter = db_or();
        $query->condition($filter);
      }
      else {
        // If we have a single filter, just add it to the query.
        $filter = $query;
      }
      foreach ($options['filters'] as $field => $string) {
        $filter->condition($this->dbFieldTable($field) . '.' . $field, '%' . db_like($string) . '%', 'LIKE');
      }
    }

    if (!empty($options['pager limit'])) {
      $query = $query->extend('Drupal\Core\Database\Query\PagerSelectExtender')->limit($options['pager limit']);
    }

    return $query;
  }

  /**
   * Createds a database record for a string object.
   *
   * @param Drupal\locale\StringInterface $string
   *   The string object.
   *
   * @return bool|int
   *   If the operation failed, returns FALSE.
   *   If it succeeded returns the last insert ID of the query, if one exists.
   *
   * @throws Drupal\locale\StringStorageException
   *   If the string is not suitable for this storage, an exception ithrown.
   */
  protected function dbStringInsert($string) {
    if (($table = $this->dbStringTable($string)) && ($fields = $this->dbStringValues($string, $table))) {
      $this->dbStringDefaults($string, $table);
      return $this->connection->insert($table, $this->options)
        ->fields($fields)
        ->execute();
    }
    else {
      throw new StringStorageException(format_string('The string cannot be saved: @string', array(
          '@string' => $string->getString()
      )));
    }
  }

  /**
   * Updates string object in the database.
   *
   * @param Drupal\locale\StringInterface $string
   *   The string object.
   *
   * @return bool|int
   *   If the record update failed, returns FALSE. If it succeeded, returns
   *   SAVED_NEW or SAVED_UPDATED.
   *
   * @throws Drupal\locale\StringStorageException
   *   If the string is not suitable for this storage, an exception is thrown.
   */
  protected function dbStringUpdate($string) {
    if (($table = $this->dbStringTable($string)) && ($keys = $this->dbStringKeys($string, $table)) &&
        ($fields = $this->dbStringValues($string, $table)) && ($values = array_diff_key($fields, $keys)))
    {
      return $this->connection->merge($table, $this->options)
      ->key($keys)
      ->fields($values)
      ->execute();
    }
    else {
      throw new StringStorageException(format_string('The string cannot be updated: @string', array(
          '@string' => $string->getString()
      )));
    }
  }

  /**
   * Creates delete query.
   *
   * @param string $table
   *   The table name.
   * @param array $keys
   *   Array with object keys indexed by field name.
   *
   * @return DeleteQuery
   *   Returns a new DeleteQuery object for the active database.
   */
  protected function dbDelete($table, $keys) {
    $query = $this->connection->delete($table, $this->options);
    foreach ($keys as $field => $value) {
      $query->condition($field, $value);
    }
    return $query;
  }

  /**
   * Executes an arbitrary SELECT query string.
   */
  protected function dbExecute($query, array $args = array()) {
    return $this->connection->query($query, $args, $this->options);
  }
}
