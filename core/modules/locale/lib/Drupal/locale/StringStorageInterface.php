<?php

/**
 * @file
 * Definition of Drupal\locale\StringStorageInterface.
 */

namespace Drupal\locale;

/**
 * Defines the locale string storage interface.
 */
interface StringStorageInterface {

  /**
   * Loads multiple source string objects.
   *
   * @param array $conditions
   *   (optional) Array with conditions that will be used to filter the strings
   *   returned and may include any of the following elements:
   *   - Any simple field value indexed by field name.
   *   - 'translated', TRUE to get only translated strings or FALSE to get only
   *     untranslated strings. If not set it returns both translated and
   *     untranslated strings that fit the other conditions.
   *   Defaults to no conditions which means that it will load all strings.
   * @param array $options
   *   (optional) An associative array of additional options. It may contain
   *   any of the following optional keys:
   *   - 'filters': Array of string filters indexed by field name.
   *   - 'pager limit': Use pager and set this limit value.
   *
   * @return array
   *   Array of Drupal\locale\StringInterface objects matching the conditions.
   */
  public function getStrings(array $conditions = array(), array $options = array());

  /**
   * Loads multiple string translation objects.
   *
   * @see Drupal\locale\StringStorageInterface::getStrings()
   *
   * @param array $conditions
   *   (optional) Array with conditions that will be used to filter the strings
   *   returned and may include all of the conditions defined by getStrings().
   * @param array $options
   *   (optional) An associative array of additional options. It may contain
   *   any of the options defined by getStrings().
   *
   * @return array
   *   Array of Drupal\locale\StringInterface objects matching the conditions.
  */
  public function getTranslations(array $conditions = array(), array $options = array());
  /**
   * Loads a string source object, fast query.
   *
   * These 'fast query' methods are the ones in the critical path and their
   * implementation must be optimized for speed, as they may run many times
   * in a single page request.
   *
   * @param array $conditions
   *   (optional) Array with conditions that will be used to filter the strings
   *   returned and may include all of the conditions defined by getStrings().
   *
   * @return Drupal\locale\SourceString|null
   *   Minimal TranslationString object if found, NULL otherwise.
   */
  public function findString(array $conditions);

  /**
   * Loads a string translation object, fast query.
   *
   * @param array $conditions
   *   (optional) Array with conditions that will be used to filter the strings
   *   returned and may include all of the conditions defined by getStrings().
   *
   * @return Drupal\locale\TranslationString|null
   *   Minimal TranslationString object if found, NULL otherwise.
   */
  public function findTranslation(array $conditions);

  /**
   * Checks whether the string version matches a given version, fix it if not.
   *
   * @param Drupal\locale\StringInterface $string
   *   The string object.
   * @param string $version
   *   Drupal version to check against.
   */
  public function checkVersion($string, $version);

  /**
   * Save string object to storage.
   *
   * @param Drupal\locale\StringInterface $string
   *   The string object.
   *
   * @return Drupal\locale\StringStorageInterface
   *   The called object.
   *
   * @throws Drupal\locale\StringStorageException
   *   In case of failures, an exception is thrown.
   */
  public function save($string);

  /**
   * Delete string from storage.
   *
   * @param Drupal\locale\StringInterface $string
   *   The string object.
   *
   * @return Drupal\locale\StringStorageInterface
   *   The called object.
   *
   * @throws Drupal\locale\StringStorageException
   *   In case of failures, an exception is thrown.
   */
  public function delete($string);

  /**
   * Deletes source strings and translations using conditions.
   *
   * @param array $conditions
   *   Array with simple field conditions for source strings.
   */
  public function deleteStrings($conditions);

  /**
   * Deletes translations using conditions.
   *
   * @param array $conditions
   *   Array with simple field conditions for string translations.
   */
  public function deleteTranslations($conditions);

  /**
   * Counts source strings.
   *
   * @return int
   *   The number of source strings contained in the storage.
   */
  public function countStrings();

  /**
   * Counts translations.
   *
   * @return array
   *   The number of translations for each language indexed by language code.
   */
  public function countTranslations();

  /**
   * Creates a source string object bound to this storage but not saved.
   *
   * @param array $values
   *   (optional) Array with initial values. Defaults to empty array.
   *
   * @return Drupal\locale\SourceString
   *   New source string object.
   */
  public function createString($values = array());

  /**
   * Creates a string translation object bound to this storage but not saved.
   *
   * @param array $values
   *   (optional) Array with initial values. Defaults to empty array.
   *
   * @return Drupal\locale\TranslationString
   *   New string translation object.
   */
  public function createTranslation($values = array());
}
