<?php

/**
 * @file
 * Administration functions for language.module.
 */

use Drupal\Core\Language\Language;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * User interface for the language overview screen.
 */
function language_admin_overview_form($form, &$form_state) {
  drupal_static_reset('language_list');
  $languages = language_list(LANGUAGE_ALL);
  $default = language_default();

  $form['languages'] = array(
    '#languages' => $languages,
    '#language_default' => $default,
    '#tree' => TRUE,
    '#header' => array(
      t('Name'),
      t('Default'),
      t('Weight'),
      t('Operations'),
    ),
    '#theme' => 'language_admin_overview_form_table',
  );

  foreach ($languages as $langcode => $language) {
    $form['languages'][$langcode]['#weight'] = $language->weight;
    $title = check_plain($language->name);
    $description = '';
    switch ($langcode) {
      case LANGUAGE_NOT_APPLICABLE:
        $description = t('For language independent content.');
        break;
      case LANGUAGE_NOT_SPECIFIED:
        $description = t('Use this when the language is not (yet) known.');
        break;
      case LANGUAGE_MULTIPLE:
        $description = t('Use this when multiple languages can be assigned, such as a multilingual PDF.');
        break;
    }
    if (!empty($description)) {
      $title .= '<div class="description">' . $description . '</div>';
    }
    $form['languages'][$langcode]['name'] = array(
      '#markup' => $title,
    );
    $form['languages'][$langcode]['default'] = array(
      '#type' => 'radio',
      '#parents' => array('site_default'),
      '#title' => t('Set @title as default', array('@title' => $language->name)),
      '#title_display' => 'invisible',
      '#return_value' => $langcode,
      '#default_value' => ($langcode == $default->langcode ? $langcode : NULL),
      '#id' => 'edit-site-default-' . $langcode,
    );
    $form['languages'][$langcode]['weight'] = array(
      '#type' => 'weight',
      '#title' => t('Weight for @title', array('@title' => $language->name)),
      '#title_display' => 'invisible',
      '#default_value' => $language->weight,
      '#attributes' => array(
        'class' => array('language-order-weight'),
      ),
      '#delta' => 30,
    );
    $links = array();
    if (empty($language->locked)) {
      $links['edit'] = array(
        'title' => t('edit'),
        'href' => 'admin/config/regional/language/edit/' . $langcode,
      );
      if ($langcode != $default->langcode) {
        $links['delete'] = array(
          'title' => t('delete'),
          'href' => 'admin/config/regional/language/delete/' . $langcode,
        );
      }
    }
    else {
      $form['languages'][$langcode]['default']['#attributes']['disabled'] = 'disabled';
    }
    $form['languages'][$langcode]['operations'] = array(
      '#type' => 'operations',
      '#links' => $links,
      '#weight' => 100,
    );
  }

  $form['actions'] = array('#type' => 'actions');
  $form['actions']['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Save configuration'),
  );

  return $form;
}

/**
 * Returns HTML for the language overview form.
 *
 * @param $variables
 *   An associative array containing:
 *   - form: A render element representing the form.
 *
 * @ingroup themeable
 */
function theme_language_admin_overview_form_table($variables) {
  $form = $variables['form'];

  $rows = array();
  foreach (element_children($form, TRUE) as $langcode) {
    $element = &$form[$langcode];
    $row = array(
      'class' => array('draggable'),
    );
    foreach (element_children($element, TRUE) as $column) {
      $cell = &$element[$column];
      $row['data'][] = drupal_render($cell);
    }
    $rows[] = $row;
  }

  $output = theme('table', array(
    'header' => $form['#header'],
    'rows' => $rows,
    'attributes' => array('id' => 'language-order'),
  ));
  $output .= drupal_render_children($form);

  drupal_add_tabledrag('language-order', 'order', 'sibling', 'language-order-weight');

  return $output;
}

/**
 * Process language overview form submissions, updating existing languages.
 */
function language_admin_overview_form_submit($form, &$form_state) {
  $languages = language_list(LANGUAGE_ALL);
  $old_default = language_default();

  foreach ($languages as $langcode => $language) {
    $language->default = ($form_state['values']['site_default'] == $langcode);
    $language->weight = $form_state['values']['languages'][$langcode]['weight'];
    language_save($language);
  }

  drupal_set_message(t('Configuration saved.'));
}

/**
 * User interface for the language addition screen.
 */
function language_admin_add_form($form, &$form_state) {
  $predefined_languages = language_admin_predefined_list();
  $predefined_languages['custom'] = t('Custom language...');
  $predefined_default = !empty($form_state['values']['predefined_langcode']) ? $form_state['values']['predefined_langcode'] : key($predefined_languages);
  $form['predefined_langcode'] = array(
    '#type' => 'select',
    '#title' => t('Language name'),
    '#default_value' => $predefined_default,
    '#options' => $predefined_languages,
  );
  $form['predefined_submit'] = array(
    '#type' => 'submit',
    '#value' => t('Add language'),
    '#limit_validation_errors' => array(array('predefined_langcode'), array('predefined_submit')),
    '#states' => array(
      'invisible' => array(
        'select#edit-predefined-langcode' => array('value' => 'custom'),
      ),
    ),
    '#validate' => array('language_admin_add_predefined_form_validate'),
    '#submit' => array('language_admin_add_form_submit'),
  );

  $form['custom_language'] = array(
    '#type' => 'container',
    '#states' => array(
      'visible' => array(
        'select#edit-predefined-langcode' => array('value' => 'custom'),
      ),
    ),
  );
  _language_admin_common_controls($form['custom_language']);
  $form['custom_language']['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Add custom language'),
    '#validate' => array('language_admin_add_custom_form_validate'),
    '#submit' => array('language_admin_add_form_submit'),
  );

  return $form;
}

/**
 * Editing screen for a particular language.
 *
 * @param $langcode
 *   Language code of the language to edit.
 */
function language_admin_edit_form($form, &$form_state, $language) {
  _language_admin_common_controls($form, $language);
  $form['actions'] = array('#type' => 'actions');
  $form['actions']['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Save language')
  );
  $form['#submit'][] = 'language_admin_edit_form_submit';
  $form['#validate'][] = 'language_admin_edit_form_validate';
  return $form;
}

/**
 * Common elements of the language addition and editing form.
 *
 * @param $form
 *   A parent form item (or empty array) to add items below.
 * @param $language
 *   Language object to edit.
 */
function _language_admin_common_controls(&$form, $language = NULL) {
  if (!is_object($language)) {
    $language = new Language(array('langcode' => NULL, 'name' => NULL,));
  }
  if (isset($language->langcode)) {
    $form['langcode_view'] = array(
      '#type' => 'item',
      '#title' => t('Language code'),
      '#markup' => $language->langcode
    );
    $form['langcode'] = array(
      '#type' => 'value',
      '#value' => $language->langcode
    );
  }
  else {
    $form['langcode'] = array(
      '#type' => 'textfield',
      '#title' => t('Language code'),
      '#maxlength' => 12,
      '#required' => TRUE,
      '#default_value' => '',
      '#disabled' => FALSE,
      '#description' => t('Use language codes as <a href="@w3ctags">defined by the W3C</a> for interoperability. <em>Examples: "en", "en-gb" and "zh-hant".</em>', array('@w3ctags' => 'http://www.w3.org/International/articles/language-tags/')),
    );
  }
  $form['name'] = array(
    '#type' => 'textfield',
    '#title' => t('Language name'),
    '#maxlength' => 64,
    '#default_value' => @$language->name,
    '#required' => TRUE,
  );
  $form['direction'] = array(
    '#type' => 'radios',
    '#title' => t('Direction'),
    '#required' => TRUE,
    '#description' => t('Direction that text in this language is presented.'),
    '#default_value' => @$language->direction,
    '#options' => array(LANGUAGE_LTR => t('Left to right'), LANGUAGE_RTL => t('Right to left')),
  );
  return $form;
}

/**
 * Element specific validator for the Add language button.
 */
function language_admin_add_predefined_form_validate($form, &$form_state) {
  $langcode = $form_state['values']['predefined_langcode'];
  if ($langcode == 'custom') {
    form_set_error('predefined_langcode', t('Fill in the language details and save the language with <em>Add custom language</em>.'));
  }
  else {
    if ($language = language_load($langcode)) {
      form_set_error('predefined_langcode', t('The language %language (%langcode) already exists.', array('%language' => $language->name, '%langcode' => $langcode)));
    }
  }
}

/**
 * Validate the language addition form on custom language button.
 */
function language_admin_add_custom_form_validate($form, &$form_state) {
  if ($form_state['values']['predefined_langcode'] == 'custom') {
    $langcode = $form_state['values']['langcode'];
    // Reuse the editing form validation routine if we add a custom language.
    language_admin_edit_form_validate($form['custom_language'], $form_state);

    if ($language = language_load($langcode)) {
      form_set_error('langcode', t('The language %language (%langcode) already exists.', array('%language' => $language->name, '%langcode' => $langcode)));
    }
  }
  else {
    form_set_error('predefined_langcode', t('Use the <em>Add language</em> button to save a predefined language.'));
  }
}

/**
 * Process the custom and predefined language addition form submission.
 */
function language_admin_add_form_submit($form, &$form_state) {
  $langcode = $form_state['values']['predefined_langcode'];
  if ($langcode == 'custom') {
    $langcode = $form_state['values']['langcode'];
    // Custom language form.
    $language = new Language(array(
      'langcode' => $langcode,
      'name' => $form_state['values']['name'],
      'direction' => $form_state['values']['direction'],
    ));
  }
  else {
    $language = new Language(array('langcode' => $langcode));
  }
  // Save the language and inform the user that it happened.
  $language = language_save($language);
  drupal_set_message(t('The language %language has been created and can now be used.', array('%language' => $language->name)));

  // Tell the user they have the option to add a language switcher block
  // to their theme so they can switch between the languages.
  drupal_set_message(t('Use one of the language switcher blocks to allow site visitors to switch between languages. You can enable these blocks on the <a href="@block-admin">block administration page</a>.', array('@block-admin' => 'admin/structure/block')));

  $form_state['redirect'] = 'admin/config/regional/language';
}

/**
 * Validate the language editing form. Reused for custom language addition too.
 */
function language_admin_edit_form_validate($form, &$form_state) {
  // Ensure sane field values for langcode and name.
  if (!isset($form['langcode_view']) && preg_match('@[^a-zA-Z_-]@', $form_state['values']['langcode'])) {
    form_set_error('langcode', t('%field may only contain characters a-z, underscores, or hyphens.', array('%field' => $form['langcode']['#title'])));
  }
  if ($form_state['values']['name'] != check_plain($form_state['values']['name'])) {
    form_set_error('name', t('%field cannot contain any markup.', array('%field' => $form['name']['#title'])));
  }
}

/**
 * Process the language editing form submission.
 */
function language_admin_edit_form_submit($form, &$form_state) {
  // Prepare a language object for saving.
  $languages = language_list();
  $langcode = $form_state['values']['langcode'];
  $language = $languages[$langcode];
  $language->name = $form_state['values']['name'];
  $language->direction = $form_state['values']['direction'];
  language_save($language);
  $form_state['redirect'] = 'admin/config/regional/language';
}

/**
 * User interface for the language deletion confirmation screen.
 */
function language_admin_delete_form($form, &$form_state, $language) {
  $langcode = $language->langcode;

  if (language_default()->langcode == $langcode) {
    drupal_set_message(t('The default language cannot be deleted.'));
    drupal_goto('admin/config/regional/language');
  }

  // For other languages, warn the user that data loss is ahead.
  $languages = language_list();

  if (!isset($languages[$langcode])) {
    throw new NotFoundHttpException();
  }
  else {
    $form['langcode'] = array('#type' => 'value', '#value' => $langcode);
    return confirm_form($form, t('Are you sure you want to delete the language %language?', array('%language' => $languages[$langcode]->name)), 'admin/config/regional/language', t('Deleting a language will remove all interface translations associated with it, and posts in this language will be set to be language neutral. This action cannot be undone.'), t('Delete'), t('Cancel'));
  }
}

/**
 * Process language deletion submissions.
 */
function language_admin_delete_form_submit($form, &$form_state) {
  $langcode = $form_state['values']['langcode'];
  $languages = language_list();
  $language = $languages[$langcode];

  $success = language_delete($langcode);

  if ($success) {
    $t_args = array('%language' => $language->name, '%langcode' => $language->langcode);
    drupal_set_message(t('The %language (%langcode) language has been removed.', $t_args));
  }

  $form_state['redirect'] = 'admin/config/regional/language';
}

/**
 * Prepare a language code list for unused predefined languages.
 */
function language_admin_predefined_list() {
  include_once DRUPAL_ROOT . '/core/includes/standard.inc';
  $languages = language_list();
  $predefined = standard_language_list();
  foreach ($predefined as $key => $value) {
    if (isset($languages[$key])) {
      unset($predefined[$key]);
      continue;
    }
    $predefined[$key] = t($value[0]);
  }
  asort($predefined);
  return $predefined;
}

/**
 * Builds the configuration form for language negotiation.
 */
function language_negotiation_configure_form() {
  language_negotiation_include();

  $form = array(
    '#submit' => array('language_negotiation_configure_form_submit'),
    '#theme' => 'language_negotiation_configure_form',
    '#language_types' => language_types_get_configurable(FALSE),
    '#language_types_info' => language_types_info(),
    '#language_negotiation_info' => language_negotiation_info(),
  );

  foreach ($form['#language_types'] as $type) {
    language_negotiation_configure_form_table($form, $type);
  }

  $form['actions'] = array('#type' => 'actions');
  $form['actions']['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Save settings'),
  );

  return $form;
}

/**
 * Builds a language negotiation method configuration table.
 */
function language_negotiation_configure_form_table(&$form, $type) {
  $info = $form['#language_types_info'][$type];

  $table_form = array(
    '#title' => t('@type language detection', array('@type' => $info['name'])),
    '#tree' => TRUE,
    '#description' => $info['description'],
    '#language_negotiation_info' => array(),
    '#show_operations' => FALSE,
    'weight' => array('#tree' => TRUE),
  );

  $negotiation_info = $form['#language_negotiation_info'];
  $enabled_methods = variable_get("language_negotiation_$type", array());
  $methods_weight = variable_get("language_negotiation_methods_weight_$type", array());

  // Add missing data to the methods lists.
  foreach ($negotiation_info as $method_id => $method) {
    if (!isset($methods_weight[$method_id])) {
      $methods_weight[$method_id] = isset($method['weight']) ? $method['weight'] : 0;
    }
  }

  // Order methods list by weight.
  asort($methods_weight);

  foreach ($methods_weight as $method_id => $weight) {
    // A language method might be no more available if the defining module has
    // been disabled after the last configuration saving.
    if (!isset($negotiation_info[$method_id])) {
      continue;
    }

    $enabled = isset($enabled_methods[$method_id]);
    $method = $negotiation_info[$method_id];

    // List the method only if the current type is defined in its 'types' key.
    // If it is not defined default to all the configurable language types.
    $types = array_flip(isset($method['types']) ? $method['types'] : $form['#language_types']);

    if (isset($types[$type])) {
      $table_form['#language_negotiation_info'][$method_id] = $method;
      $method_name = check_plain($method['name']);

      $table_form['weight'][$method_id] = array(
        '#type' => 'weight',
        '#title' => t('Weight for !title language detection method', array('!title' => drupal_strtolower($method_name))),
        '#title_display' => 'invisible',
        '#default_value' => $weight,
        '#attributes' => array('class' => array("language-method-weight-$type")),
        '#delta' => 20,
      );

      $table_form['title'][$method_id] = array('#markup' => $method_name);

      $table_form['enabled'][$method_id] = array(
        '#type' => 'checkbox',
        '#title' => t('Enable !title language detection method', array('!title' => drupal_strtolower($method_name))),
        '#title_display' => 'invisible',
        '#default_value' => $enabled,
      );
      if ($method_id === LANGUAGE_NEGOTIATION_SELECTED) {
        $table_form['enabled'][$method_id]['#default_value'] = TRUE;
        $table_form['enabled'][$method_id]['#attributes'] = array('disabled' => 'disabled');
      }

      $table_form['description'][$method_id] = array('#markup' => filter_xss_admin($method['description']));

      $config_op = array();
      if (isset($method['config'])) {
        $config_op['configure'] = array(
          'title' => t('Configure'),
          'href' => $method['config'],
        );
        // If there is at least one operation enabled show the operation column.
        $table_form['#show_operations'] = TRUE;
      }
      $table_form['operation'][$method_id] = array(
       '#type' => 'operations',
       '#links' => $config_op,
      );
    }
  }

  $form[$type] = $table_form;
}

/**
 * Returns HTML for the language negotiation configuration form.
 *
 * @param $variables
 *   An associative array containing:
 *   - form: A render element representing the form.
 *
 * @ingroup themeable
 */
function theme_language_negotiation_configure_form($variables) {
  $form = $variables['form'];
  $output = '';

  foreach ($form['#language_types'] as $type) {
    $rows = array();
    $title = '<label>' . $form[$type]['#title'] . '</label>';
    $description = '<div class="description">' . $form[$type]['#description'] . '</div>';

    foreach ($form[$type]['title'] as $id => $element) {
      // Do not take form control structures.
      if (is_array($element) && element_child($id)) {
        $row = array(
          'data' => array(
            '<strong>' . drupal_render($form[$type]['title'][$id]) . '</strong>',
            drupal_render($form[$type]['description'][$id]),
            drupal_render($form[$type]['enabled'][$id]),
            drupal_render($form[$type]['weight'][$id]),
          ),
          'class' => array('draggable'),
        );
        if ($form[$type]['#show_operations']) {
          $row['data'][] = drupal_render($form[$type]['operation'][$id]);
        }
        $rows[] = $row;
      }
    }

    $header = array(
      array('data' => t('Detection method')),
      array('data' => t('Description')),
      array('data' => t('Enabled')),
      array('data' => t('Weight')),
    );

    // If there is at least one operation enabled show the operation column.
    if ($form[$type]['#show_operations']) {
      $header[] = array('data' => t('Operations'));
    }

    $variables = array(
      'header' => $header,
      'rows' => $rows,
      'attributes' => array('id' => "language-negotiation-methods-$type"),
    );
    $table  = theme('table', $variables);
    $table .= drupal_render_children($form[$type]);

    drupal_add_tabledrag("language-negotiation-methods-$type", 'order', 'sibling', "language-method-weight-$type");

    $output .= '<div class="form-item">' . $title . $description . $table . '</div>';
  }

  $output .= drupal_render_children($form);
  return $output;
}

/**
 * Submit handler for language negotiation settings.
 */
function language_negotiation_configure_form_submit($form, &$form_state) {
  $configurable_types = $form['#language_types'];

  foreach ($configurable_types as $type) {
    $method_weights = array();
    $enabled_methods = $form_state['values'][$type]['enabled'];
    $enabled_methods[LANGUAGE_NEGOTIATION_SELECTED] = TRUE;
    $method_weights_input = $form_state['values'][$type]['weight'];

    foreach ($method_weights_input as $method_id => $weight) {
      if ($enabled_methods[$method_id]) {
        $method_weights[$method_id] = $weight;
      }
    }

    language_negotiation_set($type, $method_weights);
    variable_set("language_negotiation_methods_weight_$type", $method_weights_input);
  }

  // Update non-configurable language types and the related language negotiation
  // configuration.
  language_types_set();

  $form_state['redirect'] = 'admin/config/regional/language/detection';
  drupal_set_message(t('Language negotiation configuration saved.'));
}

/**
 * Builds the URL language negotiation method configuration form.
 */
function language_negotiation_configure_url_form($form, &$form_state) {
  global $base_url;
  language_negotiation_include();

  $form['language_negotiation_url_part'] = array(
    '#title' => t('Part of the URL that determines language'),
    '#type' => 'radios',
    '#options' => array(
      LANGUAGE_NEGOTIATION_URL_PREFIX => t('Path prefix'),
      LANGUAGE_NEGOTIATION_URL_DOMAIN => t('Domain'),
    ),
    '#default_value' => variable_get('language_negotiation_url_part', LANGUAGE_NEGOTIATION_URL_PREFIX),
  );

  $form['prefix'] = array(
    '#type' => 'fieldset',
    '#tree' => TRUE,
    '#title' => t('Path prefix configuration'),
    '#description' => t('Language codes or other custom text to use as a path prefix for URL language detection. For the default language, this value may be left blank. <strong>Modifying this value may break existing URLs. Use with caution in a production environment.</strong> Example: Specifying "deutsch" as the path prefix code for German results in URLs like "example.com/deutsch/contact".'),
    '#states' => array(
      'visible' => array(
        ':input[name="language_negotiation_url_part"]' => array(
          'value' => (string) LANGUAGE_NEGOTIATION_URL_PREFIX,
        ),
      ),
    ),
  );
  $form['domain'] = array(
    '#type' => 'fieldset',
    '#tree' => TRUE,
    '#title' => t('Domain configuration'),
    '#description' => t('The domain names to use for these languages. Leave blank for the default language. Use with caution in a production environment.<strong>Modifying this value may break existing URLs. Use with caution in a production environment.</strong> Example: Specifying "de.example.com" as language domain for German will result in an URL like "http://de.example.com/contact".'),
    '#states' => array(
      'visible' => array(
        ':input[name="language_negotiation_url_part"]' => array(
          'value' => (string) LANGUAGE_NEGOTIATION_URL_DOMAIN,
        ),
      ),
    ),
  );

  $languages = language_list();
  $prefixes = language_negotiation_url_prefixes();
  $domains = language_negotiation_url_domains();
  foreach ($languages as $langcode => $language) {
    $t_args = array('%language' => $language->name, '%langcode' => $language->langcode);
    $form['prefix'][$langcode] = array(
      '#type' => 'textfield',
      '#title' => $language->default ? t('%language (%langcode) path prefix (Default language)', $t_args) : t('%language (%langcode) path prefix', $t_args),
      '#maxlength' => 64,
      '#default_value' => isset($prefixes[$langcode]) ? $prefixes[$langcode] : '',
      '#field_prefix' => $base_url . '/',
    );
    $form['domain'][$langcode] = array(
      '#type' => 'textfield',
      '#title' => t('%language (%langcode) domain', array('%language' => $language->name, '%langcode' => $language->langcode)),
      '#maxlength' => 128,
      '#default_value' => isset($domains[$langcode]) ? $domains[$langcode] : '',
    );
  }

  $form_state['redirect'] = 'admin/config/regional/language/detection';

  $form['actions']['#type'] = 'actions';
  $form['actions']['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Save configuration'),
  );
  return $form;
}

/**
 * Validates the URL language negotiation method configuration.
 *
 * Validate that the prefixes and domains are unique, and make sure that
 * the prefix and domain are only blank for the default.
 */
function language_negotiation_configure_url_form_validate($form, &$form_state) {
  $languages = language_list();

  // Count repeated values for uniqueness check.
  $count = array_count_values($form_state['values']['prefix']);
  foreach ($languages as $langcode => $language) {
    $value = $form_state['values']['prefix'][$langcode];

    if ($value === '') {
      if (!$language->default && $form_state['values']['language_negotiation_url_part'] == LANGUAGE_NEGOTIATION_URL_PREFIX) {
        // Throw a form error if the prefix is blank for a non-default language,
        // although it is required for selected negotiation type.
        form_error($form['prefix'][$langcode], t('The prefix may only be left blank for the default language.'));
      }
    }
    elseif (strpos($value, '/') !== FALSE) {
      // Throw a form error if the string contains a slash,
      // which would not work.
      form_error($form['prefix'][$langcode], t('The prefix may not contain a slash.'));
    }
    elseif (isset($count[$value]) && $count[$value] > 1) {
      // Throw a form error if there are two languages with the same
      // domain/prefix.
      form_error($form['prefix'][$langcode], t('The prefix for %language, %value, is not unique.', array('%language' => $language->name, '%value' => $value)));
    }
  }

  // Count repeated values for uniqueness check.
  $count = array_count_values($form_state['values']['domain']);
  foreach ($languages as $langcode => $language) {
    $value = $form_state['values']['domain'][$langcode];

    if ($value === '') {
      if (!$language->default && $form_state['values']['language_negotiation_url_part'] == LANGUAGE_NEGOTIATION_URL_DOMAIN) {
        // Throw a form error if the domain is blank for a non-default language,
        // although it is required for selected negotiation type.
        form_error($form['domain'][$langcode], t('The domain may only be left blank for the default language.'));
      }
    }
    elseif (isset($count[$value]) && $count[$value] > 1) {
      // Throw a form error if there are two languages with the same
      // domain/domain.
      form_error($form['domain'][$langcode], t('The domain for %language, %value, is not unique.', array('%language' => $language->name, '%value' => $value)));
    }
  }

  // Domain names should not contain protocol and/or ports.
  foreach ($languages as $langcode => $name) {
    $value = $form_state['values']['domain'][$langcode];
    if (!empty($value)) {
      // Ensure we have exactly one protocol when checking the hostname.
      $host = 'http://' . str_replace(array('http://', 'https://'), '', $value);
      if (parse_url($host, PHP_URL_HOST) != $value) {
        form_error($form['domain'][$langcode], t('The domain for %language may only contain the domain name, not a protocol and/or port.', array('%language' => $name)));
      }
    }
  }
}

/**
 * Saves the URL language negotiation method settings.
 */
function language_negotiation_configure_url_form_submit($form, &$form_state) {
  // Save selected format (prefix or domain).
  variable_set('language_negotiation_url_part', $form_state['values']['language_negotiation_url_part']);

  // Save new domain and prefix values.
  language_negotiation_url_prefixes_save($form_state['values']['prefix']);
  language_negotiation_url_domains_save($form_state['values']['domain']);

  drupal_set_message(t('Configuration saved.'));
}

/**
 * Builds the session language negotiation method configuration form.
 */
function language_negotiation_configure_session_form($form, &$form_state) {
  $form['language_negotiation_session_param'] = array(
    '#title' => t('Request/session parameter'),
    '#type' => 'textfield',
    '#default_value' => variable_get('language_negotiation_session_param', 'language'),
    '#description' => t('Name of the request/session parameter used to determine the desired language.'),
  );

  $form_state['redirect'] = 'admin/config/regional/language/detection';

  return system_settings_form($form);
}

/**
 * Builds the selected language negotiation method configuration form.
 */
function language_negotiation_configure_selected_form($form, &$form_state) {
  $form['selected_langcode'] = array(
    '#type' => 'language_select',
    '#title' => t('Language'),
    '#languages' => LANGUAGE_CONFIGURABLE | LANGUAGE_SITE_DEFAULT,
    '#default_value' => config('language.negotiation')->get('selected_langcode'),
  );

  return system_config_form($form, $form_state);
}

/**
 * Form submission handler for language_negotiation_configure_selected_form().
 */
function language_negotiation_configure_selected_form_submit($form, &$form_state) {
  config('language.negotiation')->set('selected_langcode', $form_state['values']['selected_langcode'])->save();
}

/**
 * Builds the browser language negotiation method configuration form.
 */
function language_negotiation_configure_browser_form($form, &$form_state) {
  $form = array();

  // Initialize a language list to the ones available, including English.
  $languages = language_list();

  $existing_languages = array();
  foreach ($languages as $langcode => $language) {
    $existing_languages[$langcode] = $language->name;
  }

  // If we have no languages available, present the list of predefined languages
  // only. If we do have already added languages, set up two option groups with
  // the list of existing and then predefined languages.
  if (empty($existing_languages)) {
    $language_options = language_admin_predefined_list();
    $default = key($language_options);
  }
  else {
    $default = key($existing_languages);
    $language_options = array(
      t('Existing languages') => $existing_languages,
      t('Languages not yet added') => language_admin_predefined_list()
    );
  }

  $form['mappings'] = array(
    '#tree' => TRUE,
    '#theme' => 'language_negotiation_configure_browser_form_table',
  );

  $mappings = language_get_browser_drupal_langcode_mappings();
  foreach ($mappings as $browser_langcode => $drupal_langcode) {
    $form['mappings'][$browser_langcode] = array(
      'browser_langcode' => array(
        '#type' => 'textfield',
        '#default_value' => $browser_langcode,
        '#size' => 20,
        '#required' => TRUE,
      ),
      'drupal_langcode' => array(
        '#type' => 'select',
        '#options' => $language_options,
        '#default_value' => $drupal_langcode,
        '#required' => TRUE,
      ),
    );
  }

  // Add empty row.
  $form['new_mapping'] = array(
    '#type' => 'fieldset',
    '#title' => t('Add a new mapping'),
    '#collapsible' => TRUE,
    '#collapsed' => TRUE,
    '#tree' => TRUE,
  );
  $form['new_mapping']['browser_langcode'] = array(
    '#type' => 'textfield',
    '#title' => t('Browser language code'),
    '#description' => t('Use language codes as <a href="@w3ctags">defined by the W3C</a> for interoperability. <em>Examples: "en", "en-gb" and "zh-hant".</em>', array('@w3ctags' => 'http://www.w3.org/International/articles/language-tags/')),
    '#default_value' => '',
    '#size' => 20,
  );
  $form['new_mapping']['drupal_langcode'] = array(
    '#type' => 'select',
    '#title' => t('Drupal language'),
    '#options' => $language_options,
    '#default_value' => '',
  );

  $form['actions']['#type'] = 'actions';
  $form['actions']['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Save configuration'),
  );

  return $form;
}

/**
 * Theme browser configuration form as table.
 *
 * @param $variables
 *   An associative array containing:
 *   - form: A render element representing the form.
 *
 * @ingroup themeable
 */
function theme_language_negotiation_configure_browser_form_table($variables) {
  $form = $variables['form'];
  $rows = array();
  foreach (element_children($form, TRUE) as $key) {
    $row = array();
    $row[] = drupal_render($form[$key]['browser_langcode']);
    $row[] = drupal_render($form[$key]['drupal_langcode']);
    $links = array();
    $links['delete'] = array(
      'title' => t('Delete'),
      'href' => "admin/config/regional/language/detection/browser/delete/$key",
      'attributes' => array(
        'class' => array('image-style-link'),
      ),
    );
    $row[] = array(
      'data' => array(
        '#type' => 'operations',
        '#links' => $links,
      ),
    );

    $rows[] = $row;
  }

  $header = array(
    t('Browser language code'),
    t('Drupal language'),
    t('Operations'),
  );

  $output = theme('table', array('header' => $header, 'rows' => $rows, 'attributes' => array('id' => 'lang-neg-browser')));

  return $output;
}

/**
 * Browser language negotiation form validation.
 */
function language_negotiation_configure_browser_form_validate($form, &$form_state) {
  // Array to check if all browser language codes are unique.
  $unique_values = array();

  // Check all mappings.
  $mappings = array();
  if (isset($form_state['values']['mappings'])) {
    $mappings = $form_state['values']['mappings'];
    foreach ($mappings as $key => $data) {
      // Make sure browser_langcode is unique.
      if (array_key_exists($data['browser_langcode'], $unique_values)) {
        form_set_error('mappings][' . $key . '][browser_langcode', t('Browser language codes must be unique.'));
      }
      elseif (preg_match('/[^a-z\-]/', $data['browser_langcode'])) {
        form_set_error('mappings][' . $key . '][browser_langcode', t('Browser language codes can only contain lowercase letters and a hyphen(-).'));
      }
      $unique_values[$data['browser_langcode']] = $data['drupal_langcode'];
    }
  }

  // Check new mapping.
  $data = $form_state['values']['new_mapping'];
  if (!empty($data['browser_langcode'])) {
    // Make sure browser_langcode is unique.
    if (array_key_exists($data['browser_langcode'], $unique_values)) {
      form_set_error('mappings][' . $key . '][browser_langcode', t('Browser language codes must be unique.'));
    }
    elseif (preg_match('/[^a-z\-]/', $data['browser_langcode'])) {
      form_set_error('mappings][' . $key . '][browser_langcode', t('Browser language codes can only contain lowercase letters and a hyphen(-).'));
    }
    $unique_values[$data['browser_langcode']] = $data['drupal_langcode'];
  }

  $form_state['mappings'] = $unique_values;
}

/**
 * Browser language negotiation form submit.
 */
function language_negotiation_configure_browser_form_submit($form, &$form_state) {
  $mappings = $form_state['mappings'];
  if (!empty($mappings)) {
    language_set_browser_drupal_langcode_mappings($mappings);
  }
  $form_state['redirect'] = 'admin/config/regional/language/detection';
}

/**
 * Form for deleting a browser language negotiation mapping.
 */
function language_negotiation_configure_browser_delete_form($form, &$form_state, $browser_langcode) {
  $form_state['browser_langcode'] = $browser_langcode;
  $question = t('Are you sure you want to delete %browser_langcode?', array(
    '%browser_langcode' => $browser_langcode,
  ));
  $path = 'admin/config/regional/language/detection/browser';
  return confirm_form($form, $question, $path, '');
}

/**
 * Form submit handler to delete a browser language negotiation mapping.
 */
function language_negotiation_configure_browser_delete_form_submit($form, &$form_state) {
  $browser_langcode = $form_state['browser_langcode'];
  $mappings = language_get_browser_drupal_langcode_mappings();
  if (array_key_exists($browser_langcode, $mappings)) {
    unset($mappings[$browser_langcode]);
    language_set_browser_drupal_langcode_mappings($mappings);
  }
  $form_state['redirect'] = 'admin/config/regional/language/detection/browser';
}
