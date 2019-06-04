<?php
/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2018 Teclib' and contributors.
 *
 * http://glpi-project.org
 *
 * based on GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2003-2014 by the INDEPNET Development Team.
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * GLPI is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * GLPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 */

use Glpi\Event;

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/**
 * Ticket Class
**/
class Ticket extends CommonITILObject {

   // From CommonDBTM
   public $dohistory                   = true;
   static protected $forward_entity_to = ['TicketValidation', 'TicketCost'];

   // From CommonITIL
   public $userlinkclass               = 'Ticket_User';
   public $grouplinkclass              = 'Group_Ticket';
   public $supplierlinkclass           = 'Supplier_Ticket';

   static $rightname                   = 'ticket';

   protected $userentity_oncreate      = true;

   const MATRIX_FIELD                  = 'priority_matrix';
   const URGENCY_MASK_FIELD            = 'urgency_mask';
   const IMPACT_MASK_FIELD             = 'impact_mask';
   const STATUS_MATRIX_FIELD           = 'ticket_status';

   // HELPDESK LINK HARDWARE DEFINITION : CHECKSUM SYSTEM : BOTH=1*2^0+1*2^1=3
   const HELPDESK_MY_HARDWARE  = 0;
   const HELPDESK_ALL_HARDWARE = 1;

   // Specific ones
   /// Hardware datas used by getFromDBwithData
   public $hardwaredatas = [];
   /// Is a hardware found in getHardwareData / getFromDBwithData : hardware link to the job
   public $computerfound = 0;

   // Request type
   const INCIDENT_TYPE = 1;
   // Demand type
   const DEMAND_TYPE   = 2;

   const READMY           =      1;
   const READALL          =   1024;
   const READGROUP        =   2048;
   const READASSIGN       =   4096;
   const ASSIGN           =   8192;
   const STEAL            =  16384;
   const OWN              =  32768;
   const CHANGEPRIORITY   =  65536;
   const SURVEY           = 131072;


   function getForbiddenStandardMassiveAction() {

      $forbidden = parent::getForbiddenStandardMassiveAction();

      if (!Session::haveRightsOr(self::$rightname, [DELETE, PURGE])) {
         $forbidden[] = 'delete';
         $forbidden[] = 'purge';
         $forbidden[] = 'restore';
      }

      return $forbidden;
   }


   /**
    * Name of the type
    *
    * @param $nb : number of item in the type (default 0)
   **/
   static function getTypeName($nb = 0) {
      return _n('Ticket', 'Tickets', $nb);
   }


   /**
    * @see CommonGLPI::getMenuShorcut()
    *
    * @since 0.85
   **/
   static function getMenuShorcut() {
      return 't';
   }


   /**
    * @see CommonGLPI::getAdditionalMenuOptions()
    *
    * @since 0.85
   **/
   static function getAdditionalMenuOptions() {

      if (TicketTemplate::canView()) {
         $menu['TicketTemplate']['title']           = TicketTemplate::getTypeName(Session::getPluralNumber());
         $menu['TicketTemplate']['page']            = TicketTemplate::getSearchURL(false);
         $menu['TicketTemplate']['links']['search'] = TicketTemplate::getSearchURL(false);
         if (TicketTemplate::canCreate()) {
            $menu['TicketTemplate']['links']['add'] = TicketTemplate::getFormURL(false);
         }
         return $menu;
      }
      return false;
   }


   /**
    * @see CommonGLPI::getAdditionalMenuContent()
    *
    * @since 0.85
   **/
   static function getAdditionalMenuContent() {

      if (static::canCreate()) {
         $menu['create_ticket']['title']    = __('Create ticket');
         $menu['create_ticket']['page']     = static::getFormURL(false);
         return $menu;
      } else {
         return self::getAdditionalMenuOptions();
      }
   }


   /**
    * @see CommonGLPI::getAdditionalMenuLinks()
    *
    * @since 0.85
   **/
   static function getAdditionalMenuLinks() {
      global $CFG_GLPI;

      $links = [];
      if (TicketTemplate::canView()) {
         $links['template'] = TicketTemplate::getSearchURL(false);
      }
      if (Session::haveRightsOr('ticketvalidation', TicketValidation::getValidateRights())) {
         $opt = [];
         $opt['reset']         = 'reset';
         $opt['criteria'][0]['field']      = 55; // validation status
         $opt['criteria'][0]['searchtype'] = 'equals';
         $opt['criteria'][0]['value']      = CommonITILValidation::WAITING;
         $opt['criteria'][0]['link']       = 'AND';

         $opt['criteria'][1]['field']      = 59; // validation aprobator
         $opt['criteria'][1]['searchtype'] = 'equals';
         $opt['criteria'][1]['value']      = Session::getLoginUserID();
         $opt['criteria'][1]['link']       = 'AND';

         $opt['criteria'][2]['field']      = 52; // global validation status
         $opt['criteria'][2]['searchtype'] = 'equals';
         $opt['criteria'][2]['value']      = CommonITILValidation::WAITING;
         $opt['criteria'][2]['link']       = 'AND';

         $opt['criteria'][3]['field']      = 12; // ticket status
         $opt['criteria'][3]['searchtype'] = 'equals';
         $opt['criteria'][3]['value']      = Ticket::CLOSED;
         $opt['criteria'][3]['link']       = 'AND NOT';

         $opt['criteria'][4]['field']      = 12; // ticket status
         $opt['criteria'][4]['searchtype'] = 'equals';
         $opt['criteria'][4]['value']      = Ticket::SOLVED;
         $opt['criteria'][4]['link']       = 'AND NOT';

         $pic_validate = "<img title=\"".__s('Ticket waiting for your approval')."\" alt=\"".
                           __s('Ticket waiting for your approval')."\" src='".
                           $CFG_GLPI["root_doc"]."/pics/menu_showall.png' class='pointer'>";

         $links[$pic_validate] = Ticket::getSearchURL(false) . '?'.Toolbox::append_params($opt, '&amp;');
      }
      if (count($links)) {
         return $links;
      }
      return false;
   }


   function canAdminActors() {

      if (isset($this->fields['is_deleted']) && $this->fields['is_deleted'] == 1) {
         return false;
      }
      return Session::haveRight(self::$rightname, UPDATE);
   }


   function canAssign() {

      if (isset($this->fields['is_deleted']) && ($this->fields['is_deleted'] == 1)
          || isset($this->fields['status']) && in_array($this->fields['status'], $this->getClosedStatusArray())
      ) {
         return false;
      }
      return Session::haveRight(self::$rightname, self::ASSIGN);
   }


   function canAssignToMe() {

      if (isset($this->fields['is_deleted']) && $this->fields['is_deleted'] == 1
         || isset($this->fields['status']) && in_array($this->fields['status'], $this->getClosedStatusArray())
      ) {
         return false;
      }
      return (Session::haveRight(self::$rightname, self::STEAL)
              || (Session::haveRight(self::$rightname, self::OWN)
                  && ($this->countUsers(CommonITILActor::ASSIGN) == 0)));
   }


   static function canUpdate() {

      // To allow update of urgency and category for post-only
      if (Session::getCurrentInterface() == "helpdesk") {
         return Session::haveRight(self::$rightname, CREATE);
      }

      return Session::haveRightsOr(self::$rightname,
                                   [UPDATE,
                                         self::ASSIGN,
                                         self::STEAL,
                                         self::OWN,
                                         self::CHANGEPRIORITY]);
   }


   static function canView() {
      return (Session::haveRightsOr(self::$rightname,
                                    [self::READALL, self::READMY, UPDATE, self::READASSIGN,
                                          self::READGROUP, self::OWN])
              || Session::haveRightsOr('ticketvalidation', TicketValidation::getValidateRights()));
   }


   /**
    * Is the current user have right to show the current ticket ?
    *
    * @return boolean
   **/
   function canViewItem() {

      if (!Session::haveAccessToEntity($this->getEntityID())) {
         return false;
      }
      return (Session::haveRight(self::$rightname, self::READALL)
              || (Session::haveRight(self::$rightname, self::READMY)
                  && (($this->fields["users_id_recipient"] === Session::getLoginUserID())
                      || $this->isUser(CommonITILActor::REQUESTER, Session::getLoginUserID())
                      || $this->isUser(CommonITILActor::OBSERVER, Session::getLoginUserID())))
              || (Session::haveRight(self::$rightname, self::READGROUP)
                  && isset($_SESSION["glpigroups"])
                  && ($this->haveAGroup(CommonITILActor::REQUESTER, $_SESSION["glpigroups"])
                      || $this->haveAGroup(CommonITILActor::OBSERVER, $_SESSION["glpigroups"])))
              || (Session::haveRight(self::$rightname, self::READASSIGN)
                  && ($this->isUser(CommonITILActor::ASSIGN, Session::getLoginUserID())
                      || (isset($_SESSION["glpigroups"])
                          && $this->haveAGroup(CommonITILActor::ASSIGN, $_SESSION["glpigroups"]))
                      || (Session::haveRight(self::$rightname, self::ASSIGN)
                          && ($this->fields["status"] == self::INCOMING))))
              || (Session::haveRightsOr('ticketvalidation', TicketValidation::getValidateRights())
                  && TicketValidation::canValidate($this->fields["id"])));
   }


   /**
    * Is the current user have right to approve solution of the current ticket ?
    *
    * @return boolean
   **/
   function canApprove() {

      return ((($this->fields["users_id_recipient"] === Session::getLoginUserID())
               &&  Session::haveRight('ticket', Ticket::SURVEY))
              || $this->isUser(CommonITILActor::REQUESTER, Session::getLoginUserID())
              || (isset($_SESSION["glpigroups"])
                  && $this->haveAGroup(CommonITILActor::REQUESTER, $_SESSION["glpigroups"])));
   }


   /**
    * @see CommonDBTM::canMassiveAction()
   **/
   function canMassiveAction($action, $field, $value) {

      switch ($action) {
         case 'update' :
            switch ($field) {
               case 'status' :
                  if (!self::isAllowedStatus($this->fields['status'], $value)) {
                     return false;
                  }
                  break;
            }
            break;
      }
      return true;
   }

   /**
    * Check if current user can take into account the ticket.
    *
    * @return boolean
    */
   public function canTakeIntoAccount() {

      // Can take into account if user is assigned user
      if ($this->isUser(CommonITILActor::ASSIGN, Session::getLoginUserID())
          || (isset($_SESSION["glpigroups"])
             && $this->haveAGroup(CommonITILActor::ASSIGN, $_SESSION['glpigroups']))) {
         return true;
      }

      // Cannot take into account if user is a requester (and not assigned)
      if ($this->isUser(CommonITILActor::REQUESTER, Session::getLoginUserID())
          || (isset($_SESSION["glpigroups"])
             && $this->haveAGroup(CommonITILActor::REQUESTER, $_SESSION['glpigroups']))) {
         return false;
      }

      $canAddTask = Session::haveRight("task", CommonITILTask::ADDALLITEM);
      $canAddFollowup = Session::haveRightsOr(
         'followup',
         [
            ITILFollowup::ADDALLTICKET,
            ITILFollowup::ADDMYTICKET,
            ITILFollowup::ADDGROUPTICKET,
         ]
      );

      // Can take into account if user has rights to add tasks or followups,
      // assuming that users that does not have those rights cannot treat the ticket.
      return $canAddTask || $canAddFollowup;
   }

   /**
    * Check if ticket has already been taken into account.
    *
    * @return boolean
    */
   public function isAlreadyTakenIntoAccount() {

      return array_key_exists('takeintoaccount_delay_stat', $this->fields)
          && $this->fields['takeintoaccount_delay_stat'] != 0;
   }

   /**
    * Get Datas to be added for SLA add
    *
    * @param $slas_id      SLA id
    * @param $entities_id  entity ID of the ticket
    * @param $date         begin date of the ticket
    * @param $type         type of SLA
    *
    * @since 9.1 (before getDatasToAddSla without type parameter)
    *
    * @return array of datas to add in ticket
   **/
   function getDatasToAddSLA($slas_id, $entities_id, $date, $type) {

      list($dateField, $slaField) = SLA::getFieldNames($type);

      $calendars_id = Entity::getUsedConfig('calendars_id', $entities_id);
      $data         = [];

      $sla = new SLA();
      if ($sla->getFromDB($slas_id)) {
         $sla->setTicketCalendar($calendars_id);
         if ($sla->fields['type'] == SLM::TTR) {
            $data["slalevels_id_ttr"] = SlaLevel::getFirstSlaLevel($slas_id);
         }
         // Compute time_to_resolve
         $data[$dateField]             = $sla->computeDate($date);
         $data['sla_waiting_duration'] = 0;

      } else {
         $data["slalevels_id_ttr"]     = 0;
         $data[$slaField]              = 0;
         $data['sla_waiting_duration'] = 0;
      }
      return $data;

   }

   /**
    * Get Datas to be added for OLA add
    *
    * @param $olas_id      OLA id
    * @param $entities_id  entity ID of the ticket
    * @param $date         begin date of the ticket
    * @param $type         type of OLA
    *
    * @since 9.2 (before getDatasToAddOla without type parameter)
    *
    * @return array of datas to add in ticket
    **/
   function getDatasToAddOLA($olas_id, $entities_id, $date, $type) {

      list($dateField, $olaField) = OLA::getFieldNames($type);

      $calendars_id = Entity::getUsedConfig('calendars_id', $entities_id);
      $data         = [];

      $ola = new OLA();
      if ($ola->getFromDB($olas_id)) {
         $ola->setTicketCalendar($calendars_id);
         if ($ola->fields['type'] == SLM::TTR) {
            $data["olalevels_id_ttr"] = OlaLevel::getFirstOlaLevel($olas_id);
         }
         // Compute time_to_resolve
         $data[$dateField]             = $ola->computeDate($date);
         $data['ola_waiting_duration'] = 0;

      } else {
         $data["olalevels_id_ttr"]     = 0;
         $data[$olaField]              = 0;
         $data['ola_waiting_duration'] = 0;
      }
      return $data;

   }


   /**
    * Delete Level Agreement for the ticket
    *
    * @since 9.2
    *
    * @param string  $laType (SLA | OLA)
    * @param integer $id the sla/ola id
    * @param integer $subtype (SLM::TTR | SLM::TTO)
    * @param bool    $delete_date (default false)
    *
    * @return bool
    **/
   function deleteLevelAgreement($laType, $la_id, $subtype, $delete_date = false) {
      switch ($laType) {
         case "SLA":
            $prefix        = "sla";
            $prefix_ticket = "";
            $level_ticket  = new SlaLevel_Ticket();
            break;
         case "OLA":
            $prefix        = "ola";
            $prefix_ticket = "internal_";
            $level_ticket  = new OlaLevel_Ticket();
            break;
      }

      $input = [];
      switch ($subtype) {
         case SLM::TTR :
            $input[$prefix.'s_id_ttr'] = 0;
            if ($delete_date) {
               $input[$prefix_ticket.'time_to_resolve'] = '';
            }
            break;

         case SLM::TTO :
            $input[$prefix.'s_id_tto'] = 0;
            if ($delete_date) {
               $input[$prefix_ticket.'time_to_own'] = '';
            }
            break;
      }

      $input[$prefix.'_waiting_duration'] = 0;
      $input['id'] = $la_id;
      $level_ticket->deleteForTicket($la_id, $subtype);

      return $this->update($input);
   }


   /**
    * Is the current user have right to create the current ticket ?
    *
    * @return boolean
   **/
   function canCreateItem() {

      if (!Session::haveAccessToEntity($this->getEntityID())) {
         return false;
      }
      return self::canCreate();
   }


   /**
    * Is the current user have right to update the current ticket ?
    *
    * @return boolean
   **/
   function canUpdateItem() {
      if (!$this->checkEntity()) {
         return false;
      }

      // for all, if no modification in ticket return true
      if ($can_requester = $this->canRequesterUpdateItem()) {
         return true;
      }

      // for self-service only, if modification in ticket, we can't update the ticket
      if (Session::getCurrentInterface() == "helpdesk"
          && !$can_requester) {
         return false;
      }

      // if we don't have global UPDATE right, maybe we can own the current ticket
      if (!Session::haveRight(self::$rightname, UPDATE)
          && !$this->ownItem()) {
         //we always return false, as ownItem() = true is managed by below self::canUpdate
         return false;
      }

      return self::canupdate();
   }


   /**
    * Is the current user is a requester of the current ticket and have the right to update it ?
    *
    * @return boolean
    */
   function canRequesterUpdateItem() {
       return ($this->isUser(CommonITILActor::REQUESTER, Session::getLoginUserID())
               || $this->fields["users_id_recipient"] === Session::getLoginUserID())
              && $this->fields['status'] != self::SOLVED
              && $this->fields['status'] != self::CLOSED
              && $this->numberOfFollowups() == 0
              && $this->numberOfTasks() == 0;
   }

   /**
    * Is the current user have OWN right and is the assigned to the ticket
    *
    * @return boolean
    */
   function ownItem() {
      return Session::haveRight(self::$rightname, self::OWN)
             && $this->isUser(CommonITILActor::ASSIGN, Session::getLoginUserID());
   }


   /**
    * @since 0.85
   **/
   static function canDelete() {

      // to allow delete for self-service only if no action on the ticket
      if (Session::getCurrentInterface() == "helpdesk") {
         return Session::haveRight(self::$rightname, CREATE);
      }
      return Session::haveRight(self::$rightname, DELETE);
   }

   /**
    * is the current user could reopen the current ticket
    * @since  9.2
    * @return boolean
    */
   function canReopen() {
      return Session::haveRight('followup', CREATE)
             && in_array($this->fields["status"], $this->getClosedStatusArray())
             && ($this->isAllowedStatus($this->fields['status'], self::INCOMING)
                 || $this->isAllowedStatus($this->fields['status'], self::ASSIGNED));
   }


   /**
    * Is the current user have right to delete the current ticket ?
    *
    * @return boolean
   **/
   function canDeleteItem() {

      if (!Session::haveAccessToEntity($this->getEntityID())) {
         return false;
      }

      // user can delete his ticket if no action on it
      if (Session::getCurrentInterface() == "helpdesk"
          && (!($this->isUser(CommonITILActor::REQUESTER, Session::getLoginUserID())
               || $this->fields["users_id_recipient"] === Session::getLoginUserID())
             || $this->numberOfFollowups() > 0
             || $this->numberOfTasks() > 0
             || $this->fields["date"] != $this->fields["date_mod"])) {
         return false;
      }

      return static::canDelete();
   }

   /**
    * @see CommonITILObject::getDefaultActor()
   **/
   function getDefaultActor($type) {

      if ($type == CommonITILActor::ASSIGN) {
         if (Session::haveRight(self::$rightname, self::OWN)
             && $_SESSION['glpiset_default_tech']) {
            return Session::getLoginUserID();
         }
      }
      if ($type == CommonITILActor::REQUESTER) {
         if (Session::haveRight(self::$rightname, CREATE)
             && $_SESSION['glpiset_default_requester']) {
            return Session::getLoginUserID();
         }
      }
      return 0;
   }


   /**
    * @see CommonITILObject::getDefaultActorRightSearch()
   **/
   function getDefaultActorRightSearch($type) {

      $right = "all";
      if ($type == CommonITILActor::ASSIGN) {
         $right = "own_ticket";
         if (!Session::haveRight(self::$rightname, self::ASSIGN)) {
            $right = 'id';
         }
      }
      return $right;
   }


   function pre_deleteItem() {
      global $CFG_GLPI;

      if (!isset($this->input['_disablenotif']) && $CFG_GLPI['use_notifications']) {
         NotificationEvent::raiseEvent('delete', $this);
      }
      return true;
   }


   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {

      if (static::canView()) {
         $nb    = 0;
         $title = self::getTypeName(Session::getPluralNumber());
         if ($_SESSION['glpishow_count_on_tabs']) {
            switch ($item->getType()) {
               case 'User' :
                  $nb = countElementsInTable(
                     ['glpi_tickets', 'glpi_tickets_users'], [
                        'glpi_tickets_users.tickets_id'  => new \QueryExpression(DB::quoteName('glpi_tickets.id')),
                        'glpi_tickets_users.users_id'    => $item->getID(),
                        'glpi_tickets_users.type'        => CommonITILActor::REQUESTER
                     ] + getEntitiesRestrictCriteria(self::getTable())
                  );
                  $title = __('Created tickets');
                  break;

               case 'Supplier' :
                  $nb = countElementsInTable(
                     ['glpi_tickets', 'glpi_suppliers_tickets'], [
                        'glpi_suppliers_tickets.tickets_id'    => new \QueryExpression(DB::quoteName('glpi_tickets.id')),
                        'glpi_suppliers_tickets.suppliers_id'  => $item->getID()
                     ] + getEntitiesRestrictCriteria(self::getTable())
                  );
                  break;

               case 'SLA' :
                  $nb = countElementsInTable(
                     'glpi_tickets', [
                        'OR'  => [
                           'slas_id_tto'  => $item->getID(),
                           'slas_id_ttr'  => $item->getID()
                        ]
                     ]
                  );
                  break;
               case 'OLA' :
                  $nb = countElementsInTable(
                     'glpi_tickets', [
                        'OR'  => [
                           'olas_id_tto'  => $item->getID(),
                           'olas_id_ttr'  => $item->getID()
                        ]
                     ]
                  );
                  break;

               case 'Group' :
                  $nb = countElementsInTable(
                     ['glpi_tickets', 'glpi_groups_tickets'], [
                        'glpi_groups_tickets.tickets_id' => new \QueryExpression(DB::quoteName('glpi_tickets.id')),
                        'glpi_groups_tickets.groups_id'  => $item->getID(),
                        'glpi_groups_tickets.type'       => CommonITILActor::REQUESTER
                     ] + getEntitiesRestrictCriteria(self::getTable())
                  );
                  $title = __('Created tickets');
                  break;

               default :
                  // Direct one
                  $nb = countElementsInTable(
                     'glpi_items_tickets',
                     [
                        'INNER JOIN' => [
                           'glpi_tickets' => [
                              'FKEY' => [
                                 'glpi_items_tickets' => 'tickets_id',
                                 'glpi_tickets'       => 'id'
                              ]
                           ]
                        ],
                        'WHERE' => [
                           'itemtype' => $item->getType(),
                           'items_id' => $item->getID(),
                           'is_deleted' => 0
                        ]
                     ]
                  );

                  // Linked items
                  $linkeditems = $item->getLinkedItems();

                  if (count($linkeditems)) {
                     foreach ($linkeditems as $type => $tab) {
                        foreach ($tab as $ID) {
                           $nb += countElementsInTable(
                              'glpi_items_tickets',
                              [
                                 'INNER JOIN' => [
                                    'glpi_tickets' => [
                                       'FKEY' => [
                                          'glpi_items_tickets' => 'tickets_id',
                                          'glpi_tickets'       => 'id'
                                       ]
                                    ]
                                 ],
                                 'WHERE' => [
                                    'itemtype' => $type,
                                    'items_id' => $ID,
                                    'is_deleted' => 0
                                 ]
                              ]
                           );
                        }
                     }
                  }
                  break;
            }

         } // glpishow_count_on_tabs
         // Not for Ticket class
         if ($item->getType() != __CLASS__) {
            return self::createTabEntry($title, $nb);
         }
      } // self::READALL right check

      // Not check self::READALL for Ticket itself
      switch ($item->getType()) {
         case __CLASS__ :
            $ong    = [];

            $timeline    = $item->getTimelineItems();
            $nb_elements = count($timeline);
            $ong[1]      = __("Processing ticket")." <sup class='tab_nb'>$nb_elements</sup>";

            // enquete si statut clos
            $satisfaction = new TicketSatisfaction();
            if ($satisfaction->getFromDB($item->getID())
                && $item->fields['status'] == self::CLOSED) {
               $ong[3] = __('Satisfaction');
            }
            if ($item->canView()) {
               $ong[4] = __('Statistics');
            }
            return $ong;

         //   default :
         //      return _n('Ticket','Tickets', Session::getPluralNumber());
      }

      return '';
   }


   static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {

      switch ($item->getType()) {
         case __CLASS__ :
            switch ($tabnum) {

               case 1 :
                  echo "<div class='timeline_box'>";
                  $rand = mt_rand();
                  $item->showTimelineForm($rand);
                  $item->showTimeline($rand);
                  echo "</div>";
                  break;

               case 3 :
                  $satisfaction = new TicketSatisfaction();
                  if (($item->fields['status'] == self::CLOSED)
                      && $satisfaction->getFromDB($_GET["id"])) {

                     $duration = Entity::getUsedConfig('inquest_duration', $item->fields['entities_id']);
                     $date2    = strtotime($satisfaction->fields['date_begin']);
                     if (($duration == 0)
                         || (strtotime("now") - $date2) <= $duration*DAY_TIMESTAMP) {
                        $satisfaction->showForm($item);
                     } else {
                        echo "<p class='center b'>".__('Satisfaction survey expired')."</p>";
                     }

                  } else {
                     echo "<p class='center b'>".__('No generated survey')."</p>";
                  }
                  break;

               case 4 :
                  $item->showStats();
                  break;
            }
            break;

         case 'Group' :
         case 'SLA' :
         case 'OLA' :
         default :
            self::showListForItem($item, $withtemplate);
      }
      return true;
   }


   function defineTabs($options = []) {

      $ong = [];
      $this->addDefaultFormTab($ong);
      $this->addStandardTab(__CLASS__, $ong, $options);
      $this->addStandardTab('TicketValidation', $ong, $options);
      $this->addStandardTab('KnowbaseItem_Item', $ong, $options);
      $this->addStandardTab('Item_Ticket', $ong, $options);
      $this->addStandardTab('TicketCost', $ong, $options);
      $this->addStandardTab('Itil_Project', $ong, $options);
      $this->addStandardTab('ProjectTask_Ticket', $ong, $options);
      $this->addStandardTab('Problem_Ticket', $ong, $options);
      $this->addStandardTab('Change_Ticket', $ong, $options);
      $this->addStandardTab('Log', $ong, $options);

      return $ong;
   }


   /**
    * Retrieve data of the hardware linked to the ticket if exists
    *
    * @return nothing : set computerfound to 1 if founded
   **/
   function getAdditionalDatas() {

      $this->hardwaredatas = [];

      if (!empty($this->fields["id"])) {
         $item_ticket = new Item_Ticket();
         $data = $item_ticket->find(['tickets_id' => $this->fields["id"]]);

         foreach ($data as $val) {
            if (!empty($val["itemtype"]) && ($item = getItemForItemtype($val["itemtype"]))) {
               if ($item->getFromDB($val["items_id"])) {
                  $this->hardwaredatas[] = $item;
               }
            }
         }
      }

   }


   function cleanDBonPurge() {

      // OlaLevel_Ticket does not extends CommonDBConnexity
      $olaLevel_ticket = new OlaLevel_Ticket();
      $olaLevel_ticket->deleteForTicket($this->fields['id'], SLM::TTO);
      $olaLevel_ticket->deleteForTicket($this->fields['id'], SLM::TTR);

      // SlaLevel_Ticket does not extends CommonDBConnexity
      $slaLevel_ticket = new SlaLevel_Ticket();
      $slaLevel_ticket->deleteForTicket($this->fields['id'], SLM::TTO);
      $slaLevel_ticket->deleteForTicket($this->fields['id'], SLM::TTR);

      // TicketSatisfaction does not extends CommonDBConnexity
      $tf = new TicketSatisfaction();
      $tf->deleteByCriteria(['tickets_id' => $this->fields['id']]);

      // CommonITILTask does not extends CommonDBConnexity
      $tt = new TicketTask();
      $tt->deleteByCriteria(['tickets_id' => $this->fields['id']]);

      $this->deleteChildrenAndRelationsFromDb(
         [
            Change_Ticket::class,
            Item_Ticket::class,
            Problem_Ticket::class,
            ProjectTask_Ticket::class,
            TicketCost::class,
            Ticket_Ticket::class,
            TicketValidation::class,
         ]
      );

      parent::cleanDBonPurge();

   }


   function prepareInputForUpdate($input) {
      global $CFG_GLPI, $DB;

      // Get ticket : need for comparison
      $this->getFromDB($input['id']);

      // Clean new lines before passing to rules
      if (isset($input["content"])) {
         $input["content"] = preg_replace('/\\\\r\\\\n/', "\n", $input['content']);
         $input["content"] = preg_replace('/\\\\n/', "\n", $input['content']);
      }

      // automatic recalculate if user changes urgence or technician change impact
      $canpriority               = Session::haveRight(self::$rightname, self::CHANGEPRIORITY);
      if ((isset($input['urgency']) && $input['urgency'] != $this->fields['urgency'])
         || (isset($input['impact']) && $input['impact'] != $this->fields['impact'])
         && ($canpriority && !isset($input['priority']) || !$canpriority)
      ) {
         if (!isset($input['urgency'])) {
            $input['urgency'] = $this->fields['urgency'];
         }
         if (!isset($input['impact'])) {
            $input['impact'] = $this->fields['impact'];
         }
         $input['priority'] = self::computePriority($input['urgency'], $input['impact']);
      }

      // Security checks
      if (!Session::isCron()
          && !Session::haveRight(self::$rightname, self::ASSIGN)) {
         if (isset($input["_itil_assign"])
             && isset($input['_itil_assign']['_type'])
             && ($input['_itil_assign']['_type'] == 'user')) {

            // must own_ticket to grab a non assign ticket
            if ($this->countUsers(CommonITILActor::ASSIGN) == 0) {
               if ((!Session::haveRightsOr(self::$rightname, [self::STEAL, self::OWN]))
                   || !isset($input["_itil_assign"]['users_id'])
                   || ($input["_itil_assign"]['users_id'] != Session::getLoginUserID())) {
                  unset($input["_itil_assign"]);
               }

            } else {
               // Can not steal or can steal and not assign to me
               if (!Session::haveRight(self::$rightname, self::STEAL)
                   || !isset($input["_itil_assign"]['users_id'])
                   || ($input["_itil_assign"]['users_id'] != Session::getLoginUserID())) {
                  unset($input["_itil_assign"]);
               }
            }
         }

         // No supplier assign
         if (isset($input["_itil_assign"])
             && isset($input['_itil_assign']['_type'])
             && ($input['_itil_assign']['_type'] == 'supplier')) {
            unset($input["_itil_assign"]);
         }

         // No group
         if (isset($input["_itil_assign"])
             && isset($input['_itil_assign']['_type'])
             && ($input['_itil_assign']['_type'] == 'group')) {
            unset($input["_itil_assign"]);
         }
      }
      $check_allowed_fields_for_template = false;
      $allowed_fields                    = [];
      if (!Session::isCron()
          && (!Session::haveRight(self::$rightname, UPDATE)
            // Closed tickets
            || in_array($this->fields['status'], $this->getClosedStatusArray()))
         ) {

         $allowed_fields                    = ['id'];
         $check_allowed_fields_for_template = true;

         if (in_array($this->fields['status'], $this->getClosedStatusArray())) {
            $allowed_fields[] = 'status';

            // probably transfer
            $allowed_fields[] = 'entities_id';
            $allowed_fields[] = 'itilcategories_id';
         } else {
            if ($this->canApprove()
                || $this->canAssign()
                || $this->canAssignToMe()
                || isset($input['_from_assignment'])) {
                $allowed_fields[] = 'status';
                $allowed_fields[] = '_accepted';
            }
            // for post-only with validate right or validation created by rules
            if (TicketValidation::canValidate($this->fields['id'])
                || TicketValidation::canCreate()
                || isset($input["_rule_process"])) {
                $allowed_fields[] = 'global_validation';
            }
            // Manage assign and steal right
            if (Session::haveRightsOr(self::$rightname, [self::ASSIGN, self::STEAL])) {
                $allowed_fields[] = '_itil_assign';
            }

            // Can only update initial fields if no followup or task already added
            if ($this->canUpdateItem()) {
                $allowed_fields[] = 'content';
                $allowed_fields[] = 'urgency';
                $allowed_fields[] = 'priority'; // automatic recalculate if user changes urgence
                $allowed_fields[] = 'itilcategories_id';
                $allowed_fields[] = 'name';
                $allowed_fields[] = 'items_id';
                $allowed_fields[] = '_filename';
                $allowed_fields[] = '_tag_filename';
                $allowed_fields[] = '_prefix_filename';
                $allowed_fields[] = 'takeintoaccount_delay_stat';
            }
         }

         foreach ($allowed_fields as $field) {
            if (isset($input[$field])) {
               $ret[$field] = $input[$field];
            }
         }

         $input = $ret;

         // Only ID return false
         if (count($input) == 1) {
            return false;
         }
      }

      //// check mandatory fields
      // First get ticket template associated : entity and type/category
      if (isset($input['entities_id'])) {
         $entid = $input['entities_id'];
      } else {
         $entid = $this->fields['entities_id'];
      }

      if (isset($input['type'])) {
         $type = $input['type'];
      } else {
         $type = $this->fields['type'];
      }

      if (isset($input['itilcategories_id'])) {
         $categid = $input['itilcategories_id'];
      } else {
         $categid = $this->fields['itilcategories_id'];
      }

      $tt = $this->getTicketTemplateToUse(0, $type, $categid, $entid);

      if (count($tt->mandatory)) {
         $mandatory_missing = [];
         $fieldsname        = $tt->getAllowedFieldsNames(true);
         foreach ($tt->mandatory as $key => $val) {
            if ((!$check_allowed_fields_for_template || in_array($key, $allowed_fields))
                && (isset($input[$key])
                    && (empty($input[$key]) || ($input[$key] == 'NULL'))
                    // Take only into account already set items : do not block old tickets
                    && (!empty($this->fields[$key]))
                )) {
               $mandatory_missing[$key] = $fieldsname[$val];
            }
         }
         if (count($mandatory_missing)) {
            //TRANS: %s are the fields concerned
            $message = sprintf(__('Mandatory fields are not filled. Please correct: %s'),
                               implode(", ", $mandatory_missing));
            Session::addMessageAfterRedirect($message, false, ERROR);
            return false;
         }
      }

      /// Process Business Rules
      // Add actors on standard input
      $rules               = new RuleTicketCollection($entid);
      $rule                = $rules->getRuleClass();
      $changes             = [];
      $post_added          = [];
      $tocleanafterrules   = [];
      $usertypes           = [
         CommonITILActor::ASSIGN    => 'assign',
         CommonITILActor::REQUESTER => 'requester',
         CommonITILActor::OBSERVER  => 'observer'
      ];
      foreach ($usertypes as $k => $t) {
         //handle new input
         if (isset($input['_itil_'.$t]) && isset($input['_itil_'.$t]['_type'])) {
            $field = $input['_itil_'.$t]['_type'].'s_id';
            if (isset($input['_itil_'.$t][$field])
                && !isset($input[$field.'_'.$t])) {
               $input['_'.$field.'_'.$t][]             = $input['_itil_'.$t][$field];
               $tocleanafterrules['_'.$field.'_'.$t][] = $input['_itil_'.$t][$field];
            }
         }

         //handle existing actors: load all existing actors from ticket
         //to make sure business rules will receive all informations, and not just
         //what have been entered in the html form.
         //
         //ref also this actor into $post_added to avoid the filling of $changes
         //and triggering businness rules when not needed
         $users = $this->getUsers($k);
         if (count($users)) {
            $field = 'users_id';
            foreach ($users as $user) {
               if (!isset($input['_'.$field.'_'.$t]) || !in_array($user[$field], $input['_'.$field.'_'.$t])) {
                  if (!isset($input['_'.$field.'_'.$t])) {
                     $post_added['_'.$field.'_'.$t] = '_'.$field.'_'.$t;
                  }
                  $input['_'.$field.'_'.$t][]             = $user[$field];
                  $tocleanafterrules['_'.$field.'_'.$t][] = $user[$field];
               }
            }
         }

         $groups = $this->getGroups($k);
         if (count($groups)) {
            $field = 'groups_id';
            foreach ($groups as $group) {
               if (!isset($input['_'.$field.'_'.$t]) || !in_array($group[$field], $input['_'.$field.'_'.$t])) {
                  if (!isset($input['_'.$field.'_'.$t])) {
                     $post_added['_'.$field.'_'.$t] = '_'.$field.'_'.$t;
                  }
                  $input['_'.$field.'_'.$t][]             = $group[$field];
                  $tocleanafterrules['_'.$field.'_'.$t][] = $group[$field];
               }
            }
         }

         $suppliers = $this->getSuppliers($k);
         if (count($suppliers)) {
            $field = 'suppliers_id';
            foreach ($suppliers as $supplier) {
               if (!isset($input['_'.$field.'_'.$t]) || !in_array($supplier[$field], $input['_'.$field.'_'.$t])) {
                  if (!isset($input['_'.$field.'_'.$t])) {
                     $post_added['_'.$field.'_'.$t] = '_'.$field.'_'.$t;
                  }
                  $input['_'.$field.'_'.$t][]             = $supplier[$field];
                  $tocleanafterrules['_'.$field.'_'.$t][] = $supplier[$field];
               }
            }
         }
      }

      foreach ($rule->getCriterias() as $key => $val) {
         if (array_key_exists($key, $input)
             && !array_key_exists($key, $post_added)) {
            if (!isset($this->fields[$key])
                || ($DB->escape($this->fields[$key]) != $input[$key])) {
               $changes[] = $key;
            }
         }
      }

      // Business Rules do not override manual SLA and OLA
      $manual_slas_id = [];
      $manual_olas_id = [];
      foreach ([SLM::TTR, SLM::TTO] as $slmType) {
         list($dateField, $slaField) = SLA::getFieldNames($slmType);
         if (isset($input[$slaField]) && ($input[$slaField] > 0)) {
            $manual_slas_id[$slmType] = $input[$slaField];
         }

         list($dateField, $olaField) = OLA::getFieldNames($slmType);
         if (isset($input[$olaField]) && ($input[$olaField] > 0)) {
            $manual_olas_id[$slmType] = $input[$olaField];
         }
      }

      // Only process rules on changes
      if (count($changes)) {
         if (in_array('_users_id_requester', $changes)) {
            // If _users_id_requester changed : set users_locations
            $user = new User();
            if (isset($input["_itil_requester"]["users_id"])
                && $user->getFromDB($input["_itil_requester"]["users_id"])) {
               $input['users_locations'] = $user->fields['locations_id'];
               $changes[]                = 'users_locations';
            }
            // If _users_id_requester changed : add _groups_id_of_requester to changes
            $changes[] = '_groups_id_of_requester';
         }

         $input = $rules->processAllRules($input,
                                          $input,
                                          ['recursive'   => true,
                                                'entities_id' => $entid],
                                          ['condition'     => RuleTicket::ONUPDATE,
                                          'only_criteria' => $changes]);
         $input = Toolbox::stripslashes_deep($input);
      }

      //Action for send_validation rule : do validation before clean
      $this->manageValidationAdd($input);

      // Clean actors fields added for rules
      foreach ($tocleanafterrules as $key => $val) {
         if ($input[$key] == $val) {
            unset($input[$key]);
         }
      }

      // Manage fields from auto update or rules : map rule actions to standard additional ones
      $usertypes  = ['assign', 'requester', 'observer'];
      $actortypes = ['user','group','supplier'];
      foreach ($usertypes as $t) {
         foreach ($actortypes as $a) {
            if (isset($input['_'.$a.'s_id_'.$t])) {
               switch ($a) {
                  case 'user' :
                     $additionalfield           = '_additional_'.$t.'s';
                     $input[$additionalfield][] = ['users_id' => $input['_'.$a.'s_id_'.$t]];
                     break;

                  default :
                     $additionalfield           = '_additional_'.$a.'s_'.$t.'s';
                     $input[$additionalfield][] = $input['_'.$a.'s_id_'.$t];
                     break;
               }
            }
         }
      }

      if (isset($input['_link'])) {
         $ticket_ticket = new Ticket_Ticket();
         if (!empty($input['_link']['tickets_id_2'])) {
            if ($ticket_ticket->can(-1, CREATE, $input['_link'])) {
               if ($ticket_ticket->add($input['_link'])) {
                  $input['_forcenotif'] = true;
               }
            } else {
               Session::addMessageAfterRedirect(__('Unknown ticket'), false, ERROR);
            }
         }
      }

      // SLA / OLA affect by rules : reset time_to_resolve / internal_time_to_resolve
      // Manual SLA / OLA defined : reset time_to_resolve / internal_time_to_resolve
      // No manual SLA / OLA and due date defined : reset auto SLA / OLA
      foreach ([SLM::TTR, SLM::TTO] as $slmType) {
         $this->slaAffect($slmType, $input, $manual_slas_id);
         $this->olaAffect($slmType, $input, $manual_olas_id);
      }

      if (isset($input['content'])) {
         if (isset($input['_filename'])) {
            $input['content']       = $input['content'];
            $input['_disablenotif'] = true;
         } else {
            $input['_donotadddocs'] = true;
         }
      }

      $input = parent::prepareInputForUpdate($input);
      return $input;
   }


   /**
    *  SLA affect by rules : reset time_to_resolve and time_to_own
    *  Manual SLA defined : reset time_to_resolve and time_to_own
    *  No manual SLA and due date defined : reset auto SLA
    *
    *  @since 9.1
    *
    * @param $type
    * @param $input
    * @param $manual_slas_id
    */
   function slaAffect($type, &$input, $manual_slas_id) {

      list($dateField, $slaField) = SLA::getFieldNames($type);

      // Restore slas
      if (isset($manual_slas_id[$type])
          && !isset($input['_'.$slaField])) {
         $input[$slaField] = $manual_slas_id[$type];
      }

      // Ticket update
      if (isset($this->fields['id']) && $this->fields['id'] > 0) {
         if (!isset($manual_slas_id[$type])
             && isset($input[$slaField]) && ($input[$slaField] > 0)
             && ($input[$slaField] != $this->fields[$slaField])) {

            if (isset($input[$dateField])) {
               // Unset due date
               unset($input[$dateField]);
            }
         }

         if (isset($input[$slaField]) && ($input[$slaField] > 0)
             && ($input[$slaField] != $this->fields[$slaField])) {

            $date = $this->fields['date'];
            /// Use updated date if also done
            if (isset($input["date"])) {
               $date = $input["date"];
            }
            // Get datas to initialize SLA and set it
            $sla_data = $this->getDatasToAddSLA($input[$slaField], $this->fields['entities_id'],
                                                $date, $type);
            if (count($sla_data)) {
               foreach ($sla_data as $key => $val) {
                  $input[$key] = $val;
               }
            }
         }
      } else { // Ticket add
         if (!isset($manual_slas_id[$type])
             && isset($input[$dateField]) && ($input[$dateField] != 'NULL')) {
            // Valid due date
            if ($input[$dateField] >= $input['date']) {
               if (isset($input[$slaField])) {
                  unset($input[$slaField]);
               }
            } else {
               // Unset due date
               unset($input[$dateField]);
            }
         }

         if (isset($input[$slaField]) && ($input[$slaField] > 0)) {
            // Get datas to initialize SLA and set it
            $sla_data = $this->getDatasToAddSLA($input[$slaField], $input['entities_id'],
                                                $input['date'], $type);
            if (count($sla_data)) {
               foreach ($sla_data as $key => $val) {
                  $input[$key] = $val;
               }
            }
         }
      }
   }

   /**
    *  OLA affect by rules : reset internal_time_to_resolve and internal_time_to_own
    *  Manual OLA defined : reset internal_time_to_resolve and internal_time_to_own
    *  No manual OLA and due date defined : reset auto OLA
    *
    *  @since 9.1
    *
    * @param $type
    * @param $input
    * @param $manual_olas_id
    */
   function olaAffect($type, &$input, $manual_olas_id) {

      list($dateField, $olaField) = OLA::getFieldNames($type);

      // Restore olas
      if (isset($manual_olas_id[$type])
          && !isset($input['_'.$olaField])) {
         $input[$olaField] = $manual_olas_id[$type];
      }

      // Ticket update
      if (isset($this->fields['id']) && $this->fields['id'] > 0) {
         if (!isset($manual_olas_id[$type])
             && isset($input[$olaField]) && ($input[$olaField] > 0)
             && ($input[$olaField] != $this->fields[$olaField])) {

            if (isset($input[$dateField])) {
               // Unset due date
               unset($input[$dateField]);
            }
         }

         if (isset($input[$olaField]) && ($input[$olaField] > 0)
             && ($input[$olaField] != $this->fields[$olaField]
                 || isset($input['_'.$olaField]))) {

            $date = date('Y-m-d H:i:s');

            // Get datas to initialize OLA and set it
            $ola_data = $this->getDatasToAddOLA($input[$olaField], $this->fields['entities_id'],
                                                $date, $type);
            if (count($ola_data)) {
               foreach ($ola_data as $key => $val) {
                  $input[$key] = $val;
               }
            }
         }
      } else { // Ticket add
         if (!isset($manual_olas_id[$type])
             && isset($input[$dateField]) && ($input[$dateField] != 'NULL')) {
            // Valid due date
            if ($input[$dateField] >= $input['date']) {
               if (isset($input[$olaField])) {
                  unset($input[$olaField]);
               }
            } else {
               // Unset due date
               unset($input[$dateField]);
            }
         }

         if (isset($input[$olaField]) && ($input[$olaField] > 0)) {
            // Get datas to initialize OLA and set it
            $ola_data = $this->getDatasToAddOLA($input[$olaField], $input['entities_id'],
                                                $input['date'], $type);
            if (count($ola_data)) {
               foreach ($ola_data as $key => $val) {
                  $input[$key] = $val;
               }
            }
         }
      }
   }


   /**
    * Manage SLA level escalation
    *
    * @since 9.1
    *
    * @param $slas_id
   **/
   function manageSlaLevel($slas_id) {

      $calendars_id = Entity::getUsedConfig('calendars_id', $this->fields['entities_id']);
      // Add first level in working table
      $slalevels_id = SlaLevel::getFirstSlaLevel($slas_id);

      $sla = new SLA();
      if ($sla->getFromDB($slas_id)) {
         $sla->setTicketCalendar($calendars_id);
         $sla->addLevelToDo($this, $slalevels_id);
      }
      SlaLevel_Ticket::replayForTicket($this->getID(), $sla->getField('type'));
   }

   /**
    * Manage OLA level escalation
    *
    * @since 9.1
    *
    * @param $slas_id
   **/
   function manageOlaLevel($slas_id) {

      $calendars_id = Entity::getUsedConfig('calendars_id', $this->fields['entities_id']);
      // Add first level in working table
      $olalevels_id = OlaLevel::getFirstOlaLevel($slas_id);

      $ola = new OLA();
      if ($ola->getFromDB($slas_id)) {
         $ola->setTicketCalendar($calendars_id);
         $ola->addLevelToDo($this, $olalevels_id);
      }
      OlaLevel_Ticket::replayForTicket($this->getID(), $ola->getField('type'));
   }


   function pre_updateInDB() {

      if (!$this->isAlreadyTakenIntoAccount() && $this->canTakeIntoAccount()) {
         $this->updates[]                            = "takeintoaccount_delay_stat";
         $this->fields['takeintoaccount_delay_stat'] = $this->computeTakeIntoAccountDelayStat();
      }

      parent::pre_updateInDB();

   }


   /**
    * Compute take into account stat of the current ticket
   **/
   function computeTakeIntoAccountDelayStat() {

      if (isset($this->fields['id'])
          && !empty($this->fields['date'])) {
         $calendars_id = $this->getCalendar();
         $calendar     = new Calendar();

         // Using calendar
         if (($calendars_id > 0) && $calendar->getFromDB($calendars_id)) {
            return max(1, $calendar->getActiveTimeBetween($this->fields['date'],
                                                          $_SESSION["glpi_currenttime"]));
         }
         // Not calendar defined
         return max(1, strtotime($_SESSION["glpi_currenttime"])-strtotime($this->fields['date']));
      }
      return 0;
   }


   function post_updateItem($history = 1) {
      global $CFG_GLPI;

      // Put same status on duplicated tickets when solving or closing (autoclose on solve)
      if (isset($this->input['status'])
          && in_array('status', $this->updates)
          && (in_array($this->input['status'], $this->getSolvedStatusArray())
              || in_array($this->input['status'], $this->getClosedStatusArray()))) {
         Ticket_Ticket::manageLinkedTicketsOnSolved($this->getID());
      }

      $donotif = count($this->updates);

      if (isset($this->input['_forcenotif'])) {
         $donotif = true;
      }

      // Manage SLA / OLA Level : add actions
      foreach ([SLM::TTR, SLM::TTO] as $slmType) {
         list($dateField, $slaField) = SLA::getFieldNames($slmType);
         if (in_array($slaField, $this->updates)
             && ($this->fields[$slaField] > 0)) {
            $this->manageSlaLevel($this->fields[$slaField]);
         }

         list($dateField, $olaField) = OLA::getFieldNames($slmType);
         if (in_array($olaField, $this->updates)
             && ($this->fields[$olaField] > 0)) {
            $this->manageOlaLevel($this->fields[$olaField]);
         }
      }

      if (count($this->updates)) {
         // Update Ticket Tco
         if (in_array("actiontime", $this->updates)
             || in_array("cost_time", $this->updates)
             || in_array("cost_fixed", $this->updates)
             || in_array("cost_material", $this->updates)) {

            if (!empty($this->input["items_id"])) {
               foreach ($this->input["items_id"] as $itemtype => $items) {
                  foreach ($items as $items_id) {
                     if ($itemtype && ($item = getItemForItemtype($itemtype))) {
                        if ($item->getFromDB($items_id)) {
                           $newinput               = [];
                           $newinput['id']         = $items_id;
                           $newinput['ticket_tco'] = self::computeTco($item);
                           $item->update($newinput);
                        }
                     }
                  }
               }
            }
         }

         $donotif                 = true;
      }

      if (isset($this->input['_disablenotif'])) {
         $donotif = false;
      }

      if ($donotif && $CFG_GLPI["use_notifications"]) {
         $mailtype = "update";

         if (isset($this->input["status"])
             && $this->input["status"]
             && in_array("status", $this->updates)
             && in_array($this->input["status"], $this->getSolvedStatusArray())) {

            $mailtype = "solved";
         }

         if (isset($this->input["status"])
             && $this->input["status"]
             && in_array("status", $this->updates)
             && in_array($this->input["status"], $this->getClosedStatusArray())) {

            $mailtype = "closed";
         }
         // to know if a solution is approved or not
         if ((isset($this->input['solvedate']) && ($this->input['solvedate'] == 'NULL')
              && isset($this->oldvalues['solvedate']) && $this->oldvalues['solvedate'])
             && (isset($this->input['status'])
                 && ($this->input['status'] != $this->oldvalues['status'])
                 && ($this->oldvalues['status'] == self::SOLVED))) {

            $mailtype = "rejectsolution";
         }

         // Read again ticket to be sure that all data are up to date
         $this->getFromDB($this->fields['id']);
         NotificationEvent::raiseEvent($mailtype, $this);
      }

      // inquest created immediatly if delay = O
      $inquest       = new TicketSatisfaction();
      $rate          = Entity::getUsedConfig('inquest_config', $this->fields['entities_id'],
                                             'inquest_rate');
      $delay         = Entity::getUsedConfig('inquest_config', $this->fields['entities_id'],
                                             'inquest_delay');
      $duration      = Entity::getUsedConfig('inquest_duration', $this->fields['entities_id'],
                                             'inquest_duration');
      $type          = Entity::getUsedConfig('inquest_config', $this->fields['entities_id']);
      $max_closedate = $this->fields['closedate'];

      if (in_array("status", $this->updates)
          && in_array($this->input["status"], $this->getClosedStatusArray())
          && ($delay == 0)
          && ($rate > 0)
          && (mt_rand(1, 100) <= $rate)) {

         // For reopened ticket
         if ($inquest->getFromDB($this->fields['id'])) {
            $resp = $inquest->fields;
            $inquest->delete($resp);
         }

         $inquest->add(
            [
               'tickets_id'    => $this->fields['id'],
               'date_begin'    => $_SESSION["glpi_currenttime"],
               'entities_id'   => $this->fields['entities_id'],
               'type'          => $type,
               'max_closedate' => $max_closedate,
            ]
         );
      }
   }


   function prepareInputForAdd($input) {
      global $CFG_GLPI, $DB;

      // save value before clean;
      $title = ltrim($input['name']);
      // Standard clean datas
      $input =  parent::prepareInputForAdd($input);

      // Do not check mandatory on auto import (mailgates)
      if (!isset($input['_auto_import'])) {
         if (isset($input['_tickettemplates_id']) && $input['_tickettemplates_id']) {
            $tt = new TicketTemplate();
            if ($tt->getFromDBWithDatas($input['_tickettemplates_id'])) {
               if (count($tt->mandatory)) {
                  $mandatory_missing = [];
                  $fieldsname        = $tt->getAllowedFieldsNames(true);
                  foreach ($tt->mandatory as $key => $val) {
                     // for title if mandatory (restore initial value)
                     if ($key == 'name') {
                        $input['name']                     = $title;
                     }
                     // Check only defined values : Not defined not in form
                     if (isset($input[$key])) {
                        // If content is also predefined need to be different from predefined value
                        if (($key == 'content')
                            && isset($tt->predefined['content'])) {
                           // Clean new lines to be fix encoding
                           if (strcmp(preg_replace("/\r?\n/", "",
                                                   Html::cleanPostForTextArea($input[$key])),
                                      preg_replace("/\r?\n/", "",
                                                   $tt->predefined['content'])) == 0) {
                              $mandatory_missing[$key] = $fieldsname[$val];
                           }
                        }

                        if (empty($input[$key]) || ($input[$key] == 'NULL')
                            || (is_array($input[$key])
                                && ($input[$key] === [0 => "0"]))) {
                           $mandatory_missing[$key] = $fieldsname[$val];
                        }
                     }

                     if (($key == '_add_validation')
                         && !empty($input['users_id_validate'])
                         && isset($input['users_id_validate'][0])
                         && ($input['users_id_validate'][0] > 0)) {

                        unset($mandatory_missing['_add_validation']);
                     }

                     // For time_to_resolve and time_to_own : check also slas
                     // For internal_time_to_resolve and internal_time_to_own : check also olas
                     foreach ([SLM::TTR, SLM::TTO] as $slmType) {
                        list($dateField, $slaField) = SLA::getFieldNames($slmType);
                        if (($key == $dateField)
                            && isset($input[$slaField]) && ($input[$slaField] > 0)
                            && isset($mandatory_missing[$dateField])) {
                           unset($mandatory_missing[$dateField]);
                        }
                        list($dateField, $olaField) = OLA::getFieldNames($slmType);
                        if (($key == $dateField)
                            && isset($input[$olaField]) && ($input[$olaField] > 0)
                            && isset($mandatory_missing[$dateField])) {
                           unset($mandatory_missing[$dateField]);
                        }
                     }

                     // For document mandatory
                     if (($key == '_documents_id')
                           && !isset($input['_filename'])
                           && !isset($input['_tag_filename'])
                           && !isset($input['_stock_image'])
                           && !isset($input['_tag_stock_image'])) {

                        $mandatory_missing[$key] = $fieldsname[$val];
                     }
                  }
                  if (count($mandatory_missing)) {
                     //TRANS: %s are the fields concerned
                     $message = sprintf(__('Mandatory fields are not filled. Please correct: %s'),
                                        implode(", ", $mandatory_missing));
                     Session::addMessageAfterRedirect($message, false, ERROR);
                     return false;
                  }
               }
            }
         }
      }

      if (!isset($input["requesttypes_id"])) {
         $input["requesttypes_id"] = RequestType::getDefault('helpdesk');
      }

      if (!isset($input['global_validation'])) {
         $input['global_validation'] = CommonITILValidation::NONE;
      }

      // Set additional default dropdown
      $dropdown_fields = ['users_locations', 'items_locations'];
      foreach ($dropdown_fields as $field) {
         if (!isset($input[$field])) {
            $input[$field] = 0;
         }
      }
      if (!isset($input['itemtype']) || !isset($input['items_id']) || !($input['items_id'] > 0)) {
         $input['itemtype'] = '';
      }

      // Get first item location
      $item = null;
      if (isset($input["items_id"])
            && is_array($input["items_id"])
            && (count($input["items_id"]) > 0)) {
         $infocom = new Infocom();
         foreach ($input["items_id"] as $itemtype => $items) {
            foreach ($items as $items_id) {
               if ($item = getItemForItemtype($itemtype)) {
                  $item->getFromDB($items_id);
                  $input['items_locations'] = $item->fields['locations_id'];
                  if ($infocom->getFromDBforDevice($itemtype, $items_id)) {
                     $input['items_businesscriticities']
                        = Dropdown::getDropdownName('glpi_businesscriticities',
                                                    $infocom->fields['businesscriticities_id']);
                  }
                  if (isset($item->fields['groups_id'])) {
                     $input['items_groups'] = $item->fields['groups_id'];

                  }
                  break(2);
               }
            }
         }
      }

      // Business Rules do not override manual SLA and OLA
      $manual_slas_id = [];
      $manual_olas_id = [];
      foreach ([SLM::TTR, SLM::TTO] as $slmType) {
         list($dateField, $slaField) = SLA::getFieldNames($slmType);
         if (isset($input[$slaField]) && ($input[$slaField] > 0)) {
            $manual_slas_id[$slmType] = $input[$slaField];
         }
         list($dateField, $olaField) = OLA::getFieldNames($slmType);
         if (isset($input[$olaField]) && ($input[$olaField] > 0)) {
            $manual_olas_id[$slmType] = $input[$olaField];
         }
      }

      // fill auto-assign when no tech defined (only for tech)
      if (!isset($input['_auto_import'])
          && isset($_SESSION['glpiset_default_tech']) && $_SESSION['glpiset_default_tech']
          && Session::getCurrentInterface() == 'central'
          && (!isset($input['_users_id_assign']) || $input['_users_id_assign'] == 0)
          && Session::haveRight("ticket", Ticket::OWN)
      ) {
         $input['_users_id_assign'] = Session::getLoginUserID();
      }

      // add calendars matching date creation (for business rules)
      $calendars = [];
      $ite_calandar = $DB->request([
         'SELECT' => ['id'],
         'FROM'   => Calendar::getTable(),
         'WHERE'  => getEntitiesRestrictCriteria('', '', $input['entities_id'], true)
      ]);
      foreach ($ite_calandar as $calendar_data) {
         $calendar = new Calendar;
         $calendar->getFromDB($calendar_data['id']);
         if ($calendar->isAWorkingHour(time())) {
            $calendars[] = $calendar_data['id'];
         }
      }
      if (count($calendars)) {
         $input['_date_creation_calendars_id'] = $calendars;
      }

      // Process Business Rules
      $rules = new RuleTicketCollection($input['entities_id']);

      // Set unset variables with are needed
      $user = new User();
      if (isset($input["_users_id_requester"])
          && !is_array($input["_users_id_requester"])
          && $user->getFromDB($input["_users_id_requester"])) {
         $input['users_locations'] = $user->fields['locations_id'];
         $tmprequester = $input["_users_id_requester"];
      } else {
         $tmprequester = 0;
      }

      // Clean new lines before passing to rules
      if (isset($input["content"])) {
         $input["content"] = preg_replace('/\\\\r\\\\n/', "\\n", $input['content']);
         $input["content"] = preg_replace('/\\\\n/', "\\n", $input['content']);
      }

      $input = $rules->processAllRules($input,
                                       $input,
                                       ['recursive' => true],
                                       ['condition' => RuleTicket::ONADD]);
      $input = Toolbox::stripslashes_deep($input);

      // Recompute default values based on values computed by rules
      $input = $this->computeDefaultValuesForAdd($input);

      if (isset($input['_users_id_requester'])
          && !is_array($input['_users_id_requester'])
          && ($input['_users_id_requester'] != $tmprequester)) {
         // if requester set by rule, clear address from mailcollector
         unset($input['_users_id_requester_notif']);
      }
      if (isset($input['_users_id_requester_notif'])
         && isset($input['_users_id_requester_notif']['alternative_email'])
         && is_array($input['_users_id_requester_notif']['alternative_email'])) {
         foreach ($input['_users_id_requester_notif']['alternative_email'] as $email) {
            if ($email && !NotificationMailing::isUserAddressValid($email)) {
               Session::addMessageAfterRedirect(
                  sprintf(__('Invalid email address %s'), $email),
                  false,
                  ERROR
               );
               return false;
            }
         }
      }

      // Manage auto assign
      $auto_assign_mode = Entity::getUsedConfig('auto_assign_mode', $input['entities_id']);

      switch ($auto_assign_mode) {
         case Entity::CONFIG_NEVER :
            break;

         case Entity::AUTO_ASSIGN_HARDWARE_CATEGORY :
            if ($item != null) {
               // Auto assign tech from item
               if ((!isset($input['_users_id_assign']) || ($input['_users_id_assign'] == 0))
                   && $item->isField('users_id_tech')) {
                  $input['_users_id_assign'] = $item->getField('users_id_tech');
               }
               // Auto assign group from item
               if ((!isset($input['_groups_id_assign']) || ($input['_groups_id_assign'] == 0))
                   && $item->isField('groups_id_tech')) {
                  $input['_groups_id_assign'] = $item->getField('groups_id_tech');
               }
            }
            // Auto assign tech/group from Category
            if (($input['itilcategories_id'] > 0)
                && ((!isset($input['_users_id_assign']) || !$input['_users_id_assign'])
                    || (!isset($input['_groups_id_assign']) || !$input['_groups_id_assign']))) {

               $cat = new ITILCategory();
               $cat->getFromDB($input['itilcategories_id']);
               if ((!isset($input['_users_id_assign']) || !$input['_users_id_assign'])
                   && $cat->isField('users_id')) {
                  $input['_users_id_assign'] = $cat->getField('users_id');
               }
               if ((!isset($input['_groups_id_assign']) || !$input['_groups_id_assign'])
                   && $cat->isField('groups_id')) {
                  $input['_groups_id_assign'] = $cat->getField('groups_id');
               }
            }
            break;

         case Entity::AUTO_ASSIGN_CATEGORY_HARDWARE :
            // Auto assign tech/group from Category
            if (($input['itilcategories_id'] > 0)
                && ((!isset($input['_users_id_assign']) || !$input['_users_id_assign'])
                    || (!isset($input['_groups_id_assign']) || !$input['_groups_id_assign']))) {

               $cat = new ITILCategory();
               $cat->getFromDB($input['itilcategories_id']);
               if ((!isset($input['_users_id_assign']) || !$input['_users_id_assign'])
                   && $cat->isField('users_id')) {
                  $input['_users_id_assign'] = $cat->getField('users_id');
               }
               if ((!isset($input['_groups_id_assign']) || !$input['_groups_id_assign'])
                   && $cat->isField('groups_id')) {
                  $input['_groups_id_assign'] = $cat->getField('groups_id');
               }
            }
            if ($item != null) {
               // Auto assign tech from item
               if ((!isset($input['_users_id_assign']) || ($input['_users_id_assign'] == 0))
                   && $item->isField('users_id_tech')) {
                  $input['_users_id_assign'] = $item->getField('users_id_tech');
               }
               // Auto assign group from item
               if ((!isset($input['_groups_id_assign']) || ($input['_groups_id_assign'] == 0))
                   && $item->isField('groups_id_tech')) {
                  $input['_groups_id_assign'] = $item->getField('groups_id_tech');
               }
            }
            break;
      }

      // Replay setting auto assign if set in rules engine or by auto_assign_mode
      if (((isset($input["_users_id_assign"])
           && ((!is_array($input['_users_id_assign']) &&  $input["_users_id_assign"] > 0)
               || is_array($input['_users_id_assign']) && count($input['_users_id_assign']) > 0))
           || (isset($input["_groups_id_assign"])
           && ((!is_array($input['_groups_id_assign']) && $input["_groups_id_assign"] > 0)
               || is_array($input['_groups_id_assign']) && count($input['_groups_id_assign']) > 0))
           || (isset($input["_suppliers_id_assign"])
           && ((!is_array($input['_suppliers_id_assign']) && $input["_suppliers_id_assign"] > 0)
               || is_array($input['_suppliers_id_assign']) && count($input['_suppliers_id_assign']) > 0)))
          && (in_array($input['status'], $this->getNewStatusArray()))) {

         $input["status"] = self::ASSIGNED;
      }

      // Manage SLA / OLA asignment
      // Manual SLA / OLA defined : reset due date
      // No manual SLA / OLA and due date defined : reset auto SLA / OLA
      foreach ([SLM::TTR, SLM::TTO] as $slmType) {
         $this->slaAffect($slmType, $input, $manual_slas_id);
         $this->olaAffect($slmType, $input, $manual_olas_id);
      }

      // auto set type if not set
      if (!isset($input["type"])) {
         $input['type'] = Entity::getUsedConfig('tickettype', $input['entities_id'], '',
                                                Ticket::INCIDENT_TYPE);
      }

      return $input;
   }


   function post_addItem() {
      global $CFG_GLPI;

      // Log this event
      $username = 'anonymous';
      if (isset($_SESSION["glpiname"])) {
         $username = $_SESSION["glpiname"];
      }
      Event::log($this->fields['id'], "ticket", 4, "tracking",
                 sprintf(__('%1$s adds the item %2$s'), $username,
                         $this->fields['id']));

      if (isset($this->input["_followup"])
          && is_array($this->input["_followup"])
          && (strlen($this->input["_followup"]['content']) > 0)) {

         $fup  = new ITILFollowup();
         $type = "new";
         if (isset($this->fields["status"]) && ($this->fields["status"] == self::SOLVED)) {
            $type = "solved";
         }
         $toadd = ['type'       => $type,
                        'items_id' => $this->fields['id'],
                        'itemtype' => 'Ticket'];

         if (isset($this->input["_followup"]['content'])
             && (strlen($this->input["_followup"]['content']) > 0)) {
            $toadd["content"] = $this->input["_followup"]['content'];
         }

         if (isset($this->input["_followup"]['is_private'])) {
            $toadd["is_private"] = $this->input["_followup"]['is_private'];
         }
         // $toadd['_no_notif'] = true;

         $fup->add($toadd);
      }

      if ((isset($this->input["plan"]) && count($this->input["plan"]))
          || (isset($this->input["actiontime"]) && ($this->input["actiontime"] > 0))) {

         $task = new TicketTask();
         $type = "new";
         if (isset($this->fields["status"]) && ($this->fields["status"]  == self::SOLVED)) {
            $type = "solved";
         }
         $toadd = ["type"       => $type,
                        "tickets_id" => $this->fields['id'],
                        "actiontime" => $this->input["actiontime"]];

         if (isset($this->input["plan"]) && count($this->input["plan"])) {
            $toadd["plan"] = $this->input["plan"];
         }

         if (isset($_SESSION['glpitask_private'])) {
            $toadd['is_private'] = $_SESSION['glpitask_private'];
         }

         // $toadd['_no_notif'] = true;

         $task->add($toadd);
      }

      $ticket_ticket = new Ticket_Ticket();

      // From interface
      if (isset($this->input['_link'])) {
         $this->input['_link']['tickets_id_1'] = $this->fields['id'];
         // message if ticket's ID doesn't exist
         if (!empty($this->input['_link']['tickets_id_2'])) {
            if ($ticket_ticket->can(-1, CREATE, $this->input['_link'])) {
               $ticket_ticket->add($this->input['_link']);
            } else {
               Session::addMessageAfterRedirect(__('Unknown ticket'), false, ERROR);
            }
         }
      }

      // From mailcollector : do not check rights
      if (isset($this->input["_linkedto"])) {
         $input2['tickets_id_1'] = $this->fields['id'];
         $input2['tickets_id_2'] = $this->input["_linkedto"];
         $input2['link']         = Ticket_Ticket::LINK_TO;
         $ticket_ticket->add($input2);
      }

      // Manage SLA / OLA Level : add actions
      foreach ([SLM::TTR, SLM::TTO] as $slmType) {
         list($dateField, $slaField) = SLA::getFieldNames($slmType);
         if (isset($this->input[$slaField]) && ($this->input[$slaField] > 0)) {
            $this->manageSlaLevel($this->input[$slaField]);
         }
         list($dateField, $olaField) = OLA::getFieldNames($slmType);
         if (isset($this->input[$olaField]) && ($this->input[$olaField] > 0)) {
            $this->manageOlaLevel($this->input[$olaField]);
         }
      }

      // Add project task link if needed
      if (isset($this->input['_projecttasks_id'])) {
         $projecttask = new ProjectTask();
         if ($projecttask->getFromDB($this->input['_projecttasks_id'])) {
            $pt = new ProjectTask_Ticket();
            $pt->add(['projecttasks_id' => $this->input['_projecttasks_id'],
                           'tickets_id'      => $this->fields['id'],
                           /*'_no_notif'   => true*/]);
         }
      }

      // Add tasks in tasktemplates if defined in tickettemplate
      if (isset($this->input['_tasktemplates_id'])
          && is_array($this->input['_tasktemplates_id'])
          && count($this->input['_tasktemplates_id'])) {
         $tasktemplate = new TaskTemplate;
         $tickettask   = new TicketTask;
         foreach ($this->input['_tasktemplates_id'] as $tasktemplates_id) {
            $tasktemplate->getFromDB($tasktemplates_id);
            $tasktemplate_content = Toolbox::addslashes_deep($tasktemplate->fields["content"]);
            $tickettask->add(['tasktemplates_id'  => $tasktemplates_id,
                              'content'           => $tasktemplate_content,
                              'taskcategories_id' => $tasktemplate->fields['taskcategories_id'],
                              'actiontime'        => $tasktemplate->fields['actiontime'],
                              'state'             => $tasktemplate->fields['state'],
                              'tickets_id'        => $this->fields['id'],
                              'is_private'        => $tasktemplate->fields['is_private'],
                              'users_id_tech'     => $tasktemplate->fields['users_id_tech'],
                              'groups_id_tech'    => $tasktemplate->fields['groups_id_tech'],
                              '_disablenotif'     => true
                              ]);
         }
      }

      if (isset($this->input['_promoted_fup_id']) && $this->input['_promoted_fup_id'] > 0) {
         $fup = new ITILFollowup();
         $fup->getFromDB($this->input['_promoted_fup_id']);
         $fup_ticket_id = $fup->fields['items_id'];
         $fup->update([
            'id'                 => $this->input['_promoted_fup_id'],
            'sourceof_items_id'  => $this->getID()
         ]);
         Event::log($this->getID(), "ticket", 4, "tracking",
              sprintf(__('%s promotes a followup from ticket %s'), $_SESSION["glpiname"], $fup->fields['items_id']));
      }

      if (!empty($this->input['items_id'])) {
         $item_ticket = new Item_Ticket();
         foreach ($this->input['items_id'] as $itemtype => $items) {
            foreach ($items as $items_id) {
               $item_ticket->add(['items_id'      => $items_id,
                                       'itemtype'      => $itemtype,
                                       'tickets_id'    => $this->fields['id'],
                                       '_disablenotif' => true]);
            }
         }
      }

      parent::post_addItem();

      $this->manageValidationAdd($this->input);

      // Processing Email
      if (!isset($this->input['_disablenotif']) && $CFG_GLPI["use_notifications"]) {
         // Clean reload of the ticket
         $this->getFromDB($this->fields['id']);

         $type = "new";
         if (isset($this->fields["status"]) && ($this->fields["status"] == self::SOLVED)) {
            $type = "solved";
         }
         NotificationEvent::raiseEvent($type, $this);
      }

      if (isset($_SESSION['glpiis_ids_visible']) && !$_SESSION['glpiis_ids_visible']) {
         Session::addMessageAfterRedirect(sprintf(__('%1$s (%2$s)'),
                              __('Your ticket has been registered, its treatment is in progress.'),
                                                  sprintf(__('%1$s: %2$s'), __('Ticket'),
                                                          "<a href='".Ticket::getFormURLWithID($this->fields['id'])."'>".
                                                            $this->fields['id']."</a>")));
      }

   }


   /**
    * Manage Validation add from input
    *
    * @since 0.85
    *
    * @param $input array : input array
    *
    * @return nothing
   **/
   function manageValidationAdd($input) {

      //Action for send_validation rule
      if (isset($input["_add_validation"])) {
         if (isset($input['entities_id'])) {
            $entid = $input['entities_id'];
         } else if (isset($this->fields['entities_id'])) {
            $entid = $this->fields['entities_id'];
         } else {
            return false;
         }

         $validations_to_send = [];
         if (!is_array($input["_add_validation"])) {
            $input["_add_validation"] = [$input["_add_validation"]];
         }

         foreach ($input["_add_validation"] as $key => $validation) {
            switch ($validation) {
               case 'requester_supervisor' :
                  if (isset($input['_groups_id_requester'])
                      && $input['_groups_id_requester']) {
                     $users = Group_User::getGroupUsers(
                        $input['_groups_id_requester'],
                        ['is_manager' => 1]
                     );
                     foreach ($users as $data) {
                        $validations_to_send[] = $data['id'];
                     }
                  }
                  // Add to already set groups
                  foreach ($this->getGroups(CommonITILActor::REQUESTER) as $d) {
                     $users = Group_User::getGroupUsers(
                        $d['groups_id'],
                        ['is_manager' => 1]
                     );
                     foreach ($users as $data) {
                        $validations_to_send[] = $data['id'];
                     }
                  }
                  break;

               case 'assign_supervisor' :
                  if (isset($input['_groups_id_assign'])
                      && $input['_groups_id_assign']) {
                     $users = Group_User::getGroupUsers(
                        $input['_groups_id_assign'],
                        ['is_manager' => 1]
                     );
                     foreach ($users as $data) {
                        $validations_to_send[] = $data['id'];
                     }
                  }
                  foreach ($this->getGroups(CommonITILActor::ASSIGN) as $d) {
                     $users = Group_User::getGroupUsers(
                        $d['groups_id'],
                        ['is_manager' => 1]
                     );
                     foreach ($users as $data) {
                        $validations_to_send[] = $data['id'];
                     }
                  }
                  break;

               case 'requester_responsible':
                  if (isset($input['_users_id_requester'])) {
                     if (is_array($input['_users_id_requester'])) {
                        foreach ($input['_users_id_requester'] as $users_id) {
                           $user = new User();
                           if ($user->getFromDB($users_id)) {
                              $validations_to_send[] = $user->getField('users_id_supervisor');
                           }
                        }
                     } else {
                        $user = new User();
                        if ($user->getFromDB($input['_users_id_requester'])) {
                           $validations_to_send[] = $user->getField('users_id_supervisor');
                        }
                     }
                  }
                  break;

               default :
                  // Group case from rules
                  if ($key === 'group') {
                     foreach ($validation as $groups_id) {
                        $validation_right = 'validate_incident';
                        if (isset($input['type'])
                            && ($input['type'] == Ticket::DEMAND_TYPE)) {
                           $validation_right = 'validate_request';
                        }
                        $opt = ['groups_id' => $groups_id,
                                     'right'     => $validation_right,
                                     'entity'    => $entid];

                        $data_users = TicketValidation::getGroupUserHaveRights($opt);

                        foreach ($data_users as $user) {
                           $validations_to_send[] = $user['id'];
                        }
                     }
                  } else {
                     $validations_to_send[] = $validation;
                  }
            }

         }

         // Validation user added on ticket form
         if (isset($input['users_id_validate'])) {
            if (array_key_exists('groups_id', $input['users_id_validate'])) {
               foreach ($input['users_id_validate'] as $key => $validation_to_add) {
                  if (is_numeric($key)) {
                     $validations_to_send[] = $validation_to_add;
                  }
               }
            } else {
               foreach ($input['users_id_validate'] as $key => $validation_to_add) {
                  if (is_numeric($key)) {
                     $validations_to_send[] = $validation_to_add;
                  }
               }
            }
         }

         // Keep only one
         $validations_to_send = array_unique($validations_to_send);

         $validation          = new TicketValidation();

         if (count($validations_to_send)) {
            $values                = [];
            $values['tickets_id']  = $this->fields['id'];
            if (isset($input['id']) && $input['id'] != $this->fields['id']) {
               $values['_ticket_add'] = true;
            }

            // to know update by rules
            if (isset($input["_rule_process"])) {
               $values['_rule_process'] = $input["_rule_process"];
            }
            // if auto_import, tranfert it for validation
            if (isset($input['_auto_import'])) {
               $values['_auto_import'] = $input['_auto_import'];
            }

            // Cron or rule process of hability to do
            if (Session::isCron()
                || isset($input["_auto_import"])
                || isset($input["_rule_process"])
                || $validation->can(-1, CREATE, $values)) { // cron or allowed user

               $add_done = false;
               foreach ($validations_to_send as $user) {
                  // Do not auto add twice same validation
                  if (!TicketValidation::alreadyExists($values['tickets_id'], $user)) {
                     $values["users_id_validate"] = $user;
                     if ($validation->add($values)) {
                        $add_done = true;
                     }
                  }
               }
               if ($add_done) {
                  Event::log($this->fields['id'], "ticket", 4, "tracking",
                             sprintf(__('%1$s updates the item %2$s'), $_SESSION["glpiname"],
                                     $this->fields['id']));
               }
            }
         }
      }
      return true;
   }


   /**
    * Get active or solved tickets for an hardware last X days
    *
    * @since 0.83
    *
    * @param $itemtype  string   Item type
    * @param $items_id  integer  ID of the Item
    * @param $days      integer  day number
    *
    * @return integer
   **/
   function getActiveOrSolvedLastDaysTicketsForItem($itemtype, $items_id, $days) {
      global $DB;

      $result = [];

      $iterator = $DB->request([
         'FROM'      => $this->getTable(),
         'LEFT JOIN' => [
            'glpi_items_tickets' => [
               'ON' => [
                  'glpi_items_tickets' => 'tickets_id',
                  $this->getTable()    => 'id'
               ]
            ]
         ],
         'WHERE'     => [
            'glpi_items_tickets.items_id' => $items_id,
            'glpi_items_tickets.itemtype' => $itemtype,
            'OR'                          => [
               [
                  'NOT' => [
                     $this->getTable() . '.status' => array_merge(
                        $this->getClosedStatusArray(),
                        $this->getSolvedStatusArray()
                     )
                  ]
               ],
               [
                  'NOT' => [$this->getTable() . '.solvedate' => null],
                  new \QueryExpression(
                     "ADDDATE(" . $DB->quoteName($this->getTable()) .
                     ".".$DB->quoteName('solvedate').", INTERVAL $days DAY) > NOW()"
                  )
               ]
            ]
         ]
      ]);

      while ($tick = $iterator->next()) {
         $result[$tick['id']] = $tick['name'];
      }

      return $result;
   }


   /**
    * Count active tickets for an hardware
    *
    * @since 0.83
    *
    * @param $itemtype  string   Item type
    * @param $items_id  integer  ID of the Item
    *
    * @return integer
   **/
   function countActiveTicketsForItem($itemtype, $items_id) {
      global $DB;

      $result = $DB->request([
         'COUNT'     => 'cpt',
         'FROM'      => $this->getTable(),
         'LEFT JOIN' => [
            'glpi_items_tickets' => [
               'ON' => [
                  'glpi_items_tickets' => 'tickets_id',
                  $this->getTable()    => 'id'
               ]
            ]
         ],
         'WHERE'     => [
            'glpi_items_tickets.itemtype' => $itemtype,
            'glpi_items_tickets.items_id' => $items_id,
            'NOT'                         => [
               $this->getTable() . '.status' => array_merge(
                  $this->getSolvedStatusArray(),
                  $this->getClosedStatusArray()
               )
            ]
         ]
      ])->next();
      return $result['cpt'];
   }


   /**
    * Count solved tickets for an hardware last X days
    *
    * @since 0.83
    *
    * @param $itemtype  string   Item type
    * @param $items_id  integer  ID of the Item
    * @param $days      integer  day number
    *
    * @return integer
   **/
   function countSolvedTicketsForItemLastDays($itemtype, $items_id, $days) {
      global $DB;

      $result = $DB->request([
         'COUNT'     => 'cpt',
         'FROM'      => $this->getTable(),
         'LEFT JOIN' => [
            'glpi_items_tickets' => [
               'ON' => [
                  'glpi_items_tickets' => 'tickets_id',
                  $this->getTable()    => 'id'
               ]
            ]
         ],
         'WHERE'     => [
            'glpi_items_tickets.itemtype' => $itemtype,
            'glpi_items_tickets.items_id' => $items_id,
            $this->getTable() . '.status' => array_merge(
                  $this->getSolvedStatusArray(),
                  $this->getClosedStatusArray()
            ),
            new \QueryExpression(
               "ADDDATE(`".$this->getTable()."`.`solvedate`, INTERVAL $days DAY) > NOW()"
            ),
            'NOT'                         => [
               $this->getTable() . '.solvedate' => null
            ]
         ]
      ])->next();
      return $result['cpt'];
   }


   /**
    * Update date mod of the ticket
    *
    * @since 0.83.3 new proto
    *
    * @param $ID                           ID of the ticket
    * @param $no_stat_computation  boolean do not cumpute take into account stat (false by default)
    * @param $users_id_lastupdater integer to force last_update id (default 0 = not used)
   **/
   function updateDateMod($ID, $no_stat_computation = false, $users_id_lastupdater = 0) {

      if ($this->getFromDB($ID)) {
         if (!$no_stat_computation
             && !$this->isAlreadyTakenIntoAccount()
             && ($this->canTakeIntoAccount() || isCommandLine())) {
            return $this->update(
               [
                  'id'                         => $ID,
                  'takeintoaccount_delay_stat' => $this->computeTakeIntoAccountDelayStat(),
                  '_disablenotif'              => true
               ]
            );
         }

         parent::updateDateMod($ID, $no_stat_computation, $users_id_lastupdater);
      }
   }


   /**
    * Overloaded from commonDBTM
    *
    * @since 0.83
    *
    * @param $type itemtype of object to add
    *
    * @return rights
   **/
   function canAddItem($type) {

      if ($type == 'Document') {
         if ($this->getField('status') == self::CLOSED) {
            return false;
         }

         if ($this->canAddFollowups()) {
            return true;
         }
      }

      // as self::canUpdate & $this->canUpdateItem checks more general rights
      // (like STEAL or OWN),
      // we specify only the rights needed for this action
      return $this->checkEntity()
             && (Session::haveRight(self::$rightname, UPDATE)
                 || $this->canRequesterUpdateItem());
   }


   function canAddFollowups() {

      return ((Session::haveRight("followup", ITILFollowup::ADDMYTICKET)
               && ($this->isUser(CommonITILActor::REQUESTER, Session::getLoginUserID())
                   || (isset($this->fields["users_id_recipient"])
                        && ($this->fields["users_id_recipient"] === Session::getLoginUserID()))))
              || Session::haveRight('followup', ITILFollowup::ADDALLTICKET)
              || (Session::haveRight('followup', ITILFollowup::ADDGROUPTICKET)
                  && isset($_SESSION["glpigroups"])
                  && $this->haveAGroup(CommonITILActor::REQUESTER, $_SESSION['glpigroups']))
              || $this->isUser(CommonITILActor::ASSIGN, Session::getLoginUserID())
              || (isset($_SESSION["glpigroups"])
                  && $this->haveAGroup(CommonITILActor::ASSIGN, $_SESSION['glpigroups'])));
   }

   /**
    * Check if user can add followups to the ticket.
    *
    * @param integer $user_id
    *
    * @return boolean
    */
   public function canUserAddFollowups($user_id) {

      $entity_id = $this->fields['entities_id'];

      $group_user = new Group_User();
      $user_groups = $group_user->getUserGroups($user_id, ['entities_id' => $entity_id]);
      $user_groups_ids = [];
      foreach ($user_groups as $user_group) {
         $user_groups_ids[] = $user_group['id'];
      }

      $rightname = ITILFollowup::$rightname;

      return (
            Profile::haveUserRight($user_id, $rightname, ITILFollowup::ADDMYTICKET, $entity_id)
            && ($this->isUser(CommonITILActor::REQUESTER, $user_id)
               || (
                  isset($this->fields['users_id_recipient'])
                  && ($this->fields['users_id_recipient'] === $user_id)
               )
            )
         )
         || Profile::haveUserRight($user_id, $rightname, ITILFollowup::ADDALLTICKET, $entity_id)
         || (
            Profile::haveUserRight($user_id, $rightname, ITILFollowup::ADDGROUPTICKET, $entity_id)
            && $this->haveAGroup(CommonITILActor::REQUESTER, $user_groups_ids)
         )
         || $this->isUser(CommonITILActor::ASSIGN, Session::getLoginUserID())
         || $this->haveAGroup(CommonITILActor::ASSIGN, $user_groups_ids);
   }


   /**
    * Get default values to search engine to override
   **/
   static function getDefaultSearchRequest() {

      $search = ['criteria' => [0 => ['field'      => 12,
                                                     'searchtype' => 'equals',
                                                     'value'      => 'notclosed']],
                      'sort'     => 19,
                      'order'    => 'DESC'];

      if (Session::haveRight(self::$rightname, self::READALL)) {
         $search['criteria'][0]['value'] = 'notold';
      }
      return $search;
   }


   /**
    * @see CommonDBTM::getSpecificMassiveActions()
   **/
   function getSpecificMassiveActions($checkitem = null) {

      $isadmin = static::canUpdate();
      $actions = parent::getSpecificMassiveActions($checkitem);

      if (Session::getCurrentInterface() == 'central') {
         if (ITILFollowup::canCreate()) {
            $actions['ITILFollowup'.MassiveAction::CLASS_ACTION_SEPARATOR.'add_followup']
               = __('Add a new followup');
         }

         if (TicketTask::canCreate()) {
            $actions[__CLASS__.MassiveAction::CLASS_ACTION_SEPARATOR.'add_task'] = __('Add a new task');
         }

         if (TicketValidation::canCreate()) {
            $actions['TicketValidation'.MassiveAction::CLASS_ACTION_SEPARATOR.'submit_validation']
               = __('Approval request');
         }

         if (Item_Ticket::canCreate()) {
            $actions['Item_Ticket'.MassiveAction::CLASS_ACTION_SEPARATOR.'add_item'] = _x('button', 'Add an item');
         }

         if (Item_Ticket::canDelete()) {
            $actions['Item_Ticket'.MassiveAction::CLASS_ACTION_SEPARATOR.'delete_item'] = _x('button', 'Remove an item');
         }

         if (Session::haveRight(self::$rightname, UPDATE)) {
            $actions[__CLASS__.MassiveAction::CLASS_ACTION_SEPARATOR.'add_actor']
               = __('Add an actor');
            $actions[__CLASS__.MassiveAction::CLASS_ACTION_SEPARATOR.'update_notif']
               = __('Set notifications for all actors');
            $actions['Ticket_Ticket'.MassiveAction::CLASS_ACTION_SEPARATOR.'add']
               = _x('button', 'Link tickets');

         }

         if (Session::haveRight(self::$rightname, UPDATE)) {
            $kb_item = new KnowbaseItem();
            $kb_item->getEmpty();
            if ($kb_item->canViewItem()) {
               $actions['KnowbaseItem_Item'.MassiveAction::CLASS_ACTION_SEPARATOR.'add'] = _x('button', 'Link knowledgebase article');
            }
         }

         if (Ticket::canCreate() && Ticket::canDelete()) {
            $actions[__CLASS__.MassiveAction::CLASS_ACTION_SEPARATOR.'merge_as_followup']
               = __('Merge as Followup');
         }
      }
      return $actions;
   }


   static function showMassiveActionsSubForm(MassiveAction $ma) {
      global $CFG_GLPI;

      switch ($ma->getAction()) {
         case 'merge_as_followup' :
            $itemtype = $ma->getItemtype(true);
            $mergeparam = [
               'name'         => "_mergeticket",
               'used'         => $ma->items['Ticket'],
               'displaywith'  => ['id']
            ];
            echo "<table class='center-h'><tr>";
            echo "<td>".__('Ticket')."</td><td colspan='3'>";
            Ticket::dropdown($mergeparam);
            echo "</td></tr><tr><td>".__('Merge Followups')."</td><td>";
            Html::showCheckbox([
               'name'    => 'with_followups',
               'checked' => false
            ]);
            echo "</td><td>".__('Merge Documents')."</td><td>";
            Html::showCheckbox([
               'name'    => 'with_documents',
               'checked' => false
            ]);
            echo "</td></tr><tr><td colspan='4'>";
            echo Html::submit(_x('button', 'Merge'), [
               'name'      => 'merge',
               'confirm'   => __('Confirm the merge? This ticket will be deleted!')
            ]);
            echo "</td></tr></table>";
            return true;
      }
      return parent::showMassiveActionsSubForm($ma);
   }


   static function processMassiveActionsForOneItemtype(MassiveAction $ma, CommonDBTM $item,
                                                       array $ids) {
      global $DB;

      switch ($ma->getAction()) {
         case 'merge_as_followup' :
            $merge_succeeded = false;
            $input = $ma->getInput();

            foreach ($ids as $id) {
               try {
                  $DB->beginTransaction();
                  $fup = new ITILFollowup();
                  $document_item = new Document_Item();
                  $input2 = [];

                  foreach ($input as $key => $val) {
                     $input2[$key] = $val;
                  }
                  if ($item->can($id, CREATE) && $item->can($id, DELETE)) {
                     if (!$item->getFromDB($id)) {
                        //Cannot retreive ticket. Abort/fail the merge
                        throw new \RuntimeException(ERROR_ON_ACTION, MassiveAction::ACTION_KO);
                     }

                     //Build followup from the original ticket
                     $input2 = [
                        'itemtype'        => 'Ticket',
                        'items_id'        => $input['_mergeticket'],
                        'content'         => $DB->escape($item->fields['name']."\n\n".$item->fields['content']),
                        'users_id'        => $item->fields['users_id_recipient'],
                        'date_creation'   => $item->fields['date_creation'],
                        'date_mod'        => $item->fields['date_mod'],
                        'date'            => $item->fields['date_creation'],
                        'sourceitems_id'  => $item->getID()
                     ];

                     if (!$fup->add($input2)) {
                        //Cannot add followup. Abort/fail the merge
                        throw new \RuntimeException(ERROR_ON_ACTION, MassiveAction::ACTION_KO);
                     }

                     if (isset($input['with_followups'])) {
                        //Migrate/clone any followups to the ticket
                        $tomerge = $fup->find([
                           'items_id' => $id,
                           'itemtype' => 'Ticket'
                        ]);
                        foreach ($tomerge as $key => $fup2) {
                           $fup2['items_id'] = $input['_mergeticket'];
                           $fup2['sourceitems_id'] = $item->getID();
                           $fup2['content'] = $DB->escape($fup2['content']);
                           unset($fup2['id']);
                           if (!$fup->add($fup2)) {
                              //Cannot add followup. Abort/fail the merge
                              throw new \RuntimeException(ERROR_ON_ACTION, MassiveAction::ACTION_KO);
                           }
                        }
                     }

                     if (isset($input['with_documents'])) {
                        // Create new links for any Documents
                        $tomerge = $document_item->find([
                           'itemtype' => 'Ticket',
                           'items_id' => $id
                        ]);
                        // Need to check for each item since new duplicates could be added during the merge process
                        $existing_docs = $document_item->find([
                           'itemtype' => 'Ticket',
                           'items_id' => $input['_mergeticket']
                        ]);
                        $tomerge = array_diff($tomerge, $existing_docs);

                        foreach ($tomerge as $key => $document_item2) {
                           $document_item2['items_id'] = $input['_mergeticket'];
                           unset($document_item2['id']);
                           if (!$document_item->add($document_item2)) {
                              //Cannot add document. Abort/fail the merge
                              throw new \RuntimeException(ERROR_ON_ACTION, MassiveAction::ACTION_KO);
                           }
                        }
                     }

                     //Add relation (this is parent of merge target)
                     $tt = new Ticket_Ticket();
                     $linkparams = [
                        'link'         => Ticket_Ticket::SON_OF,
                        'tickets_id_1' => $id,
                        'tickets_id_2' => $input['_mergeticket']
                     ];

                     // Remove existing links
                     $tt->deleteByCriteria([
                        'OR' => [
                           'AND' => [
                              'tickets_id_1' => $input['_mergeticket'],
                              'tickets_id_2' => $id
                           ],
                           'AND' => [
                              'tickets_id_2' => $input['_mergeticket'],
                              'tickets_id_1' => $id
                           ]
                        ]
                     ]);

                     if (!$tt->add($linkparams)) {
                        //Cannot link tickets. Abort/fail the merge
                        throw new \RuntimeException(ERROR_ON_ACTION, MassiveAction::ACTION_KO);
                     }

                     if (!$item->delete(['id' => $id, '_disablenotif' => true])) {
                        throw new \RuntimeException(ERROR_ON_ACTION, MassiveAction::ACTION_KO);
                     }

                     $DB->commit();
                     $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_OK);
                     Event::log($input['_mergeticket'], 'ticket', 4, 'tracking',
                        sprintf(__('%s merges ticket %s into %s'), $_SESSION['glpiname'],
                        $id, $input['_mergeticket']));
                  } else {
                     throw new \RuntimeException(ERROR_RIGHT, MassiveAction::ACTION_NORIGHT);
                  }
               } catch (\RuntimeException $e) {
                  $DB->rollBack();
                  $ma->itemDone($item->getType(), $id, $e->getCode());
                  $ma->addMessage($item->getErrorMessage($e->getMessage()));
               }
            }

            return;
      }
      parent::processMassiveActionsForOneItemtype($ma, $item, $ids);
   }


   function rawSearchOptions() {
      global $CFG_GLPI;
      $tab = [];

      $tab = array_merge($tab, $this->getSearchOptionsMain());

      $tab[] = [
         'id'                 => '155',
         'table'              => $this->getTable(),
         'field'              => 'time_to_own',
         'name'               => __('Time to own'),
         'datatype'           => 'datetime',
         'maybefuture'        => true,
         'massiveaction'      => false,
         'additionalfields'   => ['status']
      ];

      $tab[] = [
         'id'                 => '158',
         'table'              => $this->getTable(),
         'field'              => 'time_to_own',
         'name'               => __('Time to own + Progress'),
         'massiveaction'      => false,
         'nosearch'           => true,
         'additionalfields'   => ['status']
      ];

      $tab[] = [
         'id'                 => '159',
         'table'              => 'glpi_tickets',
         'field'              => 'is_late',
         'name'               => __('Time to own exceedeed'),
         'datatype'           => 'bool',
         'massiveaction'      => false,
         'computation'        => 'IF(TABLE.`time_to_own` IS NOT NULL
                                            AND TABLE.`status` <> '.self::WAITING.'
                                            AND (TABLE.`takeintoaccount_delay_stat`
                                                        > TIME_TO_SEC(TIMEDIFF(TABLE.`time_to_own`,
                                                                               TABLE.`date`))
                                                 OR (TABLE.`takeintoaccount_delay_stat` = 0
                                                      AND TABLE.`time_to_own` < NOW())),
                                            1, 0)'
      ];

      $tab[] = [
         'id'                 => '180',
         'table'              => $this->getTable(),
         'field'              => 'internal_time_to_resolve',
         'name'               => __('Internal time to resolve'),
         'datatype'           => 'datetime',
         'maybefuture'        => true,
         'massiveaction'      => false,
         'additionalfields'   => ['status']
      ];

      $tab[] = [
         'id'                 => '181',
         'table'              => $this->getTable(),
         'field'              => 'internal_time_to_resolve',
         'name'               => __('Internal time to resolve + Progress'),
         'massiveaction'      => false,
         'nosearch'           => true,
         'additionalfields'   => ['status']
      ];

      $tab[] = [
         'id'                 => '182',
         'table'              => $this->getTable(),
         'field'              => 'is_late',
         'name'               => __('Internal time to resolve exceedeed'),
         'datatype'           => 'bool',
         'massiveaction'      => false,
         'computation'        => 'IF(TABLE.`internal_time_to_resolve` IS NOT NULL
                                            AND TABLE.`status` <> 4
                                            AND (TABLE.`solvedate` > TABLE.`internal_time_to_resolve`
                                                 OR (TABLE.`solvedate` IS NULL
                                                      AND TABLE.`internal_time_to_resolve` < NOW())),
                                            1, 0)'
      ];

      $tab[] = [
         'id'                 => '185',
         'table'              => $this->getTable(),
         'field'              => 'internal_time_to_own',
         'name'               => __('Internal time to own'),
         'datatype'           => 'datetime',
         'maybefuture'        => true,
         'massiveaction'      => false,
         'additionalfields'   => ['status']
      ];

      $tab[] = [
         'id'                 => '186',
         'table'              => $this->getTable(),
         'field'              => 'internal_time_to_own',
         'name'               => __('Internal time to own + Progress'),
         'massiveaction'      => false,
         'nosearch'           => true,
         'additionalfields'   => ['status']
      ];

      $tab[] = [
         'id'                 => '187',
         'table'              => 'glpi_tickets',
         'field'              => 'is_late',
         'name'               => __('Internal time to own exceedeed'),
         'datatype'           => 'bool',
         'massiveaction'      => false,
         'computation'        => 'IF(TABLE.`internal_time_to_own` IS NOT NULL
                                            AND TABLE.`status` <> '.self::WAITING.'
                                            AND (TABLE.`takeintoaccount_delay_stat`
                                                        > TIME_TO_SEC(TIMEDIFF(TABLE.`internal_time_to_own`,
                                                                               TABLE.`date`))
                                                 OR (TABLE.`takeintoaccount_delay_stat` = 0
                                                      AND TABLE.`internal_time_to_own` < NOW())),
                                            1, 0)'
      ];

      $tab[] = [
         'id'                 => '14',
         'table'              => $this->getTable(),
         'field'              => 'type',
         'name'               => __('Type'),
         'searchtype'         => 'equals',
         'datatype'           => 'specific'
      ];

      $tab[] = [
         'id'                 => '13',
         'table'              => 'glpi_items_tickets',
         'field'              => 'items_id',
         'name'               => _n('Associated element', 'Associated elements', Session::getPluralNumber()),
         'datatype'           => 'specific',
         'comments'           => true,
         'nosort'             => true,
         'nosearch'           => true,
         'additionalfields'   => ['itemtype'],
         'joinparams'         => [
            'jointype'           => 'child'
         ],
         'forcegroupby'       => true,
         'massiveaction'      => false
      ];

      $tab[] = [
         'id'                 => '131',
         'table'              => 'glpi_items_tickets',
         'field'              => 'itemtype',
         'name'               => _n('Associated item type', 'Associated item types', Session::getPluralNumber()),
         'datatype'           => 'itemtypename',
         'itemtype_list'      => 'ticket_types',
         'nosort'             => true,
         'additionalfields'   => ['itemtype'],
         'joinparams'         => [
            'jointype'           => 'child'
         ],
         'forcegroupby'       => true,
         'massiveaction'      => false
      ];

      $tab[] = [
         'id'                 => '9',
         'table'              => 'glpi_requesttypes',
         'field'              => 'name',
         'name'               => __('Request source'),
         'datatype'           => 'dropdown'
      ];

      $location_so = Location::rawSearchOptionsToAdd();
      foreach ($location_so as &$so) {
         //duplicated search options :(
         switch ($so['id']) {
            case 3:
               $so['id'] = 83;
               break;
            case 91:
               $so['id'] = 84;
               break;
            case 92:
               $so['id'] = 85;
               break;
            case 93:
               $so['id'] = 86;
               break;
         }
      }
      $tab = array_merge($tab, $location_so);

      // For ticket template
      $tab[] = [
         'id'                 => '142',
         'table'              => 'glpi_documents',
         'field'              => 'name',
         'name'               => _n('Document', 'Documents', Session::getPluralNumber()),
         'forcegroupby'       => true,
         'usehaving'          => true,
         'nosearch'           => true,
         'nodisplay'          => true,
         'datatype'           => 'dropdown',
         'massiveaction'      => false,
         'joinparams'         => [
            'jointype'           => 'items_id',
            'beforejoin'         => [
               'table'              => 'glpi_documents_items',
               'joinparams'         => [
                  'jointype'           => 'itemtype_item'
               ]
            ]
         ]
      ];

      $tab = array_merge($tab, $this->getSearchOptionsActors());

      $tab[] = [
         'id'                 => 'sla',
         'name'               => __('SLA')
      ];

      $tab[] = [
         'id'                 => '37',
         'table'              => 'glpi_slas',
         'field'              => 'name',
         'linkfield'          => 'slas_id_tto',
         'name'               => __('SLA')."&nbsp;".__('Time to own'),
         'massiveaction'      => false,
         'datatype'           => 'dropdown',
         'joinparams'         => [
            'condition'          => "AND NEWTABLE.`type` = '".SLM::TTO."'"
         ],
         'condition'          => "`glpi_slas`.`type` = '".SLM::TTO."'"
      ];

      $tab[] = [
         'id'                 => '30',
         'table'              => 'glpi_slas',
         'field'              => 'name',
         'linkfield'          => 'slas_id_ttr',
         'name'               => __('SLA')."&nbsp;".__('Time to resolve'),
         'massiveaction'      => false,
         'datatype'           => 'dropdown',
         'joinparams'         => [
            'condition'          => "AND NEWTABLE.`type` = '".SLM::TTR."'"
         ],
         'condition'          => "`glpi_slas`.`type` = '".SLM::TTR."'"
      ];

      $tab[] = [
         'id'                 => '32',
         'table'              => 'glpi_slalevels',
         'field'              => 'name',
         'name'               => __('SLA')."&nbsp;".__('Escalation level'),
         'massiveaction'      => false,
         'datatype'           => 'dropdown',
         'joinparams'         => [
            'beforejoin'         => [
               'table'              => 'glpi_slalevels_tickets',
               'joinparams'         => [
                  'jointype'           => 'child'
               ]
            ]
         ],
         'forcegroupby'       => true
      ];

      $tab[] = [
         'id'                 => 'ola',
         'name'               => __('OLA')
      ];

      $tab[] = [
         'id'                 => '190',
         'table'              => 'glpi_olas',
         'field'              => 'name',
         'linkfield'          => 'olas_id_tto',
         'name'               => __('OLA')."&nbsp;".__('Internal time to own'),
         'massiveaction'      => false,
         'datatype'           => 'dropdown',
         'joinparams'         => [
            'condition'          => "AND NEWTABLE.`type` = '".SLM::TTO."'"
         ],
         'condition'          => "`glpi_olas`.`type` = '".SLM::TTO."'"
      ];

      $tab[] = [
         'id'                 => '191',
         'table'              => 'glpi_olas',
         'field'              => 'name',
         'linkfield'          => 'olas_id_ttr',
         'name'               => __('OLA')."&nbsp;".__('Internal time to resolve'),
         'massiveaction'      => false,
         'datatype'           => 'dropdown',
         'joinparams'         => [
            'condition'          => "AND NEWTABLE.`type` = '".SLM::TTR."'"
         ],
         'condition'          => "`glpi_olas`.`type` = '".SLM::TTR."'"
      ];

      $tab[] = [
         'id'                 => '192',
         'table'              => 'glpi_olalevels',
         'field'              => 'name',
         'name'               => __('OLA')."&nbsp;".__('Escalation level'),
         'massiveaction'      => false,
         'datatype'           => 'dropdown',
         'joinparams'         => [
            'beforejoin'         => [
               'table'              => 'glpi_olalevels_tickets',
               'joinparams'         => [
                  'jointype'           => 'child'
               ]
            ]
         ],
         'forcegroupby'       => true
      ];

      $validation_options = TicketValidation::rawSearchOptionsToAdd();
      if (!Session::haveRightsOr(
         'ticketvalidation',
         [
            TicketValidation::CREATEINCIDENT,
            TicketValidation::CREATEREQUEST
         ]
      )) {
         foreach ($validation_options as &$validation_option) {
            if (isset($validation_option['table'])) {
               $validation_option['massiveaction'] = false;
            }
         }
      }
      $tab = array_merge($tab, $validation_options);

      $tab[] = [
         'id'                 => 'satisfaction',
         'name'               => __('Satisfaction survey')
      ];

      $tab[] = [
         'id'                 => '31',
         'table'              => 'glpi_ticketsatisfactions',
         'field'              => 'type',
         'name'               => __('Type'),
         'massiveaction'      => false,
         'searchtype'         => ['equals', 'notequals'],
         'searchequalsonfield' => true,
         'joinparams'         => [
            'jointype'           => 'child'
         ],
         'datatype'           => 'specific'
      ];

      $tab[] = [
         'id'                 => '60',
         'table'              => 'glpi_ticketsatisfactions',
         'field'              => 'date_begin',
         'name'               => __('Creation date'),
         'datatype'           => 'datetime',
         'massiveaction'      => false,
         'joinparams'         => [
            'jointype'           => 'child'
         ]
      ];

      $tab[] = [
         'id'                 => '61',
         'table'              => 'glpi_ticketsatisfactions',
         'field'              => 'date_answered',
         'name'               => __('Response date'),
         'datatype'           => 'datetime',
         'massiveaction'      => false,
         'joinparams'         => [
            'jointype'           => 'child'
         ]
      ];

      $tab[] = [
         'id'                 => '62',
         'table'              => 'glpi_ticketsatisfactions',
         'field'              => 'satisfaction',
         'name'               => __('Satisfaction'),
         'datatype'           => 'number',
         'massiveaction'      => false,
         'joinparams'         => [
            'jointype'           => 'child'
         ]
      ];

      $tab[] = [
         'id'                 => '63',
         'table'              => 'glpi_ticketsatisfactions',
         'field'              => 'comment',
         'name'               => __('Comments'),
         'datatype'           => 'text',
         'massiveaction'      => false,
         'joinparams'         => [
            'jointype'           => 'child'
         ]
      ];

      $tab = array_merge($tab, ITILFollowup::rawSearchOptionsToAdd());

      $tab = array_merge($tab, TicketTask::rawSearchOptionsToAdd());

      $tab = array_merge($tab, $this->getSearchOptionsStats());

      $tab[] = [
         'id'                 => '150',
         'table'              => $this->getTable(),
         'field'              => 'takeintoaccount_delay_stat',
         'name'               => __('Take into account time'),
         'datatype'           => 'timestamp',
         'forcegroupby'       => true,
         'massiveaction'      => false
      ];

      if (Session::haveRightsOr(self::$rightname,
                                [self::READALL, self::READASSIGN, self::OWN])) {
         $tab[] = [
            'id'                 => 'linktickets',
            'name'               => _n('Linked ticket', 'Linked tickets', Session::getPluralNumber())
         ];

         $tab[] = [
            'id'                 => '40',
            'table'              => 'glpi_tickets_tickets',
            'field'              => 'tickets_id_1',
            'name'               => __('All linked tickets'),
            'massiveaction'      => false,
            'forcegroupby'       => true,
            'searchtype'         => 'equals',
            'joinparams'         => [
               'jointype' => 'item_item'
            ],
            'additionalfields'   => ['tickets_id_2']
         ];

         $tab[] = [
            'id'                 => '47',
            'table'              => 'glpi_tickets_tickets',
            'field'              => 'tickets_id_1',
            'name'               => __('Duplicated tickets'),
            'massiveaction'      => false,
            'searchtype'         => 'equals',
            'joinparams'         => [
               'jointype'           => 'item_item',
               'condition'          => 'AND NEWTABLE.`link` = '.Ticket_Ticket::DUPLICATE_WITH
            ],
            'additionalfields'   => ['tickets_id_2'],
            'forcegroupby'       => true
         ];

         $tab[] = [
            'id'                 => '41',
            'table'              => 'glpi_tickets_tickets',
            'field'              => 'id',
            'name'               => __('Number of all linked tickets'),
            'massiveaction'      => false,
            'datatype'           => 'count',
            'usehaving'          => true,
            'joinparams'         => [
               'jointype'           => 'item_item'
            ]
         ];

         $tab[] = [
            'id'                 => '46',
            'table'              => 'glpi_tickets_tickets',
            'field'              => 'id',
            'name'               => __('Number of duplicated tickets'),
            'massiveaction'      => false,
            'datatype'           => 'count',
            'usehaving'          => true,
            'joinparams'         => [
               'jointype'           => 'item_item',
               'condition'          => 'AND NEWTABLE.`link` = '.Ticket_Ticket::DUPLICATE_WITH
            ]
         ];

         $tab[] = [
            'id'                 => '50',
            'table'              => 'glpi_tickets',
            'field'              => 'id',
            'linkfield'          => 'tickets_id_2',
            'name'               => __('Parent tickets'),
            'massiveaction'      => false,
            'searchtype'         => 'equals',
            'datatype'           => 'itemlink',
            'usehaving'          => true,
            'joinparams'         => [
               'beforejoin'         => [
                  'table'              => 'glpi_tickets_tickets',
                  'joinparams'         => [
                     'jointype'           => 'child',
                     'linkfield'          => 'tickets_id_1',
                     'condition'          => 'AND NEWTABLE.`link` = '.Ticket_Ticket::SON_OF,
                  ]
               ]
            ],
            'forcegroupby'       => true
         ];

         $tab[] = [
            'id'                 => '67',
            'table'              => 'glpi_tickets',
            'field'              => 'id',
            'linkfield'          => 'tickets_id_1',
            'name'               => __('Child tickets'),
            'massiveaction'      => false,
            'searchtype'         => 'equals',
            'datatype'           => 'itemlink',
            'usehaving'          => true,
            'joinparams'         => [
               'beforejoin'         => [
                  'table'              => 'glpi_tickets_tickets',
                  'joinparams'         => [
                     'jointype'           => 'child',
                     'linkfield'          => 'tickets_id_2',
                     'condition'          => 'AND NEWTABLE.`link` = '.Ticket_Ticket::SON_OF,
                  ]
               ]
            ],
            'forcegroupby'       => true
         ];

         $tab[] = [
            'id'                 => '68',
            'table'              => 'glpi_tickets_tickets',
            'field'              => 'id',
            'name'               => __('Number of sons tickets'),
            'massiveaction'      => false,
            'datatype'           => 'count',
            'usehaving'          => true,
            'joinparams'         => [
               'linkfield'          => 'tickets_id_2',
               'jointype'           => 'child',
               'condition'          => 'AND NEWTABLE.`link` = '.Ticket_Ticket::SON_OF
            ],
            'forcegroupby'       => true
         ];

         $tab[] = [
            'id'                 => '69',
            'table'              => 'glpi_tickets_tickets',
            'field'              => 'id',
            'name'               => __('Number of parent tickets'),
            'massiveaction'      => false,
            'datatype'           => 'count',
            'usehaving'          => true,
            'joinparams'         => [
               'linkfield'          => 'tickets_id_1',
               'jointype'           => 'child',
               'condition'          => 'AND NEWTABLE.`link` = '.Ticket_Ticket::SON_OF
            ],
            'additionalfields'   => ['tickets_id_2']
         ];

         $tab = array_merge($tab, $this->getSearchOptionsSolution());

         if (Session::haveRight('ticketcost', READ)) {
            $tab = array_merge($tab, TicketCost::rawSearchOptionsToAdd());
         }
      }

      if (Session::haveRight('problem', READ)) {
         $tab[] = [
            'id'                 => 'problem',
            'name'               => __('Problems')
         ];

         $tab[] = [
            'id'                 => '141',
            'table'              => 'glpi_problems_tickets',
            'field'              => 'id',
            'name'               => _x('quantity', 'Number of problems'),
            'forcegroupby'       => true,
            'usehaving'          => true,
            'datatype'           => 'count',
            'massiveaction'      => false,
            'joinparams'         => [
               'jointype'           => 'child'
            ]
         ];
      }

      // Filter search fields for helpdesk
      if (!Session::isCron() // no filter for cron
          && (Session::getCurrentInterface() != 'central')) {
         $tokeep = ['common', 'requester','satisfaction'];
         if (Session::haveRightsOr('ticketvalidation',
                                   array_merge(TicketValidation::getValidateRights(),
                                               TicketValidation::getCreateRights()))) {
            $tokeep[] = 'validation';
         }
         $keep = false;
         foreach ($tab as $key => &$val) {
            if (!isset($val['table'])) {
               $keep = in_array($val['id'], $tokeep);
            }
            if (!$keep) {
               if (isset($val['table'])) {
                  $val['nosearch'] = true;
               }
            }
         }
      }
      return $tab;
   }


   /**
    * @since 0.84
    *
    * @param $field
    * @param $values
    * @param $options   array
   **/
   static function getSpecificValueToDisplay($field, $values, array $options = []) {

      if (!is_array($values)) {
         $values = [$field => $values];
      }
      switch ($field) {
         case 'content' :
            $content = Toolbox::unclean_cross_side_scripting_deep(Html::entity_decode_deep($values[$field]));
            $content = Html::clean($content);
            if (empty($content)) {
               $content = ' ';
            }
            return nl2br($content);

         case 'type':
            return self::getTicketTypeName($values[$field]);
      }
      return parent::getSpecificValueToDisplay($field, $values, $options);
   }


   /**
    * @since 0.84
    *
    * @param $field
    * @param $name            (default '')
    * @param $values          (default '')
    * @param $options   array
    *
    * @return string
   **/
   static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = []) {

      if (!is_array($values)) {
         $values = [$field => $values];
      }
      $options['display'] = false;
      switch ($field) {
         case 'content' :
            return "<textarea cols='90' rows='6' name='$name'>".$values['content']."</textarea>";

         case 'type':
            $options['value'] = $values[$field];
            return self::dropdownType($name, $options);
      }
      return parent::getSpecificValueToSelect($field, $name, $values, $options);
   }


   /**
    * Dropdown of ticket type
    *
    * @param $name            select name
    * @param $options   array of options:
    *    - value     : integer / preselected value (default 0)
    *    - toadd     : array / array of specific values to add at the begining
    *    - on_change : string / value to transmit to "onChange"
    *    - display   : boolean / display or get string (default true)
    *
    * @return string id of the select
   **/
   static function dropdownType($name, $options = []) {

      $params['value']       = 0;
      $params['toadd']       = [];
      $params['on_change']   = '';
      $params['display']     = true;

      if (is_array($options) && count($options)) {
         foreach ($options as $key => $val) {
            $params[$key] = $val;
         }
      }

      $items = [];
      if (count($params['toadd']) > 0) {
         $items = $params['toadd'];
      }

      $items += self::getTypes();

      return Dropdown::showFromArray($name, $items, $params);
   }


   /**
    * Get ticket types
    *
    * @return array of types
   **/
   static function getTypes() {

      $options[self::INCIDENT_TYPE] = __('Incident');
      $options[self::DEMAND_TYPE]   = __('Request');

      return $options;
   }


   /**
    * Get ticket type Name
    *
    * @param $value type ID
   **/
   static function getTicketTypeName($value) {

      switch ($value) {
         case self::INCIDENT_TYPE :
            return __('Incident');

         case self::DEMAND_TYPE :
            return __('Request');

         default :
            // Return $value if not defined
            return $value;
      }
   }


   /**
    * get the Ticket status list
    *
    * @param $withmetaforsearch boolean (false by default)
    *
    * @return array
   **/
   static function getAllStatusArray($withmetaforsearch = false) {

      // To be overridden by class
      $tab = [self::INCOMING => _x('status', 'New'),
                   self::ASSIGNED => _x('status', 'Processing (assigned)'),
                   self::PLANNED  => _x('status', 'Processing (planned)'),
                   self::WAITING  => __('Pending'),
                   self::SOLVED   => _x('status', 'Solved'),
                   self::CLOSED   => _x('status', 'Closed')];

      if ($withmetaforsearch) {
         $tab['notold']    = _x('status', 'Not solved');
         $tab['notclosed'] = _x('status', 'Not closed');
         $tab['process']   = __('Processing');
         $tab['old']       = _x('status', 'Solved + Closed');
         $tab['all']       = __('All');
      }
      return $tab;
   }


   /**
    * Get the ITIL object closed status list
    *
    * @since 0.83
    *
    * @return array
   **/
   static function getClosedStatusArray() {
      return [self::CLOSED];
   }


   /**
    * Get the ITIL object solved status list
    *
    * @since 0.83
    *
    * @return array
   **/
   static function getSolvedStatusArray() {
      return [self::SOLVED];
   }

   /**
    * Get the ITIL object new status list
    *
    * @since 0.83.8
    *
    * @return array
   **/
   static function getNewStatusArray() {
      return [self::INCOMING];
   }

   /**
    * Get the ITIL object assign or plan status list
    *
    * @since 0.83
    *
    * @return array
   **/
   static function getProcessStatusArray() {
      return [self::ASSIGNED, self::PLANNED];
   }


   /**
    * Calculate Ticket TCO for an item
    *
    *@param $item CommonDBTM object of the item
    *
    *@return float
   **/
   static function computeTco(CommonDBTM $item) {
      global $DB;

      $totalcost = 0;

      $iterator = $DB->request([
         'SELECT'    => 'glpi_ticketcosts.*',
         'FROM'      => 'glpi_ticketcosts',
         'LEFT JOIN' => [
            'glpi_items_tickets' => [
               'ON' => [
                  'glpi_items_tickets' => 'tickets_id',
                  'glpi_ticketcosts'   => 'tickets_id'
               ]
            ]
         ],
         'WHERE'     => [
            'glpi_items_tickets.itemtype' => get_class($item),
            'glpi_items_tickets.items_id' => $item->getField('id'),
            'OR'                          => [
               'glpi_ticketcosts.cost_time'     => ['>', 0],
               'glpi_ticketcosts.cost_fixed'    => ['>', 0],
               'glpi_ticketcosts.cost_material' => ['>', 0]
            ]
         ]
      ]);

      while ($data = $iterator->next()) {
         $totalcost += TicketCost::computeTotalCost(
            $data["actiontime"],
            $data["cost_time"],
            $data["cost_fixed"],
            $data["cost_material"]
         );
      }
      return $totalcost;
   }


   /**
    * Print the helpdesk form
    *
    * @param $ID              integer  ID of the user who want to display the Helpdesk
    * @param $ticket_template boolean  ticket template for preview : false if not used for preview
    *                                  (false by default)
    *
    * @return nothing (print the helpdesk)
   **/
   function showFormHelpdesk($ID, $ticket_template = false) {
      global $DB, $CFG_GLPI;

      if (!self::canCreate()) {
         return false;
      }

      if (!$ticket_template
          && Session::haveRightsOr('ticketvalidation', TicketValidation::getValidateRights())) {

         $opt                  = [];
         $opt['reset']         = 'reset';
         $opt['criteria'][0]['field']      = 55; // validation status
         $opt['criteria'][0]['searchtype'] = 'equals';
         $opt['criteria'][0]['value']      = CommonITILValidation::WAITING;
         $opt['criteria'][0]['link']       = 'AND';

         $opt['criteria'][1]['field']      = 59; // validation aprobator
         $opt['criteria'][1]['searchtype'] = 'equals';
         $opt['criteria'][1]['value']      = Session::getLoginUserID();
         $opt['criteria'][1]['link']       = 'AND';

         $url_validate = Ticket::getSearchURL()."?".Toolbox::append_params($opt,
                                                                                           '&amp;');

         if (TicketValidation::getNumberToValidate(Session::getLoginUserID()) > 0) {
            echo "<a href='$url_validate' title=\"".__s('Ticket waiting for your approval')."\"
                   alt=\"".__s('Ticket waiting for your approval')."\">".
                   __('Tickets awaiting approval')."</a><br><br>";
         }
      }

      $email  = UserEmail::getDefaultForUser($ID);
      $default_use_notif = Entity::getUsedConfig('is_notif_enable_default', $_SESSION['glpiactive_entity'], '', 1);

      // Set default values...
      $default_values = ['_users_id_requester_notif'
                                                    => ['use_notification'
                                                              => (($email == "")?0:$default_use_notif)],
                                                    'nodelegate'          => 1,
                                                    '_users_id_requester' => 0,
                                                    '_users_id_observer'  => [0],
                                                    '_users_id_observer_notif'
                                                    => ['use_notification' => $default_use_notif],
                                                    'name'                => '',
                                                    'content'             => '',
                                                    'itilcategories_id'   => 0,
                                                    'locations_id'        => 0,
                                                    'urgency'             => 3,
                                                    'items_id'            => 0,
                                                    'entities_id'         => $_SESSION['glpiactive_entity'],
                                                    'plan'                => [],
                                                    'global_validation'   => CommonITILValidation::NONE,
                                                    '_add_validation'     => 0,
                                                    'type'                => Entity::getUsedConfig('tickettype',
                                                                             $_SESSION['glpiactive_entity'],
                                                                             '', Ticket::INCIDENT_TYPE),
                              '_right'              => "id",
                              '_filename'           => [],
                              '_tag_filename'       => [],
                              '_tasktemplates_id'   => []];

      // Get default values from posted values on reload form
      if (!$ticket_template) {
         if (isset($_POST)) {
            $options = $_POST;
         }
      }

      if (isset($options['name'])) {
         $order           = ["\\'", '\\"', "\\\\"];
         $replace         = ["'", '"', "\\"];
         $options['name'] = str_replace($order, $replace, $options['name']);
      }

      // Restore saved value or override with page parameter
      $saved = $this->restoreInput();
      foreach ($default_values as $name => $value) {
         if (!isset($options[$name])) {
            if (isset($saved[$name])) {
               $options[$name] = $saved[$name];
            } else {
               $options[$name] = $value;
            }
         }
      }

      // Check category / type validity
      if ($options['itilcategories_id']) {
         $cat = new ITILCategory();
         if ($cat->getFromDB($options['itilcategories_id'])) {
            switch ($options['type']) {
               case self::INCIDENT_TYPE :
                  if (!$cat->getField('is_incident')) {
                     $options['itilcategories_id'] = 0;
                  }
                  break;

               case self::DEMAND_TYPE :
                  if (!$cat->getField('is_request')) {
                     $options['itilcategories_id'] = 0;
                  }
                  break;

               default :
                  break;
            }
         }
      }

      if (!$ticket_template) {
         echo "<form method='post' name='helpdeskform' action='".
               $CFG_GLPI["root_doc"]."/front/tracking.injector.php' enctype='multipart/form-data'>";
      }

      $delegating = User::getDelegateGroupsForUser($options['entities_id']);

      if (count($delegating) || $CFG_GLPI['use_check_pref']) {
         echo "<div class='center'><table class='tab_cadre_fixe'>";
      }

      if (count($delegating)) {
         echo "<tr><th colspan='2'>".__('This ticket concerns me')." ";

         $rand   = Dropdown::showYesNo("nodelegate", $options['nodelegate']);

         $params = ['nodelegate' => '__VALUE__',
                         'rand'       => $rand,
                         'right'      => "delegate",
                         '_users_id_requester'
                                      => $options['_users_id_requester'],
                         '_users_id_requester_notif'
                                      => $options['_users_id_requester_notif'],
                         'use_notification'
                                      => $options['_users_id_requester_notif']['use_notification'],
                         'entity_restrict'
                                      => $_SESSION["glpiactive_entity"]];

         Ajax::UpdateItemOnSelectEvent("dropdown_nodelegate".$rand, "show_result".$rand,
                                       $CFG_GLPI["root_doc"]."/ajax/dropdownDelegationUsers.php",
                                       $params);

         $class = 'right';
         if ($CFG_GLPI['use_check_pref'] && $options['nodelegate']) {
            echo "</th><th>".__('Check your personnal information');
            $class = 'center';
         }

         echo "</th></tr>";
         echo "<tr class='tab_bg_1'><td colspan='2' class='".$class."'>";
         echo "<div id='show_result$rand'>";

         $self = new self();
         if ($options["_users_id_requester"] == 0) {
            $options['_users_id_requester'] = Session::getLoginUserID();
         } else {
            $options['_right'] = "delegate";
         }
         $self->showActorAddFormOnCreate(CommonITILActor::REQUESTER, $options);
         echo "</div>";
         if ($CFG_GLPI['use_check_pref'] && $options['nodelegate']) {
            echo "</td><td class='center'>";
            User::showPersonalInformation(Session::getLoginUserID());
         }
         echo "</td></tr>";

         echo "</table></div>";
         echo "<input type='hidden' name='_users_id_recipient' value='".Session::getLoginUserID()."'>";

      } else {
         // User as requester
         $options['_users_id_requester'] = Session::getLoginUserID();

         if ($CFG_GLPI['use_check_pref']) {
            echo "<tr><th>".__('Check your personnal information')."</th></tr>";
            echo "<tr class='tab_bg_1'><td class='center'>";
            User::showPersonalInformation(Session::getLoginUserID());
            echo "</td></tr>";
            echo "</table></div>";
         }
      }

      echo "<input type='hidden' name='_from_helpdesk' value='1'>";
      echo "<input type='hidden' name='requesttypes_id' value='".RequestType::getDefault('helpdesk').
           "'>";

      // Load ticket template if available :
      $tt = $this->getTicketTemplateToUse($ticket_template, $options['type'],
                                          $options['itilcategories_id'],
                                          $_SESSION["glpiactive_entity"]);

      // Predefined fields from template : reset them
      if (isset($options['_predefined_fields'])) {
         $options['_predefined_fields']
                        = Toolbox::decodeArrayFromInput($options['_predefined_fields']);
      } else {
         $options['_predefined_fields'] = [];
      }

      // Store predefined fields to be able not to take into account on change template
      $predefined_fields = [];

      if (isset($tt->predefined) && count($tt->predefined)) {
         foreach ($tt->predefined as $predeffield => $predefvalue) {
            if (isset($options[$predeffield]) && isset($default_values[$predeffield])) {
               // Is always default value : not set
               // Set if already predefined field
               // Set if ticket template change
               if (((count($options['_predefined_fields']) == 0)
                    && ($options[$predeffield] == $default_values[$predeffield]))
                   || (isset($options['_predefined_fields'][$predeffield])
                       && ($options[$predeffield] == $options['_predefined_fields'][$predeffield]))
                   || (isset($options['_tickettemplates_id'])
                       && ($options['_tickettemplates_id'] != $tt->getID()))) {
                  $options[$predeffield]            = $predefvalue;
                  $predefined_fields[$predeffield] = $predefvalue;
               }
            } else { // Not defined options set as hidden field
               echo "<input type='hidden' name='$predeffield' value='$predefvalue'>";
            }
         }
         // All predefined override : add option to say predifined exists
         if (count($predefined_fields) == 0) {
            $predefined_fields['_all_predefined_override'] = 1;
         }
      } else { // No template load : reset predefined values
         if (count($options['_predefined_fields'])) {
            foreach ($options['_predefined_fields'] as $predeffield => $predefvalue) {
               if ($options[$predeffield] == $predefvalue) {
                  $options[$predeffield] = $default_values[$predeffield];
               }
            }
         }
      }

      if (isset($options['_tasktemplates_id'])) {
         foreach ($options['_tasktemplates_id'] as $tasktemplates_id) {
            echo "<input type='hidden' name='_tasktemplates_id[]' value='$tasktemplates_id'>";
         }
      }

      if (($CFG_GLPI['urgency_mask'] == (1<<3))
          || $tt->isHiddenField('urgency')) {
         // Dont show dropdown if only 1 value enabled or field is hidden
         echo "<input type='hidden' name='urgency' value='".$options['urgency']."'>";
      }

      // Display predefined fields if hidden
      if ($tt->isHiddenField('items_id')) {
         if (!empty($options['items_id'])) {
            foreach ($options['items_id'] as $itemtype => $items) {
               foreach ($items as $items_id) {
                  echo "<input type='hidden' name='items_id[$itemtype][$items_id]' value='$items_id'>";
               }
            }
         }
      }
      if ($tt->isHiddenField('locations_id')) {
         echo "<input type='hidden' name='locations_id' value='".$options['locations_id']."'>";
      }
      echo "<input type='hidden' name='entities_id' value='".$_SESSION["glpiactive_entity"]."'>";
      echo "<div class='center'><table class='tab_cadre_fixe'>";

      Plugin::doHook("pre_item_form", ['item' => $this, 'options' => &$options]);

      echo "<tr><th>".__('Describe the incident or request')."</th><th>";
      if (Session::isMultiEntitiesMode()) {
         echo "(".Dropdown::getDropdownName("glpi_entities", $_SESSION["glpiactive_entity"]).")";
      }
      echo "</th></tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".sprintf(__('%1$s%2$s'), __('Type'), $tt->getMandatoryMark('type'))."</td>";
      echo "<td>";
      self::dropdownType('type', ['value'     => $options['type'],
                                  'on_change' => 'this.form.submit()']);
      echo "</td></tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".sprintf(__('%1$s%2$s'), __('Category'),
                          $tt->getMandatoryMark('itilcategories_id'))."</td>";
      echo "<td>";

      $condition = ['is_helpdeskvisible' => 1];
      switch ($options['type']) {
         case self::DEMAND_TYPE :
            $condition['is_request'] = 1;
            break;
         default: // self::INCIDENT_TYPE :
            $condition['is_incident'] = 1;
      }
      $opt = ['value'     => $options['itilcategories_id'],
              'condition' => $condition,
              'entity'    => $_SESSION["glpiactive_entity"],
              'on_change' => 'this.form.submit()'];

      if ($options['itilcategories_id'] && $tt->isMandatoryField("itilcategories_id")) {
         $opt['display_emptychoice'] = false;
      }

      ITILCategory::dropdown($opt);
      echo "</td></tr>";

      if ($CFG_GLPI['urgency_mask'] != (1<<3)) {
         if (!$tt->isHiddenField('urgency')) {
            echo "<tr class='tab_bg_1'>";
            echo "<td>".sprintf(__('%1$s%2$s'), __('Urgency'), $tt->getMandatoryMark('urgency')).
                 "</td>";
            echo "<td>";
            self::dropdownUrgency(['value' => $options["urgency"]]);
            echo "</td></tr>";
         }
      }

      if (empty($delegating)
          && NotificationTargetTicket::isAuthorMailingActivatedForHelpdesk()) {
         echo "<tr class='tab_bg_1'>";
         echo "<td>".__('Inform me about the actions taken')."</td>";
         echo "<td>";
         if ($options["_users_id_requester"] == 0) {
            $options['_users_id_requester'] = Session::getLoginUserID();
         }
         $_POST['value']            = $options['_users_id_requester'];
         $_POST['field']            = '_users_id_requester_notif';
         $_POST['use_notification'] = $options['_users_id_requester_notif']['use_notification'];
         include (GLPI_ROOT."/ajax/uemailUpdate.php");

         echo "</td></tr>";
      }
      if (($_SESSION["glpiactiveprofile"]["helpdesk_hardware"] != 0)
          && (count($_SESSION["glpiactiveprofile"]["helpdesk_item_type"]))) {
         if (!$tt->isHiddenField('items_id')) {
            echo "<tr class='tab_bg_1'>";
            echo "<td>".sprintf(__('%1$s%2$s'), _n('Associated element', 'Associated elements', Session::getPluralNumber()),
                                $tt->getMandatoryMark('items_id'))."</td>";
            echo "<td>";
            $options['_canupdate'] = Session::haveRight('ticket', CREATE);
            Item_Ticket::itemAddForm($this, $options);
            echo "</td></tr>";
         }
      }

      if (!$tt->isHiddenField('locations_id')) {
         echo "<tr class='tab_bg_1'><td>";
         printf(__('%1$s%2$s'), __('Location'), $tt->getMandatoryMark('locations_id'));
         echo "</td><td>";
         Location::dropdown(['value'  => $options["locations_id"]]);
         echo "</td></tr>";
      }

      if (!$tt->isHiddenField('_users_id_observer')
          || $tt->isPredefinedField('_users_id_observer')) {
         echo "<tr class='tab_bg_1'>";
         echo "<td>".sprintf(__('%1$s%2$s'), _n('Watcher', 'Watchers', 2),
                             $tt->getMandatoryMark('_users_id_observer'))."</td>";
         echo "<td>";
         $options['_right'] = "all";

         if (!$tt->isHiddenField('_users_id_observer')) {
            // Observer

            if ($tt->isPredefinedField('_users_id_observer')
               && !is_array($options['_users_id_observer'])) {

               //convert predefined value to array
               $options['_users_id_observer'] = [$options['_users_id_observer']];
               $options['_users_id_observer_notif']['use_notification'] =
                  [$options['_users_id_observer_notif']['use_notification']];

               // add new line to permit adding more observers
               $options['_users_id_observer'][1] = 0;
               $options['_users_id_observer_notif']['use_notification'][1] = 1;
            }

            echo "<div class='actor_single first-actor'>";
            if (isset($options['_users_id_observer'])) {
               $observers = $options['_users_id_observer'];
               foreach ($observers as $index_observer => $observer) {
                  $options = array_merge($options, ['_user_index' => $index_observer]);
                  self::showFormHelpdeskObserver($options);
               }
            }
            echo "</div>";

         } else { // predefined value
            if (isset($options["_users_id_observer"]) && $options["_users_id_observer"]) {
               echo self::getActorIcon('user', CommonITILActor::OBSERVER)."&nbsp;";
               echo Dropdown::getDropdownName("glpi_users", $options["_users_id_observer"]);
               echo "<input type='hidden' name='_users_id_observer' value=\"".
                      $options["_users_id_observer"]."\">";
            }
         }
         echo "</td></tr>";
      }

      if (!$tt->isHiddenField('name')
          || $tt->isPredefinedField('name')) {
         echo "<tr class='tab_bg_1'>";
         echo "<td>".sprintf(__('%1$s%2$s'), __('Title'), $tt->getMandatoryMark('name'))."<td>";
         if (!$tt->isHiddenField('name')) {
            $opt = [
                'value'     => $options['name'],
                'maxlength' => 250,
                'size'      => 80,
            ];

            if ($tt->isMandatoryField('name')) {
                $opt['required'] = 'required';
            }
            echo Html::input('name', $opt);
         } else {
            echo $options['name'];
            echo "<input type='hidden' name='name' value=\"".$options['name']."\">";
         }
         echo "</td></tr>";
      }

      if (!$tt->isHiddenField('content')
          || $tt->isPredefinedField('content')) {
         echo "<tr class='tab_bg_1'>";
         echo "<td>".sprintf(__('%1$s%2$s'), __('Description'), $tt->getMandatoryMark('content'));

         $rand       = mt_rand();
         $rand_text  = mt_rand();

         $cols       = 100;
         $rows       = 10;
         $content_id = "content$rand";
         echo "<td class='center middle'>";

         $content = $options['content'];
         if (!$ticket_template) {
            $content = Html::cleanPostForTextArea($options['content']);
         }
         $content = Html::setRichTextContent($content_id, $content, $rand);

         echo "<div id='content$rand_text'>";
         echo "<textarea id='$content_id' name='content' cols='$cols' rows='$rows'
               " . ($tt->isMandatoryField('content') ? " required='required'" : '') .">".
                $content."</textarea></div>";

         if (!$tt->isHiddenField('_documents_id')) {
            Html::file(['editor_id' => $content_id,
                             'showtitle' => false,
                             'multiple' => true]);
         }

         echo "</td></tr>";
      }
      Plugin::doHook("post_item_form", ['item' => $this, 'options' => &$options]);

      if (!$ticket_template) {
         echo "<tr class='tab_bg_1'>";
         echo "<td colspan='2' class='center'>";

         if ($tt->isField('id') && ($tt->fields['id'] > 0)) {
            echo "<input type='hidden' name='_tickettemplates_id' value='".$tt->fields['id']."'>";
            echo "<input type='hidden' name='_predefined_fields'
                   value=\"".Toolbox::prepareArrayForInput($predefined_fields)."\">";
         }
         echo "<input type='submit' name='add' value=\"".__s('Submit message')."\" class='submit'>";
         echo "</td></tr>";
      }

      echo "</table></div>";
      if (!$ticket_template) {
         Html::closeForm();
      }
   }

   /**
    * Display a single oberver selector
    *
    *  * @param $options array options for default values ($options of showActorAddFormOnCreate)
   **/
   static function showFormHelpdeskObserver($options = []) {
      global $CFG_GLPI;

      //default values
      $ticket = new Ticket();
      $params['_users_id_observer_notif']['use_notification'] = true;
      $params['_users_id_observer']                           = 0;
      $params['entities_id']                                  = $_SESSION["glpiactive_entity"];
      $params['_right']                                       = "all";

      // overide default value by function parameters
      if (is_array($options) && count($options)) {
         foreach ($options as $key => $val) {
            $params[$key] = $val;
         }
      }

      // add a user selector
      $rand_observer = $ticket->showActorAddFormOnCreate(CommonITILActor::OBSERVER, $params);

      // add an additionnal observer on user selection
      Ajax::updateItemOnSelectEvent("dropdown__users_id_observer[]$rand_observer",
                                    "observer_$rand_observer",
                                    $CFG_GLPI["root_doc"]."/ajax/helpdesk_observer.php",
                                    $params);

      //remove 'new observer' anchor on user selection
      echo Html::scriptBlock("
      $('#dropdown__users_id_observer__$rand_observer').on('change', function(event) {
         $('#addObserver$rand_observer').remove();
      });");

      // add "new observer" anchor
      echo "<a id='addObserver$rand_observer' class='add-observer' onclick='this.remove()'>";
      echo Html::image($CFG_GLPI['root_doc']."/pics/meta_plus.png", ['alt' => __('Add')]);
      echo "</a>";

      // add an additionnal observer on anchor click
      Ajax::updateItemOnEvent("addObserver$rand_observer",
                              "observer_$rand_observer",
                              $CFG_GLPI["root_doc"]."/ajax/helpdesk_observer.php",
                              $params, ['click']);

      // div for an additionnal observer
      echo "<div class='actor_single' id='observer_$rand_observer'></div>";

   }


   /**
    * @since 0.83
    *
    * @param $entity  integer  entities_id usefull is function called by cron (default 0)
   **/
   static function getDefaultValues($entity = 0) {
      global $CFG_GLPI;

      if (is_numeric(Session::getLoginUserID(false))) {
         $users_id_requester = Session::getLoginUserID();
         $users_id_assign    = Session::getLoginUserID();
         // No default requester if own ticket right = tech and update_ticket right to update requester
         if (Session::haveRightsOr(self::$rightname, [UPDATE, self::OWN]) && !$_SESSION['glpiset_default_requester']) {
            $users_id_requester = 0;
         }
         if (!Session::haveRight(self::$rightname, self::OWN) || !$_SESSION['glpiset_default_tech']) {
            $users_id_assign = 0;
         }
         $entity      = $_SESSION['glpiactive_entity'];
         $requesttype = $_SESSION['glpidefault_requesttypes_id'];
      } else {
         $users_id_requester = 0;
         $users_id_assign    = 0;
         $requesttype        = $CFG_GLPI['default_requesttypes_id'];
      }

      $type = Entity::getUsedConfig('tickettype', $entity, '', Ticket::INCIDENT_TYPE);

      $default_use_notif = Entity::getUsedConfig('is_notif_enable_default', $entity, '', 1);

      // Set default values...
      return  ['_users_id_requester'       => $users_id_requester,
               '_users_id_requester_notif' => ['use_notification'  => [$default_use_notif],
                                               'alternative_email' => ['']],
               '_groups_id_requester'      => 0,
               '_users_id_assign'          =>  $users_id_assign,
               '_users_id_assign_notif'    => ['use_notification'  => [$default_use_notif],
                                               'alternative_email' => ['']],
               '_groups_id_assign'         => 0,
               '_users_id_observer'        => 0,
               '_users_id_observer_notif'  => ['use_notification'  => [$default_use_notif],
                                               'alternative_email' => ['']],
               '_groups_id_observer'       => 0,
               '_link'                     => ['tickets_id_2' => '',
                                               'link'         => ''],
               '_suppliers_id_assign'      => 0,
               '_suppliers_id_assign_notif' => ['use_notification'  => [$default_use_notif],
                                                'alternative_email' => ['']],
               'name'                      => '',
               'content'                   => '',
               'itilcategories_id'         => 0,
               'urgency'                   => 3,
               'impact'                    => 3,
               'priority'                  => self::computePriority(3, 3),
               'requesttypes_id'           => $requesttype,
               'actiontime'                => 0,
               'date'                      => null,
               'entities_id'               => $entity,
               'status'                    => self::INCOMING,
               'followup'                  => [],
               'itemtype'                  => '',
               'items_id'                  => 0,
               'locations_id'              => 0,
               'plan'                      => [],
               'global_validation'         => CommonITILValidation::NONE,
               'time_to_resolve'           => 'NULL',
               'time_to_own'               => 'NULL',
               'slas_id_tto'               => 0,
               'slas_id_ttr'               => 0,
               'internal_time_to_resolve'  => 'NULL',
               'internal_time_to_own'      => 'NULL',
               'olas_id_tto'               => 0,
               'olas_id_ttr'               => 0,
               '_add_validation'           => 0,
               'users_id_validate'         => [],
               'type'                      => $type,
               '_documents_id'             => [],
               '_tasktemplates_id'         => [],
               '_filename'                 => [],
               '_tag_filename'             => []];
   }


   /**
    * Get ticket template to use
    * Use force_template first, then try on template define for type and category
    * then use default template of active profile of connected user and then use default entity one
    *
    * @param $force_template      integer tickettemplate_id to used (case of preview for example)
    *                             (default 0)
    * @param $type                integer type of the ticket (default 0)
    * @param $itilcategories_id   integer ticket category (default 0)
    * @param $entities_id         integer (default -1)
    *
    * @since 0.84
    *
    * @return ticket template object
   **/
   function getTicketTemplateToUse($force_template = 0, $type = 0, $itilcategories_id = 0,
                                   $entities_id = -1) {

      // Load ticket template if available :
      $tt              = new TicketTemplate();
      $template_loaded = false;

      if ($force_template) {
         // with type and categ
         if ($tt->getFromDBWithDatas($force_template, true)) {
            $template_loaded = true;
         }
      }

      if (!$template_loaded
          && $type
          && $itilcategories_id) {

         $categ = new ITILCategory();
         if ($categ->getFromDB($itilcategories_id)) {
            $field = '';
            switch ($type) {
               case self::INCIDENT_TYPE :
                  $field = 'tickettemplates_id_incident';
                  break;

               case self::DEMAND_TYPE :
                  $field = 'tickettemplates_id_demand';
                  break;
            }

            if (!empty($field) && $categ->fields[$field]) {
               // without type and categ
               if ($tt->getFromDBWithDatas($categ->fields[$field], false)) {
                  $template_loaded = true;
               }
            }
         }
      }

      // If template loaded from type and category do not check after
      if ($template_loaded) {
         return $tt;
      }

      if (!$template_loaded) {
         // load default profile one if not already loaded
         if (isset($_SESSION['glpiactiveprofile']['tickettemplates_id'])
             && $_SESSION['glpiactiveprofile']['tickettemplates_id']) {
            // with type and categ
            if ($tt->getFromDBWithDatas($_SESSION['glpiactiveprofile']['tickettemplates_id'],
                                        true)) {
               $template_loaded = true;
            }
         }
      }

      if (!$template_loaded
          && ($entities_id >= 0)) {

         // load default entity one if not already loaded
         if ($template_id = Entity::getUsedConfig('tickettemplates_id', $entities_id)) {
            // with type and categ
            if ($tt->getFromDBWithDatas($template_id, true)) {
               $template_loaded = true;
            }
         }
      }

      // Check if profile / entity set type and category and try to load template for these values
      if ($template_loaded) { // template loaded for profile or entity
         $newtype              = $type;
         $newitilcategories_id = $itilcategories_id;
         // Get predefined values for ticket template
         if (isset($tt->predefined['itilcategories_id']) && $tt->predefined['itilcategories_id']) {
            $newitilcategories_id = $tt->predefined['itilcategories_id'];
         }
         if (isset($tt->predefined['type']) && $tt->predefined['type']) {
            $newtype = $tt->predefined['type'];
         }
         if ($newtype
             && $newitilcategories_id) {

            $categ = new ITILCategory();
            if ($categ->getFromDB($newitilcategories_id)) {
               $field = '';
               switch ($newtype) {
                  case self::INCIDENT_TYPE :
                     $field = 'tickettemplates_id_incident';
                     break;

                  case self::DEMAND_TYPE :
                     $field = 'tickettemplates_id_demand';
                     break;
               }

               if (!empty($field) && $categ->fields[$field]) {
                  // without type and categ
                  if ($tt->getFromDBWithDatas($categ->fields[$field], false)) {
                     $template_loaded = true;
                  }
               }
            }
         }
      }
      return $tt;
   }


   function showForm($ID, $options = []) {
      global $CFG_GLPI;

      if (isset($options['_add_fromitem']) && isset($options['itemtype'])) {
         $item = new $options['itemtype'];
         $item->getFromDB($options['items_id'][$options['itemtype']][0]);
         $options['entities_id'] = $item->fields['entities_id'];
      }

      $default_values = self::getDefaultValues();

      // Restore saved value or override with page parameter
      $saved = $this->restoreInput();

      foreach ($default_values as $name => $value) {
         if (!isset($options[$name])) {
            if (isset($saved[$name])) {
               $options[$name] = $saved[$name];
            } else {
               $options[$name] = $value;
            }
         }
      }

      if (isset($options['content'])) {
         // Clean new lines to be fix encoding
         $order              = ['\\r', '\\n', "\\'", '\\"', "\\\\"];
         $replace            = ["", "", "'", '"', "\\"];
         $options['content'] = str_replace($order, $replace, $options['content']);
      }
      if (isset($options['name'])) {
         $order           = ["\\'", '\\"', "\\\\"];
         $replace         = ["'", '"', "\\"];
         $options['name'] = str_replace($order, $replace, $options['name']);
      }

      if (!isset($options['_skip_promoted_fields'])) {
         $options['_skip_promoted_fields'] = false;
      }

      if (!$ID) {
         // Override defaut values from projecttask if needed
         if (isset($options['_projecttasks_id'])) {
            $pt = new ProjectTask();
            if ($pt->getFromDB($options['_projecttasks_id'])) {
               $options['name'] = $pt->getField('name');
               $options['content'] = $pt->getField('name');
            }
         }
         // Override defaut values from followup if needed
         if (isset($options['_promoted_fup_id']) && !$options['_skip_promoted_fields']) {
            $fup = new ITILFollowup();
            if ($fup->getFromDB($options['_promoted_fup_id'])) {
               $options['content'] = $fup->getField('content');
               $options['_users_id_requester'] = $fup->fields['users_id'];
               $options['_link'] = [
                  'link'         => Ticket_Ticket::SON_OF,
                  'tickets_id_2' => $fup->fields['items_id']
               ];
            }
            //Allow overriding the default values
            $options['_skip_promoted_fields'] = true;
         }
      }

      // Check category / type validity
      if ($options['itilcategories_id']) {
         $cat = new ITILCategory();
         if ($cat->getFromDB($options['itilcategories_id'])) {
            switch ($options['type']) {
               case self::INCIDENT_TYPE :
                  if (!$cat->getField('is_incident')) {
                     $options['itilcategories_id'] = 0;
                  }
                  break;

               case self::DEMAND_TYPE :
                  if (!$cat->getField('is_request')) {
                     $options['itilcategories_id'] = 0;
                  }
                  break;

               default :
                  break;
            }
         }
      }

      // Default check
      if ($ID > 0) {
         $this->check($ID, READ);
      } else {
         // Create item
         $this->check(-1, CREATE, $options);
      }

      if (!$ID) {
         $this->userentities = [];
         if ($options["_users_id_requester"]) {
            //Get all the user's entities
            $requester_entities = Profile_User::getUserEntities($options["_users_id_requester"], true,
                                                          true);
            $user_entities = $_SESSION['glpiactiveentities'];
            $this->userentities = array_intersect($requester_entities, $user_entities);
         }
         $this->countentitiesforuser = count($this->userentities);

         if (($this->countentitiesforuser > 0)
             && !in_array($this->fields["entities_id"], $this->userentities)) {
            // If entity is not in the list of user's entities,
            // then use as default value the first value of the user's entites list
            $this->fields["entities_id"] = $this->userentities[0];
            // Pass to values
            $options['entities_id']       = $this->userentities[0];
         }
      }

      if ($options['type'] <= 0) {
         $options['type'] = Entity::getUsedConfig('tickettype', $options['entities_id'], '',
                                                 Ticket::INCIDENT_TYPE);
      }

      if (!isset($options['template_preview'])) {
         $options['template_preview'] = 0;
      }

      if (!isset($options['_promoted_fup_id'])) {
         $options['_promoted_fup_id'] = 0;
      }

      // Load ticket template if available :
      if ($ID) {
         $tt = $this->getTicketTemplateToUse($options['template_preview'], $this->fields['type'],
                                             $this->fields['itilcategories_id'], $this->fields['entities_id']);
      } else {
         $tt = $this->getTicketTemplateToUse($options['template_preview'], $options['type'],
                                             $options['itilcategories_id'], $options['entities_id']);
      }

      // Predefined fields from template : reset them
      if (isset($options['_predefined_fields'])) {
         $options['_predefined_fields']
                        = Toolbox::decodeArrayFromInput($options['_predefined_fields']);
      } else {
         $options['_predefined_fields'] = [];
      }

      // Store predefined fields to be able not to take into account on change template
      // Only manage predefined values on ticket creation
      $predefined_fields = [];
      if (!$ID) {

         if (isset($tt->predefined) && count($tt->predefined)) {
            foreach ($tt->predefined as $predeffield => $predefvalue) {
               if (isset($default_values[$predeffield])) {
                  // Is always default value : not set
                  // Set if already predefined field
                  // Set if ticket template change
                  if (((count($options['_predefined_fields']) == 0)
                       && ($options[$predeffield] == $default_values[$predeffield]))
                      || (isset($options['_predefined_fields'][$predeffield])
                          && ($options[$predeffield] == $options['_predefined_fields'][$predeffield]))
                      || (isset($options['_tickettemplates_id'])
                          && ($options['_tickettemplates_id'] != $tt->getID()))
                      // user pref for requestype can't overwrite requestype from template
                      // when change category
                      || (($predeffield == 'requesttypes_id')
                          && empty($saved))) {

                     // Load template data
                     $options[$predeffield]            = $predefvalue;
                     $this->fields[$predeffield]      = $predefvalue;
                     $predefined_fields[$predeffield] = $predefvalue;
                  }
               }
            }
            // All predefined override : add option to say predifined exists
            if (count($predefined_fields) == 0) {
               $predefined_fields['_all_predefined_override'] = 1;
            }

         } else { // No template load : reset predefined values
            if (count($options['_predefined_fields'])) {
               foreach ($options['_predefined_fields'] as $predeffield => $predefvalue) {
                  if ($options[$predeffield] == $predefvalue) {
                     $options[$predeffield] = $default_values[$predeffield];
                  }
               }
            }
         }
      }
      // Put ticket template on $options for actors
      $options['_tickettemplate'] = $tt;

      // check right used for this ticket
      $canupdate     = !$ID
                        || (Session::getCurrentInterface() == "central"
                            && $this->canUpdateItem());
      $can_requester = $this->canRequesterUpdateItem();
      $canpriority   = Session::haveRight(self::$rightname, self::CHANGEPRIORITY);
      $canassign     = $this->canAssign();
      $canassigntome = $this->canAssignTome();

      if ($ID && in_array($this->fields['status'], $this->getClosedStatusArray())) {
         $canupdate = false;
         // No update for actors
         $options['_noupdate'] = true;
      }

      $showuserlink              = 0;
      if (Session::haveRight('user', READ)) {
         $showuserlink = 1;
      }

      if ($options['template_preview']) {
         // Add all values to fields of tickets for template preview
         foreach ($options as $key => $val) {
            if (!isset($this->fields[$key])) {
               $this->fields[$key] = $val;
            }
         }
      }

      // In percent
      $colsize1 = '13';
      $colsize2 = '29';
      $colsize3 = '13';
      $colsize4 = '45';

      if (!$options['template_preview']) {
         echo "<form method='post' name='form_ticket' enctype='multipart/form-data' action='".
                Ticket::getFormURL()."'>";
         if (isset($options['_projecttasks_id'])) {
            echo "<input type='hidden' name='_projecttasks_id' value='".$options['_projecttasks_id']."'>";
         }
         if (isset($this->fields['_tasktemplates_id'])) {
            foreach ($this->fields['_tasktemplates_id'] as $tasktemplates_id) {
               echo "<input type='hidden' name='_tasktemplates_id[]' value='$tasktemplates_id'>";
            }
         }
      }
      echo "<div class='spaced' id='tabsbody'>";

      echo "<table class='tab_cadre_fixe' id='mainformtable'>";

      // Optional line
      $ismultientities = Session::isMultiEntitiesMode();
      echo "<tr class='headerRow responsive_hidden'>";
      echo "<th colspan='4'>";

      if ($ID) {
         $text = sprintf(__('%1$s - ID %2$d'), $this->getTypeName(1), $ID);
         if ($ismultientities) {
            $text = sprintf(__('%1$s (%2$s)'), $text,
                            Dropdown::getDropdownName('glpi_entities',
                                                      $this->fields['entities_id']));
         }
         echo $text;
      } else {
         if ($ismultientities) {
            printf(__('The ticket will be added in the entity %s'),
                   Dropdown::getDropdownName("glpi_entities", $this->fields['entities_id']));
         } else {
            echo __('New ticket');
         }
      }
      echo "</th></tr>";

      Plugin::doHook("pre_item_form", ['item' => $this, 'options' => &$options]);

      echo "<tr class='tab_bg_1'>";
      echo "<th width='$colsize1%'>";
      echo $tt->getBeginHiddenFieldText('date');
      if (!$ID) {
         printf(__('%1$s%2$s'), __('Opening date'), $tt->getMandatoryMark('date'));
      } else {
         echo __('Opening date');
      }
      echo $tt->getEndHiddenFieldText('date');
      echo "</th>";
      echo "<td width='$colsize2%'>";
      echo $tt->getBeginHiddenFieldValue('date');
      $date = $this->fields["date"];

      if ($canupdate) {
         Html::showDateTimeField("date", ['value'      => $date,
                                          'timestep'   => 1,
                                          'maybeempty' => false,
                                          'required'   => ($tt->isMandatoryField('date') && !$ID)]);
      } else {
         echo Html::convDateTime($date);
      }
      echo $tt->getEndHiddenFieldValue('date', $this);
      echo "</td>";

      if ($ID) {
         echo "<th width='$colsize1%'>".__('By')."</th>";
         echo "<td width='$colsize2%'>";
         if ($canupdate) {
            User::dropdown(['name'   => 'users_id_recipient',
                                 'value'  => $this->fields["users_id_recipient"],
                                 'entity' => $this->fields["entities_id"],
                                 'right'  => 'all']);
         } else {
            echo getUserName($this->fields["users_id_recipient"], $showuserlink);
         }

         echo "</td>";
      } else {
         echo "<th width='$colsize1%'></th>";
         echo "<td width='$colsize1%'></td>";
      }
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      if ($ID) {
         echo "<th width='$colsize3%'>".__('Last update')."</th>";
         echo "<td width='$colsize4%' colspan='3'>";
         if ($this->fields['users_id_lastupdater'] > 0) {
            //TRANS: %1$s is the update date, %2$s is the last updater name
            printf(__('%1$s by %2$s'), Html::convDateTime($this->fields["date_mod"]),
                   getUserName($this->fields["users_id_lastupdater"], $showuserlink));
         }
         echo "</td>";
      }
      echo "</tr>";

      // SLAs
      echo "<tr class='tab_bg_1'>";
      echo "<th width='$colsize1%'>".$tt->getBeginHiddenFieldText('time_to_own');
      if (!$ID) {
         printf(__('%1$s%2$s'), __('Time to own'), $tt->getMandatoryMark('time_to_own'));
      } else {
         echo __('Time to own');
      }
      echo $tt->getEndHiddenFieldText('time_to_own');
      echo "</th>";
      echo "<td width='$colsize2%' class='nopadding'>";
      $sla = new SLA();
      $sla->showForTicket($this, SLM::TTO, $tt, $canupdate);
      echo "</td>";
      echo "<th width='$colsize3%'>".$tt->getBeginHiddenFieldText('time_to_resolve');
      if (!$ID) {
         printf(__('%1$s%2$s'), __('Time to resolve'), $tt->getMandatoryMark('time_to_resolve'));
      } else {
         echo __('Time to resolve');
      }
      echo $tt->getEndHiddenFieldText('time_to_resolve');
      echo "</th>";
      echo "<td width='$colsize4%' class='nopadding'>";
      $sla->showForTicket($this, SLM::TTR, $tt, $canupdate);
      echo "</td>";
      echo "</tr>";

      // OLAs
      echo "<tr class='tab_bg_1'>";
      echo "<th width='$colsize1%'>".$tt->getBeginHiddenFieldText('internal_time_to_own');
      if (!$ID) {
         printf(__('%1$s%2$s'), __('Internal time to own'), $tt->getMandatoryMark('internal_time_to_own'));
      } else {
         echo __('Internal time to own');
      }
      echo $tt->getEndHiddenFieldText('internal_time_to_own');
      echo "</th>";
      echo "<td width='$colsize2%' class='nopadding'>";
      $ola = new OLA();
      $ola->showForTicket($this, SLM::TTO, $tt, $canupdate);
      echo "</td>";
      echo "<th width='$colsize3%'>".$tt->getBeginHiddenFieldText('internal_time_to_resolve');
      if (!$ID) {
         printf(__('%1$s%2$s'), __('Internal time to resolve'), $tt->getMandatoryMark('internal_time_to_resolve'));
      } else {
         echo __('Internal time to resolve');
      }
      echo $tt->getEndHiddenFieldText('internal_time_to_resolve');
      echo "</th>";
      echo "<td width='$colsize4%' class='nopadding'>";
      $ola->showForTicket($this, SLM::TTR, $tt, $canupdate);
      echo "</td>";
      echo "</tr>";

      if ($ID
          && (in_array($this->fields["status"], $this->getSolvedStatusArray())
              || in_array($this->fields["status"], $this->getClosedStatusArray()))) {

         echo "<tr class='tab_bg_1'>";
         echo "<th width='$colsize1%'>".__('Resolution date')."</th>";
         echo "<td width='$colsize2%'>";
         Html::showDateTimeField("solvedate", ['value'      => $this->fields["solvedate"],
                                                    'timestep'   => 1,
                                                    'maybeempty' => false,
                                                    'canedit'    => $canupdate]);
         echo "</td>";
         if (in_array($this->fields["status"], $this->getClosedStatusArray())) {
            echo "<th width='$colsize3%'>".__('Close date')."</th>";
            echo "<td width='$colsize4%'>";
            Html::showDateTimeField("closedate", ['value'      => $this->fields["closedate"],
                                                       'timestep'   => 1,
                                                       'maybeempty' => false,
                                                       'canedit'    => $canupdate]);
            echo "</td>";
         } else {
            echo "<td colspan='2'>&nbsp;</td>";
         }
         echo "</tr>";
      }

      if ($ID) {
         echo "</table>";
         echo "<table  class='tab_cadre_fixe' id='mainformtable2'>";
      }

      echo "<tr class='tab_bg_1'>";
      echo "<th width='$colsize1%'>".sprintf(__('%1$s%2$s'), __('Type'),
                                             $tt->getMandatoryMark('type'))."</th>";
      echo "<td width='$colsize2%'>";
      // Permit to set type when creating ticket without update right
      if ($canupdate) {
         $opt = ['value' => $this->fields["type"]];
         /// Auto submit to load template
         if (!$ID) {
            $opt['on_change'] = 'this.form.submit()';
         }
         $rand = self::dropdownType('type', $opt);
         if ($ID) {
            $params = ['type'            => '__VALUE__',
                            'entity_restrict' => $this->fields['entities_id'],
                            'value'           => $this->fields['itilcategories_id'],
                            'currenttype'     => $this->fields['type']];

            Ajax::updateItemOnSelectEvent("dropdown_type$rand", "show_category_by_type",
                                          $CFG_GLPI["root_doc"]."/ajax/dropdownTicketCategories.php",
                                          $params);
         }
      } else {
         echo self::getTicketTypeName($this->fields["type"]);
      }
      echo "</td>";
      echo "<th width='$colsize3%'>".sprintf(__('%1$s%2$s'), __('Category'),
                                             $tt->getMandatoryMark('itilcategories_id'))."</th>";
      echo "<td width='$colsize4%'>";
      // Permit to set category when creating ticket without update right
      if ($canupdate || $can_requester) {
         $conditions = [];

         $opt = ['value'  => $this->fields["itilcategories_id"],
                      'entity' => $this->fields["entities_id"]];
         if (Session::getCurrentInterface() == "helpdesk") {
            $conditions['is_helpdeskvisible'] = 1;
         }
         /// Auto submit to load template
         if (!$ID) {
            $opt['on_change'] = 'this.form.submit()';
         }
         /// if category mandatory, no empty choice
         /// no empty choice is default value set on ticket creation, else yes
         if (($ID || $options['itilcategories_id'])
             && $tt->isMandatoryField("itilcategories_id")
             && ($this->fields["itilcategories_id"] > 0)) {
            $opt['display_emptychoice'] = false;
         }

         switch ($this->fields["type"]) {
            case self::INCIDENT_TYPE :
               $conditions['is_incident'] = 1;
               break;

            case self::DEMAND_TYPE :
               $conditions['is_request'] = 1;
               break;

            default :
               break;
         }
         echo "<span id='show_category_by_type'>";
         $opt['condition'] = $conditions;
         ITILCategory::dropdown($opt);
         echo "</span>";
      } else {
         echo Dropdown::getDropdownName("glpi_itilcategories", $this->fields["itilcategories_id"]);
      }
      echo "</td>";
      echo "</tr>";

      if (!$ID) {
         echo "</table>";
         $this->showActorsPartForm($ID, $options);
         echo "<table class='tab_cadre_fixe' id='mainformtable3'>";
      }

      echo "<tr class='tab_bg_1'>";
      echo "<th width='$colsize1%'>".$tt->getBeginHiddenFieldText('status');
      printf(__('%1$s%2$s'), __('Status'), $tt->getMandatoryMark('status'));
      echo $tt->getEndHiddenFieldText('status')."</th>";
      echo "<td width='$colsize2%'>";
      echo $tt->getBeginHiddenFieldValue('status');
      if ($canupdate) {
         self::dropdownStatus(['value'     => $this->fields["status"],
                                    'showtype'  => 'allowed']);
         TicketValidation::alertValidation($this, 'status');
      } else {
         echo self::getStatus($this->fields["status"]);
         if ($this->canReopen()) {
            $link = $this->getLinkURL(). "&amp;_openfollowup=1&amp;forcetab=";
            $link .= "Ticket$1";
            echo "&nbsp;<a class='vsubmit' href='$link'>". __('Reopen')."</a>";
         }
      }
      echo $tt->getEndHiddenFieldValue('status', $this);

      echo "</td>";
      echo "<th width='$colsize3%'>".$tt->getBeginHiddenFieldText('requesttypes_id');
      printf(__('%1$s%2$s'), __('Request source'), $tt->getMandatoryMark('requesttypes_id'));
      echo $tt->getEndHiddenFieldText('requesttypes_id')."</th>";
      echo "<td width='$colsize4%'>";
      echo $tt->getBeginHiddenFieldValue('requesttypes_id');
      if ($canupdate) {
         RequestType::dropdown(['value' => $this->fields["requesttypes_id"], 'condition' => ['is_active' => 1, 'is_ticketheader' => 1]]);
      } else {
         echo Dropdown::getDropdownName('glpi_requesttypes', $this->fields["requesttypes_id"]);
         echo Html::hidden('requesttypes_id', ['value' => $this->fields["requesttypes_id"]]);
      }
      echo $tt->getEndHiddenFieldValue('requesttypes_id', $this);
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<th>".$tt->getBeginHiddenFieldText('urgency');
      printf(__('%1$s%2$s'), __('Urgency'), $tt->getMandatoryMark('urgency'));
      echo $tt->getEndHiddenFieldText('urgency')."</th>";
      echo "<td>";

      if ($canupdate || $can_requester) {
         echo $tt->getBeginHiddenFieldValue('urgency');
         $idurgency = self::dropdownUrgency(['value' => $this->fields["urgency"]]);
         echo $tt->getEndHiddenFieldValue('urgency', $this);

      } else {
         $idurgency = "value_urgency".mt_rand();
         echo "<input id='$idurgency' type='hidden' name='urgency' value='".
                $this->fields["urgency"]."'>";
         echo $tt->getBeginHiddenFieldValue('urgency');
         echo parent::getUrgencyName($this->fields["urgency"]);
         echo $tt->getEndHiddenFieldValue('urgency', $this);
      }
      echo "</td>";
      // Display validation state
      echo "<th>";
      if (!$ID) {
         echo $tt->getBeginHiddenFieldText('_add_validation');
         printf(__('%1$s%2$s'), __('Approval request'), $tt->getMandatoryMark('_add_validation'));
         echo $tt->getEndHiddenFieldText('_add_validation');
      } else {
         echo $tt->getBeginHiddenFieldText('global_validation');
         echo __('Approval');
         echo $tt->getEndHiddenFieldText('global_validation');
      }
      echo "</th>";
      echo "<td>";
      if (!$ID) {
         echo $tt->getBeginHiddenFieldValue('_add_validation');
         $validation_right = '';
         if (($options['type'] == self::INCIDENT_TYPE)
             && Session::haveRight('ticketvalidation', TicketValidation::CREATEINCIDENT)) {
            $validation_right = 'validate_incident';
         }
         if (($options['type'] == self::DEMAND_TYPE)
             && Session::haveRight('ticketvalidation', TicketValidation::CREATEREQUEST)) {
            $validation_right = 'validate_request';
         }

         if (!empty($validation_right)) {
            echo "<input type='hidden' name='_add_validation' value='".
                   $options['_add_validation']."'>";

            $params = ['name'               => "users_id_validate",
                            'entity'             => $this->fields['entities_id'],
                            'right'              => $validation_right,
                            'users_id_validate'  => $options['users_id_validate']];
            TicketValidation::dropdownValidator($params);
         }
         echo $tt->getEndHiddenFieldValue('_add_validation', $this);
         if ($tt->isPredefinedField('global_validation')) {
            echo "<input type='hidden' name='global_validation' value='".
                   $tt->predefined['global_validation']."'>";
         }
      } else {
         echo $tt->getBeginHiddenFieldValue('global_validation');

         if (Session::haveRightsOr('ticketvalidation', TicketValidation::getCreateRights())
             && $canupdate) {
            TicketValidation::dropdownStatus('global_validation',
                                             ['global' => true,
                                                   'value'  => $this->fields['global_validation']]);
         } else {
            echo TicketValidation::getStatus($this->fields['global_validation']);
         }
         echo $tt->getEndHiddenFieldValue('global_validation', $this);

      }
      echo "</td></tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<th>".$tt->getBeginHiddenFieldText('impact');
      printf(__('%1$s%2$s'), __('Impact'), $tt->getMandatoryMark('impact'));
      echo $tt->getEndHiddenFieldText('impact')."</th>";
      echo "<td>";
      echo $tt->getBeginHiddenFieldValue('impact');

      if ($canupdate) {
         $idimpact = self::dropdownImpact(['value' => $this->fields["impact"]]);
      } else {
         $idimpact = "value_impact".mt_rand();
         echo "<input id='$idimpact' type='hidden' name='impact' value='".$this->fields["impact"]."'>";
         echo parent::getImpactName($this->fields["impact"]);
      }
      echo $tt->getEndHiddenFieldValue('impact', $this);
      echo "</td>";

      echo "<th>".$tt->getBeginHiddenFieldText('locations_id');
      printf(__('%1$s%2$s'), __('Location'), $tt->getMandatoryMark('locations_id'));
      echo $tt->getEndHiddenFieldText('locations_id')."</th>";
      echo "<td>";
      echo $tt->getBeginHiddenFieldValue('locations_id');
      if ($canupdate) {
         Location::dropdown(['value'  => $this->fields['locations_id'],
                                  'entity' => $this->fields['entities_id']]);
      } else {
         echo Dropdown::getDropdownName('glpi_locations', $this->fields["locations_id"]);
      }
      echo $tt->getEndHiddenFieldValue('locations_id', $this);
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<th>".$tt->getBeginHiddenFieldText('priority');
      printf(__('%1$s%2$s'), __('Priority'), $tt->getMandatoryMark('priority'));
      echo $tt->getEndHiddenFieldText('priority')."</th>";
      echo "<td>";
      $idajax = 'change_priority_' . mt_rand();

      if ($canpriority
          && !$tt->isHiddenField('priority')) {
         $idpriority = parent::dropdownPriority(['value'     => $this->fields["priority"],
                                                      'withmajor' => true]);
         $idpriority = 'dropdown_priority'.$idpriority;
         echo "&nbsp;<span id='$idajax' style='display:none'></span>";

      } else {
         $idpriority = 0;
         echo $tt->getBeginHiddenFieldValue('priority');
         echo "<span id='$idajax'>".parent::getPriorityName($this->fields["priority"])."</span>";
         echo "<input id='$idajax' type='hidden' name='priority' value='".$this->fields["priority"]."'>";
         echo $tt->getEndHiddenFieldValue('priority', $this);
      }

      if ($canupdate || $can_requester) {
         $params = ['urgency'  => '__VALUE0__',
                    'impact'   => '__VALUE1__',
                    'priority' => $idpriority];
         Ajax::updateItemOnSelectEvent(['dropdown_urgency'.$idurgency,
                                             'dropdown_impact'.$idimpact],
                                       $idajax,
                                       $CFG_GLPI["root_doc"]."/ajax/priority.php", $params);
      }
      echo "</td>";

      if (!$ID) {
         echo "<th rowspan='2'>".$tt->getBeginHiddenFieldText('items_id');
         printf(__('%1$s%2$s'), _n('Associated element', 'Associated elements', Session::getPluralNumber()), $tt->getMandatoryMark('items_id'));
         echo $tt->getEndHiddenFieldText('items_id');
         echo "</th>";
         echo "<td rowspan='2'>";
         echo $tt->getBeginHiddenFieldValue('items_id');
         $options['_canupdate'] = Session::haveRight('ticket', CREATE);
         if ($options['_canupdate']) {
            Item_Ticket::itemAddForm($this, $options);
         }
         echo $tt->getEndHiddenFieldValue('items_id', $this);
         echo "</td>";
      } else {
         echo "<th></th>";
         echo "<td></td>";
      }
      echo "</tr>";

      if (!$ID
          && Session::haveRight('followup', ITILFollowup::ADDALLTICKET)) {

         echo "<tr class='tab_bg_1'>";
         // Need comment right to add a followup with the actiontime
         echo "<th>".$tt->getBeginHiddenFieldText('actiontime');
         printf(__('%1$s%2$s'), __('Total duration'), $tt->getMandatoryMark('actiontime'));
         echo $tt->getEndHiddenFieldText('actiontime')."</th>";
         echo "<td>";
         echo $tt->getBeginHiddenFieldValue('actiontime');
         Dropdown::showTimeStamp('actiontime', ['value' => $options['actiontime'],
                                                'addfirstminutes' => true]);
         echo $tt->getEndHiddenFieldValue('actiontime', $this);
         echo "</td>";
         echo "</tr>";
      }

      echo "</table>";
      if ($ID) {
         $this->showActorsPartForm($ID, $options);
      }

      echo "<table class='tab_cadre_fixe' id='mainformtable4'>";
      echo "<tr class='tab_bg_1'>";
      echo "<th style='width:$colsize1%'>".$tt->getBeginHiddenFieldText('name');
      printf(__('%1$s%2$s'), __('Title'), $tt->getMandatoryMark('name'));
      echo $tt->getEndHiddenFieldText('name')."</th>";
      echo "<td colspan='3'>";
      if ($canupdate || $can_requester) {
         echo $tt->getBeginHiddenFieldValue('name');
         echo "<input type='text' style='width:98%' maxlength=250 name='name' ".
                ($tt->isMandatoryField('name') ? " required='required'" : '') .
                " value=\"".Html::cleanInputText($this->fields["name"])."\">";
         echo $tt->getEndHiddenFieldValue('name', $this);
      } else {
         if (empty($this->fields["name"])) {
            echo __('Without title');
         } else {
            echo $this->fields["name"];
         }
      }
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<th style='width:$colsize1%'>".$tt->getBeginHiddenFieldText('content');
      printf(__('%1$s%2$s'), __('Description'), $tt->getMandatoryMark('content'));
      if ($canupdate || $can_requester) {
         $content = Toolbox::unclean_cross_side_scripting_deep(Html::entity_decode_deep($this->fields['content']));
         Html::showTooltip(nl2br(Html::Clean($content)));
      }
      echo $tt->getEndHiddenFieldText('content')."</th>";
      echo "<td colspan='3'>";

      echo $tt->getBeginHiddenFieldValue('content');
      $rand       = mt_rand();
      $rand_text  = mt_rand();
      $rows       = 10;
      $content_id = "content$rand";

      $content = $this->fields['content'];
      if (!isset($options['template_preview'])) {
         $content = Html::cleanPostForTextArea($content);
      }

      $content = Html::setRichTextContent(
         $content_id,
         $content,
         $rand,
         !$canupdate
      );

      echo "<div id='content$rand_text'>";
      if ($canupdate || $can_requester) {
         echo "<textarea id='$content_id' name='content' style='width:100%' rows='$rows'".
               ($tt->isMandatoryField('content') ? " required='required'" : '') . ">" .
               $content."</textarea></div>";
      } else {
         echo Toolbox::getHtmlToDisplay($content);
      }
      echo $tt->getEndHiddenFieldValue('content', $this);

      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<th style='width:$colsize1%'>". _n('Linked ticket', 'Linked tickets',
                                               Session::getPluralNumber());
      $rand_linked_ticket = mt_rand();
      if ($canupdate) {
         echo "<span class='fa fa-plus pointer' onClick=\"".Html::jsShow("linkedticket$rand_linked_ticket")."\"
                title=\"".__s('Add')."\"><span class='sr-only'>" . __s('Add') . "</span></span>";
      }
      echo '</th>';
      echo "<td colspan='3'>";
      if ($canupdate) {
         echo "<div style='display:none' id='linkedticket$rand_linked_ticket'>";
         echo "<table class='tab_format' width='100%'><tr><td width='30%'>";
         Ticket_Ticket::dropdownLinks('_link[link]',
                                      (isset($options["_link"])?$options["_link"]['link']:''));
         echo "<input type='hidden' name='_link[tickets_id_1]' value='$ID'>\n";
         echo "</td><td width='70%'>";
         $linkparam = ['name'        => '_link[tickets_id_2]',
                       'used'        => [$this->getID()],
                       'displaywith' => ['id']];

         if (isset($options["_link"])) {
            $linkparam['value'] = $options["_link"]['tickets_id_2'];
         }
         Ticket::dropdown($linkparam);
         echo "</td></tr></table>";
         echo "</div>";

         if (isset($options["_link"])
             && !empty($options["_link"]['tickets_id_2'])) {
            echo "<script language='javascript'>";
               echo "$(function() {";
            echo Html::jsShow("linkedticket$rand_linked_ticket");
            echo "});</script>";
         }
      }

      Ticket_Ticket::displayLinkedTicketsTo($ID);
      echo "</td>";
      echo "</tr>";

      if (!in_array($this->fields['status'], $this->getClosedStatusArray())) {
         // View files added
         echo "<tr class='tab_bg_1'>";
         // Permit to add doc when creating a ticket
         echo "<th style='width:$colsize1%'>";
         echo $tt->getBeginHiddenFieldText('_documents_id');
         $doctitle =  sprintf(__('File (%s)'), Document::getMaxUploadSize());
         printf(__('%1$s%2$s'), $doctitle, $tt->getMandatoryMark('_documents_id'));
         // Do not show if hidden.
         if (!$tt->isHiddenField('_documents_id')) {
            DocumentType::showAvailableTypesLink();
         }
         echo $tt->getEndHiddenFieldText('_documents_id');
         echo "</th>";
         echo "<td colspan='3'>";
         // Do not set values
         echo $tt->getEndHiddenFieldValue('_documents_id');
         if ($tt->isPredefinedField('_documents_id')) {
            if (isset($options['_documents_id'])
               && is_array($options['_documents_id'])
               && count($options['_documents_id'])) {

               echo "<span class='b'>".__('Default documents:').'</span>';
               echo "<br>";
               $doc = new Document();
               foreach ($options['_documents_id'] as $key => $val) {
                  if ($doc->getFromDB($val)) {
                     echo "<input type='hidden' name='_documents_id[$key]' value='$val'>";
                     echo "- ".$doc->getNameID()."<br>";
                  }
               }
            }
         }
         Html::file(['filecontainer' => 'fileupload_info_ticket',
                        'editor_id'     => $content_id,
                        'showtitle'     => false,
                        'multiple'     => true]);
         echo "</td>";
         echo "</tr>";
      }

      Plugin::doHook("post_item_form", ['item' => $this, 'options' => &$options]);

      echo "</table>";

      $display_save_btn = $canupdate
                       || $can_requester
                       || $canpriority
                       || $canassign
                       || $canassigntome;

      if ($display_save_btn
          && !$options['template_preview']) {
         if ($ID) {
            echo "<div class='center'>";
            if ($this->fields["is_deleted"] == 1) {
               if (self::canDelete()) {
                  echo "<input type='submit' class='submit' name='restore' value='".
                         _sx('button', 'Restore')."'>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
               }
            } else {
               if ($display_save_btn) {
                  echo "<input type='submit' class='submit' name='update' value='".
                         _sx('button', 'Save')."'>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
               }
            }
            if ($this->fields["is_deleted"] == 1) {
               if (self::canPurge()) {
                  echo "<input type='submit' class='submit' name='purge' value='".
                         _sx('button', 'Delete permanently')."' ".
                         Html::addConfirmationOnAction(__('Confirm the final deletion?')).">";
               }
            } else {
               if ($this->canDeleteItem()) {
                  echo "<input type='submit' class='submit small_space' name='delete' value='".
                         _sx('button', 'Put in trashbin')."'>";
               }
            }
            echo "<input type='hidden' name='_read_date_mod' value='".$this->getField('date_mod')."'>";
            echo "</div>";
         } else {
            echo "<div class='tab_bg_2 center'>";
            $add_params = ['name' => 'add'];
            if ($options['_promoted_fup_id']) {
               $add_params['confirm'] = __('Confirm the promotion?');
            }
            echo Html::submit(_x('button', 'Add'), $add_params);
            if ($tt->isField('id') && ($tt->fields['id'] > 0)) {
               echo "<input type='hidden' name='_tickettemplates_id' value='".$tt->fields['id']."'>";
               echo "<input type='hidden' name='_predefined_fields'
                      value=\"".Toolbox::prepareArrayForInput($predefined_fields)."\">";
            }
            echo Html::hidden('_promoted_fup_id', ['value' => $options['_promoted_fup_id']]);
            echo Html::hidden('_skip_promoted_fields', ['value' => $options['_skip_promoted_fields']]);
            echo '</div>';
         }
      }

      echo "</table>";
      echo "<input type='hidden' name='id' value='$ID'>";

      echo "</div>";

      if (!$options['template_preview']) {
         Html::closeForm();
      }

      return true;
   }


   /**
    * @param $size (default 25)
   **/
   static function showDocumentAddButton($size = 25) {
      global $CFG_GLPI;

      echo "<script type='text/javascript'>var nbfiles=1; var maxfiles = 5;</script>";
      echo "<span id='addfilebutton' class='fa fa-plus pointer' title=\"".__s('Add')."\"".
             "\" onClick=\"if (nbfiles<maxfiles){
                           var row = ".Html::jsGetElementbyID('uploadfiles').";
                           row.append('<br><input type=\'file\' name=\'filename[]\' size=\'$size\'>');
                           nbfiles++;
                           if (nbfiles==maxfiles) {
                              ".Html::jsHide('addfilebutton')."
                           }
                        }\"
              <span class='sr-only'>" . __s('Add') . "</span></span>";
   }


   /**
    * @param $start
    * @param $status             (default ''process)
    * @param $showgrouptickets   (true by default)
    */
   static function showCentralList($start, $status = "process", $showgrouptickets = true) {
      global $DB, $CFG_GLPI;

      if (!Session::haveRightsOr(self::$rightname, [CREATE, self::READALL, self::READASSIGN])
          && !Session::haveRightsOr('ticketvalidation', TicketValidation::getValidateRights())) {

         return false;
      }

      $search_users_id = " (`glpi_tickets_users`.`users_id` = '".Session::getLoginUserID()."'
                            AND `glpi_tickets_users`.`type` = '".CommonITILActor::REQUESTER."') ";
      $search_assign   = " (`glpi_tickets_users`.`users_id` = '".Session::getLoginUserID()."'
                            AND `glpi_tickets_users`.`type` = '".CommonITILActor::ASSIGN."')";
      $search_observer = " (`glpi_tickets_users`.`users_id` = '".Session::getLoginUserID()."'
                            AND `glpi_tickets_users`.`type` = '".CommonITILActor::OBSERVER."')";
      $is_deleted      = " `glpi_tickets`.`is_deleted` = 0 ";

      if ($showgrouptickets) {
         $search_users_id = " 0 = 1 ";
         $search_assign   = " 0 = 1 ";

         if (count($_SESSION['glpigroups'])) {
            $groups        = implode("','", $_SESSION['glpigroups']);
            $search_assign = " (`glpi_groups_tickets`.`groups_id` IN ('".$groups."')
                                AND `glpi_groups_tickets`.`type` = '".CommonITILActor::ASSIGN."')";

            if (Session::haveRight(self::$rightname, self::READGROUP)) {
               $search_users_id = " (`glpi_groups_tickets`.`groups_id` IN ('".$groups."')
                                     AND `glpi_groups_tickets`.`type`
                                           = '".CommonITILActor::REQUESTER."') ";
               $search_observer = " (`glpi_groups_tickets`.`groups_id` IN ('".$groups."')
                                     AND `glpi_groups_tickets`.`type`
                                           = '".CommonITILActor::OBSERVER."') ";
            }
         }
      }

      $query = "SELECT DISTINCT `glpi_tickets`.`id`
                FROM `glpi_tickets`
                LEFT JOIN `glpi_tickets_users`
                     ON (`glpi_tickets`.`id` = `glpi_tickets_users`.`tickets_id`)
                LEFT JOIN `glpi_groups_tickets`
                     ON (`glpi_tickets`.`id` = `glpi_groups_tickets`.`tickets_id`)";

      switch ($status) {
         case "waiting" : // on affiche les tickets en attente
            $query .= "WHERE $is_deleted
                             AND ($search_assign)
                             AND `glpi_tickets`.`status` = '".self::WAITING."' ".
                             getEntitiesRestrictRequest("AND", "glpi_tickets");
            break;

         case "process" : // on affiche les tickets planifi??s ou assign??s au user
            $query .= "WHERE $is_deleted
                             AND ( $search_assign )
                             AND (`glpi_tickets`.`status` IN ('".implode("','", self::getProcessStatusArray())."')) ".
                             getEntitiesRestrictRequest("AND", "glpi_tickets");
            break;

         case "toapprove" : // on affiche les tickets planifi??s ou assign??s au user
            $query .= "WHERE $is_deleted
                             AND (`glpi_tickets`.`status` = '".self::SOLVED."')
                             AND ($search_users_id";
            if (!$showgrouptickets &&  Session::haveRight('ticket', Ticket::SURVEY)) {
               $query .= " OR `glpi_tickets`.users_id_recipient = '".Session::getLoginUserID()."' ";
            }
            $query .= ")".
                      getEntitiesRestrictRequest("AND", "glpi_tickets");
            break;

         case "tovalidate" : // on affiche les tickets ?? valider
            $query .= " LEFT JOIN `glpi_ticketvalidations`
                           ON (`glpi_tickets`.`id` = `glpi_ticketvalidations`.`tickets_id`)
                        WHERE $is_deleted
                              AND `users_id_validate` = '".Session::getLoginUserID()."'
                              AND `glpi_ticketvalidations`.`status` = '".CommonITILValidation::WAITING."'
                              AND `glpi_tickets`.`global_validation` = '".CommonITILValidation::WAITING."'
                              AND (`glpi_tickets`.`status` NOT IN ('".self::CLOSED."',
                                                                   '".self::SOLVED."')) ".
                       getEntitiesRestrictRequest("AND", "glpi_tickets");
            break;

         case "validation.rejected" : // tickets with rejected validation (approval)
         case "rejected" : // old ambiguous key
            $query .= "WHERE $is_deleted
                             AND ($search_assign)
                             AND `status` <> '".self::CLOSED."'
                             AND `global_validation` = '".CommonITILValidation::REFUSED."' ".
                             getEntitiesRestrictRequest("AND", "glpi_tickets");
            break;

         case "solution.rejected" : // tickets with rejected solution
            $last_solution_query = "SELECT `last_solution`.`id`
                                    FROM `glpi_itilsolutions` as `last_solution`
                                    WHERE `last_solution`.`items_id` = `glpi_tickets`.`id`
                                    AND `last_solution`.`itemtype` = 'Ticket'
                                    ORDER BY `last_solution`.`id` DESC
                                    LIMIT 1";
            $query .= " LEFT JOIN `glpi_itilsolutions`
                           ON (`glpi_itilsolutions`.`id` = ($last_solution_query))
                        WHERE $is_deleted
                             AND ($search_assign)
                             AND `glpi_tickets`.`status` <> '".self::CLOSED."'
                             AND `glpi_itilsolutions`.`status` = '".CommonITILValidation::REFUSED."' ".
                             getEntitiesRestrictRequest("AND", "glpi_tickets");
            break;

         case "observed" :
            $query .= "WHERE $is_deleted
                             AND ($search_observer)
                             AND (`glpi_tickets`.`status` IN ('".self::INCOMING."',
                                                              '".self::PLANNED."',
                                                              '".self::ASSIGNED."',
                                                              '".self::WAITING."'))
                             AND NOT ( $search_assign )
                             AND NOT ( $search_users_id ) ".
                             getEntitiesRestrictRequest("AND", "glpi_tickets");
            break;

         case "survey" : // tickets dont l'enqu??te de satisfaction n'est pas remplie et encore valide
            $query .= " INNER JOIN `glpi_ticketsatisfactions`
                           ON (`glpi_tickets`.`id` = `glpi_ticketsatisfactions`.`tickets_id`)
                        INNER JOIN `glpi_entities`
                           ON (`glpi_entities`.`id` = `glpi_tickets`.`entities_id`)
                        WHERE $is_deleted
                              AND ($search_users_id";
            if (Session::haveRight('ticket', Ticket::SURVEY)) {
               $query .= " OR `glpi_tickets`.`users_id_recipient` = '" . Session::getLoginUserID() . "'";
            }
            $query .=  ")
                              AND `glpi_tickets`.`status` = '".self::CLOSED."'
                              AND (`glpi_entities`.`inquest_duration` = 0
                                   OR DATEDIFF(ADDDATE(`glpi_ticketsatisfactions`.`date_begin`,
                                                       INTERVAL
                                                       `glpi_entities`.`inquest_duration` DAY),
                                               CURDATE()) > 0)
                              AND `glpi_ticketsatisfactions`.`date_answered` IS NULL ".
                              getEntitiesRestrictRequest("AND", "glpi_tickets");
            break;

         case "requestbyself" : // on affiche les tickets demand??s le user qui sont planifi??s ou assign??s
               // ?? quelqu'un d'autre (exclut les self-tickets)

         default :
            $query .= "WHERE $is_deleted
                             AND ($search_users_id)
                             AND (`glpi_tickets`.`status` IN ('".self::INCOMING."',
                                                              '".self::PLANNED."',
                                                              '".self::ASSIGNED."',
                                                              '".self::WAITING."'))
                             AND NOT ( $search_assign ) ".
                             getEntitiesRestrictRequest("AND", "glpi_tickets");
      }

      $query  .= " ORDER BY `glpi_tickets`.`date_mod` DESC";
      $result  = $DB->query($query);
      $numrows = $DB->numrows($result);

      if ($_SESSION['glpidisplay_count_on_home'] > 0) {
         $query  .= " LIMIT ".intval($start).','.intval($_SESSION['glpidisplay_count_on_home']);
         $result  = $DB->query($query);
         $number  = $DB->numrows($result);
      } else {
         $number = 0;
      }

      if ($numrows > 0) {
         echo "<table class='tab_cadrehov'>";
         echo "<tr class='noHover'><th colspan='4'>";

         $options['reset'] = 'reset';
         $forcetab         = '';
         $num              = 0;
         if ($showgrouptickets) {
            switch ($status) {
               case "toapprove" :
                  $options['criteria'][0]['field']      = 12; // status
                  $options['criteria'][0]['searchtype'] = 'equals';
                  $options['criteria'][0]['value']      = self::SOLVED;
                  $options['criteria'][0]['link']       = 'AND';

                  $options['criteria'][1]['field']      = 71; // groups_id
                  $options['criteria'][1]['searchtype'] = 'equals';
                  $options['criteria'][1]['value']      = 'mygroups';
                  $options['criteria'][1]['link']       = 'AND';
                  $forcetab                 = 'Ticket$2';

                  echo "<a href=\"".Ticket::getSearchURL()."?".
                         Toolbox::append_params($options, '&amp;')."\">".
                         Html::makeTitle(__('Your tickets to close'), $number, $numrows)."</a>";
                  break;

               case "waiting" :
                  $options['criteria'][0]['field']      = 12; // status
                  $options['criteria'][0]['searchtype'] = 'equals';
                  $options['criteria'][0]['value']      = self::WAITING;
                  $options['criteria'][0]['link']       = 'AND';

                  $options['criteria'][1]['field']      = 8; // groups_id_assign
                  $options['criteria'][1]['searchtype'] = 'equals';
                  $options['criteria'][1]['value']      = 'mygroups';
                  $options['criteria'][1]['link']       = 'AND';

                  echo "<a href=\"".Ticket::getSearchURL()."?".
                         Toolbox::append_params($options, '&amp;')."\">".
                         Html::makeTitle(__('Tickets on pending status'), $number, $numrows)."</a>";
                  break;

               case "process" :
                  $options['criteria'][0]['field']      = 12; // status
                  $options['criteria'][0]['searchtype'] = 'equals';
                  $options['criteria'][0]['value']      = 'process';
                  $options['criteria'][0]['link']       = 'AND';

                  $options['criteria'][1]['field']      = 8; // groups_id_assign
                  $options['criteria'][1]['searchtype'] = 'equals';
                  $options['criteria'][1]['value']      = 'mygroups';
                  $options['criteria'][1]['link']       = 'AND';

                  echo "<a href=\"".Ticket::getSearchURL()."?".
                         Toolbox::append_params($options, '&amp;')."\">".
                         Html::makeTitle(__('Tickets to be processed'), $number, $numrows)."</a>";
                  break;

               case "observed":
                  $options['criteria'][0]['field']      = 12; // status
                  $options['criteria'][0]['searchtype'] = 'equals';
                  $options['criteria'][0]['value']      = 'notold';
                  $options['criteria'][0]['link']       = 'AND';

                  $options['criteria'][1]['field']      = 65; // groups_id
                  $options['criteria'][1]['searchtype'] = 'equals';
                  $options['criteria'][1]['value']      = 'mygroups';
                  $options['criteria'][1]['link']       = 'AND';

                  echo "<a href=\"".Ticket::getSearchURL()."?".
                         Toolbox::append_params($options, '&amp;')."\">".
                         Html::makeTitle(__('Your observed tickets'), $number, $numrows)."</a>";
                  break;

               case "requestbyself" :
               default :
                  $options['criteria'][0]['field']      = 12; // status
                  $options['criteria'][0]['searchtype'] = 'equals';
                  $options['criteria'][0]['value']      = 'notold';
                  $options['criteria'][0]['link']       = 'AND';

                  $options['criteria'][1]['field']      = 71; // groups_id
                  $options['criteria'][1]['searchtype'] = 'equals';
                  $options['criteria'][1]['value']      = 'mygroups';
                  $options['criteria'][1]['link']       = 'AND';

                  echo "<a href=\"".Ticket::getSearchURL()."?".
                         Toolbox::append_params($options, '&amp;')."\">".
                         Html::makeTitle(__('Your tickets in progress'), $number, $numrows)."</a>";
            }

         } else {
            switch ($status) {
               case "waiting" :
                  $options['criteria'][0]['field']      = 12; // status
                  $options['criteria'][0]['searchtype'] = 'equals';
                  $options['criteria'][0]['value']      = self::WAITING;
                  $options['criteria'][0]['link']       = 'AND';

                  $options['criteria'][1]['field']      = 5; // users_id_assign
                  $options['criteria'][1]['searchtype'] = 'equals';
                  $options['criteria'][1]['value']      = Session::getLoginUserID();
                  $options['criteria'][1]['link']       = 'AND';

                  echo "<a href=\"".Ticket::getSearchURL()."?".
                         Toolbox::append_params($options, '&amp;')."\">".
                         Html::makeTitle(__('Tickets on pending status'), $number, $numrows)."</a>";
                  break;

               case "process" :
                  $options['criteria'][0]['field']      = 5; // users_id_assign
                  $options['criteria'][0]['searchtype'] = 'equals';
                  $options['criteria'][0]['value']      = Session::getLoginUserID();
                  $options['criteria'][0]['link']       = 'AND';

                  $options['criteria'][1]['field']      = 12; // status
                  $options['criteria'][1]['searchtype'] = 'equals';
                  $options['criteria'][1]['value']      = 'process';
                  $options['criteria'][1]['link']       = 'AND';

                  echo "<a href=\"".Ticket::getSearchURL()."?".
                         Toolbox::append_params($options, '&amp;')."\">".
                         Html::makeTitle(__('Tickets to be processed'), $number, $numrows)."</a>";
                  break;

               case "tovalidate" :
                  $options['criteria'][0]['field']      = 55; // validation status
                  $options['criteria'][0]['searchtype'] = 'equals';
                  $options['criteria'][0]['value']      = CommonITILValidation::WAITING;
                  $options['criteria'][0]['link']       = 'AND';

                  $options['criteria'][1]['field']      = 59; // validation aprobator
                  $options['criteria'][1]['searchtype'] = 'equals';
                  $options['criteria'][1]['value']      = Session::getLoginUserID();
                  $options['criteria'][1]['link']       = 'AND';

                  $options['criteria'][2]['field']      = 12; // validation aprobator
                  $options['criteria'][2]['searchtype'] = 'equals';
                  $options['criteria'][2]['value']      = 'old';
                  $options['criteria'][2]['link']       = 'AND NOT';

                  $options['criteria'][3]['field']      = 52; // global validation status
                  $options['criteria'][3]['searchtype'] = 'equals';
                  $options['criteria'][3]['value']      = CommonITILValidation::WAITING;
                  $options['criteria'][3]['link']       = 'AND';
                  $forcetab                         = 'TicketValidation$1';

                  echo "<a href=\"".Ticket::getSearchURL()."?".
                        Toolbox::append_params($options, '&amp;')."\">".
                        Html::makeTitle(__('Your tickets to validate'), $number, $numrows)."</a>";

                  break;

               case "validation.rejected" :
               case "rejected" : // old ambiguous key
                  $options['criteria'][0]['field']      = 52; // validation status
                  $options['criteria'][0]['searchtype'] = 'equals';
                  $options['criteria'][0]['value']      = CommonITILValidation::REFUSED;
                  $options['criteria'][0]['link']       = 'AND';

                  $options['criteria'][1]['field']      = 5; // assign user
                  $options['criteria'][1]['searchtype'] = 'equals';
                  $options['criteria'][1]['value']      = Session::getLoginUserID();
                  $options['criteria'][1]['link']       = 'AND';

                  echo "<a href=\"".Ticket::getSearchURL()."?".
                        Toolbox::append_params($options, '&amp;')."\">".
                        Html::makeTitle(__('Your tickets having rejected approval status'), $number, $numrows)."</a>";

                  break;

               case "solution.rejected" :
                  $options['criteria'][0]['field']      = 39; // last solution status
                  $options['criteria'][0]['searchtype'] = 'equals';
                  $options['criteria'][0]['value']      = CommonITILValidation::REFUSED;
                  $options['criteria'][0]['link']       = 'AND';

                  $options['criteria'][1]['field']      = 5; // assign user
                  $options['criteria'][1]['searchtype'] = 'equals';
                  $options['criteria'][1]['value']      = Session::getLoginUserID();
                  $options['criteria'][1]['link']       = 'AND';

                  echo "<a href=\"".Ticket::getSearchURL()."?".
                        Toolbox::append_params($options, '&amp;')."\">".
                        Html::makeTitle(__('Your tickets having rejected solution'), $number, $numrows)."</a>";

                  break;

               case "toapprove" :
                  $options['criteria'][0]['field']      = 12; // status
                  $options['criteria'][0]['searchtype'] = 'equals';
                  $options['criteria'][0]['value']      = self::SOLVED;
                  $options['criteria'][0]['link']       = 'AND';

                  $options['criteria'][1]['field']      = 4; // users_id_assign
                  $options['criteria'][1]['searchtype'] = 'equals';
                  $options['criteria'][1]['value']      = Session::getLoginUserID();
                  $options['criteria'][1]['link']       = 'AND';

                  $options['criteria'][2]['field']      = 22; // users_id_recipient
                  $options['criteria'][2]['searchtype'] = 'equals';
                  $options['criteria'][2]['value']      = Session::getLoginUserID();
                  $options['criteria'][2]['link']       = 'OR';

                  $options['criteria'][3]['field']      = 12; // status
                  $options['criteria'][3]['searchtype'] = 'equals';
                  $options['criteria'][3]['value']      = self::SOLVED;
                  $options['criteria'][3]['link']       = 'AND';

                  $forcetab                 = 'Ticket$2';

                  echo "<a href=\"".Ticket::getSearchURL()."?".
                        Toolbox::append_params($options, '&amp;')."\">".
                        Html::makeTitle(__('Your tickets to close'), $number, $numrows)."</a>";
                  break;

               case "observed" :
                  $options['criteria'][0]['field']      = 66; // users_id
                  $options['criteria'][0]['searchtype'] = 'equals';
                  $options['criteria'][0]['value']      = Session::getLoginUserID();
                  $options['criteria'][0]['link']       = 'AND';

                  $options['criteria'][1]['field']      = 12; // status
                  $options['criteria'][1]['searchtype'] = 'equals';
                  $options['criteria'][1]['value']      = 'notold';
                  $options['criteria'][1]['link']       = 'AND';

                  echo "<a href=\"".Ticket::getSearchURL()."?".
                        Toolbox::append_params($options, '&amp;')."\">".
                        Html::makeTitle(__('Your observed tickets'), $number, $numrows)."</a>";
                  break;

               case "survey" :
                  $options['criteria'][0]['field']      = 12; // status
                  $options['criteria'][0]['searchtype'] = 'equals';
                  $options['criteria'][0]['value']      = self::CLOSED;
                  $options['criteria'][0]['link']       = 'AND';

                  $options['criteria'][1]['field']      = 60; // enquete generee
                  $options['criteria'][1]['searchtype'] = 'contains';
                  $options['criteria'][1]['value']      = '^';
                  $options['criteria'][1]['link']       = 'AND';

                  $options['criteria'][2]['field']      = 61; // date_answered
                  $options['criteria'][2]['searchtype'] = 'contains';
                  $options['criteria'][2]['value']      = 'NULL';
                  $options['criteria'][2]['link']       = 'AND';

                  if (Session::haveRight('ticket', Ticket::SURVEY)) {
                     $options['criteria'][3]['field']      = 22; // author
                     $options['criteria'][3]['searchtype'] = 'equals';
                     $options['criteria'][3]['value']      = Session::getLoginUserID();
                     $options['criteria'][3]['link']       = 'AND';
                  } else {
                     $options['criteria'][3]['field']      = 4; // requester
                     $options['criteria'][3]['searchtype'] = 'equals';
                     $options['criteria'][3]['value']      = Session::getLoginUserID();
                     $options['criteria'][3]['link']       = 'AND';
                  }
                  $forcetab                 = 'Ticket$3';

                  echo "<a href=\"".Ticket::getSearchURL()."?".
                         Toolbox::append_params($options, '&amp;')."\">".
                         Html::makeTitle(__('Satisfaction survey'), $number, $numrows)."</a>";
                  break;

               case "requestbyself" :
               default :
                  $options['criteria'][0]['field']      = 4; // users_id
                  $options['criteria'][0]['searchtype'] = 'equals';
                  $options['criteria'][0]['value']      = Session::getLoginUserID();
                  $options['criteria'][0]['link']       = 'AND';

                  $options['criteria'][1]['field']      = 12; // status
                  $options['criteria'][1]['searchtype'] = 'equals';
                  $options['criteria'][1]['value']      = 'notold';
                  $options['criteria'][1]['link']       = 'AND';

                  echo "<a href=\"".Ticket::getSearchURL()."?".
                        Toolbox::append_params($options, '&amp;')."\">".
                        Html::makeTitle(__('Your tickets in progress'), $number, $numrows)."</a>";
            }
         }

         echo "</th></tr>";
         if ($number) {
            echo "<tr><th></th>";
            echo "<th>".__('Requester')."</th>";
            echo "<th>"._n('Associated element', 'Associated elements', Session::getPluralNumber())."</th>";
            echo "<th>".__('Description')."</th></tr>";
            for ($i = 0; $i < $number; $i++) {
               $ID = $DB->result($result, $i, "id");
               self::showVeryShort($ID, $forcetab);
            }
         }
         echo "</table>";

      }
   }

   /**
    * Get tickets count
    *
    * @param $foruser boolean : only for current login user as requester (false by default)
   **/
   static function showCentralCount($foruser = false) {
      global $DB, $CFG_GLPI;

      // show a tab with count of jobs in the central and give link
      if (!Session::haveRight(self::$rightname, self::READALL) && !self::canCreate()) {
         return false;
      }
      if (!Session::haveRight(self::$rightname, self::READALL)) {
         $foruser = true;
      }

      $query = "SELECT `glpi_tickets`.`status`,
                       COUNT(DISTINCT `glpi_tickets`.`id`) AS COUNT
                FROM `glpi_tickets` ";

      if ($foruser) {
         $query .= " LEFT JOIN `glpi_tickets_users`
                        ON (`glpi_tickets`.`id` = `glpi_tickets_users`.`tickets_id`
                            AND `glpi_tickets_users`.`type` = '".CommonITILActor::REQUESTER."')
                     LEFT JOIN `glpi_ticketvalidations`
                        ON (`glpi_tickets`.`id` = `glpi_ticketvalidations`.`tickets_id`)";

         if (Session::haveRight(self::$rightname, self::READGROUP)
             && isset($_SESSION["glpigroups"])
             && count($_SESSION["glpigroups"])) {
            $query .= " LEFT JOIN `glpi_groups_tickets`
                           ON (`glpi_tickets`.`id` = `glpi_groups_tickets`.`tickets_id`
                               AND `glpi_groups_tickets`.`type` = '".CommonITILActor::REQUESTER."')";
         }
      }
      $query .= getEntitiesRestrictRequest("WHERE", "glpi_tickets");

      if ($foruser) {
         $query .= " AND (`glpi_tickets_users`.`users_id` = '".Session::getLoginUserID()."'
                           OR `glpi_tickets`.`users_id_recipient` = '".Session::getLoginUserID()."'
                           OR `glpi_ticketvalidations`.`users_id_validate` = '".Session::getLoginUserID()."'";

         if (Session::haveRight(self::$rightname, self::READGROUP)
             && isset($_SESSION["glpigroups"])
             && count($_SESSION["glpigroups"])) {
            $groups = implode(",", $_SESSION['glpigroups']);
            $query .= " OR `glpi_groups_tickets`.`groups_id` IN (".$groups.") ";
         }
         $query.= ")";
      }
      $query_deleted = $query;

      $query         .= " AND `glpi_tickets`.`is_deleted` = 0
                         GROUP BY `status`";
      $query_deleted .= " AND `glpi_tickets`.`is_deleted` = 1
                         GROUP BY `status`";

      $result         = $DB->query($query);
      $result_deleted = $DB->query($query_deleted);

      $status = [];
      foreach (self::getAllStatusArray() as $key => $val) {
         $status[$key] = 0;
      }

      if ($DB->numrows($result) > 0) {
         while ($data = $DB->fetch_assoc($result)) {
            $status[$data["status"]] = $data["COUNT"];
         }
      }

      $number_deleted = 0;
      if ($DB->numrows($result_deleted) > 0) {
         while ($data = $DB->fetch_assoc($result_deleted)) {
            $number_deleted += $data["COUNT"];
         }
      }
      $options['criteria'][0]['field']      = 12;
      $options['criteria'][0]['searchtype'] = 'equals';
      $options['criteria'][0]['value']      = 'process';
      $options['criteria'][0]['link']       = 'AND';
      $options['reset']         ='reset';

      echo "<table class='tab_cadrehov' >";
      echo "<tr class='noHover'><th colspan='2'>";

      if (Session::getCurrentInterface() != "central") {
         echo "<a href=\"".$CFG_GLPI["root_doc"]."/front/helpdesk.public.php?create_ticket=1\" class='pointer'>".
                __('Create a ticket')."&nbsp;<i class='fa fa-plus'></i><span class='sr-only'>". __s('Add')."</span></a>";
      } else {
         echo "<a href=\"".Ticket::getSearchURL()."?".
                Toolbox::append_params($options, '&amp;')."\">".__('Ticket followup')."</a>";
      }
      echo "</th></tr>";
      echo "<tr><th>"._n('Ticket', 'Tickets', Session::getPluralNumber())."</th><th>"._x('quantity', 'Number')."</th></tr>";

      if (Session::haveRightsOr('ticketvalidation', TicketValidation::getValidateRights())) {
           $number_waitapproval = TicketValidation::getNumberToValidate(Session::getLoginUserID());

           $opt = [];
           $opt['reset']      = 'reset';
           $opt['criteria'][0]['field']      = 55; // validation status
           $opt['criteria'][0]['searchtype'] = 'equals';
           $opt['criteria'][0]['value']      = CommonITILValidation::WAITING;
           $opt['criteria'][0]['link']       = 'AND';

           $opt['criteria'][1]['field']      = 59; // validation aprobator
           $opt['criteria'][1]['searchtype'] = 'equals';
           $opt['criteria'][1]['value']      = Session::getLoginUserID();
           $opt['criteria'][1]['link']       = 'AND';

           echo "<tr class='tab_bg_2'>";
           echo "<td><a href=\"".Ticket::getSearchURL()."?".
               Toolbox::append_params($opt, '&amp;')."\">".__('Ticket waiting for your approval')."</a></td>";
           echo "<td class='numeric'>".$number_waitapproval."</td></tr>";
      }

      foreach ($status as $key => $val) {
         $options['criteria'][0]['value'] = $key;
         echo "<tr class='tab_bg_2'>";
         echo "<td><a href=\"".Ticket::getSearchURL()."?".
                    Toolbox::append_params($options, '&amp;')."\">".self::getStatus($key)."</a></td>";
         echo "<td class='numeric'>$val</td></tr>";
      }

      $options['criteria'][0]['value'] = 'all';
      $options['is_deleted']  = 1;
      echo "<tr class='tab_bg_2'>";
      echo "<td><a href=\"".Ticket::getSearchURL()."?".
                 Toolbox::append_params($options, '&amp;')."\">".__('Deleted')."</a></td>";
      echo "<td class='numeric'>".$number_deleted."</td></tr>";

      echo "</table><br>";
   }


   static function showCentralNewList() {
      global $DB, $CFG_GLPI;

      if (!Session::haveRight(self::$rightname, self::READALL)) {
         return false;
      }

      $query = "SELECT ".self::getCommonSelect()."
                FROM `glpi_tickets` ".self::getCommonLeftJoin()."
                WHERE `status` = '".self::INCOMING."' ".
                      getEntitiesRestrictRequest("AND", "glpi_tickets")."
                      AND `is_deleted` = 0
                ORDER BY `glpi_tickets`.`date_mod` DESC
                LIMIT ".intval($_SESSION['glpilist_limit']);

      $result = $DB->query($query);
      $number = $DB->numrows($result);

      if ($number > 0) {
         Session::initNavigateListItems('Ticket');
         $options['criteria'][0]['field']      = 12;
         $options['criteria'][0]['searchtype'] = 'equals';
         $options['criteria'][0]['value']   = self::INCOMING;
         $options['criteria'][0]['link']       = 'AND';
         $options['reset']         ='reset';

         echo "<div class='center'><table class='tab_cadre_fixe'>";
         //TRANS: %d is the number of new tickets
         echo "<tr><th colspan='12'>".sprintf(_n('%d new ticket', '%d new tickets', $number), $number);
         echo "<a href='".Ticket::getSearchURL()."?".
                Toolbox::append_params($options, '&amp;')."'>".__('Show all')."</a>";
         echo "</th></tr>";

         self::commonListHeader(Search::HTML_OUTPUT);

         while ($data = $DB->fetch_assoc($result)) {
            Session::addToNavigateListItems('Ticket', $data["id"]);
            self::showShort($data["id"]);
         }
         echo "</table></div>";

      } else {
         echo "<div class='center'>";
         echo "<table class='tab_cadre_fixe'>";
         echo "<tr><th>".__('No ticket found.')."</th></tr>";
         echo "</table>";
         echo "</div><br>";
      }
   }

   /**
   * Display tickets for an item
    *
    * Will also display tickets of linked items
    *
    * @param CommonDBTM $item         CommonDBTM object
    * @param boolean    $withtemplate (default 0)
    *
    * @return void (display a table)
   **/
   static function showListForItem(CommonDBTM $item, $withtemplate = 0) {
      global $DB, $CFG_GLPI;

      if (!Session::haveRightsOr(self::$rightname,
                                  [self::READALL, self::READMY, self::READASSIGN, CREATE])) {
         return false;
      }

      if ($item->isNewID($item->getID())) {
         return false;
      }

      $restrict         = '';
      $order            = '';
      $options['reset'] = 'reset';

      switch ($item->getType()) {
         case 'User' :
            $restrict = "(`glpi_tickets_users`.`users_id` = '".$item->getID()."' ".
                        " AND `glpi_tickets_users`.`type` = ".CommonITILActor::REQUESTER.")";
            $order    = '`glpi_tickets`.`date_mod` DESC';

            $options['reset'] = 'reset';

            $options['criteria'][0]['field']      = 4; // status
            $options['criteria'][0]['searchtype'] = 'equals';
            $options['criteria'][0]['value']      = $item->getID();
            $options['criteria'][0]['link']       = 'AND';
            break;

         case 'SLA' :
            $restrict  = "`slas_id_tto` = '".$item->getID()."'
                           OR `slas_id_ttr` = '".$item->getID()."'";
            $order     = '`glpi_tickets`.`time_to_resolve` DESC';

            $options['criteria'][0]['field']      = 30;
            $options['criteria'][0]['searchtype'] = 'equals';
            $options['criteria'][0]['value']      = $item->getID();
            $options['criteria'][0]['link']       = 'AND';
            break;

         case 'OLA' :
            $restrict  = "`olas_id_tto` = '".$item->getID()."'
                           OR `olas_id_ttr` = '".$item->getID()."'";
            $order     = '`glpi_tickets`.`internal_time_to_resolve` DESC';

            $options['criteria'][0]['field']      = 30;
            $options['criteria'][0]['searchtype'] = 'equals';
            $options['criteria'][0]['value']      = $item->getID();
            $options['criteria'][0]['link']       = 'AND';
            break;

         case 'Supplier' :
            $restrict = "(`glpi_suppliers_tickets`.`suppliers_id` = '".$item->getID()."' ".
                        "  AND `glpi_suppliers_tickets`.`type` = ".CommonITILActor::ASSIGN.")";
            $order    = '`glpi_tickets`.`date_mod` DESC';

            $options['criteria'][0]['field']      = 6;
            $options['criteria'][0]['searchtype'] = 'equals';
            $options['criteria'][0]['value']      = $item->getID();
            $options['criteria'][0]['link']       = 'AND';
            break;

         case 'Group' :
            // Mini search engine
            if ($item->haveChildren()) {
               $tree = Session::getSavedOption(__CLASS__, 'tree', 0);
               echo "<table class='tab_cadre_fixe'>";
               echo "<tr class='tab_bg_1'><th>".__('Last tickets')."</th></tr>";
               echo "<tr class='tab_bg_1'><td class='center'>";
               echo __('Child groups')."&nbsp;";
               Dropdown::showYesNo('tree', $tree, -1,
                                   ['on_change' => 'reloadTab("start=0&tree="+this.value)']);
            } else {
               $tree = 0;
            }
            echo "</td></tr></table>";

            if ($tree) {
               $restrict = "IN (".implode(',', getSonsOf('glpi_groups', $item->getID())).")";
            } else {
               $restrict = "='".$item->getID()."'";
            }
            $restrict = "(`glpi_groups_tickets`.`groups_id` $restrict".
                         " AND `glpi_groups_tickets`.`type` = ".CommonITILActor::REQUESTER.")";
            $order    = '`glpi_tickets`.`date_mod` DESC';

            $options['criteria'][0]['field']      = 71;
            $options['criteria'][0]['searchtype'] = ($tree ? 'under' : 'equals');
            $options['criteria'][0]['value']      = $item->getID();
            $options['criteria'][0]['link']       = 'AND';
            break;

         default :
            $restrict = "(`glpi_items_tickets`.`items_id` = '".$item->getID()."' ".
                        " AND `glpi_items_tickets`.`itemtype` = '".$item->getType()."')";

            // you can only see your tickets
            if (!Session::haveRight(self::$rightname, self::READALL)) {
               $restrict .= " AND (`glpi_tickets`.`users_id_recipient` = '".Session::getLoginUserID()."'
                                   OR (`glpi_tickets_users`.`tickets_id` = `glpi_tickets`.`id`
                                       AND `glpi_tickets_users`.`users_id`
                                            = '".Session::getLoginUserID()."')";
               if (count($_SESSION['glpigroups'])) {
                  $restrict .= " OR `glpi_groups_tickets`.`groups_id` IN (".implode(",", $_SESSION['glpigroups']).")";
               }
               $restrict .= ")";
            }

            $order    = '`glpi_tickets`.`date_mod` DESC';

            $options['criteria'][0]['field']      = 12;
            $options['criteria'][0]['searchtype'] = 'equals';
            $options['criteria'][0]['value']      = 'all';
            $options['criteria'][0]['link']       = 'AND';

            $options['metacriteria'][0]['itemtype']   = $item->getType();
            $options['metacriteria'][0]['field']      = Search::getOptionNumber($item->getType(),
                                                                                'id');
            $options['metacriteria'][0]['searchtype'] = 'equals';
            $options['metacriteria'][0]['value']      = $item->getID();
            $options['metacriteria'][0]['link']       = 'AND';
            break;
      }

      $query = "SELECT ".self::getCommonSelect()."
                FROM `glpi_tickets` ".self::getCommonLeftJoin()."
                WHERE $restrict ".
                      getEntitiesRestrictRequest("AND", "glpi_tickets")."
                AND glpi_tickets.is_deleted = 0
                ORDER BY $order
                LIMIT ".intval($_SESSION['glpilist_limit']);
      $result = $DB->query($query);
      $number = $DB->numrows($result);

      $colspan = 11;
      if (count($_SESSION["glpiactiveentities"]) > 1) {
         $colspan++;
      }

      // Ticket for the item
      // Link to open a new ticket
      if ($item->getID()
          && Ticket::isPossibleToAssignType($item->getType())
          && self::canCreate()
          && !(!empty($withtemplate) && ($withtemplate == 2))
            && (!isset($item->fields['is_template']) || ($item->fields['is_template'] == 0))) {
         echo "<div class='firstbloc'>";
         Html::showSimpleForm(Ticket::getFormURL(),
                              '_add_fromitem', __('New ticket for this item...'),
                              ['itemtype' => $item->getType(),
                                    'items_id' => $item->getID()]);
         echo "</div>";
      }

      if ($item->getID()
          && ($item->getType() == 'User')
          && self::canCreate()
          && !(!empty($withtemplate) && ($withtemplate == 2))) {
         echo "<div class='firstbloc'>";
         Html::showSimpleForm(Ticket::getFormURL(),
                              '_add_fromitem', __('New ticket for this item...'),
                              ['_users_id_requester' => $item->getID()]);
         echo "</div>";
      }

      echo "<div>";

      if ($number > 0) {
         echo "<table class='tab_cadre_fixehov'>";
         if (Session::haveRight(self::$rightname, self::READALL)) {
            Session::initNavigateListItems('Ticket',
            //TRANS : %1$s is the itemtype name, %2$s is the name of the item (used for headings of a list)
                                           sprintf(__('%1$s = %2$s'), $item->getTypeName(1),
                                                   $item->getName()));

            echo "<tr class='noHover'><th colspan='$colspan'>";
            $title = sprintf(_n('Last %d ticket', 'Last %d tickets', $number), $number);
            $link = "<a href='".Ticket::getSearchURL()."?".
                      Toolbox::append_params($options, '&amp;')."'>".__('Show all')."</a>";
            $title = printf(__('%1$s (%2$s)'), $title, $link);
            echo "</th></tr>";
         } else {
            echo "<tr><th colspan='$colspan'>".__("You don't have right to see all tickets")."</th></tr>";
         }

      } else {
         echo "<table class='tab_cadre_fixe'>";
         echo "<tr><th>".__('No ticket found.')."</th></tr>";
      }

      // Ticket list
      if ($number > 0) {
         self::commonListHeader(Search::HTML_OUTPUT);

         while ($data = $DB->fetch_assoc($result)) {
            Session::addToNavigateListItems('Ticket', $data["id"]);
            self::showShort($data["id"]);
         }
         self::commonListHeader(Search::HTML_OUTPUT);
      }

      echo "</table></div>";

      // Tickets for linked items
      $linkeditems = $item->getLinkedItems();
      $restrict    = [];
      if (count($linkeditems)) {
         foreach ($linkeditems as $ltype => $tab) {
            foreach ($tab as $lID) {
               $restrict[] = "(`glpi_items_tickets`.`itemtype` = '$ltype' AND `glpi_items_tickets`.`items_id` = '$lID')";
            }
         }
      }

      if (count($restrict)
          && Session::haveRight(self::$rightname, self::READALL)) {
         $query = "SELECT ".self::getCommonSelect()."
                   FROM `glpi_tickets` ".self::getCommonLeftJoin()."
                   WHERE ".implode(' OR ', $restrict).
                         getEntitiesRestrictRequest(' AND ', 'glpi_tickets') . "
                   ORDER BY `glpi_tickets`.`date_mod` DESC
                   LIMIT ".intval($_SESSION['glpilist_limit']);
         $result = $DB->query($query);
         $number = $DB->numrows($result);

         echo "<div class='spaced'><table class='tab_cadre_fixe'>";
         echo "<tr><th colspan='12'>";
         echo _n('Ticket on linked items', 'Tickets on linked items', $number);
         echo "</th></tr>";
         if ($number > 0) {
            self::commonListHeader(Search::HTML_OUTPUT);
            while ($data = $DB->fetch_assoc($result)) {
               // Session::addToNavigateListItems(TRACKING_TYPE,$data["id"]);
               self::showShort($data["id"]);
            }
            self::commonListHeader(Search::HTML_OUTPUT);
         } else {
            echo "<tr><th>".__('No ticket found.')."</th></tr>";
         }
         echo "</table></div>";

      } // Subquery for linked item

   }

   /**
    * @param $ID
    * @param $forcetab  string   name of the tab to force at the display (default '')
   **/
   static function showVeryShort($ID, $forcetab = '') {
      global $CFG_GLPI;

      // Prints a job in short form
      // Should be called in a <table>-segment
      // Print links or not in case of user view
      // Make new job object and fill it from database, if success, print it
      $showprivate = false;
      if (Session::haveRight('followup', ITILFollowup::SEEPRIVATE)) {
         $showprivate = true;
      }

      $job  = new self();
      $rand = mt_rand();
      if ($job->getFromDBwithData($ID, 0)) {
         $bgcolor = $_SESSION["glpipriority_".$job->fields["priority"]];
         // $rand    = mt_rand();
         echo "<tr class='tab_bg_2'>";
         echo "<td class='center' bgcolor='$bgcolor'>".sprintf(__('%1$s: %2$s'), __('ID'),
                                                               $job->fields["id"])."</td>";
         echo "<td class='center'>";

         if (isset($job->users[CommonITILActor::REQUESTER])
             && count($job->users[CommonITILActor::REQUESTER])) {
            foreach ($job->users[CommonITILActor::REQUESTER] as $d) {
               if ($d["users_id"] > 0) {
                  $userdata = getUserName($d["users_id"], 2);
                  $name     = "<span class='b'>".$userdata['name']."</span>";
                  $name     = sprintf(__('%1$s %2$s'), $name,
                                      Html::showToolTip($userdata["comment"],
                                                        ['link'    => $userdata["link"],
                                                              'display' => false]));
                  echo $name;
               } else {
                  echo $d['alternative_email']."&nbsp;";
               }
               echo "<br>";
            }
         }

         if (isset($job->groups[CommonITILActor::REQUESTER])
             && count($job->groups[CommonITILActor::REQUESTER])) {
            foreach ($job->groups[CommonITILActor::REQUESTER] as $d) {
               echo Dropdown::getDropdownName("glpi_groups", $d["groups_id"]);
               echo "<br>";
            }
         }

         echo "</td>";

         echo "<td class='center'>";
         if (!empty($job->hardwaredatas)) {
            foreach ($job->hardwaredatas as $hardwaredatas) {
               if ($hardwaredatas->canView()) {
                  echo $hardwaredatas->getTypeName()." - ";
                  echo "<span class='b'>".$hardwaredatas->getLink()."</span><br/>";
               } else if ($hardwaredatas) {
                  echo $hardwaredatas->getTypeName()." - ";
                  echo "<span class='b'>".$hardwaredatas->getNameID()."</span><br/>";
               }
            }
         } else {
            echo __('General');
         }
         echo "<td>";

         $link = "<a id='ticket".$job->fields["id"].$rand."' href='".Ticket::getFormURLWithID($job->fields["id"]);
         if ($forcetab != '') {
            $link .= "&amp;forcetab=".$forcetab;
         }
         $link   .= "'>";
         $link   .= "<span class='b'>".$job->getNameID()."</span></a>";
         $link    = sprintf(__('%1$s (%2$s)'), $link,
                            sprintf(__('%1$s - %2$s'), $job->numberOfFollowups($showprivate),
                                    $job->numberOfTasks($showprivate)));
         $content = Toolbox::unclean_cross_side_scripting_deep(html_entity_decode($job->fields['content'],
                                                                                  ENT_QUOTES,
                                                                                  "UTF-8"));
         $link    = printf(__('%1$s %2$s'), $link,
                           Html::showToolTip(nl2br(Html::Clean($content)),
                                             ['applyto' => 'ticket'.$job->fields["id"].$rand,
                                                   'display' => false]));
         echo "</td>";

         // Finish Line
         echo "</tr>";
      } else {
         echo "<tr class='tab_bg_2'>";
         echo "<td colspan='6' ><i>".__('No ticket in progress.')."</i></td></tr>";
      }
   }


   static function getCommonSelect() {

      $SELECT = "";
      if (count($_SESSION["glpiactiveentities"])>1) {
         $SELECT .= ", `glpi_entities`.`completename` AS entityname,
                       `glpi_tickets`.`entities_id` AS entityID ";
      }

      return " DISTINCT `glpi_tickets`.*,
                        `glpi_itilcategories`.`completename` AS catname
                        $SELECT";
   }


   static function getCommonLeftJoin() {

      $FROM = "";
      if (count($_SESSION["glpiactiveentities"])>1) {
         $FROM .= " LEFT JOIN `glpi_entities`
                        ON (`glpi_entities`.`id` = `glpi_tickets`.`entities_id`) ";
      }

      return " LEFT JOIN `glpi_groups_tickets`
                  ON (`glpi_tickets`.`id` = `glpi_groups_tickets`.`tickets_id`)
               LEFT JOIN `glpi_tickets_users`
                  ON (`glpi_tickets`.`id` = `glpi_tickets_users`.`tickets_id`)
               LEFT JOIN `glpi_suppliers_tickets`
                  ON (`glpi_tickets`.`id` = `glpi_suppliers_tickets`.`tickets_id`)
               LEFT JOIN `glpi_itilcategories`
                  ON (`glpi_tickets`.`itilcategories_id` = `glpi_itilcategories`.`id`)
               LEFT JOIN `glpi_tickettasks`
                  ON (`glpi_tickets`.`id` = `glpi_tickettasks`.`tickets_id`)
               LEFT JOIN `glpi_items_tickets`
                  ON (`glpi_tickets`.`id` = `glpi_items_tickets`.`tickets_id`)
               $FROM";

   }


   /**
    * @param $output
   **/
   static function showPreviewAssignAction($output) {

      //If ticket is assign to an object, display this information first
      if (isset($output["entities_id"])
          && isset($output["items_id"])
          && isset($output["itemtype"])) {

         if ($item = getItemForItemtype($output["itemtype"])) {
            if ($item->getFromDB($output["items_id"])) {
               echo "<tr class='tab_bg_2'>";
               echo "<td>".__('Assign equipment')."</td>";

               echo "<td>".$item->getLink(['comments' => true])."</td>";
               echo "</tr>";
            }
         }

         unset($output["items_id"]);
         unset($output["itemtype"]);
      }
      unset($output["entities_id"]);
      return $output;
   }


   /**
    * Give cron information
    *
    * @param $name : task's name
    *
    * @return arrray of information
   **/
   static function cronInfo($name) {

      switch ($name) {
         case 'closeticket' :
            return ['description' => __('Automatic tickets closing')];

         case 'alertnotclosed' :
            return ['description' => __('Not solved tickets')];

         case 'createinquest' :
            return ['description' => __('Generation of satisfaction surveys')];
      }
      return [];
   }


   /**
    * Cron for ticket's automatic close
    *
    * @param $task : crontask object
    *
    * @return integer (0 : nothing done - 1 : done)
   **/
   static function cronCloseTicket($task) {
      global $DB;

      $ticket = new self();

      // Recherche des entit??s
      $tot = 0;

      $entities = $DB->request(
         [
            'SELECT' => 'id',
            'FROM'   => Entity::getTable(),
         ]
      );
      foreach ($entities as $entity) {
         $delay  = Entity::getUsedConfig('autoclose_delay', $entity['id'], '', Entity::CONFIG_NEVER);
         if ($delay >= 0) {
            $query = "SELECT *
                      FROM `glpi_tickets`
                      WHERE `entities_id` = '".$entity['id']."'
                            AND `status` = '".self::SOLVED."'
                            AND `is_deleted` = 0";

            if ($delay > 0) {
               $calendars_id = Entity::getUsedConfig('calendars_id', $entity['id']);
               if ($calendars_id > 0) {
                  $calendar = new Calendar;
                  $calendar->getFromDB($calendars_id);
                  $end_date = $calendar->computeEndDate(
                     date('Y-m-d H:i:s'),
                     - $delay * DAY_TIMESTAMP,
                     0,
                     true
                  );
                  $query .= " AND `solvedate` <= '$end_date'";
               } else {
                  // no calendar, remove all days
                  $query .= " AND ADDDATE(`solvedate`, INTERVAL ".$delay." DAY) < NOW()";
               }
            }

            $nb = 0;
            foreach ($DB->request($query) as $tick) {
               $ticket->update(['id'           => $tick['id'],
                                     'status'       => self::CLOSED,
                                     '_auto_update' => true]);
               $nb++;
            }

            if ($nb) {
               $tot += $nb;
               $task->addVolume($nb);
               $task->log(Dropdown::getDropdownName('glpi_entities', $entity['id'])." : $nb");
            }
         }
      }

      return ($tot > 0 ? 1 : 0);
   }


   /**
    * Cron for alert old tickets which are not solved
    *
    * @param $task : crontask object
    *
    * @return integer (0 : nothing done - 1 : done)
   **/
   static function cronAlertNotClosed($task) {
      global $DB, $CFG_GLPI;

      if (!$CFG_GLPI["use_notifications"]) {
         return 0;
      }
      // Recherche des entit??s
      $tot = 0;
      foreach (Entity::getEntitiesToNotify('notclosed_delay') as $entity => $value) {
         $query = "SELECT `glpi_tickets`.*
                   FROM `glpi_tickets`
                   WHERE `glpi_tickets`.`entities_id` = '".$entity."'
                         AND `glpi_tickets`.`is_deleted` = 0
                         AND `glpi_tickets`.`status` IN ('".self::INCOMING."',
                                                         '".self::ASSIGNED."',
                                                         '".self::PLANNED."',
                                                         '".self::WAITING."')
                         AND `glpi_tickets`.`closedate` IS NULL
                         AND ADDDATE(`glpi_tickets`.`date`, INTERVAL ".$value." DAY) < NOW()";
         $tickets = [];
         foreach ($DB->request($query) as $tick) {
            $tickets[] = $tick;
         }

         if (!empty($tickets)) {
            if (NotificationEvent::raiseEvent('alertnotclosed', new self(),
                                              ['items'       => $tickets,
                                                    'entities_id' => $entity])) {

               $tot += count($tickets);
               $task->addVolume(count($tickets));
               $task->log(sprintf(__('%1$s: %2$s'),
                                  Dropdown::getDropdownName('glpi_entities', $entity),
                                  count($tickets)));
            }
         }
      }

      return ($tot > 0 ? 1 : 0);
   }


   /**
    * Cron for ticketsatisfaction's automatic generated
    *
    * @param $task : crontask object
    *
    * @return integer (0 : nothing done - 1 : done)
   **/
   static function cronCreateInquest($task) {
      global $DB;

      $conf        = new Entity();
      $inquest     = new TicketSatisfaction();
      $tot         = 0;
      $maxentity   = [];
      $tabentities = [];

      $rate = Entity::getUsedConfig('inquest_config', 0, 'inquest_rate');
      if ($rate > 0) {
         $tabentities[0] = $rate;
      }

      foreach ($DB->request('glpi_entities') as $entity) {
         $rate   = Entity::getUsedConfig('inquest_config', $entity['id'], 'inquest_rate');
         $parent = Entity::getUsedConfig('inquest_config', $entity['id'], 'entities_id');

         if ($rate > 0) {
            $tabentities[$entity['id']] = $rate;
         }
      }

      foreach ($tabentities as $entity => $rate) {
         $parent        = Entity::getUsedConfig('inquest_config', $entity, 'entities_id');
         $delay         = Entity::getUsedConfig('inquest_config', $entity, 'inquest_delay');
         $duration      = Entity::getUsedConfig('inquest_config', $entity, 'inquest_duration');
         $type          = Entity::getUsedConfig('inquest_config', $entity);
         $max_closedate = Entity::getUsedConfig('inquest_config', $entity, 'max_closedate');

         $query = "SELECT `glpi_tickets`.`id`,
                          `glpi_tickets`.`closedate`,
                          `glpi_tickets`.`entities_id`
                   FROM `glpi_tickets`
                   LEFT JOIN `glpi_ticketsatisfactions`
                       ON `glpi_ticketsatisfactions`.`tickets_id` = `glpi_tickets`.`id`
                   LEFT JOIN `glpi_entities`
                       ON `glpi_tickets`.`entities_id` = `glpi_entities`.`id`
                   WHERE `glpi_tickets`.`entities_id` = '$entity'
                         AND `glpi_tickets`.`is_deleted` = 0
                         AND `glpi_tickets`.`status` = '".self::CLOSED."'
                         AND `glpi_tickets`.`closedate` > '$max_closedate'
                         AND ADDDATE(`glpi_tickets`.`closedate`, INTERVAL $delay DAY)<=NOW()
                         AND ADDDATE(`glpi_entities`.`max_closedate`, INTERVAL $duration DAY)<=NOW()
                         AND `glpi_ticketsatisfactions`.`id` IS NULL
                   ORDER BY `closedate` ASC";

         $nb            = 0;
         $max_closedate = '';

         foreach ($DB->request($query) as $tick) {
            $max_closedate = $tick['closedate'];
            if (mt_rand(1, 100) <= $rate) {
               if ($inquest->add(['tickets_id'  => $tick['id'],
                                       'date_begin'  => $_SESSION["glpi_currenttime"],
                                       'entities_id' => $tick['entities_id'],
                                       'type'        => $type])) {
                  $nb++;
               }
            }
         }

         // conservation de toutes les max_closedate des entites filles
         if (!empty($max_closedate)
             && (!isset($maxentity[$parent])
                 || ($max_closedate > $maxentity[$parent]))) {
            $maxentity[$parent] = $max_closedate;
         }

         if ($nb) {
            $tot += $nb;
            $task->addVolume($nb);
            $task->log(sprintf(__('%1$s: %2$s'),
                               Dropdown::getDropdownName('glpi_entities', $entity), $nb));
         }
      }

      // Sauvegarde du max_closedate pour ne pas tester les m??me tickets 2 fois
      foreach ($maxentity as $parent => $maxdate) {
         $conf->getFromDB($parent);
         $conf->update(['id'            => $conf->fields['id'],
                             //'entities_id'   => $parent,
                             'max_closedate' => $maxdate]);
      }

      return ($tot > 0 ? 1 : 0);
   }


   /**
    * Display debug information for current object
   **/
   function showDebug() {
      NotificationEvent::debugEvent($this);
   }


   /**
    * @since 0.85
    *
    * @see commonDBTM::getRights()
    **/
   function getRights($interface = 'central') {

      $values = parent::getRights();
      unset($values[READ]);
      $values[self::READMY]    = __('See my ticket');
                                                  //TRANS: short for : See tickets created by my groups
      $values[self::READGROUP] = ['short' => __('See group ticket'),
                                       'long'  => __('See tickets created by my groups')];
      if ($interface == 'central') {
         $values[self::READALL]        = __('See all tickets');
                                                //TRANS: short for : See assigned tickets (group associated)
         $values[self::READASSIGN]     = ['short' => __('See assigned'),
                                               'long'  => __('See assigned tickets')];
                                               //TRANS: short for : Assign a ticket
         $values[self::ASSIGN]         = ['short' => __('Assign'),
                                               'long'  => __('Assign a ticket')];
                                               //TRANS: short for : Steal a ticket
         $values[self::STEAL]          = ['short' => __('Steal'),
                                               'long'  => __('Steal a ticket')];
                                               //TRANS: short for : To be in charge of a ticket
         $values[self::OWN]            = ['short' => __('Beeing in charge'),
                                               'long'  => __('To be in charge of a ticket')];
         $values[self::CHANGEPRIORITY] = __('Change the priority');
         $values[self::SURVEY]         = ['short' => __('Approve solution/Reply survey (my ticket)'),
                                               'long'  => __('Approve solution and reply to survey for ticket created by me')];
      }
      if ($interface == 'helpdesk') {
         unset($values[UPDATE], $values[DELETE], $values[PURGE]);
      }
      return $values;
   }

   /**
    * Convert img of the collector for ticket
    *
    * @since 0.85
    *
    * @param string $html  html content of input
    * @param array  $files filenames
    * @param array  $tags  image tags
    *
    * @return string html content
   **/
   static function convertContentForTicket($html, $files, $tags) {

      preg_match_all("/src\s*=\s*['|\"](.+?)['|\"]/", $html, $matches, PREG_PATTERN_ORDER);
      if (isset($matches[1]) && count($matches[1])) {
         // Get all image src

         foreach ($matches[1] as $src) {
            // Set tag if image matches
            foreach ($files as $data => $filename) {
               if (preg_match("/".$data."/i", $src)) {
                  $html = preg_replace("`<img.*src=['|\"]".$src."['|\"][^>]*\>`", "<p>".Document::getImageTag($tags[$filename])."</p>", $html);
               }
            }
         }
      }

      return $html;

   }


   /**
    * Summary of getTicketActors
    * Get the list of actors for the current ticket
    * will return an assoc array of users_id => array of roles.
    * @return array[] of array[] of users and roles
    * @since 0.90
    * @deprecated 9.4.0
   **/
   function getTicketActors() {
      Toolbox::deprecated('Use getITILActors');
      return $this->getITILActors();
   }


   /**
    * @since 0.90
    *
    * @param $tickets_id
    * @param $action         (default 'add')
   **/
   static function getSplittedSubmitButtonHtml($tickets_id, $action = "add") {

      $locale = _sx('button', 'Add');
      if ($action == 'update') {
         $locale = _x('button', 'Save');
      }
      $ticket       = new self();
      $ticket->getFromDB($tickets_id);
      $all_status   = Ticket::getAllowedStatusArray($ticket->fields['status']);
      $rand = mt_rand();

      $html = "<div class='x-split-button' id='x-split-button'>
               <input type='submit' value='$locale' name='$action' class='x-button x-button-main'>
               <span class='x-button x-button-drop'>&nbsp;</span>
               <ul class='x-button-drop-menu'>";
      foreach ($all_status as $status_key => $status_label) {
         $checked = "";
         if ($status_key == $ticket->fields['status']) {
            $checked = "checked='checked'";
         }
         $html .= "<li data-status='".self::getStatusKey($status_key)."'>";
         $html .= "<input type='radio' id='status_radio_$status_key$rand' name='_status'
                    $checked value='$status_key'>";
         $html .= "<label for='status_radio_$status_key$rand'>";
         $html .= Ticket::getStatusIcon($status_key) . "&nbsp;";
         $html .= $status_label;
         $html .= "</label>";
         $html .= "</li>";
      }
      $html .= "</ul></div>";

      $html.= "<script type='text/javascript'>$(function() {split_button();});</script>";
      return $html;
   }


   /**
    * Get correct Calendar: Entity or Sla
    *
    * @since 0.90.4
    *
   **/
   function getCalendar() {

      if (isset($this->fields['slas_id_ttr']) && $this->fields['slas_id_ttr'] > 0) {
         $slm = new SLM();
         if ($slm->getFromDB($this->fields['slas_id_ttr'])) {
            // not -1: calendar of the entity
            if ($slm->getField('calendars_id') >= 0) {
               return $slm->getField('calendars_id');
            }
         }
      }
      return parent::getCalendar();
   }


   /**
    * Select a field using standard system
    *
    * @since 9.1
    */
   function getValueToSelect($field_id_or_search_options, $name = '', $values = '', $options = []) {
      if (isset($field_id_or_search_options['linkfield'])) {
         switch ($field_id_or_search_options['linkfield']) {
            case 'requesttypes_id':
               if (isset($field_id_or_search_options['joinparams']) && Toolbox::in_array_recursive('glpi_itilfollowups', $field_id_or_search_options['joinparams'])) {
                  $opt = ['is_itilfollowup' => 1];
               } else {
                  $opt = ['is_ticketheader' => 1];
               }
               if ($field_id_or_search_options['linkfield']  == $name) {
                  $opt['is_active'] = 1;
               }
               if (isset( $options['condition'] )) {
                  if (!is_array($options['condition'])) {
                     $options['condition'] = [$options['condition']];
                  }
                  $opt = array_merge($opt, $options['condition']);
               }
               $options['condition'] = $opt;
               break;
         }
      }
      return parent::getValueToSelect($field_id_or_search_options, $name, $values, $options);
   }

   function showStatsDates() {
      $now                      = time();
      $date_creation            = strtotime($this->fields['date']);
      $date_takeintoaccount     = $date_creation + $this->fields['takeintoaccount_delay_stat'];
      if ($date_takeintoaccount == $date_creation) {
         $date_takeintoaccount  = 0;
      }
      $internal_time_to_own     = strtotime($this->fields['internal_time_to_own']);
      $time_to_own              = strtotime($this->fields['time_to_own']);
      $internal_time_to_resolve = strtotime($this->fields['internal_time_to_resolve']);
      $time_to_resolve          = strtotime($this->fields['time_to_resolve']);
      $solvedate                = strtotime($this->fields['solvedate']);
      $closedate                = strtotime($this->fields['closedate']);
      $goal_takeintoaccount     = ($date_takeintoaccount > 0 ? $date_takeintoaccount : $now);
      $goal_solvedate           = ($solvedate > 0 ? $solvedate : $now);

      $sla = new SLA;
      $ola = new OLA;
      $sla_tto_link =
      $sla_ttr_link =
      $ola_tto_link =
      $ola_ttr_link = "";

      if ($sla->getFromDB($this->fields['slas_id_tto'])) {
         $sla_tto_link = "<a href='".$sla->getLinkURL()."'>
                          <i class='far fa-clock slt' title='".$sla->getName()."'></i></a>";
      }
      if ($sla->getFromDB($this->fields['slas_id_ttr'])) {
         $sla_ttr_link = "<a href='".$sla->getLinkURL()."'>
                          <i class='far fa-clock slt' title='".$sla->getName()."'></i></a>";
      }
      if ($ola->getFromDB($this->fields['olas_id_tto'])) {
         $ola_tto_link = "<a href='".$ola->getLinkURL()."'>
                          <i class='far fa-clock slt' title='".$ola->getName()."'></i></a>";
      }
      if ($ola->getFromDB($this->fields['olas_id_ttr'])) {
         $ola_ttr_link = "<a href='".$ola->getLinkURL()."'>
                          <i class='far fa-clock slt' title='".$ola->getName()."'></i></a>";
      }

      $dates = [
         $date_creation.'_date_creation' => [
            'timestamp' => $date_creation,
            'label'     => __('Opening date'),
            'class'     => 'creation'
         ],
         $date_takeintoaccount.'_date_takeintoaccount' => [
            'timestamp' => $date_takeintoaccount,
            'label'     => __('Take into account'),
            'class'     => 'checked'
         ],
         $internal_time_to_own.'_internal_time_to_own' => [
            'timestamp' => $internal_time_to_own,
            'label'     => __('Internal time to own')." ".$ola_tto_link,
            'class'     => ($internal_time_to_own < $goal_takeintoaccount
                               ? 'passed' : '')." ".
                           ($date_takeintoaccount != ''
                               ? 'checked' : ''),
         ],
         $time_to_own.'_time_to_own' => [
            'timestamp' => $time_to_own,
            'label'     => __('Time to own')." ".$sla_tto_link,
            'class'     => ($time_to_own < $goal_takeintoaccount
                               ? 'passed' : '')." ".
                           ($date_takeintoaccount != ''
                               ? 'checked' : ''),
         ],
         $internal_time_to_resolve.'_internal_time_to_resolve' => [
            'timestamp' => $internal_time_to_resolve,
            'label'     => __('Internal time to resolve')." ".$ola_ttr_link,
            'class'     => ($internal_time_to_resolve < $goal_solvedate
                               ? 'passed' : '')." ".
                           ($solvedate != ''
                               ? 'checked' : '')
         ],
         $time_to_resolve.'_time_to_resolve' => [
            'timestamp' => $time_to_resolve,
            'label'     => __('Time to resolve')." ".$sla_ttr_link,
            'class'     => ($time_to_resolve < $goal_solvedate
                               ? 'passed' : '')." ".
                           ($solvedate != ''
                               ? 'checked' : '')
         ],
         $solvedate.'_solvedate' => [
            'timestamp' => $solvedate,
            'label'     => __('Resolution date'),
            'class'     => 'checked'
         ],
         $closedate.'_closedate' => [
            'timestamp' => $closedate,
            'label'     => __('Closing date'),
            'class'     => 'end'
         ]
      ];

      Html::showDatesTimelineGraph([
         'title'   => _n('Date', 'Dates', Session::getPluralNumber()),
         'dates'   => $dates,
         'add_now' => $this->getField('closedate') == ""
      ]);
   }
}
