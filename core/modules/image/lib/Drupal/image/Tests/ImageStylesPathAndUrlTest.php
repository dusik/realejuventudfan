<?php

/**
 * @file
 * Definition of Drupal\image\Tests\ImageStylesPathAndUrlTest.
 */

namespace Drupal\image\Tests;

use Drupal\simpletest\WebTestBase;

/**
 * Tests the functions for generating paths and URLs for image styles.
 */
class ImageStylesPathAndUrlTest extends WebTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('image', 'image_module_test');

  protected $style_name;
  protected $image_info;
  protected $image_filepath;

  public static function getInfo() {
    return array(
      'name' => 'Image styles path and URL functions',
      'description' => 'Tests functions for generating paths and URLs to image styles.',
      'group' => 'Image',
    );
  }

  function setUp() {
    parent::setUp();

    $this->style_name = 'style_foo';
    image_style_save(array('name' => $this->style_name, 'label' => $this->randomString()));
  }

  /**
   * Test image_style_path().
   */
  function testImageStylePath() {
    $scheme = 'public';
    $actual = image_style_path($this->style_name, "$scheme://foo/bar.gif");
    $expected = "$scheme://styles/" . $this->style_name . "/$scheme/foo/bar.gif";
    $this->assertEqual($actual, $expected, 'Got the path for a file URI.');

    $actual = image_style_path($this->style_name, 'foo/bar.gif');
    $expected = "$scheme://styles/" . $this->style_name . "/$scheme/foo/bar.gif";
    $this->assertEqual($actual, $expected, 'Got the path for a relative file path.');
  }

  /**
   * Test image_style_url() with a file using the "public://" scheme.
   */
  function testImageStyleUrlAndPathPublic() {
    $this->_testImageStyleUrlAndPath('public');
  }

  /**
   * Test image_style_url() with a file using the "private://" scheme.
   */
  function testImageStyleUrlAndPathPrivate() {
    $this->_testImageStyleUrlAndPath('private');
  }

  /**
   * Test image_style_url() with the "public://" scheme and unclean URLs.
   */
   function testImageStylUrlAndPathPublicUnclean() {
     $this->_testImageStyleUrlAndPath('public', FALSE);
   }

  /**
   * Test image_style_url() with the "private://" schema and unclean URLs.
   */
  function testImageStyleUrlAndPathPrivateUnclean() {
    $this->_testImageStyleUrlAndPath('private', FALSE);
  }

  /**
   * Test image_style_url().
   */
  function _testImageStyleUrlAndPath($scheme, $clean_url = TRUE) {
    $script_path_original = $GLOBALS['script_path'];
    $GLOBALS['script_path'] = $clean_url ? '' : 'index.php/';

    // Make the default scheme neither "public" nor "private" to verify the
    // functions work for other than the default scheme.
    variable_set('file_default_scheme', 'temporary');

    // Create the directories for the styles.
    $directory = $scheme . '://styles/' . $this->style_name;
    $status = file_prepare_directory($directory, FILE_CREATE_DIRECTORY);
    $this->assertNotIdentical(FALSE, $status, 'Created the directory for the generated images for the test style.');

    // Create a working copy of the file.
    $files = $this->drupalGetTestFiles('image');
    $file = array_shift($files);
    $image_info = image_get_info($file->uri);
    $original_uri = file_unmanaged_copy($file->uri, $scheme . '://', FILE_EXISTS_RENAME);
    // Let the image_module_test module know about this file, so it can claim
    // ownership in hook_file_download().
    variable_set('image_module_test_file_download', $original_uri);
    $this->assertNotIdentical(FALSE, $original_uri, 'Created the generated image file.');

    // Get the URL of a file that has not been generated and try to create it.
    $generated_uri = $scheme . '://styles/' . $this->style_name . '/' . $scheme . '/'. drupal_basename($original_uri);
    $this->assertFalse(file_exists($generated_uri), 'Generated file does not exist.');
    $generate_url = image_style_url($this->style_name, $original_uri);

    if ($GLOBALS['script_path']) {
      $this->assertTrue(strpos($generate_url, $GLOBALS['script_path']) !== FALSE, 'When using non-clean URLS, the system path contains the script name.');
    }

    // Fetch the URL that generates the file.
    $this->drupalGet($generate_url);
    $this->assertResponse(200, 'Image was generated at the URL.');
    $this->assertTrue(file_exists($generated_uri), 'Generated file does exist after we accessed it.');
    $this->assertRaw(file_get_contents($generated_uri), 'URL returns expected file.');
    $generated_image_info = image_get_info($generated_uri);
    $this->assertEqual($this->drupalGetHeader('Content-Type'), $generated_image_info['mime_type'], 'Expected Content-Type was reported.');
    $this->assertEqual($this->drupalGetHeader('Content-Length'), $generated_image_info['file_size'], 'Expected Content-Length was reported.');
    if ($scheme == 'private') {
      $this->assertEqual($this->drupalGetHeader('Expires'), 'Sun, 19 Nov 1978 05:00:00 GMT', 'Expires header was sent.');
      $this->assertEqual($this->drupalGetHeader('Cache-Control'), 'no-cache, private', 'Cache-Control header was set to prevent caching.');
      $this->assertEqual($this->drupalGetHeader('X-Image-Owned-By'), 'image_module_test', 'Expected custom header has been added.');

      // Make sure that a second request to the already existing derivate works
      // too.
      $this->drupalGet($generate_url);
      $this->assertResponse(200, 'Image was generated at the URL.');

      // Repeat this with a different file that we do not have access to and
      // make sure that access is denied.
      $file_noaccess = array_shift($files);
      $original_uri_noaccess = file_unmanaged_copy($file_noaccess->uri, $scheme . '://', FILE_EXISTS_RENAME);
      $generated_uri_noaccess = $scheme . '://styles/' . $this->style_name . '/' . $scheme . '/'. drupal_basename($original_uri_noaccess);
      $this->assertFalse(file_exists($generated_uri_noaccess), 'Generated file does not exist.');
      $generate_url_noaccess = image_style_url($this->style_name, $original_uri_noaccess);

      $this->drupalGet($generate_url_noaccess);
      $this->assertResponse(403, 'Confirmed that access is denied for the private image style.');
      // Verify that images are not appended to the response. Currently this test only uses PNG images.
      if (strpos($generate_url, '.png') === FALSE ) {
        $this->fail('Confirming that private image styles are not appended require PNG file.');
      }
      else {
        // Check for PNG-Signature (cf. http://www.libpng.org/pub/png/book/chapter08.html#png.ch08.div.2) in the
        // response body.
        $this->assertNoRaw( chr(137) . chr(80) . chr(78) . chr(71) . chr(13) . chr(10) . chr(26) . chr(10), 'No PNG signature found in the response body.');
      }
    }

    $GLOBALS['script_path'] = $script_path_original;
  }
}
