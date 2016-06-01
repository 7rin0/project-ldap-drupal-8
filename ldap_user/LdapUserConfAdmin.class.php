<?php

/**
 * @file
 * This class extends LdapUserConf for configuration and other admin functions.
 */

module_load_include('php', 'ldap_user', 'LdapUserConf.class');
module_load_include('inc', 'user', 'user.pages');
/**
 *
 */
class LdapUserConfAdmin extends LdapUserConf {

  /**
   * Basic settings.
   */

  protected $drupalAcctProvisionServerDescription;
  protected $drupalAcctProvisionServerOptions = array();
  protected $ldapEntryProvisionServerOptions = array();

  protected $drupalAccountProvisionEventsDescription;
  protected $drupalAccountProvisionEventsOptions = array();

  protected $ldapEntryProvisionTriggersDescription;
  protected $ldapEntryProvisionTriggersOptions = array();

  protected $synchFormRow = 0;

  /*
   * 3. Drupal Account Provisioning and Syncing
   */
  public $userConflictResolveDescription;
  public $userConflictResolveDefault = LDAP_USER_CONFLICT_RESOLVE_DEFAULT;
  public $userConflictOptions;

  public $acctCreationDescription = '';
  public $acctCreationDefault = LDAP_USER_ACCT_CREATION_LDAP_BEHAVIOR_DEFAULT;
  public $acctCreationOptions;


  public $errorMsg = NULL;
  public $hasError = FALSE;
  public $errorName = NULL;

  /**
   *
   */
  public function clearError() {
    $this->hasError = FALSE;
    $this->errorMsg = NULL;
    $this->errorName = NULL;
  }

  /**
   *
   */
  public function save() {
    foreach ($this->saveable as $property) {
      $save[$property] = $this->{$property};
    }
    \Drupal::configFactory()->getEditable('ldap_user.settings')->set('ldap_user_conf', $save)->save();
    ldap_user_conf_cache_clear();
  }

  /**
   *
   */
  static public function uninstall() {
    \Drupal::config('ldap_user.settings')->clear('ldap_user_conf')->save();
  }

  /**
   *
   */
  public function __construct() {
    parent::__construct();
    $this->setTranslatableProperties();

    if ($servers = ldap_servers_get_servers(NULL, 'enabled')) {
      foreach ($servers as $sid => $ldap_server) {
        $enabled = ($ldap_server->get('status')) ? 'Enabled' : 'Disabled';
        $this->drupalAcctProvisionServerOptions[$sid] = $ldap_server->label() . ' (' . $ldap_server->get('address') . ') Status: ' . $enabled;
        $this->ldapEntryProvisionServerOptions[$sid] = $ldap_server->label() . ' (' . $ldap_server->get('address') . ') Status: ' . $enabled;
      }
    }
    $this->drupalAcctProvisionServerOptions['none'] = t('None');
    $this->ldapEntryProvisionServerOptions['none'] = t('None');

  }

  /**
   * Generate admin form for ldapUserConf object.
   *
   * @return array $form as drupal form api form array
   */
  public function drupalForm() {
    if (count($this->drupalAcctProvisionServerOptions) == 0) {
      $message = ldap_servers_no_enabled_servers_msg('configure LDAP User');
      $form['intro'] = array(
        '#type' => 'item',
        '#markup' => t('<h1>LDAP User Settings</h1>') . $message,
      );
      return $form;
    }
    $form['#storage'] = array();
    $form['#theme'] = array('ldap_user_conf_form');

    $form['intro'] = array(
      '#type' => 'item',
      '#markup' => t('<h1>LDAP User Settings</h1>'),
    );

    $form['manual_drupal_account_editing'] = array(
      '#type' => 'fieldset',
      '#title' => t('Manual Drupal Account Creation and Updates'),
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
    );

    $form['manual_drupal_account_editing']['manualAccountConflict'] = array(
      '#type' => 'radios',
      '#options' => $this->manualAccountConflictOptions,
      '#title' => t('How to resolve LDAP conflicts with manually  created Drupal accounts.'),
      '#description' => t('This applies only to accounts created manually through admin/people/create
        for which an LDAP entry can be found on the LDAP server selected in "LDAP Servers Providing Provisioning Data"'),
      '#default_value' => $this->manualAccountConflict,
    );

    $form['basic_to_drupal'] = array(
      '#type' => 'fieldset',
      '#title' => t('Basic Provisioning to Drupal Account Settings'),
      '#collapsible' => TRUE,
      '#collapsed' => !($this->drupalAcctProvisionServer),
    );

    $form['basic_to_drupal']['drupalAcctProvisionServer'] = array(
      '#type' => 'radios',
      '#title' => t('LDAP Servers Providing Provisioning Data'),
      '#required' => 1,
      '#default_value' => $this->drupalAcctProvisionServer,
      '#options' => $this->drupalAcctProvisionServerOptions,
      '#description' => $this->drupalAcctProvisionServerDescription,
      '#states' => array(
    // Action to take.
        'enabled' => array(
          ':input[name=drupalAcctProvisionTriggers]' => array('value' => LDAP_USER_DRUPAL_USER_PROV_ON_AUTHENTICATE),
        ),
      ),
    );

    $form['basic_to_drupal']['drupalAcctProvisionTriggers'] = array(
      '#type' => 'checkboxes',
      '#title' => t('Drupal Account Provisioning Events'),
      '#required' => FALSE,
      '#default_value' => $this->drupalAcctProvisionTriggers,
      '#options' => $this->drupalAccountProvisionEventsOptions,
      '#description' => $this->drupalAccountProvisionEventsDescription,
    );

    $form['basic_to_drupal']['userConflictResolve'] = array(
      '#type' => 'radios',
      '#title' => t('Existing Drupal User Account Conflict'),
      '#required' => 1,
      '#default_value' => $this->userConflictResolve,
      '#options' => $this->userConflictOptions,
      '#description' => t($this->userConflictResolveDescription),
    );

    $form['basic_to_drupal']['acctCreation'] = array(
      '#type' => 'radios',
      '#title' => t('Application of Drupal Account settings to LDAP Authenticated Users'),
      '#required' => 1,
      '#default_value' => $this->acctCreation,
      '#options' => $this->acctCreationOptions,
      '#description' => t($this->acctCreationDescription),
    );

    $account_options = array();
    $account_options['ldap_user_orphan_do_not_check'] = t('Do not check for orphaned Drupal accounts.');
    $account_options['ldap_user_orphan_email'] = t('Perform no action, but email list of orphaned accounts. (All the other options will send email summaries also.)');
    foreach (user_cancel_methods()['#options'] as $option_name => $option_title) {
      $account_options[$option_name] = $option_title;
    }

    // @todo these 2 options are removed until this feature is better tested in
    // actual production environments; it has potentially disastrous effects
    unset($account_options['user_cancel_reassign']);
    unset($account_options['user_cancel_delete']);

    $form['basic_to_drupal']['orphanedDrupalAcctBehavior'] = array(
      '#type' => 'radios',
      '#title' => t('Action to perform on Drupal account that no longer have a
        corresponding LDAP entry'),
      '#required' => 0,
      '#default_value' => $this->orphanedDrupalAcctBehavior,
      '#options' => $account_options,
      '#description' => t($this->orphanedDrupalAcctBehaviorDescription),
    );

    $form['basic_to_drupal']['orphanedCheckQty'] = array(
      '#type' => 'textfield',
      '#size' => 10,
      '#title' => t('Number of users to check each cron run.'),
      '#description' => t(''),
      '#default_value' => $this->orphanedCheckQty,
      '#required' => FALSE,
    );

    $form['basic_to_ldap'] = array(
      '#type' => 'fieldset',
      '#title' => t('Basic Provisioning to LDAP Settings'),
      '#collapsible' => TRUE,
      '#collapsed' => !($this->ldapEntryProvisionServer),
    );

    $form['basic_to_ldap']['ldapEntryProvisionServer'] = array(
      '#type' => 'radios',
      '#title' => t('LDAP Servers to Provision LDAP Entries on'),
      '#required' => 1,
      '#default_value' => $this->ldapEntryProvisionServer,
      '#options' => $this->ldapEntryProvisionServerOptions,
      '#description' => $this->ldapEntryProvisionServerDescription,
    );

    $form['basic_to_ldap']['ldapEntryProvisionTriggers'] = array(
      '#type' => 'checkboxes',
      '#title' => t('LDAP Entry Provisioning Events'),
      '#required' => FALSE,
      '#default_value' => $this->ldapEntryProvisionTriggers,
      '#options' => $this->ldapEntryProvisionTriggersOptions,
      '#description' => $this->ldapEntryProvisionTriggersDescription,
    );

    /**
 *  $form['ws'] = array(
 *    '#type' => 'fieldset',
 *    '#title' => t('[Untested and Unfinished Code] REST Webservice for Provisioning and Synching.'),
 *    '#collapsible' => TRUE,
 *    '#collapsed' => !$this->wsEnabled,
 *    '#description' => t('Once configured, this webservice can be used to trigger creation, synching, deletion, etc of an LDAP associated Drupal account.'),
 *  ); *
 *  $form['ws']['wsEnabled'] = array(
 *    '#type' => 'checkbox',
 *    '#title' => t('Enable REST Webservice'),
 *    '#required' => FALSE,
 *    '#default_value' => $this->wsEnabled,
 *  ); *
 *  $form['ws']['wsUserIps'] = array(
 *    '#type' => 'textarea',
 *    '#title' => t('Allowed IP Addresses to request webservice.'),
 *    '#required' => FALSE,
 *    '#default_value' => join("\n", $this->wsUserIps),
 *    '#description' => t('One Per Line. The current server address is LOCAL_ADDR and the client ip requesting this page is REMOTE_ADDR .', $_SERVER),
 *    '#cols' => 20,
 *    '#rows' => 2,
 *    '#states' => array(
 *      'visible' => array(   // action to take.
 *        ':input[name="wsEnabled"]' => array('checked' => TRUE),
 *      ),
 *    ),
 *  ); *
 *  if (!$this->wsKey) {
 *    $urls = t('URLs are not available until a key is create a key and urls will be generated');
 *  }
 *  else {
 *    $urls = \Drupal::theme()->render('item_list',
 *      array(
 *        'items' => ldap_user_ws_urls_item_list(),
 *        'title' => 'REST urls',
 *        'type' => 'ul',
 *      ));
 *  } *
 *  $form['ws']['wsKey'] = array(
 *    '#type' => 'textfield',
 *    '#title' => t('Key for webservice'),
 *    '#required' => FALSE,
 *    '#default_value' => $this->wsKey,
 *    '#description' => t('Any random string of characters.') . $urls,
 *    '#states' => array(
 *      'visible' => array(   // action to take.
 *        ':input[name="wsEnabled"]' => array('checked' => TRUE),
 *      ),
 *    ),
 *  );
 */

    $form['server_mapping_preamble'] = array(
      '#type' => 'markup',
      '#markup' => t('
The relationship between a Drupal user and an LDAP entry is defined within the LDAP server configurations.


The mappings below are for user fields, properties, and profile2 data that are not automatically mapped elsewhere.
Mappings such as username or email address that are configured elsewhere are shown at the top for clarity.
When more than one ldap server is enabled for provisioning data (or simply more than one configuration for the same ldap server),
mappings need to be setup for each server.  If no tables are listed below, you have not enabled any provisioning servers at
the top of this form.
'),

    );

    foreach (array(LDAP_USER_PROV_DIRECTION_TO_DRUPAL_USER, LDAP_USER_PROV_DIRECTION_TO_LDAP_ENTRY) as $direction) {
      $sid = $this->provisionSidFromDirection[$direction];
      $ldap_server = ($sid) ? ldap_servers_get_servers($sid, NULL, TRUE) : FALSE;
      $ldap_server_selected = (boolean) $ldap_server;

      if ($direction == LDAP_USER_PROV_DIRECTION_TO_DRUPAL_USER) {
        $parent_fieldset = 'basic_to_drupal';
        $description = t('Provisioning from LDAP to Drupal Mappings:');
      }
      elseif ($direction == LDAP_USER_PROV_DIRECTION_TO_LDAP_ENTRY) {
        $parent_fieldset = 'basic_to_ldap';
        $description = t('Provisioning from Drupal to LDAP Mappings:');
      }

      $mapping_id = 'mappings__' . $direction;
      $table_id = $mapping_id . '__table';

      $form[$parent_fieldset][$mapping_id] = array(
        '#type' => 'fieldset',
        '#title' => $description,
        '#collapsible' => TRUE,
        '#collapsed' => FALSE,
        '#description' => '',
      );

      $form[$parent_fieldset][$mapping_id][$table_id] = array(
        '#type' => 'table',
        '#header' => array(t('Label'), t('Machine name'), t('Weight'), t('Operations')),
        '#attributes' => array('class' => array('mappings-table')),
      );

      $headers = $this->getServerMappingHeader($direction);
      $form[$parent_fieldset][$mapping_id][$table_id]['#header'] = $headers['header'];
      // Add in the second header as the first row.
      $form[$parent_fieldset][$mapping_id][$table_id]['second-header'] = array(
        '#attributes' => array('class' => 'header'),
      );
      // Second header uses the same format as header.
      foreach ($headers['second_header'] as $cell) {
        $form[$parent_fieldset][$mapping_id][$table_id]['second-header'][] = array(
          '#title' => $cell['data'],
          '#type' => 'item',
        );
        if (isset($cell['class'])) {
          $form[$parent_fieldset][$mapping_id][$table_id]['second-header']['#attributes'] = array('class' => array($cell['class']));
        }
        if (isset($cell['rowspan'])) {
          $form[$parent_fieldset][$mapping_id][$table_id]['second-header']['#rowspan'] = $cell['rowspan'];
        }
        if (isset($cell['colspan'])) {
          $form[$parent_fieldset][$mapping_id][$table_id]['second-header']['#colspan'] = $cell['colspan'];
        }
      }

      // Add in all the mappings. @TODO fix the save handlers.
      $form[$parent_fieldset][$mapping_id][$table_id] += $this->getServerMappingFields($direction);

      $password_notes = '<h3>' . t('Password Tokens') . '</h3><ul>' .
      '<li>' . t('Pwd: Random -- Uses a random Drupal generated password') . '</li>' .
      '<li>' . t('Pwd: User or Random -- Uses password supplied on user forms.
  If none available uses random password.') . '</li></ul>' .
      '<h3>' . t('Password Concerns') . '</h3>' .
      '<ul>' .
      '<li>' . t('Provisioning passwords to LDAP means passwords must meet the LDAP\'s
password requirements.  Password Policy module can be used to add requirements.') . '</li>' .
      '<li>' . t('Some LDAPs require a user to reset their password if it has been changed
by someone other that user.  Consider this when provisioning LDAP passwords.') . '</li>' .
      '</ul></p>';

      $source_drupal_token_notes = <<<EOT
<p>Examples in form: Source Drupal User token => Target LDAP Token (notes)</p>
<ul>
<li>Source Drupal User token => Target LDAP Token</li>
<li>cn=[property.name],ou=test,dc=ad,dc=mycollege,dc=edu => [dn] (example of token and constants)</li>
<li>top => [objectclass:0] (example of constants mapped to multivalued attribute)</li>
<li>person => [objectclass:1] (example of constants mapped to multivalued attribute)</li>
<li>organizationalPerson => [objectclass:2] (example of constants mapped to multivalued attribute)</li>
<li>user => [objectclass:3] (example of constants mapped to multivalued attribute)</li>
<li>Drupal Provisioned LDAP Account => [description] (example of constant)</li>
<li>[field.field_lname] => [sn]</li>

</ul>
EOT;

      // Add some password notes.
      if ($direction == LDAP_USER_PROV_DIRECTION_TO_LDAP_ENTRY) {
        $form[$parent_fieldset]['password_notes'] = array(
          '#type' => 'fieldset',
          '#title' => t('Password Notes'),
          '#collapsible' => TRUE,
          '#collapsed' => TRUE,
          'directions' => array(
            '#type' => 'markup',
            '#markup' => $password_notes,
          ),
        );
        $form[$parent_fieldset]['source_drupal_token_notes'] = array(
          '#type' => 'fieldset',
          '#title' => t('Source Drupal User Tokens and Corresponding Target LDAP Tokens'),
          '#collapsible' => TRUE,
          '#collapsed' => TRUE,
          'directions' => array(
            '#type' => 'markup',
            '#markup' => $source_drupal_token_notes,
          ),
        );
      }
    }

    foreach (array('orphanedCheckQty', 'orphanedDrupalAcctBehavior', 'acctCreation', 'userConflictResolve', 'drupalAcctProvisionTriggers', 'mappings__' . LDAP_USER_PROV_DIRECTION_TO_DRUPAL_USER) as $input_name) {
      $form['basic_to_drupal'][$input_name]['#states']['invisible'] =
        array(
          ':input[name=drupalAcctProvisionServer]' => array('value' => 'none'),
        );
    }

    foreach (array('ldapEntryProvisionTriggers', 'password_notes', 'source_drupal_token_notes', 'mappings__' . LDAP_USER_PROV_DIRECTION_TO_LDAP_ENTRY) as $input_name) {
      $form['basic_to_ldap'][$input_name]['#states']['invisible'] =
        array(
          ':input[name=ldapEntryProvisionServer]' => array('value' => 'none'),
        );
    }

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => 'Save',
    );

    return $form;
  }

  /**
   * Validate submitted form.
   *
   * @param array $values
   *   as $form_state->getValues() from drupal form api
   * @param array $storage
   *   as $form_state['storage'] from drupal form api
   *
   * @return array in form array($errors, $warnings)to be thrown by form api
   */
  public function drupalFormValidate($values, $storage) {
    $this->populateFromDrupalForm($values, $storage);
    list($errors, $warnings) = $this->validate($values);

    // @TODO these form fields aren't named like this anymore
    // since failed mapping rows in form, don't populate ->ldapUserSynchMappings, need to validate these from values
    foreach ($values as $field => $value) {
      $parts = explode('__', $field);
      // Since synch mapping fields are in n-tuples, process entire n-tuple at once (on field == configurable_to_drupal)
      if (count($parts) != 4 || $parts[1] !== 'sm' || $parts[2] != 'configurable_to_drupal') {
        continue;
      }
      list($direction, $discard, $column_name, $i) = $parts;
      // TODO: Remove unused variable or is a tmp state?
      // $action = $storage['synch_mapping_fields'][$direction][$i]['action'];
      $tokens = array();
      $row_mappings = array();
      foreach (array('remove', 'configurable_to_drupal', 'configurable_to_ldap', 'convert', 'direction', 'ldap_attr', 'user_attr', 'user_tokens') as $column_name) {
        $input_name = join('__', array('sm', $column_name, $i));
        $row_mappings[$column_name] = isset($values[$input_name]) ? $values[$input_name] : NULL;
      }

      $has_values = $row_mappings['ldap_attr'] || $row_mappings['user_attr'];
      if ($has_values) {
        $tokens['%ldap_attr'] = $row_mappings['ldap_attr'];
        $row_descriptor = t("server %sid row mapping to ldap attribute %ldap_attr", $tokens);
        $tokens['!row_descriptor'] = $row_descriptor;
        if (!$row_mappings['direction']) {
          $input_name = join('__', array('sm', 'direction', $i));
          $errors[$input_name] = t('No mapping direction given in !row_descriptor', $tokens);
        }
        if ($direction == LDAP_USER_PROV_DIRECTION_TO_DRUPAL_USER && $row_mappings['user_attr'] == 'user_tokens') {
          $input_name = join('__', array('sm', 'user_attr', $i));
          $errors[$input_name] = t('User tokens not allowed when mapping to Drupal user.  Location: !row_descriptor', $tokens);
        }
        if (!$row_mappings['ldap_attr']) {
          $input_name = join('__', array('sm', 'ldap_attr', $i));
          $errors[$input_name] = t('No ldap attribute given in !row_descriptor', $tokens);
        }
        if (!$row_mappings['user_attr']) {
          $input_name = join('__', array('sm', 'user_attr', $i));
          $errors[$input_name] = t('No user attribute given in !row_descriptor', $tokens);
        }
      }

    }
    return array($errors, $warnings);
  }

  /**
   * Validate object, not form.
   *
   * @param array $values
   *   as $form_state->getValues() from drupal form api
   *
   * @return array in form array($errors, $warnings)to be thrown by form api
   *
   * @todo validate that a user field exists, such as field.field_user_lname
   */
  public function validate($values) {
    $errors = array();
    $warnings = array();
    $tokens = array();
    $has_drupal_acct_prov_servers = $this->drupalAcctProvisionServer === 'none' ? 0 : 1;
    $has_drupal_acct_prov_settings_options  = (count(array_filter($this->drupalAcctProvisionTriggers)) > 0);

    if (!$has_drupal_acct_prov_servers && $has_drupal_acct_prov_settings_options) {
      $warnings['drupalAcctProvisionServer'] = t('No Servers are enabled to provide provisioning to Drupal, but Drupal Account Provisioning Options are selected.', $tokens);
    }
    if ($has_drupal_acct_prov_servers && !$has_drupal_acct_prov_settings_options) {
      $warnings['drupalAcctProvisionTriggers'] = t('Servers are enabled to provide provisioning to Drupal, but no Drupal Account Provisioning Options are selected.  This will result in no synching happening.', $tokens);
    }

    $has_ldap_prov_servers = (boolean) ($this->ldapEntryProvisionServer);
    $has_ldap_prov_settings_options = (count(array_filter($this->ldapEntryProvisionTriggers)) > 0);
    if (!$has_ldap_prov_servers && $has_ldap_prov_settings_options) {
      $warnings['ldapEntryProvisionServer'] = t('No Servers are enabled to provide provisioning to ldap, but LDAP Entry Options are selected.', $tokens);
    }
    if ($has_ldap_prov_servers && !$has_ldap_prov_settings_options) {
      $warnings['ldapEntryProvisionTriggers'] = t('Servers are enabled to provide provisioning to ldap, but no LDAP Entry Options are selected.  This will result in no synching happening.', $tokens);
    }

    if (isset($this->ldapUserSynchMappings)) {
      $to_ldap_entries_mappings_exist = FALSE;
      foreach ($this->ldapUserSynchMappings as $synch_direction => $mappings) {
        $map_index = array();
        // $tokens['%sid'] = $this->drupalAcctProvisionServer;
        $tokens['%sid'] = $this->drupalAcctProvisionServer === 'none' ? 0 : $this->drupalAcctProvisionServer;
        $to_drupal_user_mappings_exist = FALSE;
        $to_ldap_entries_mappings_exist = FALSE;

        foreach ($mappings as $target_attr => $mapping) {
          if ($mapping['direction'] == LDAP_USER_PROV_DIRECTION_TO_DRUPAL_USER) {
            $attr_value = $mapping['user_attr'];
            $attr_name = 'user_attr';
          }
          if ($mapping['direction'] == LDAP_USER_PROV_DIRECTION_TO_LDAP_ENTRY) {
            $attr_value = $mapping['ldap_attr'];
            $attr_name = 'ldap_attr';
          }
          foreach ($values as $field => $value) {
            $parts = explode('__', $field);
            if (count($parts) == 4 && $parts[2] == $attr_name && $value == $attr_value) {
              $map_index[$attr_value] = $parts[3];
            }
          }
        }

        foreach ($mappings as $target_attr => $mapping) {
          foreach ($mapping as $key => $value) {
            if (is_scalar($value)) {
              $tokens['%' . $key] = $value;
            }
          }
          $row_descriptor = t("server %sid row mapping to ldap attribute %ldap_attr", $tokens);
          $tokens['!row_descriptor'] = $row_descriptor;
          $ldap_attribute_maps_in_token = array();
          // debug('calling ldap_servers_token_extract_attributes from validate, mapping='); debug($mapping['ldap_attr']);.
          ldap_servers_token_extract_attributes($ldap_attribute_maps_in_token, $mapping['ldap_attr']);

          if ($mapping['direction'] == LDAP_USER_PROV_DIRECTION_TO_DRUPAL_USER) {
            $row_id = $map_index[$mapping['user_attr']];
            $to_drupal_user_mappings_exist = TRUE;
          }
          if ($mapping['direction'] == LDAP_USER_PROV_DIRECTION_TO_LDAP_ENTRY) {
            $row_id = $map_index[$mapping['ldap_attr']];
            $to_ldap_entries_mappings_exist = TRUE;

            if (count(array_keys($ldap_attribute_maps_in_token)) != 1) {
              $token_field_id = join('__', array('sm', 'user_tokens', $row_id));
              $errors[$token_field_id] = t('When provisioning to ldap, ldap attribute column must be singular token such as [cn]. %ldap_attr is not.
                Do not use compound tokens such as "[displayName] [sn]" or literals such as "physics". Location: !row_descriptor', $tokens);
            }

          }
          // FIXME none of these id's should be working.
          $ldap_attr_field_id = join('__', array('sm', 'ldap_attr', $row_id));
          $user_attr_field_id = join('__', array('sm', 'user_attr', $row_id));
          $first_context_field_id = join('__', array('sm', 1, $row_id));
          $user_tokens_field_id = join('__', array('sm', 'user_tokens', $row_id));

          if (!$mapping['ldap_attr']) {
            $errors[$ldap_attr_field_id] = t('No LDAP Attribute given in !row_descriptor', $tokens);
          }
          if ($mapping['user_attr'] == 'user_tokens' && !$mapping['user_tokens']) {
            $errors[$user_tokens_field_id] = t('User tokens selected in !row_descriptor, but user tokens column empty.', $tokens);
          }

          if (isset($mapping['prov_events']) && count($mapping['prov_events']) == 0) {
            $warnings[$first_context_field_id] = t('No synchronization events checked in !row_descriptor.
              This field will not be synchronized until some are checked.', $tokens);
          }
        }
      }
      if ($to_ldap_entries_mappings_exist && !isset($mappings['[dn]'])) {
        $errors['mappings__' . $synch_direction] = t('Mapping rows exist for provisioning to ldap, but no ldap attribute is targetted for [dn].
          One row must map to [dn].  This row will have a user token like cn=[property.name],ou=users,dc=ldap,dc=mycompany,dc=com');
      }
    }
    return array($errors, $warnings);
  }

  /**
   * Populate object with data from form values.
   *
   * @param array $values
   *   as $form_state->getValues() from drupal form api
   * @param array $storage
   *   as $form_state['storage'] from drupal form api
   */
  protected function populateFromDrupalForm($values, $storage) {
    // print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
    $this->drupalAcctProvisionServer = $values['drupalAcctProvisionServer'];
    $this->ldapEntryProvisionServer = $values['ldapEntryProvisionServer'];
    $this->drupalAcctProvisionTriggers = $values['drupalAcctProvisionTriggers'];
    $this->ldapEntryProvisionTriggers = $values['ldapEntryProvisionTriggers'];
    $this->orphanedDrupalAcctBehavior = $values['orphanedDrupalAcctBehavior'];
    $this->orphanedCheckQty = $values['orphanedCheckQty'];
    $this->manualAccountConflict = $values['manualAccountConflict'];
    $this->userConflictResolve  = ($values['userConflictResolve']) ? (int) $values['userConflictResolve'] : NULL;
    $this->acctCreation  = ($values['acctCreation']) ? (int) $values['acctCreation'] : NULL;
    // $this->wsKey  = ($values['wsKey']) ? $values['wsKey'] : NULL;.
    // $this->wsUserIps  = ($values['wsUserIps']) ? explode("\n", $values['wsUserIps']) : array();
    //  foreach ($this->wsUserIps as $i => $ip) {
    //    $this->wsUserIps[$i] = trim($ip);
    //  }
    // $this->wsEnabled  = ($values['wsEnabled']) ? (int)$values['wsEnabled'] : 0;.
    // $this->ldapUserSynchMappings = $this->synchMappingsFromForm($values, $storage);
  }

  /**
   * Extract synch mappings array from mapping table in admin form.
   *
   * @param array $values
   *   as $form_state->getValues() from drupal form api
   * @param array $storage
   *   as $form_state['storage'] from drupal form api
   *
   *   WARNING: Rewritten using nested forms. This may no longer apply.
   *
   *   $values input names in form:
   *   1__sm__configurable__5,
   *   1__sm__remove__5,
   *   1__sm__ldap_attr__5,
   *   1__sm__convert__5,
   *   1__sm__direction__5,
   *   1__sm__user_attr__5,
   *   1__sm__user_tokens__5
   *   1__sm__1__5,
   *   1__sm__2__5,
   *   ...where
   *    -- first arg is direction, eg 1 or 2 LDAP_USER_PROV_DIRECTION_TO_DRUPAL_USER or LDAP_USER_PROV_DIRECTION_TO_LDAP_ENTRY
   *    -- second arg is discarded ('sm')
   *    -- third part is field, e.g. user_attr
   *    -- fourth is the row in the configuration form, e.g. 5
   *
   *   where additiond data is in $form['#storage'][<direction>]['synch_mapping_fields'][N]
   *   $form['#storage']['synch_mapping_fields'][<direction>][N] = array(
   *    'sid' => $sid,
   *    'action' => 'add',
   *   );
   */
  private function synchMappingsFromForm($values, $storage) {

    $mappings = array();
    foreach ($values as $field_name => $value) {
      $parts = explode('__', $field_name);
      if ($parts[0] != 'mappings') {
        continue;
      }
      $direction = $parts[1];

      // These are our rows.
      foreach ($value as $row_descriptor => $columns) {
        if ($row_descriptor == 'second-header') {
          continue;
        }

        // TODO: remove unused variable or it is a new feature not yet implemented ?
        // $action = $storage['synch_mapping_fields'][$direction][$row_descriptor]['action'];

        $key = ($direction == LDAP_USER_PROV_DIRECTION_TO_DRUPAL_USER) ? _ldap_user_sanitise($columns['user_attr']) : _ldap_user_sanitise($columns['ldap_attr']);
        // Only save if its configurable and has an ldap and drupal attributes. The others are optional.
        if ($columns['configurable_to_drupal'] && $columns['ldap_attr'] && $columns['user_attr']) {
          $mappings[$direction][$key] = array(
            'ldap_attr'   => $columns['ldap_attr'],
            'user_attr'   => $columns['user_attr'],
            'convert'     => $columns['convert'],
            'direction'   => $direction,
            'user_tokens' => $columns['user_tokens'],
            'config_module' => 'ldap_user',
            'prov_module' => 'ldap_user',
            'enabled'     => 1,
          );

          $synchEvents = ($direction == LDAP_USER_PROV_DIRECTION_TO_DRUPAL_USER) ? $this->provisionsDrupalEvents : $this->provisionsLdapEvents;
          foreach ($synchEvents as $prov_event => $discard) {
            if (isset($columns[$prov_event]) && $columns[$prov_event]) {
              $mappings[$direction][$key]['prov_events'][] = $prov_event;
            }
          }
        }
      }
    }
    return $mappings;
  }

  /**
   * Method to respond to successfully validated form submit.
   *
   * @param array $values
   *   as $form_state->getValues() from drupal form api
   * @param array $storage
   *   as $form_state['storage'] from drupal form api
   *
   * @return by reference to $form array
   */
  public function drupalFormSubmit($values, $storage) {
    $this->populateFromDrupalForm($values, $storage);
    try {
      $save_result = $this->save();
      return TRUE;
    }
    catch (Exception $e) {
      drupal_set_message('Failed to save object. Your form data was not saved.', 'error');
      return $e;
    }

  }

  /**
   * Migrated from ldap_user.theme.inc .
   */
  private function getServerMappingHeader($direction) {
    $ldap_user_conf_admin = ldap_user_conf('admin');

    if ($direction == LDAP_USER_PROV_DIRECTION_TO_DRUPAL_USER) {

      $direction_text = 'todrupal';

      $header = array(
        array(
          'data' => t('Remove'),
          'rowspan' => 1,
          'class' => 'mixedcase',
        ),
        array(
          'data' => t('Source LDAP Tokens. (See http://drupal.org/node/1245736) ') ,
          'class' => 'mixedcase',
          'rowspan' => 1,
          'colspan' => 2,
        ),
        array(
          'data' => t('Target Drupal Attribute'),
          'rowspan' => 1,
          'class' => 'mixedcase',
        ),
        array(
          'data' => t('When Should Attribute Be Synchronized to Drupal?'),
          'colspan' => count($ldap_user_conf_admin->provisionsDrupalEvents),
          'rowspan' => 1,
          'class' => 'mixedcase',
        ),

      );

      $second_header = array(
        array(
          'data' => t(''),
          'header' => TRUE,
          'class' => 'mixedcase',
        ),
        array(
          'data' => t('e.g: "[sn]", "[mail:0]", "[ou:last]", "[sn], [givenName]" etc.
                Constants such as "17" or "imported" should not be enclosed in [].'),
          'header' => TRUE,
          'class' => 'mixedcase',
        ),
        array(
          'data' => t('Convert From Binary?'),
          'class' => 'mixedcase',
          'header' => TRUE,
        ),
        array(
          'data' => "",
          'header' => TRUE,
        ),
      );

      foreach ($ldap_user_conf_admin->provisionsDrupalEvents as $col_id => $col_name) {
        $second_header[] = array('data' => $col_name, 'header' => TRUE, 'class' => 'mixedcase');
      }
    }
    // To ldap.
    else {

      $direction_text = 'toldap';

      $header = array(
        array(
          'data' => t('Remove'),
          'rowspan' => 1,
          'class' => 'mixedcase',
        ),
        array(
          'data' => t('Source Drupal User Attribute') ,
          'class' => 'mixedcase',
          'rowspan' => 1,
          'colspan' => 3,
        ),
        array(
          'data' => t('Target LDAP Token'),
          'rowspan' => 1,
          'class' => 'mixedcase',
        ),
        array(
          'data' => t('When Should Attribute Be Synchronized to LDAP?'),
          'colspan' => count($ldap_user_conf_admin->provisionsLdapEvents),
          'rowspan' => 1,
          'class' => 'mixedcase',
        ),
      );

      $second_header = array(
        array(
          'data' => t(''),
          'header' => TRUE,
        ),
        array(
          'data' => t('(Select "user tokens" to use token field)'),
          'header' => TRUE,
          'class' => 'mixedcase',
        ),
        array(
          'data' => t('Source Drupal User tokens such as: "[property.name]", "[field.field_fname] [field.field_lname]". Constants such as "from_drupal" or "18" should not be enclosed in []'),
          'header' => TRUE,
          'class' => 'mixedcase',
        ),
        array(
          'data' => t('Convert From Binary'),
          'header' => TRUE,
          'class' => 'mixedcase',
        ),
        array(
          'data' => t('Use singular token format such as [sn], [givenName], etc.'),
          'header' => TRUE,
          'class' => 'mixedcase',
        ),
      );
      foreach ($ldap_user_conf_admin->provisionsLdapEvents as $col_id => $col_name) {
        $second_header[] = array('data' => $col_name, 'header' => TRUE, 'class' => 'mixedcase');
      }
    }
    return ['header' => $header, 'second_header' => $second_header];
  }

  /**
   *
   */
  private function getServerMappingFields($direction) {
    if ($direction == LDAP_USER_PROV_DIRECTION_NONE) {
      return;
    }

    $rows = array();

    $text = ($direction == LDAP_USER_PROV_DIRECTION_TO_DRUPAL_USER) ? 'target' : 'source';
    $user_attr_options = array('0' => t('Select') . ' ' . $text);

    if (!empty($this->synchMapping[$direction])) {
      foreach ($this->synchMapping[$direction] as $target_id => $mapping) {
        if (!isset($mapping['name']) || isset($mapping['exclude_from_mapping_ui']) && $mapping['exclude_from_mapping_ui']) {
          continue;
        }
        if (
          (isset($mapping['configurable_to_drupal']) && $mapping['configurable_to_drupal'] && $direction == LDAP_USER_PROV_DIRECTION_TO_DRUPAL_USER)
          ||
          (isset($mapping['configurable_to_ldap']) && $mapping['configurable_to_ldap']  && $direction == LDAP_USER_PROV_DIRECTION_TO_LDAP_ENTRY)
          ) {
          $user_attr_options[$target_id] = substr($mapping['name'], 0, 25);
        }
      }
    }
    $user_attr_options['user_tokens'] = '-- user tokens --';

    $row = 0;

    // 1. non configurable mapping rows.
    foreach ($this->synchMapping[$direction] as $target_id => $mapping) {
      $row_id = _ldap_user_sanitise($target_id);
      if (isset($mapping['exclude_from_mapping_ui']) && $mapping['exclude_from_mapping_ui']) {
        continue;
      }
      // Is configurable by ldap_user module (not direction to ldap_user)
      if (!$this->isMappingConfigurable($mapping, 'ldap_user') && ($mapping['direction'] == $direction || $mapping['direction'] == LDAP_USER_PROV_DIRECTION_ALL)) {
        $rows[$row_id] = $this->getSyncFormRow('nonconfigurable', $direction, $mapping, $user_attr_options, $row_id);
        $row++;
      }
    }

    // 2. existing configurable mappings rows.
    if (!empty($this->ldapUserSynchMappings[$direction])) {
      // Key could be ldap attribute name or user attribute name.
      foreach ($this->ldapUserSynchMappings[$direction] as $target_attr_token => $mapping) {
        if (isset($mapping['enabled']) && $mapping['enabled'] && $this->isMappingConfigurable($this->synchMapping[$direction][$target_attr_token], 'ldap_user')) {
          $row_id = 'row-' . $row;
          $rows[$row_id] = $this->getSyncFormRow('update', $direction, $mapping, $user_attr_options, $row_id);
          $row++;
        }
      }
    }

    // 3. leave 4 rows for adding more mappings.
    for ($i = 0; $i < 4; $i++) {
      $row_id = 'custom-' . $i;
      $rows[$row_id] = $this->getSyncFormRow('add', $direction, NULL, $user_attr_options, $row_id);
      $row++;
    }

    return $rows;
  }

  /**
   * Get mapping form row to ldap user provisioning mapping admin form table.
   *
   * @param drupal form array $form
   * @param string $action
   *   is 'add', 'update', or 'nonconfigurable'
   * @param enum $direction
   *   LDAP_USER_PROV_DIRECTION_TO_DRUPAL_USER or LDAP_USER_PROV_DIRECTION_TO_LDAP_ENTRY
   * @param array $mapping
   *   is current setting for updates or nonconfigurable items
   * @param array $user_attr_options
   *   of drupal user target options
   * @param int $row
   *   is current row in table
   *
   * @return a single row
   */
  private function getSyncFormRow($action, $direction, $mapping, $user_attr_options, $row_id) {

    $result = array();
    $id_prefix = 'mappings__' . $direction . '__table';
    $user_attr_input_id = $id_prefix . "[$row_id][user_attr]";

    $result['remove'] = array(
      '#type' => 'checkbox',
      '#title' => 'Remove',
      '#title_display' => 'invisible',
      '#default_value' => NULL,
      '#disabled' => ($action == 'add' || $action == 'nonconfigurable'),
    );

    if ($action == 'nonconfigurable') {
      $ldap_attr = array(
        '#type' => 'item',
        '#default_value' => isset($mapping['ldap_attr']) ? $mapping['ldap_attr'] : '',
        '#markup' => isset($mapping['source']) ? $mapping['source'] : '?',
        '#attributes' => array('class' => array('source')),
      );
    }
    else {
      $ldap_attr = array(
        '#type' => 'textfield',
        '#title' => 'LDAP attribute',
        '#title_display' => 'invisible',
        '#default_value' => isset($mapping['ldap_attr']) ? $mapping['ldap_attr'] : '',
        '#size' => 20,
        '#maxlength' => 255,
        '#attributes' => array('class' => array('ldap-attr')),
      );
      // Change the visibility rules for LDAP_USER_PROV_DIRECTION_TO_LDAP_ENTRY.
      if ($direction == LDAP_USER_PROV_DIRECTION_TO_LDAP_ENTRY) {
        $ldap_attr['#states'] = array(
          'visible' => array(
            'select[name="' . $user_attr_input_id . '"]' => array('value' => 'user_tokens'),
          ),
        );
      }
    }

    $convert = array(
      '#type' => 'checkbox',
      '#title' => 'Convert from binary',
      '#title_display' => 'invisible',
      '#default_value' => isset($mapping['convert']) ? $mapping['convert'] : '',
      '#disabled' => ($action == 'nonconfigurable'),
      '#attributes' => array('class' => array('convert')),
    );

    if ($action == 'nonconfigurable') {
      $user_attr = array(
        '#type' => 'item',
        '#markup' => isset($mapping['name']) ? $mapping['name'] : '?',
      );
    }
    else {
      $user_attr = array(
        '#type' => 'select',
        '#title' => 'User attribute',
        '#title_display' => 'invisible',
        '#default_value' => isset($mapping['user_attr']) ? $mapping['user_attr'] : '',
        '#options' => $user_attr_options,
      );
    }

    // Get the order of the columns correctly.
    if ($direction == LDAP_USER_PROV_DIRECTION_TO_LDAP_ENTRY) {
      $result['user_attr'] = $user_attr;
      $result['ldap_attr'] = $ldap_attr;
      $result['convert'] = $convert;
    }
    else {
      $result['ldap_attr'] = $ldap_attr;
      $result['convert'] = $convert;
      $result['user_attr'] = $user_attr;
    }

    if ($direction == LDAP_USER_PROV_DIRECTION_TO_LDAP_ENTRY) {
      $result['user_tokens'] = array(
        '#type' => 'textfield',
        '#title' => 'User tokens',
        '#title_display' => 'invisible',
        '#default_value' => isset($mapping['user_tokens']) ? $mapping['user_tokens'] : '',
        '#size' => 20,
        '#maxlength' => 255,
        '#disabled' => ($action == 'nonconfigurable'),
        '#attributes' => array('class' => array('tokens')),
      );
    }

    $result['#storage']['synch_mapping_fields'][$direction] = array(
      'action' => $action,
      'direction' => $direction,
    );
    // FIXME: Is col used?
    $col = ($direction == LDAP_USER_PROV_DIRECTION_TO_LDAP_ENTRY) ? 5 : 4;
    $synchEvents = ($direction == LDAP_USER_PROV_DIRECTION_TO_DRUPAL_USER) ? $this->provisionsDrupalEvents : $this->provisionsLdapEvents;

    foreach ($synchEvents as $prov_event => $prov_event_name) {
      // FIXME: Is col used?
      $col++;
      // FIXME: $id never used.
      // $id = $id_prefix . join('__', array('sm', $prov_event, $row));
      $result[$prov_event] = array(
        '#type' => 'checkbox',
        '#title' => $prov_event,
        '#title_display' => 'invisible',
        '#default_value' => isset($mapping['prov_events']) ? (int) (in_array($prov_event, $mapping['prov_events'])) : '',
        '#disabled' => (!$this->provisionEventConfigurable($prov_event, $mapping) || ($action == 'nonconfigurable')),
        '#attributes' => array('class' => array('synch-method')),
      );
    }

    // This one causes the extra column.
    $result['configurable_to_drupal'] = array(
      '#type' => 'hidden',
      '#default_value' => ($action != 'nonconfigurable' ? 1 : 0),
      '#class' => '',
    );

    return $result;
  }

  /**
   * Is a mapping configurable by a given module?
   *
   * @param array $mapping
   *   as mapping configuration for field, attribute, property, etc.
   * @param string $module
   *   machine name such as ldap_user
   *
   * @return boolean
   */
  private function isMappingConfigurable($mapping = NULL, $module = 'ldap_user') {
    $configurable = (
      (
        (!isset($mapping['configurable_to_drupal']) && !isset($mapping['configurable_to_ldap'])) ||
        (isset($mapping['configurable_to_drupal']) && $mapping['configurable_to_drupal']) ||
        (isset($mapping['configurable_to_ldap']) && $mapping['configurable_to_ldap'])
      )
      &&
      (
        !isset($mapping['config_module']) ||
        (isset($mapping['config_module']) && $mapping['config_module'] == $module)
      )
    );
    return $configurable;
  }

  /**
   * Is a particular synch method viable for a given mapping?
   * That is, Can it be enabled in the UI by admins?
   *
   * @param int $prov_event
   * @param array $mapping
   *   is array of mapping configuration.
   *
   * @return boolean
   */
  private function provisionEventConfigurable($prov_event, $mapping = NULL) {

    if ($mapping) {
      if ($prov_event == LDAP_USER_EVENT_CREATE_LDAP_ENTRY || $prov_event == LDAP_USER_EVENT_SYNCH_TO_LDAP_ENTRY) {
        $configurable = (boolean) (!isset($mapping['configurable_to_ldap']) || $mapping['configurable_to_ldap']);
      }
      elseif ($prov_event == LDAP_USER_EVENT_CREATE_DRUPAL_USER || $prov_event == LDAP_USER_EVENT_SYNCH_TO_DRUPAL_USER) {
        $configurable = (boolean) (!isset($mapping['configurable_to_drupal']) || $mapping['configurable_to_drupal']);
      }
    }
    else {
      $configurable = TRUE;
    }

    return $configurable;
  }

  /**
   *
   */
  protected function setTranslatableProperties() {

    $values['drupalAcctProvisionServerDescription'] = t('Check ONE LDAP server configuration to use
      in provisioning Drupal users and their user fields.');
    $values['ldapEntryProvisionServerDescription'] = t('Check ONE LDAP server configuration to create LDAP entries on.');

    $values['drupalAccountProvisionEventsDescription'] = t('Which user fields and properties are synched on create or synch is determined in the
      "Provisioning from LDAP to Drupal mappings" table below in the right two columns.');

    $values['drupalAccountProvisionEventsOptions'] = array(
      LDAP_USER_DRUPAL_USER_PROV_ON_AUTHENTICATE => t('Create or Synch to Drupal user on successful authentication with LDAP
        credentials. (Requires LDAP Authentication module).'),
      LDAP_USER_DRUPAL_USER_PROV_ON_USER_UPDATE_CREATE => t('Create or Synch to Drupal user anytime a Drupal user account
        is created or updated. Requires a server with binding method of "Service Account Bind" or "Anonymous Bind".'),
    );

    $values['ldapEntryProvisionTriggersDescription'] = t('Which LDAP attributes are synched on create or synch is determined in the
      "Provisioning from Drupal to LDAP mappings" table below in the right two columns.');

    $values['ldapEntryProvisionTriggersOptions'] = array(
      LDAP_USER_LDAP_ENTRY_PROV_ON_USER_UPDATE_CREATE => t('Create or Synch to LDAP entry when a Drupal account is created or updated.
        Only applied to accounts with a status of approved.'),
      LDAP_USER_LDAP_ENTRY_PROV_ON_AUTHENTICATE => t('Create or Synch to LDAP entry when a user authenticates.'),
      LDAP_USER_LDAP_ENTRY_DELETE_ON_USER_DELETE => t('Delete LDAP entry when the corresponding Drupal Account is deleted.  This only applies when the LDAP entry was provisioned by Drupal by the LDAP User module.'),
      LDAP_USER_DRUPAL_USER_PROV_ON_ALLOW_MANUAL_CREATE => t('Provide option on admin/people/create to create corresponding LDAP Entry.'),

    );

    $values['orphanedDrupalAcctBehaviorDescription'] = '<ul>' .
       '<li style="color: red">' . t('This is a new feature as of 11/7/2012!
        It is highly recommended to use the "Perform no action,
        but email list of orphaned accounts" for some time before using the
        "Disable the account" options.') . '</li>' .

      '<li>' . t('These actions will only occur if the query to server is successful
      and does not return a user entry.  If the ldap server is down or otherwise
      cannot be connected to, no actions
      will be performed.') . '</li>' .

      '<li>' .
      t('When initially using this option, its best to simply check email and
        delete the accounts by hand.  When you are happy with the
      behavior, switch to one of the automated options.') . '</li>' .

      '</ul>';

    $values['manualAccountConflictOptions'] = array(
      LDAP_USER_MANUAL_ACCT_CONFLICT_REJECT => t('Reject manual creation of Drupal accounts that conflict with LDAP Accounts. This only applies to accounts created on user logon;  Account conflicts can still be generated by manually creating users that conflict with ldap users and these users will have their data synched with LDAP data.'),
      LDAP_USER_MANUAL_ACCT_CONFLICT_LDAP_ASSOCIATE => t('Associate manually created Drupal accounts with related LDAP Account if one exists.'),
      LDAP_USER_MANUAL_ACCT_CONFLICT_SHOW_OPTION_ON_FORM => t('Show option on user create form to determine how account conflict is resolved.'),
    );

    /**
    *  Drupal Account Provisioning and Synching
    */
    $values['userConflictResolveDescription'] = 'What should be done if a local Drupal or other external user account already exists with the same login name.';
    $values['userConflictOptions'] = array(
      LDAP_USER_CONFLICT_LOG => t('Don\'t associate Drupal account with LDAP.  Require user to use Drupal password. Log the conflict'),
      LDAP_USER_CONFLICT_RESOLVE => t('Associate Drupal account with the LDAP entry.  This option
      is useful for creating accounts and assigning roles before an LDAP user authenticates.'),
    );

    $values['acctCreationOptions'] = array(
      LDAP_USER_ACCT_CREATION_LDAP_BEHAVIOR => t('Account creation settings at
        /admin/config/people/accounts/settings do not affect "LDAP Associated" Drupal accounts.'),
      LDAP_USER_ACCT_CREATION_USER_SETTINGS_FOR_LDAP => t('Account creation policy
         at /admin/config/people/accounts/settings applies to both Drupal and LDAP Authenticated users.
         "Visitors" option automatically creates and account when they successfully LDAP authenticate.
         "Admin" and "Admin with approval" do not allow user to authenticate until the account is approved.'),

    );

    foreach ($values as $property => $default_value) {
      $this->$property = $default_value;
    }
  }

}
