<?php

/**
 * @file
 * Definition of Drupal\system\Tests\Bundle\BundleTest.
 */

namespace Drupal\system\Tests\Bundle;

use Drupal\simpletest\WebTestBase;

/**
 * Tests bundle registration to the DIC.
 */
class BundleTest extends WebTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('bundle_test');

  public static function getInfo() {
    return array(
      'name' => 'Bundle Registration',
      'description' => 'Tests bundle registration to the DIC.',
      'group' => 'Bundle',
    );
  }

  /**
   * Tests that services provided by module bundles get registered to the DIC.
   */
  function testBundleRegistration() {
    $this->assertTrue(drupal_container()->getDefinition('file.usage')->getClass() == 'Drupal\\bundle_test\\TestFileUsage', 'Class has been changed');
    $this->assertTrue(drupal_container()->has('bundle_test_class'), 'The bundle_test_class service has been registered to the DIC');
    // The event subscriber method in the test class calls drupal_set_message with
    // a message saying it has fired. This will fire on every page request so it
    // should show up on the front page.
    $this->drupalGet('');
    $this->assertText(t('The bundle_test event subscriber fired!'), 'The bundle_test event subscriber fired');
  }
}
