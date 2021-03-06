<?php

/**
 * @file
 */

/**
 *
 */
function ldap_severs_user_data_setup_batch($consumer_type = NULL) {

  $max_uid = db_query("SELECT max(uid) FROM {users}")->fetchField();
  $step = 100;
  $operations = array();
  for ($uid = 2; $uid <= $max_uid; $uid += $step) {
    $operations[] = array("ldap_servers_empty_user_data", array($uid, $step, $consumer_type));
  }

  // Put all that information into our batch array.
  return array(
    'operations' => $operations,
    'title' => t('Empty LDAP Authorization Data in user->data[ldap_authorizations][%consumer_id]', array('%consumer_id' => $consumer_type)),
    'init_message' => t('Initializing'),
    'error_message' => t('An error occurred'),
    'finished' => t('Finished.'),
  );

}

/**
 * Function to remove $user->data['ldap_authorizations'] on uninstall
 * which is called from ldap_authorization uninstall batches.
 */
function ldap_servers_empty_user_data($start, $step, $consumer_type, &$context) {

  $query = new EntityFieldQuery();
  $query->entityCondition('entity_type', 'user')
    ->entityCondition('entity_id', array($start, $start + $step - 1), 'BETWEEN');
  $results = $query->execute();

  if (isset($results['user'])) {
    foreach ($results['user'] as $uid => $entity_data) {
      if ($uid > 1 && $drupal_account = \Drupal\user\Entity\User::load($uid)) {
        // Remove all authorization data.
        if ($consumer_type == NULL && $drupal_account->data['ldap_authorizations']) {
          $names[] = $drupal_account->getAccountName();
          unset($drupal_account->data['ldap_authorizations']);
          $drupal_accout->set('data', $drupal_account->data);
          $drupal_account->save();
        }
        // Remove only a particular consumers authorization data.
        elseif ($consumer_type != NULL && isset($drupal_account->data['ldap_authorizations'][$consumer_type])) {
          unset($drupal_account->data['ldap_authorizations'][$consumer_type]);
          $drupal_accout->set('data', $drupal_account->data);
          $drupal_account->save();
        }
      }
    }
  }
}

/**
 *
 */
function ldap_authorization_generate_users() {
  $response = "";
  // @FIXME
  // For loop was rewritten but need to be tested
  // For ($i=1; $i<1000; $i++) {
  //   $name = "user" . $i;
  //   if ($drupal_account = user_load_by_name($name)) {
  //     user_delete($drupal_account->id());
  //   }
  //   $drupal_account = new stdClass();
  //   $drupal_account->is_new = TRUE;
  //   $drupal_account->name = "user" . $i;
  //   $user_edit = array(
  //     'data' => array('ldap_authorizations' => array('og_group' => 7, 'drupal_role' => 8))
  //   );.
  // $user_response = user_save($drupal_account, $user_edit);
  //   $response .= $user_response->name . "<br/>";
  // }.
  for ($i = 1; $i < 1000; $i++) {
    $name = "user" . $i;
    if ($user_response = user_load_by_name($name)) {
      $user_response->delete();
    }

    $user_edit = array(
      'data' => array('ldap_authorizations' => array('og_group' => 7, 'drupal_role' => 8)),
    );

    $user_response = \Drupal\node\Entity\User::create('user', $user_edit);
    $user_response->is_new = TRUE;
    $user_response->name = "user" . $i;

    entity_save('user', $user_response);

    $response .= $user_response->name . "<br/>";
  }
  return $response;

}
