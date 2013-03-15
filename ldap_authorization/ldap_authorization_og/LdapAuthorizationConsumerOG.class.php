<?php

/**
 * @file
 * class for ldap authorization of organic groups
 *
 * @see LdapAuthorizationConsumerAbstract for property
 *
 */

ldap_servers_module_load_include('php', 'ldap_authorization', 'LdapAuthorizationConsumerAbstract.class');

class LdapAuthorizationConsumerOG extends LdapAuthorizationConsumerAbstract {

  public $consumerType = 'og_group';
  public $allowConsumerObjectCreation = FALSE;
  public $ogVersion = NULL; // 1, 2, etc.
  public $defaultMembershipRid;
  public $anonymousRid;
  public $defaultConsumerConfProperties = array(
      'onlyApplyToLdapAuthenticated' => TRUE,
      'useMappingsAsFilter' => TRUE,
      'synchOnLogon' => TRUE,
      'revokeLdapProvisioned' => TRUE,
      'regrantLdapProvisioned' => TRUE,
      'createConsumers' => TRUE,
      );

  function __construct($consumer_type) {
    $this->defaultMembershipRid = ldap_authorization_og1_role_name_to_role_id(OG_AUTHENTICATED_ROLE);
    $this->anonymousRid = ldap_authorization_og1_role_name_to_role_id(OG_ANONYMOUS_ROLE);
    $this->ogVersion = ldap_authorization_og_og_version();
    $params = ldap_authorization_og_ldap_authorization_consumer();
    parent::__construct('og_group', $params['og_group']);
  }

  public function og1ConsumerIdParts($consumer_id) {
    $parts = explode('-', $consumer_id);
    return (count($parts) != 2) ? array(NULL, NULL) : $parts;
  }

  /**
   * @see LdapAuthorizationConsumerAbstract::createConsumer
   */

  public function createConsumer($consumer_id, $consumer) {

    list($entity_type, $group_name, $rid) = explode(':', $consumer_id);

    $group = @ldap_authorization_og2_get_group_from_name($entity_type, $group_name);
    if ($group) {
      return FALSE;
    }

    // create og group with name of $group_name of entity type $entity_type
    $entity_info = entity_get_info($entity_type);

    $new_group_created = FALSE;

    /**
     *
     * @todo
     * need to create new entity with title of $group_name here
     *
     */

    if ($new_group_created === FALSE) {
      // if role is not created, remove from array to user object doesn't have it stored as granted
      watchdog('user', 'failed to create og group %group_name in ldap_authorizations module', array('%group_name' => $group_name));
      return FALSE;
    }
    else {
      watchdog('user', 'created  og group %group_name in ldap_authorizations module', array('%group_name' => $group_name));
    }
    return TRUE;
  }

  /**
   * @see LdapAuthorizationConsumerAbstract::normalizeMappings
   */
  public function normalizeMappings($mappings) {

    $new_mappings = array();
    if ($this->ogVersion == 2) {
      $group_entity_types = og_get_all_group_bundle();
      foreach ($mappings as $i => $mapping) {
        $from = $mapping[0];
        $to = $mapping[1];
        $to_parts = explode('(raw: ', $to);
        $user_entered = $to_parts[0];
        $new_mapping = array(
          'from' => $from,
          'user_entered' => $user_entered,
          'valid' => TRUE,
          'error_message' => '',
        );

        if (count($to_parts) == 2) { // has simplified and normalized part in (). update normalized part as validation
          $to_normalized = trim($to_parts[1], ')');
          /**
           * users (node:35:1)
           * node:students (node:21:1)
           * faculty (node:33:2)
           * node:35:1 (node:35:1)
           * node:35 (node:35:1)
           */

          $to_simplified = $to_parts[0];
          $to_simplified_parts = explode(':', trim($to_simplified));
          $entity_type = (count($to_simplified_parts) == 1) ? 'node' : $to_simplified_parts[0];
          $role = (count($to_simplified_parts) < 3) ? OG_AUTHENTICATED_ROLE : $to_simplified_parts[2];
          $group_name = (count($to_simplified_parts) == 1) ? $to_simplified_parts[0] :  $to_simplified_parts[1];
          list($group_entity, $group_entity_id) = ldap_authorization_og2_get_group_from_name($entity_type, $group_name);
          $to_simplified = join(':', array($entity_type, $group_name));
        }
        else { // may be simplified or normalized, but not both
          /**
           * users
           * node:students
           * faculty
           * node:35:1
           * node:35
           */
          $to_parts = explode(':', trim($to));
          $entity_type = (count($to_parts) == 1) ? 'node' : $to_parts[0];
          $role = (count($to_parts) < 3) ? OG_AUTHENTICATED_ROLE : $to_parts[2];
          $group_name_or_entity_id = (count($to_parts) == 1) ? $to_parts[0] :  $to_parts[1];
          list($group_entity, $group_entity_id) = ldap_authorization_og2_get_group_from_name($entity_type, $group_name_or_entity_id);
          if ($group_entity) { // if load by name works, $group_name_or_entity_id is group title
            $to_simplified = join(':', array($entity_type, $group_name_or_entity_id));
          }
          else {
            $to_simplified = FALSE;
          }
          $simplified = (boolean)($group_entity);
          if (!$group_entity && ($group_entity = @entity_load_single($entity_type, $group_name_or_entity_id))) {
            $group_entity_id = $group_name_or_entity_id;
          }
        }
        if (!$group_entity) {
          $new_mapping['normalized'] = FALSE;
          $new_mapping['simplified'] = FALSE;
          $new_mapping['valid'] = FALSE;
          $new_mapping['error_message'] = t("cannot find matching group: !to", array('!to' => $to));
        }
        else {
          $role_id = is_numeric($role) ? $role : ldap_authorization_og2_rid_from_role_name($entity_type, $group_entity->type, $group_entity_id, $role);
          $roles = og_roles($entity_type,  isset($group_entity->type) ? $group_entity->type : NULL, 0, FALSE, TRUE);
          $role_name = is_numeric($role) ? $roles[$role] : $role;
          $to_normalized = join(':', array($entity_type, $group_entity_id, $role_id));
          $to_simplified = ($to_simplified) ? $to_simplified . ':' . $role_name : $to_normalized;
          $new_mapping['normalized'] = $to_normalized;
          $new_mapping['simplified'] = $to_simplified;
          if ($to == $to_normalized) {
            /**  if not using simplified notation, do not convert to simplified.
              this would create a situation where an og group
              can change its title and the authorizations change when the
              admin specified the group by entity id
            */
            $new_mapping['user_entered'] = $to;
          }
          else {
            $new_mapping['user_entered'] = $to_simplified . ' (raw: ' . $to_normalized . ')';
          }


        }

        $new_mappings[] = $new_mapping;
      }
    }
    else { // og 1
      foreach ($mappings as $i => $mapping) {
        $new_mapping = array(
          'from' => $mapping[0],
          'user_entered' => $mapping[1],
          'normalized' => NULL,
          'simplified' => NULL,
          'valid' => TRUE,
          'error_message' => '',
        );

        $gid = NULL;
        $rid = NULL;
        $correct_syntax = "gid=43,rid=2 or group-name=students,role-name=member or node.title=students,role-name=member";
        $incorrect_syntax = t('Incorrect mapping syntax.  Correct examples are:') . $correct_syntax;
        $targets = explode(',', $mapping[1]);
        if (count($targets) != 2) {
          $new_mapping['valid'] = FALSE;
          $new_mapping['error_message'] = $incorrect_syntax;
          continue;
        }

        $group_target_and_value =  explode('=', $targets[0]);
        if (count($group_target_and_value) != 2) {
          $new_mapping['valid'] = FALSE;
          $new_mapping['error_message'] = $incorrect_syntax;
          continue;
        }

        list($group_target, $group_target_value) = $group_target_and_value;

        $role_target_and_value = explode('=', $targets[1]);
        if (count($role_target_and_value) != 2) {
          $new_mapping['valid'] = FALSE;
          $new_mapping['error_message'] = $incorrect_syntax;
          continue;
        }
        list($role_target, $role_target_value) = $role_target_and_value;


        $og_group = FALSE;
        if ($group_target == 'gid') {
          $gid = $group_target_value;
        }
        elseif ($group_target == 'group-name') {
          list($og_group, $og_node) = ldap_authorization_og1_get_group($group_target_value, 'group_name', 'object');
          if (is_object($og_group) && property_exists($og_group, 'gid') && $og_group->gid) {
            $gid = $og_group->gid;
          }
        }
        else {
          $entity_type_and_field = explode('.', $group_target);
          if (count($entity_type_and_field) != 2) {
            $new_mapping['valid'] = FALSE;
            $new_mapping['error_message'] = $incorrect_syntax;
            continue;
          }
          list($entity_type, $field) = $entity_type_and_field;

          $query = new EntityFieldQuery();
          $query->entityCondition('entity_type', $entity_type)
            ->fieldCondition($field, 'value', $group_target_value, '=')
            ->addMetaData('account', user_load(1)); // run the query as user 1

          $result = $query->execute();
          if (is_array($result) && isset($result[$entity_type]) && count($result[$entity_type]) == 1) {
            $entities = array_keys($result[$entity_type]);
            $gid = ldap_authorization_og1_entity_id_to_gid($entities[0]);
          }

        }
        if (!$og_group && $gid) {
          $og_group = og_load($gid);
        }


        if ($role_target == 'rid') {
          $role_name = ldap_authorization_og1_role_name_from_rid($role_target_value);
          $rid = $role_target_value;
        }
        elseif ($role_target == 'role-name') {
          $rid = ldap_authorization_og_rid_from_role_name($role_target_value);
          $role_name = $role_target_value;
        }

        $new_mapping['simplified'] = $og_group->label . ', '. $role_name;
        $new_mapping['normalized'] = ($gid && $rid) ? ldap_authorization_og_authorization_id($gid, $rid) : FALSE;

        $new_mappings[] = $new_mapping;
      }

    }
    return $new_mappings;
  }

/**
 * in organic groups 7.x-1.x, consumer ids are in form gid-rid such as 3-2, 3-3.  We want highest authorization available granted.
 * But, granting member role (2), revokes other roles such as admin in OG.  So for granting we want the order:
 * 3-1, 3-2, 3-3 such that 3-3 is retained.  For revoking, the order should not matter, but reverse sorting makes
 * intuitive sense.
 */

  public function sortConsumerIds($op, &$consumers) {
    if ($op == 'revoke') {
      krsort($consumers, SORT_STRING);
    }
    else {
      ksort($consumers, SORT_STRING);
    }
  }

  /**
   * @see LdapAuthorizationConsumerAbstract::populateConsumersFromConsumerIds
   */

  public function populateConsumersFromConsumerIds(&$consumers, $create_missing_consumers = FALSE) {

    // generate a query for all og groups of interest
    $gids = array();
    foreach ($consumers as $consumer_id => $consumer) {
      if (ldap_authorization_og_og_version() == 1) {
        list($gid, $rid) = $this->og1ConsumerIdParts($consumer_id);
        $gids[] = $gid;
      }
      else  {
        //debug("populateConsumersFromConsumerIds.consumer_id=$consumer_id");
        list($entity_type, $gid, $rid) = explode(':', $consumer_id);
        $gids[$entity_type][] = $gid;
      }

    }
    if (ldap_authorization_og_og_version() == 1) {
      $og_group_entities = og_load_multiple($gids);
    }
    else {
      foreach ($gids as $entity_type => $gid_x_entity) {
        $og_group_entities[$entity_type] = @entity_load($entity_type, $gid_x_entity);
      }
    }

    foreach ($consumers as $consumer_id => $consumer) {
      if (ldap_authorization_og_og_version() == 1) {
        list($gid, $rid) = $this->og1ConsumerIdParts($consumer_id);
        $consumer['exists'] = isset($og_group_entities[$gid]);
        if ($consumer['exists']) {
          $consumer['value'] = $og_group_entities[$gid];
          if (empty($consumer['name']) && property_exists($og_group_entities[$gid], 'title')) {
            $consumer['name'] = $og_group_entities[$gid]->title;
          }
          $consumer['name'] =  $consumer_id;
        }
        else {
          $consumer['value'] = NULL;
          $consumer['name'] = NULL;
        }

        $consumer['map_to_string'] = $consumer_id;
      }
      else  {
        list($entity_type, $gid, $rid) = explode(':', $consumer_id);
        $consumer['exists'] = isset($og_group_entities[$entity_type][$gid]);
        $consumer['value'] = ($consumer['exists']) ? $og_group_entities[$entity_type][$gid] : NULL;
        $consumer['map_to_string'] = $consumer_id;
        if (
          empty($consumer['name']) &&
          !empty($og_group_entities[$entity_type][$gid]) &&
          property_exists($og_group_entities[$entity_type][$gid], 'title')
        ) {
          $consumer['name'] = $og_group_entities[$entity_type][$gid]->title;
        }
      }

      if (!$consumer['exists'] && $create_missing_consumers) {
         // @todo if creation of og groups were implemented, function would be called here
         // this would mean mapping would need to have enough info to configure a group,
         // or settings would need to include a default group type to create (entity type,
         // bundle, etc.)
      }
      $consumers[$consumer_id] = $consumer;
    }
  }


  public function hasAuthorization(&$user, $consumer_id) {

    if ($this->ogVersion == 1) {
      $result = FALSE;
      list($gid, $rid) = $this->og1ConsumerIdParts($consumer_id);
      return ldap_authorization_og1_has_membership($gid, $user->uid) && ldap_authorization_og1_has_role($gid, $user->uid, $rid);
    }
    else {
      return ldap_authorization_og2_has_consumer_id($consumer_id, $user->uid);
    }
  }

  public function flushRelatedCaches($consumers = NULL) {
    if ($this->ogVersion == 1) { // og 7.x-1.x
      og_invalidate_cache();
    }
    else { // og 7.x-2.x
      og_invalidate_cache(); //gids could be passed in here, but not implemented within og
    }
  }

 /**
   * @param string $op 'grant' or 'revoke' signifying what to do with the $consumer_ids
   * @param drupal user object $object
   * @param array $user_auth_data is array specific to this consumer_type.  Stored at $user->data['ldap_authorizations'][<consumer_type>]
   * @param $consumers as associative array in form of LdapAuthorizationConsumerAbstract::populateConsumersFromConsumerIds
   * @param array $ldap_entry, when available user's ldap entry.
   * @param boolean $user_save indicates is user data array should be saved or not.  this depends on the implementation calling this function
   */

  protected function grantsAndRevokes($op, &$user, &$user_auth_data, $consumers, &$ldap_entry = NULL, $user_save = TRUE) {

    if ($this->ogVersion != 1) { // only override for og 7.x-1.x
      parent::grantsAndRevokes($op, $user, $user_auth_data, $consumers, $ldap_entry, $user_save);
      return;
    }

    if (!is_array($user_auth_data)) {
      $user_auth_data = array();
    }

    $detailed_watchdog_log = variable_get('ldap_help_watchdog_detail', 0);
    $this->sortConsumerIds($op, $consumers);

    $results = array();
    $watchdog_tokens = array();
    $watchdog_tokens['%username'] = $user->name;
    $watchdog_tokens['%action'] = $op;
    $watchdog_tokens['%user_save'] = $user_save;

    /**
     * get authorizations that exist, regardless of origin or ldap_authorization $user->data
     * in form $users_authorization_consumer_ids = array('3-2', '3,3', '4-2')
     */
    $users_authorization_consumer_ids = $this->usersAuthorizations($user);

    $watchdog_tokens['%users_authorization_ids'] = join(', ', $users_authorization_consumer_ids);
    if ($detailed_watchdog_log) {
      watchdog('ldap_authorization', "on call of grantsAndRevokes: user_auth_data=" . print_r($user_auth_data, TRUE), $watchdog_tokens, WATCHDOG_DEBUG);
    }

    /**
     * step #1:  generate $og_actions = array of form $og_actions['revokes'|'grants'][$gid] = $rid
     *  based on all consumer ids granted and revokes
     */
    //dpm('consumers');dpm($consumers); dpm('users_authorization_consumer_ids'); dpm($users_authorization_consumer_ids);
    foreach ($consumers as $consumer_id => $consumer) {
      if ($detailed_watchdog_log) {
        watchdog('ldap_authorization', "consumer_id=$consumer_id, user_save=$user_save, op=$op", $watchdog_tokens, WATCHDOG_DEBUG);
      }

      $user_has_authorization = in_array($consumer_id, $users_authorization_consumer_ids); // does user already have authorization ?
      $user_has_authorization_recorded = isset($user_auth_data[$consumer_id]);  // is authorization attribute to ldap_authorization_og in $user->data ?
      list($gid, $rid) = $this->og1ConsumerIdParts($consumer_id);
      if ($rid == $this->anonymousRid) {
        continue;
      }

      /** grants **/
      if ($op == 'grant') {
        if ($user_has_authorization && !$user_has_authorization_recorded) {
        // grant case 1: authorization id already exists for user, but is not ldap provisioned.  mark as ldap provisioned, but don't regrant
          $results[$consumer_id] = TRUE;
          $user_auth_data[$consumer_id] = array(
            'date_granted' => time(),
            'consumer_id_mixed_case' => $consumer_id,
          );
        }
        elseif (!$user_has_authorization && $consumer['exists']) {
        // grant case 2: consumer exists, but user is not member. grant authorization
          $og_actions['grants'][$gid][] = $rid;
        }
        elseif ($consumer['exists'] !== TRUE) {
        // grant case 3: something is wrong. consumers should have been created before calling grantsAndRevokes
          $results[$consumer_id] = FALSE;
        }
        elseif ($consumer['exists'] === TRUE) {
        // grant case 4: consumer exists and user has authorization recorded. do nothing
          $results[$consumer_id] = TRUE;
        }
        else {
        // grant case 5: $consumer['exists'] has not been properly set before calling function
          $results[$consumer_id] = FALSE;
          watchdog('ldap_authorization', "grantsAndRevokes consumer[exists] not properly set. consumer_id=$consumer_id, op=$op, username=%username", $watchdog_tokens, WATCHDOG_ERROR);
        }
      }
      /** revokes **/
      elseif ($op == 'revoke') {
        if ($user_has_authorization) {
          // revoke case 1: user has authorization, revoke it.  revokeSingleAuthorization will remove $user_auth_data[$consumer_id]
          $og_actions['revokes'][$gid][] = $rid;
        }
        elseif ($user_has_authorization_recorded)  {
          // revoke case 2: user does not have authorization, but has record of it. remove record of it.
          unset($user_auth_data[$consumer_id]);
          $results[$consumer_id] = TRUE;
        }
        else {
          // revoke case 3: trying to revoke something that isn't there
          $results[$consumer_id] = TRUE;
        }
      }
      if ($detailed_watchdog_log) {
        watchdog('ldap_authorization', "user_auth_data after consumer $consumer_id" . print_r($user_auth_data, TRUE), $watchdog_tokens, WATCHDOG_DEBUG);
      }
    }

    /**
     * Step #2: from array of form:  $og_actions['grants'|'revokes'][$gid][$rid]
     * - generate $user->data['ldap_authorizations'][<consumer_id>]
     * - remove and grant og memberships
     * - remove and grant og roles
     */
    //dpm("og_actions"); dpm($og_actions); dpm("user_auth_data"); dpm($user_auth_data);

    $group_audience = $user->{OG_AUDIENCE_FIELD}[LANGUAGE_NONE];

    // grants
    foreach ($og_actions['grants'] as $gid => $rids) {
      $existing_roles = og_get_user_roles($gid, $user->uid);
      if (!in_array($this->defaultMembershipRid, array_values($existing_roles))) {
        $user->{OG_AUDIENCE_FIELD}[LANGUAGE_NONE][] = array('gid' => $gid);
        og_entity_presave($user, 'user');
        $user_auth_data[ldap_authorization_og_authorization_id($gid, $this->defaultMembershipRid)] = array(
          'date_granted' => time(),
          'consumer_id_mixed_case' => $consumer_id,
        );
      }
      foreach ($rids as $rid) {
        if ($rid != $this->defaultMembershipRid && $rid != $this->anonymousRid) {
          og_role_grant($gid, $user->uid, $rid);
          $user_auth_data[ldap_authorization_og_authorization_id($gid, $rid)] = array(
            'date_granted' => time(),
            'consumer_id_mixed_case' => $consumer_id,
            );
        }
      }
    }

    // revokes
    foreach ($og_actions['revokes'] as $gid => $rids) {
      $existing_roles = og_get_user_roles($gid, $user->uid);
      if (in_array($this->defaultMembershipRid, array_values($existing_roles))) {
        // ungroup and set audience
        foreach ($group_audience['gid'] as $i => $_audience_gid) {
           if ($_audience_gid == $gid) {
             unset($user->{OG_AUDIENCE_FIELD}[LANGUAGE_NONE][$i]);
           }
        }
        og_entity_presave($user, 'user');
        $user = og_ungroup($gid, 'user', $user, TRUE);
        foreach (array_values($existing_roles) as $rid) {
          $consumer_id = ldap_authorization_og_authorization_id($gid, $rid);
          if (isset($user_auth_data[$consumer_id])) {
            unset($user_auth_data[$consumer_id]);
          }
        }
      }
      else {
        foreach ($existing_roles as $rid) {
          if ($rid != $this->defaultMembershipRid && $this->defaultMembershipRid != 1) {
            og_role_revoke($gid, $user->uid, $rid);
            unset($user_auth_data[ldap_authorization_og_authorization_id($gid, $rid)]);
          }
        }
      }
    }

    if ($user_save) {
      $user_edit['data']['ldap_authorizations'][$this->consumerType] = $user_auth_data;
      $user = user_save($user, $user_edit);
      $user_auth_data = $user->data['ldap_authorizations'][$this->consumerType];  // reload this because hooks impact it.
    }
   //  dpm("user_save=$user_save, user post save"); dpm($user ); dpm("user_auth_data post save"); dpm($user_auth_data);
    $this->flushRelatedCaches($consumers);

    if ($detailed_watchdog_log) {
      watchdog('ldap_authorization', '%username:
        <hr/>LdapAuthorizationConsumerAbstract grantsAndRevokes() method log.  action=%action:<br/> %consumer_ids_log
        ',
        $watchdog_tokens, WATCHDOG_DEBUG);
    }

  }

/**
  * revoke an authorization
  *
  * @see ldapAuthorizationConsumerAbstract::revokeSingleAuthorization()
  *
  */

  public function revokeSingleAuthorization(&$user, $consumer_id, $consumer, &$user_auth_data, $discarded_user_save_flag = TRUE, $reset = FALSE) {

    $watchdog_tokens =  array('%consumer_id' => $consumer_id, '%username' => $user->name,
      '%ogversion' => $this->ogVersion, '%function' => 'LdapAuthorizationConsumerOG.revokeSingleAuthorization()');

    list($group_entity_type, $gid, $rid) = @explode(':', $consumer_id);

    if (!$this->hasAuthorization($user, $consumer_id)) {
      og_invalidate_cache(array($gid)); // if trying to revoke, but thinks not granted, flush cache
      if (!$this->hasAuthorization($user, $consumer_id)) {
        if (isset($user_auth_data[$consumer_id])) {
          unset($user_auth_data[$consumer_id]);
        }
        return TRUE;
      }
    }

    // make sure group exists, since og doesn't do much error catching.
    if (!empty($consumer['value'])) {
      $og_group = $consumer['value'];
    }
    else {
      $og_group = @entity_load_single($group_entity_type, $gid);
      if (!$og_group) {
        return FALSE; // group cannot be found
      }
    }

    $users_group_roles = og_get_user_roles($group_entity_type, $gid, $user->uid);

    if (count($users_group_roles) == 1) {  // ungroup if only single role left
      $entity = og_ungroup($group_entity_type, $gid, 'user', $user->uid);
      $result = (boolean)($entity);
      $watchdog_tokens['%action'] = 'og_ungroup';
    }
    else { // if more than one role left, just revoke single role.
      og_role_revoke($group_entity_type, $gid, $user->uid, $rid);
      $watchdog_tokens['%action'] = 'og_role_revoke';
      $result = TRUE;
    }


    if ($reset) {
      og_invalidate_cache(array($gid));
    }
    $watchdog_tokens['%result'] = (int)$result;
    if ($this->detailedWatchdogLog) {
      watchdog('ldap_authorization_og', '%function revoked: result=%result, gid=%gid, rid=%rid, action=%action for username=%username',
        $watchdog_tokens, WATCHDOG_DEBUG);
    }
    if ($result && isset($user_auth_data[$consumer_id])) {
      unset($user_auth_data[$consumer_id]);
    }
    return $result;

  }

  /**
   * grant single authorization
   *
   * @see ldapAuthorizationConsumerAbstract::grantSingleAuthorization()
   *
   */
  public function grantSingleAuthorization(&$user, $consumer_id, $consumer, &$user_auth_data, $discarded_user_save_flag = TRUE, $reset = FALSE) {

    $watchdog_tokens =  array(
      '%consumer_id' => $consumer_id,
      '%username' => $user->name,
      '%ogversion' => $this->ogVersion,
      '%function' => 'LdapAuthorizationConsumerOG.grantSingleAuthorization()'
    );

    list($group_entity_type, $gid, $rid) = @explode(':', $consumer_id);
    $watchdog_tokens['%entity_type'] = $group_entity_type;

    if ($this->hasAuthorization($user, $consumer_id)) {
      og_invalidate_cache(array($gid)); // if trying to grant, but things already granted, flush cache
      if ($this->hasAuthorization($user, $consumer_id)) {
        return TRUE;
      }
    }

    if (empty($consumer['exists'])) {
      if ($this->detailedWatchdogLog) {
        watchdog('ldap_auth_og', '%function consumer_id %consumer_id does not exist', $watchdog_tokens, WATCHDOG_DEBUG);
      }
      return FALSE;
    }

    $watchdog_tokens['%gid'] = $gid;
    $watchdog_tokens['%rid'] = $rid;
    $watchdog_tokens['%uid'] = $user->uid;

    // CASE:  grant role
    if ($this->detailedWatchdogLog) {
      watchdog('ldap_auth_og', '%function calling og_role_grant(%entity_type, %gid, %uid, %rid). og version=%ogversion',
        $watchdog_tokens, WATCHDOG_DEBUG);
    }

    //@todo.  is 'entity' param in og2 supposed to point to entity id?
    $values = array(
      'entity_type' => 'user',
      'entity' => $user->uid,
      'field_name' => FALSE,
      'state' => OG_STATE_ACTIVE,
    );
    $og_membership = og_group($group_entity_type, $gid, $values);
    og_role_grant($group_entity_type, $gid, $user->uid, $rid);

    if ($reset) {
      og_invalidate_cache(array($gid));
    }

    if ($this->detailedWatchdogLog) {
      watchdog('ldap_auth_og', '%function <hr />granted: entity_type=%entity_type gid=%gid, rid=%rid for username=%username',
      $watchdog_tokens, WATCHDOG_DEBUG);
    }
    return TRUE;

  }

  /**
   * @see ldapAuthorizationConsumerAbstract::usersAuthorizations
   */

  public function usersAuthorizations(&$user) {

    $authorizations = array();

    if ($this->ogVersion == 1) {
      $gids = og_get_groups_by_user($user);
      $authorizations = array();
      foreach ($gids as $i => $gid) {
        $roles = og_get_user_roles($gid, $user->uid);

        if (!empty($roles[$this->defaultMembershipRid])) { // if you aren't a member, doesn't matter what roles you have in og 1.5
          if (isset($roles[$this->anonymousRid])) {
            unset($roles[$this->anonymousRid]);
          } // ignore anonymous role
          $rids = array_values($roles);
          asort($rids, SORT_NUMERIC); // go low to high to get default memberships first
          foreach ($rids as $rid) {
            $authorizations[] = ldap_authorization_og_authorization_id($gid, $rid);
          }
        }
      }
    }
    else { // og 7.x-2.x
      $user_entities = entity_load('user', array($user->uid));
      $memberships = og_get_entity_groups('user', $user_entities[$user->uid]);
      foreach ($memberships as $entity_type => $entity_memberships) {
        foreach ($entity_memberships as $og_membership_id => $gid) {
          $roles = og_get_user_roles($entity_type, $gid, $user->uid);
          foreach ($roles as $rid => $discard) {
            $authorizations[] =  ldap_authorization_og_authorization_id($gid, $rid, $entity_type);
          }
        }
      }
    }
    return $authorizations;
  }

  /**
   * @see ldapAuthorizationConsumerAbstract::convertToFriendlyAuthorizationIds
   */
  public function convertToFriendlyAuthorizationIds($authorizations) {
    $authorization_ids_friendly = array();
    foreach ($authorizations as $authorization_id => $authorization) {
      $authorization_ids_friendly[] = $authorization['name'] . '  (' . $authorization_id . ')';
    }
    return $authorization_ids_friendly;
  }

  /**
   * @see ldapAuthorizationConsumerAbstract::validateAuthorizationMappingTarget
   */
  public function validateAuthorizationMappingTarget($mapping, $form_values = NULL, $clear_cache = FALSE) {
    // these mappings have already been through the normalizeMappings() method, so no real querying needed here.

    $has_form_values = is_array($form_values);
    $message_type = NULL;
    $message_text = NULL;
    $pass = !empty($mapping['valid']) && $mapping['valid'] === TRUE;

    /**
     * @todo need to look this over
     *
     */
    if (!$pass) {
      $tokens = array(
        '!from' => $mapping['from'],
        '!user_entered' => $mapping['user_entered'],
        '!error' => $mapping['error_message'],
        );
      $message_text = '<code>"' . t('!map_to|!user_entered', $tokens) . '"</code> ' . t('has the following error: !error.', $tokens);
    }
    return array($message_type, $message_text);
  }

  /**
   * Get list of mappings based on existing Organic Groups and roles
   *
   * @param associative array $tokens of tokens and replacement values
   * @return html examples of mapping values
   */

  public function mappingExamples($tokens) {

    if ($this->ogVersion == 1) {
      $groups = og_get_all_group();
      $ogEntities = og_load_multiple($groups);
      $OGroles = og_roles(0);

      $rows = array();
      foreach ($ogEntities as $group) {
        foreach ($OGroles as $rid => $role) {
          $example =   "<code>ou=IT,dc=myorg,dc=mytld,dc=edu|gid=" . $group->gid . ',rid=' . $rid . '</code><br/>' .
            '<code>ou=IT,dc=myorg,dc=mytld,dc=edu|group-name=' . $group->label . ',role-name=' . $role . '</code>';
          $rows[] = array(
            $group->label,
            $group->gid,
            $role,
            $example,
          );
        }
      }

      $variables = array(
      'header' => array('Group Name', 'OG Group ID', 'OG Membership Type', 'example'),
      'rows' => $rows,
      'attributes' => array(),
      );
    }
    else {

      /**
       * OG 7.x-2.x mappings:
       * $entity_type = $group_type,
       * $bundle = $group_bundle
       * $etid = $gid where edid is nid, uid, etc.
       *
       * og group is: entity_type (eg node) x entity_id ($gid) eg. node:17
       * group identifier = group_type:gid; aka entity_type:etid e.g. node:17
       *
       * membership identifier is:  group_type:gid:entity_type:etid
       * in our case: group_type:gid:user:uid aka entity_type:etid:user:uid e.g. node:17:user:2
       *
       * roles are simply rids ((1,2,3) and names (non-member, member, and administrator member) in og_role table
       * og_users_roles is simply uid x rid x gid
       *
       * .. so authorization mappings should look like:
       *    <ldap group>|group_type:gid:rid such as staff|node:17:2
       */

      $og_fields = field_info_field(OG_GROUP_FIELD);
      $rows = array();
      $role_name = OG_AUTHENTICATED_ROLE;

      if (!empty($og_fields['bundles'])) {
        foreach ($og_fields['bundles'] as $entity_type => $bundles) {

          foreach ($bundles as $i => $bundle) {

            $query = new EntityFieldQuery();
            $query->entityCondition('entity_type', $entity_type)
              ->entityCondition('bundle', $bundle)
              ->range(0, 5)
              ->addMetaData('account', user_load(1)); // run the query as user 1
            $result = $query->execute();
            $entities = entity_load($entity_type, array_keys($result[$entity_type]));
            $i=0;
            foreach ($entities as $entity_id => $entity) {
              $i++;
              $rid = ldap_authorization_og2_rid_from_role_name($entity_type, $bundle, $entity_id, OG_AUTHENTICATED_ROLE);
              $title = (is_object($entity) && property_exists($entity, 'title')) ? $entity->title : '';
              $middle = ($title && $i < 3) ? $title : $entity_id;
              $group_role_identifier = ldap_authorization_og_authorization_id($middle, $rid, $entity_type);
              $example = "<code>ou=IT,dc=myorg,dc=mytld,dc=edu|$group_role_identifier</code>";
              $rows[] = array("$entity_type $title - $role_name", $example);

            }

          }
        }
      }

      $variables = array(
        'header' => array('Group Entity - Group Title - OG Membership Type', 'example'),
        'rows' => $rows,
        'attributes' => array(),
      );
    }

    $table = theme('table', $variables);
    $link = l(t('admin/config/people/ldap/authorization/test/og_group'), 'admin/config/people/ldap/authorization/test/og_group');

$examples =
<<<EOT

<br/>
Examples for some (or all) existing OG Group IDs can be found in the table below.
This is complex.  To test what is going to happen, uncheck "When a user logs on" in IV.B.
and use $link to see what memberships sample users would receive.

$table

EOT;
    $examples = t($examples, $tokens);
    return $examples;
  }

}
