<?php

/**
 * @file
 * Definition of Drupal\layout\Tests\LayoutDerivativesTest.
 */

namespace Drupal\layout\Tests;

use Drupal\simpletest\WebTestBase;

/**
 * Tests the layout system derivatives.
 */
class LayoutDerivativesTest extends WebTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('layout', 'layout_test');

  public static function getInfo() {
    return array(
      'name' => 'Layout derivatives',
      'description' => 'Tests layout derivatives discovery.',
      'group' => 'Layout',
    );
  }

  /**
   * Tests for module/theme layout derivatives.
   */
  function testDerivatives() {
    $manager = drupal_container()->get('plugin.manager.layout');

    $definitions = $manager->getDefinitions();
    $this->assertTrue(is_array($definitions), 'Definitions found.');
    $this->assertTrue(count($definitions) == 2, 'Two definitions available.');
    $this->assertTrue(isset($definitions['static_layout:layout_test__one-col']), 'One column layout found.');
    $this->assertTrue(isset($definitions['static_layout:layout_test_theme__two-col']), 'Two column layout found.');

    // Get a one column layout instance. This is defined under the layout_test
    // module.
    $layout = $manager->createInstance('static_layout:layout_test__one-col', array());
    // Verify the expected regions are properly available.
    $regions = $layout->getRegions();
    $this->assertTrue(is_array($regions), 'Regions array present.');
    $this->assertTrue(count($regions) == 1, 'One region defined.');
    $this->assertTrue(isset($regions['middle']), 'Middle region found.');

    // Render the layout and look at whether expected region names and classes
    // were in the output.
    $render = $layout->renderLayout();
    $this->drupalSetContent($render);
    $this->assertText('Middle column');
    $this->assertRaw('class="layout-display layout-one-col');

    // Get the two column page layout defined by the layout test theme.
    $layout = $manager->createInstance('static_layout:layout_test_theme__two-col', array());
    // Verify the expected regions are properly available.
    $regions = $layout->getRegions();
    $this->assertTrue(is_array($regions), 'Regions array present.');
    $this->assertTrue(count($regions) == 2, 'Two regions defined.');
    $this->assertTrue(isset($regions['left']), 'Left region found.');
    $this->assertTrue(isset($regions['right']), 'Right region found.');

    // Render the layout and look at whether expected region names and classes
    // were in the output.
    $render = $layout->renderLayout();
    $this->drupalSetContent($render);
    $this->assertText('Left side');
    $this->assertText('Right side');
    $this->assertRaw('<div class="layout-region layout-col-right">');
  }

  /**
   * Test layout functionality as applies to pages.
   */
  function testPageLayout() {
    // The layout-test page uses the layout_test_theme page layout.
    $this->drupalGet('layout-test');
    $this->assertText('Left side');
    $this->assertText('Right side');
    $this->assertRaw('<div class="layout-region layout-col-right">');

    // Ensure the CSS was added.
    $this->assertRaw('@import url("' . url('', array('absolute' => TRUE)) . drupal_get_path('theme', 'layout_test_theme') . '/layouts/static/two-col/two-col.css');
  }
}
