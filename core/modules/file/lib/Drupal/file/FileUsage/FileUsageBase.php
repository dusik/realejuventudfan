<?php

/**
 * @file
 * Definition of Drupal\file\FileUsage\FileUsageBase.
 */

namespace Drupal\file\FileUsage;

use Drupal\file\File;

/**
 * Defines the base class for database file usage backend.
 */
abstract class FileUsageBase implements FileUsageInterface {

  /**
   * Implements Drupal\file\FileUsage\FileUsageInterface::add().
   */
  public function add(File $file, $module, $type, $id, $count = 1) {
    // Make sure that a used file is permament.
    if ($file->status != FILE_STATUS_PERMANENT) {
      $file->status = FILE_STATUS_PERMANENT;
      $file->save();
    }
  }

  /**
   * Implements Drupal\file\FileUsage\FileUsageInterface::delete().
   */
  public function delete(File $file, $module, $type = NULL, $id = NULL, $count = 1) {
    // If there are no more remaining usages of this file, mark it as temporary,
    // which result in a delete through system_cron().
    $usage = file_usage()->listUsage($file);
    if (empty($usage)) {
      $file->status = 0;
      $file->save();
    }
  }
}
