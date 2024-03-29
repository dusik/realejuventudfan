<?php

/**
 * @file
 * Definition of Drupal\node\Tests\NodeQueryAlterTest.
 */

namespace Drupal\node\Tests;

use Exception;

/**
 * Tests node_query_node_access_alter().
 */
class NodeQueryAlterTest extends NodeTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('node_access_test');

  public static function getInfo() {
    return array(
      'name' => 'Node query alter',
      'description' => 'Test that node access queries are properly altered by the node module.',
      'group' => 'Node',
    );
  }

  /**
   * User with permission to view content.
   */
  protected $accessUser;

  /**
   * User without permission to view content.
   */
  protected $noAccessUser;

  function setUp() {
    parent::setUp();

    node_access_rebuild();

    // Create some content.
    $this->drupalCreateNode();
    $this->drupalCreateNode();
    $this->drupalCreateNode();
    $this->drupalCreateNode();

    // Create user with simple node access permission. The 'node test view'
    // permission is implemented and granted by the node_access_test module.
    $this->accessUser = $this->drupalCreateUser(array('access content overview', 'access content', 'node test view'));
    $this->noAccessUser = $this->drupalCreateUser(array('access content overview', 'access content'));
    $this->noAccessUser2 = $this->drupalCreateUser(array('access content overview', 'access content'));
  }

  /**
   * Tests that node access permissions are followed.
   */
  function testNodeQueryAlterWithUI() {
    // Verify that a user with access permission can see at least one node.
    $this->drupalLogin($this->accessUser);
    $this->drupalGet('node_access_test_page');
    $this->assertText('Yes, 4 nodes', "4 nodes were found for access user");
    $this->assertNoText('Exception', "No database exception");

    // Test the content overview page.
    $this->drupalGet('admin/content');
    $table_rows = $this->xpath('//tbody/tr');
    $this->assertEqual(4, count($table_rows), "4 nodes were found for access user");

    // Verify that a user with no access permission cannot see nodes.
    $this->drupalLogin($this->noAccessUser);
    $this->drupalGet('node_access_test_page');
    $this->assertText('No nodes', "No nodes were found for no access user");
    $this->assertNoText('Exception', "No database exception");

    $this->drupalGet('admin/content');
    $this->assertText(t('No content available.'));
  }

  /**
   * Lower-level test of 'node_access' query alter, for user with access.
   *
   * Verifies that a non-standard table alias can be used, and that a
   * user with node access can view the nodes.
   */
  function testNodeQueryAlterLowLevelWithAccess() {
    // User with access should be able to view 4 nodes.
    try {
      $query = db_select('node', 'mytab')
        ->fields('mytab');
      $query->addTag('node_access');
      $query->addMetaData('op', 'view');
      $query->addMetaData('account', $this->accessUser);

      $result = $query->execute()->fetchAll();
      $this->assertEqual(count($result), 4, 'User with access can see correct nodes');
    }
    catch (Exception $e) {
      $this->fail(t('Altered query is malformed'));
    }
  }

  /**
   * Lower-level test of 'node_access' query alter, for user without access.
   *
   * Verifies that a non-standard table alias can be used, and that a
   * user without node access cannot view the nodes.
   */
  function testNodeQueryAlterLowLevelNoAccess() {
    // User without access should be able to view 0 nodes.
    try {
      $query = db_select('node', 'mytab')
        ->fields('mytab');
      $query->addTag('node_access');
      $query->addMetaData('op', 'view');
      $query->addMetaData('account', $this->noAccessUser);

      $result = $query->execute()->fetchAll();
      $this->assertEqual(count($result), 0, 'User with no access cannot see nodes');
    }
    catch (Exception $e) {
      $this->fail(t('Altered query is malformed'));
    }
  }

  /**
   * Lower-level test of 'node_access' query alter, for edit access.
   *
   * Verifies that a non-standard table alias can be used, and that a
   * user with view-only node access cannot edit the nodes.
   */
  function testNodeQueryAlterLowLevelEditAccess() {
    // User with view-only access should not be able to edit nodes.
    try {
      $query = db_select('node', 'mytab')
        ->fields('mytab');
      $query->addTag('node_access');
      $query->addMetaData('op', 'update');
      $query->addMetaData('account', $this->accessUser);

      $result = $query->execute()->fetchAll();
      $this->assertEqual(count($result), 0, 'User with view-only access cannot edit nodes');
    }
    catch (Exception $e) {
      $this->fail($e->getMessage());
      $this->fail((string) $query);
      $this->fail(t('Altered query is malformed'));
    }
  }

  /**
   * Lower-level test of 'node_access' query alter override.
   *
   * Verifies that node_access_view_all_nodes() is called from
   * node_query_node_access_alter().  We do this by checking that
   * a user which normally would not have view privileges is able
   * to view the nodes when we add a record to {node_access} paired
   * with a corresponding privilege in hook_node_grants().
   */
  function testNodeQueryAlterOverride() {
    $record = array(
      'nid' => 0,
      'gid' => 0,
      'realm' => 'node_access_all',
      'grant_view' => 1,
      'grant_update' => 0,
      'grant_delete' => 0,
    );
    drupal_write_record('node_access', $record);

    // Test that the noAccessUser still doesn't have the 'view'
    // privilege after adding the node_access record.
    drupal_static_reset('node_access_view_all_nodes');
    try {
      $query = db_select('node', 'mytab')
        ->fields('mytab');
      $query->addTag('node_access');
      $query->addMetaData('op', 'view');
      $query->addMetaData('account', $this->noAccessUser);

      $result = $query->execute()->fetchAll();
      $this->assertEqual(count($result), 0, 'User view privileges are not overridden');
    }
    catch (Exception $e) {
      $this->fail(t('Altered query is malformed'));
    }

    // Have node_test_node_grants return a node_access_all privilege,
    // to grant the noAccessUser 'view' access.  To verify that
    // node_access_view_all_nodes is properly checking the specified
    // $account instead of the global $user, we will log in as
    // noAccessUser2.
    $this->drupalLogin($this->noAccessUser2);
    variable_set('node_test_node_access_all_uid', $this->noAccessUser->uid);
    drupal_static_reset('node_access_view_all_nodes');
    try {
      $query = db_select('node', 'mytab')
        ->fields('mytab');
      $query->addTag('node_access');
      $query->addMetaData('op', 'view');
      $query->addMetaData('account', $this->noAccessUser);

      $result = $query->execute()->fetchAll();
      $this->assertEqual(count($result), 4, 'User view privileges are overridden');
    }
    catch (Exception $e) {
      $this->fail(t('Altered query is malformed'));
    }
    variable_del('node_test_node_access_all_uid');
  }
}
