<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM version 4.2                                                |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2012                                |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
*/

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC (c) 2004-2012
 * $Id$
 *
 */

/**
 *
 */
class CRM_Core_Permission_Drupal6 {

  /**
   * is this user someone with access for the entire system
   *
   * @var boolean
   */
  static protected $_viewAdminUser = FALSE;
  static protected $_editAdminUser = FALSE;

  /**
   * am in in view permission or edit permission?
   * @var boolean
   */
  static protected $_viewPermission = FALSE;
  static protected $_editPermission = FALSE;

  /**
   * the current set of permissioned groups for the user
   *
   * @var array
   */
  static protected $_viewPermissionedGroups;
  static protected $_editPermissionedGroups;

  /**
   * Get all groups from database, filtered by permissions
   * for this user
   *
   * @param string $groupType     type of group(Access/Mailing)
   * @param boolen $excludeHidden exclude hidden groups.
   *
   * @access public
   * @static
   *
   * @return array - array reference of all groups.
   *
   */
  public static function &group($groupType = NULL, $excludeHidden = TRUE) {
    if (!isset(self::$_viewPermissionedGroups)) {
      self::$_viewPermissionedGroups = self::$_editPermissionedGroups = array();
    }

    $groupKey = $groupType ? $groupType : 'all';

    if (!isset(self::$_viewPermissionedGroups[$groupKey])) {
      self::$_viewPermissionedGroups[$groupKey] = self::$_editPermissionedGroups[$groupKey] = array();

      $groups = CRM_Core_PseudoConstant::allGroup($groupType, $excludeHidden);

      if (self::check('edit all contacts')) {
        // this is the most powerful permission, so we return
        // immediately rather than dilute it further
        self::$_editAdminUser = self::$_viewAdminUser = TRUE;
        self::$_editPermission = self::$_viewPermission = TRUE;
        self::$_editPermissionedGroups[$groupKey] = $groups;
        self::$_viewPermissionedGroups[$groupKey] = $groups;
        return self::$_viewPermissionedGroups[$groupKey];
      }
      elseif (self::check('view all contacts')) {
        self::$_viewAdminUser = TRUE;
        self::$_viewPermission = TRUE;
        self::$_viewPermissionedGroups[$groupKey] = $groups;
      }


      $ids = CRM_ACL_API::group(CRM_Core_Permission::VIEW, NULL, 'civicrm_saved_search', $groups);
      foreach (array_values($ids) as $id) {
        $title = CRM_Core_DAO::getFieldValue('CRM_Contact_DAO_Group', $id, 'title');
        self::$_viewPermissionedGroups[$groupKey][$id] = $title;
        self::$_viewPermission = TRUE;
      }

      $ids = CRM_ACL_API::group(CRM_Core_Permission::EDIT, NULL, 'civicrm_saved_search', $groups);
      foreach (array_values($ids) as $id) {
        $title = CRM_Core_DAO::getFieldValue('CRM_Contact_DAO_Group', $id, 'title');
        self::$_editPermissionedGroups[$groupKey][$id] = $title;
        self::$_viewPermissionedGroups[$groupKey][$id] = $title;
        self::$_editPermission = TRUE;
        self::$_viewPermission = TRUE;
      }
    }

    return self::$_viewPermissionedGroups[$groupKey];
  }

  /**
   * Get group clause for this user
   *
   * @param int $type the type of permission needed
   * @param  array $tables (reference ) add the tables that are needed for the select clause
   * @param  array $whereTables (reference ) add the tables that are needed for the where clause
   * @param string $entityTable table permissions pertain to - if this is civicrm_group it will not be
   *    necessary to resolve smart groups
   *
   * @return string the group where clause for this user
   * @access public
   */
  public static function groupClause($type, &$tables, &$whereTables, $entityTable = 'civicrm_contact') {
    if (!isset(self::$_viewPermissionedGroups)) {
      self::group();
    }

    // we basically get all the groups here
    $groupKey = 'all';
    if ($type == CRM_Core_Permission::EDIT) {
      if (self::$_editAdminUser) {
        $clause = ' ( 1 ) ';
      }
      elseif (empty(self::$_editPermissionedGroups[$groupKey])) {
        $clause = ' ( 0 ) ';
      }
      else {
        $clauses = array();
        $groups = implode(', ', self::$_editPermissionedGroups[$groupKey]);
        $clauses[] = ' ( civicrm_group_contact.group_id IN ( ' . implode(', ', array_keys(self::$_editPermissionedGroups[$groupKey])) . " ) AND civicrm_group_contact.status = 'Added' ) ";
        $tables['civicrm_group_contact'] = 1;
        $whereTables['civicrm_group_contact'] = 1;

        // foreach group that is potentially a saved search, add the saved search clause
        foreach (array_keys(self::$_editPermissionedGroups[$groupKey]) as $id) {
          $group = new CRM_Contact_DAO_Group();
          $group->id = $id;
          if ($group->find(TRUE) && $group->saved_search_id) {
            $clause = CRM_Contact_BAO_SavedSearch::whereClause($group->saved_search_id,
              $tables,
              $whereTables
            );
            if (trim($clause)) {
              $clauses[] = $clause;
            }
          }
        }
        $clause = ' ( ' . implode(' OR ', $clauses) . ' ) ';
      }
    }
    else {
      if (self::$_viewAdminUser) {
        $clause = ' ( 1 ) ';
      }
      elseif (empty(self::$_viewPermissionedGroups[$groupKey])) {
        $clause = ' ( 0 ) ';
      }
      else {
        $clauses = array();
        $groups = implode(', ', self::$_viewPermissionedGroups[$groupKey]);
        $clauses[] = ' civicrm_group_contact.group_id IN (' . implode(', ', array_keys(self::$_viewPermissionedGroups[$groupKey])) . " ) ";
        $tables['civicrm_group_contact'] = 1;
        $whereTables['civicrm_group_contact'] = 1;

        if($entityTable == 'civicrm_contact'){
        // foreach group that is potentially a saved search, add the saved search clause if we are trying to determin
        // which contacts can be seen. per CRM-10667 - we don't need to add smart group clauses if after a
        // list of groups

        }

        $clause = ' ( ' . implode(' OR ', $clauses) . ' ) ';
      }
    }

    return $clause;
  }
  /**
   * get the current permission of this user
   *
   * @return string the permission of the user (edit or view or null)
   */
  public static function getPermission() {
    self::group();

    if (self::$_editPermission) {
      return CRM_Core_Permission::EDIT;
    }
    elseif (self::$_viewPermission) {
      return CRM_Core_Permission::VIEW;
    }
    return NULL;
  }

  /**
   * Get the permissioned where clause for the user
   *
   * @param int $type the type of permission needed
   * @param  array $tables (reference ) add the tables that are needed for the select clause
   * @param  array $whereTables (reference ) add the tables that are needed for the where clause
   *
   * @return string the group where clause for this user
   * @access public
   */
  public static function whereClause($type, &$tables, &$whereTables) {
    self::group();

    return self::groupClause($type, $tables, $whereTables);
  }

  /**
   * given a permission string, check for access requirements
   *
   * @param string $str the permission to check
   *
   * @return boolean true if yes, else false
   * @static
   * @access public
   */
  static
  function check($str, $contactID = NULL) {
    if (function_exists('user_access')) {
      return user_access($str) ? TRUE : FALSE;
    }
    return TRUE;

    /**
     * lets introduce acl in 2.1
     static $isAdmin = null;
     if ( $isAdmin === null ) {
     $session = CRM_Core_Session::singleton( );
     $isAdmin = $session->get( 'ufID' ) == 1 ? true : false;
     }
     return ( $isAdmin) ? true : CRM_ACL_API::check( $str, $contactID );
     */
  }

  /**
   * Given a roles array, check for access requirements
   *
   * @param array $array the roles to check
   *
   * @return boolean true if yes, else false
   * @static
   * @access public
   */
  static
  function checkGroupRole($array) {
    if (function_exists('user_load') && isset($array)) {
      $user = user_load(array('uid' => $GLOBALS['user']->uid));
      //if giver roles found in user roles - return true
      foreach ($array as $key => $value) {
        if (in_array($value, $user->roles)) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }
}

