<?php

/**
 * @file
 * LDAP User editing UI.
 *
 */


/**
 * Form for adding, updating, and deleting a single ldap authorization
 * mapping.
 */
function ldap_groups_admin_form($form, &$form_state) {

  $ldap_groups_conf1 = ldap_groups_conf();
  dpm($ldap_groups_conf1);
  $ldap_groups_conf = ldap_groups_conf('admin');
  $form = $ldap_groups_conf->drupalForm();
  return $form;
}


/**
 * Validate handler for the ldap_groups_admin_form.
 */
function ldap_groups_admin_form_validate($form, &$form_state) {

  $ldap_groups_conf_admin = ldap_groups_conf('admin');
  list($errors, $warnings) = $ldap_groups_conf_admin->drupalFormValidate($form_state['values']);
  foreach ($errors as $error_name => $error_text) {
    form_set_error($error_name, t($error_text));
  }
  foreach ($warnings as $warning_name => $warning_text) {
    drupal_set_message($warning_text, 'warning');
  }
  $form_state['ldap_warnings'] = (boolean)(count($warnings) > 0);
}

/**
 * Submit handler function for ldap_groups_admin_form.
 */
function ldap_groups_admin_form_submit($form, &$form_state) {

  $ldap_groups_conf = ldap_groups_conf('admin');

  // add form data to object and save or create
  $ldap_groups_conf->drupalFormSubmit($form_state['values']);

  // if ($ldap_groups_conf->hasError == FALSE) {
  //   drupal_set_message(t('LDAP groups configuration saved'), 'status');
  //   drupal_goto(LDAP_SERVERS_MENU_BASE_PATH . '/user');
  // }
  // else {
  //   form_set_error($conf->errorName, $conf->errorMsg);
  //   $ldap_groups_conf->clearError();
  // }

  drupal_goto(LDAP_SERVERS_MENU_BASE_PATH . '/groups');

}