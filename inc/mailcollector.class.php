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

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

use LitEmoji\LitEmoji;

/**
 * MailCollector class
 *
 * Merge with collect GLPI system after big modification in it
 *
 * modif and debug by  INDEPNET Development Team.
 * Original class ReceiveMail 1.0 by Mitul Koradia Created: 01-03-2006
 * Description: Reciving mail With Attechment
 * Email: mitulkoradia@gmail.com
**/
class MailCollector  extends CommonDBTM {

   // Specific one
   /// working charset of the mail
   public $charset         = "";
   /// IMAP / POP connection
   public $marubox         = '';
   /// UID of the current message
   public $uid             = -1;
   /// structure used to store the mail structure
   public $structure       = false;
   /// structure used to store files attached to a mail
   public $files;
   /// structure used to store alt files attached to a mail
   public $altfiles;
   /// Tag used to recognize embedded images of a mail
   public $tags;
   /// Message to add to body to build ticket
   public $addtobody;
   /// Number of fetched emails
   public $fetch_emails    = 0;
   /// Maximum number of emails to fetch : default to 10
   public $maxfetch_emails = 10;
   /// array of indexes -> uid for messages
   public $messages_uid    = [];
   /// Max size for attached files
   public $filesize_max    = 0;
   /// Body converted
   public $body_converted  = false;

   /**
    * Flag that tells wheter the body is in HTML format or not.
    * @var string
    */
   private $body_is_html   = false;

   public $dohistory       = true;

   static $rightname       = 'config';

   // Destination folder
   const REFUSED_FOLDER  = 'refused';
   const ACCEPTED_FOLDER = 'accepted';

   // Values for requester_field
   const REQUESTER_FIELD_FROM = 0;
   const REQUESTER_FIELD_REPLY_TO = 1;

   static function getTypeName($nb = 0) {
      return _n('Receiver', 'Receivers', $nb);
   }


   static function canCreate() {
      return static::canUpdate();
   }


   /**
    * @since 0.85
   **/
   static function canPurge() {
      return static::canUpdate();
   }


   /**
    * @see CommonGLPI::getAdditionalMenuOptions()
    *
    * @since 0.85
   **/
   static function getAdditionalMenuOptions() {

      if (static::canView()) {
         $options['options']['notimportedemail']['links']['search']
                                          = '/front/notimportedemail.php';
         return $options;
      }
      return false;
   }


   function post_getEmpty() {
      global $CFG_GLPI;

      $this->fields['filesize_max'] = $CFG_GLPI['default_mailcollector_filesize_max'];
      $this->fields['is_active']    = 1;
   }


   /**
    * @see CommonDBTM::prepareInputForUpdate()
   **/
   function prepareInputForUpdate($input) {

      if (isset($input["passwd"])) {
         if (empty($input["passwd"])) {
            unset($input["passwd"]);
         } else {
            $input["passwd"] = Toolbox::encrypt(stripslashes($input["passwd"]), GLPIKEY);
         }
      }

      if (isset($input["_blank_passwd"]) && $input["_blank_passwd"]) {
         $input['passwd'] = '';
      }

      if (isset($input['mail_server']) && !empty($input['mail_server'])) {
         $input["host"] = Toolbox::constructMailServerConfig($input);
      }

      if (isset($input['name']) && !NotificationMailing::isUserAddressValid($input['name'])) {
         Session::addMessageAfterRedirect(__('Invalid email address'), false, ERROR);
      }

      return $input;
   }


   /**
    * @see CommonDBTM::prepareInputForAdd()
   **/
   function prepareInputForAdd($input) {

      if (isset($input["passwd"])) {
         if (empty($input["passwd"])) {
            unset($input["passwd"]);
         } else {
            $input["passwd"] = Toolbox::encrypt(stripslashes($input["passwd"]), GLPIKEY);
         }
      }

      if (isset($input['mail_server']) && !empty($input['mail_server'])) {
         $input["host"] = Toolbox::constructMailServerConfig($input);
      }

      if (!NotificationMailing::isUserAddressValid($input['name'])) {
         Session::addMessageAfterRedirect(__('Invalid email address'), false, ERROR);
      }

      return $input;
   }


   /**
    * @see CommonGLPI::defineTabs()
   **/
   function defineTabs($options = []) {

      $ong = [];
      $this->addDefaultFormTab($ong);
      $this->addStandardTab(__CLASS__, $ong, $options);
      $this->addStandardTab('Log', $ong, $options);

      return $ong;
   }


   /**
    * @see CommonGLPI::getTabNameForItem()
   **/
   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {

      if (!$withtemplate) {
         switch ($item->getType()) {
            case __CLASS__ :
               return _n('Action', 'Actions', Session::getPluralNumber());
         }
      }
      return '';
   }


   /**
    * @param $item         CommonGLPI object
    * @param $tabnum       (default 1
    * @param $withtemplate (default 0)
   **/
   static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
      global $CFG_GLPI;

      if ($item->getType() == __CLASS__) {
         $item->showGetMessageForm($item->getID());
      }
      return true;
   }


   /**
    * Print the mailgate form
    *
    * @param $ID        integer  Id of the item to print
    * @param $options   array
    *     - target filename : where to go when done.
    *
    * @return boolean item found
   **/
   function showForm($ID, $options = []) {
      global $CFG_GLPI;

      $this->initForm($ID, $options);
      $options['colspan'] = 1;
      $this->showFormHeader($options);

      echo "<tr class='tab_bg_1'><td>".sprintf(__('%1$s (%2$s)'), __('Name'), __('Email address')).
           "</td><td>";
      Html::autocompletionTextField($this, "name");
      echo "</td></tr>";

      if ($this->fields['errors']) {
         echo "<tr class='tab_bg_1_2'><td>".__('Connection errors')."</td>";
         echo "<td>".$this->fields['errors']."</td>";
         echo "</tr>";
      }

      echo "<tr class='tab_bg_1'><td>".__('Active')."</td><td>";
      Dropdown::showYesNo("is_active", $this->fields["is_active"]);
      echo "</td></tr>";

      $type = Toolbox::showMailServerConfig($this->fields["host"]);

      echo "<tr class='tab_bg_1'><td>".__('Login')."</td><td>";
      Html::autocompletionTextField($this, "login");
      echo "</td></tr>";

      echo "<tr class='tab_bg_1'><td>".__('Password')."</td>";
      echo "<td><input type='password' name='passwd' value='' size='20' autocomplete='off'>";
      if ($ID > 0) {
         echo "<input type='checkbox' name='_blank_passwd'>&nbsp;".__('Clear');
      }
      echo "</td></tr>";

      echo "<tr class='tab_bg_1'><td>" . __('Use Kerberos authentication') . "</td>";
      echo "<td>";
      Dropdown::showYesNo("use_kerberos", $this->fields["use_kerberos"]);
      echo "</td></tr>\n";

      if ($type != "pop") {
         echo "<tr class='tab_bg_1'><td>" . __('Accepted mail archive folder (optional)') . "</td>";
         echo "<td>";
         echo "<input size='30' type='text' id='accepted_folder' name='accepted' value=\"".$this->fields['accepted']."\">";
         echo "<i class='fa fa-list pointer get-imap-folder'></i>";
         echo "</td></tr>\n";

         echo "<tr class='tab_bg_1'><td>" . __('Refused mail archive folder (optional)') . "</td>";
         echo "<td>";
         echo "<input size='30' type='text' id='refused_folder' name='refused' value=\"".$this->fields['refused']."\">";
         echo "<i class='fa fa-list pointer get-imap-folder'></i>";
         echo "</td></tr>\n";
      }

      echo "<tr class='tab_bg_1'>";
      echo "<td width='200px'> ". __('Maximum size of each file imported by the mails receiver').
           "</td><td>";
      self::showMaxFilesize('filesize_max', $this->fields["filesize_max"]);
      echo "</td></tr>";

      echo "<tr class='tab_bg_1'><td>" . __('Use mail date, instead of collect one') . "</td>";
      echo "<td>";
      Dropdown::showYesNo("use_mail_date", $this->fields["use_mail_date"]);
      echo "</td></tr>\n";

      echo "<tr class='tab_bg_1'><td>" . __('Use Reply-To as requester (when available)') . "</td>";
      echo "<td>";
      Dropdown::showFromArray("requester_field", [
         self::REQUESTER_FIELD_FROM => __('No'),
         self::REQUESTER_FIELD_REPLY_TO => __('Yes')
      ], ["value" => $this->fields['requester_field']]);
      echo "</td></tr>\n";

      echo "<tr class='tab_bg_1'><td>".__('Comments')."</td>";
      echo "<td><textarea cols='45' rows='5' name='comment' >".$this->fields["comment"]."</textarea>";

      if ($ID > 0) {
         echo "<br>";
         //TRANS: %s is the datetime of update
         printf(__('Last update on %s'), Html::convDateTime($this->fields["date_mod"]));
      }
      echo "</td></tr>";

      $this->showFormButtons($options);

      echo "<div id='imap-folder'></div>";
      echo Html::scriptBlock("$(function() {
         $('#imap-folder')
            .dialog(options = {
               autoOpen: false,
               autoResize:true,
               width: 'auto',
               modal: true,
            });

         $(document).on('click', '.get-imap-folder', function() {
            var input = $(this).prev('input');

            var data = 'action=getFoldersList';
            data += '&input_id=' + input.attr('id');
            // Get form values without server_mailbox value to prevent filtering
            data += '&' + $(this).closest('form').find(':not([name=\"server_mailbox\"])').serialize();
            // Force empty value for server_mailbox
            data += '&server_mailbox=';

            $('#imap-folder')
               .html('')
               .load('".$CFG_GLPI['root_doc']."/ajax/mailcollector.php', data)
               .dialog('open');
         });

         $(document).on('click', '.select_folder li', function() {
            var li       = $(this);
            var input_id = li.data('input-id');
            var folder   = li.children('.folder-name').html();

            $('#'+input_id).val(folder);
            $('#imap-folder').dialog('close');
         })
      });");
      return true;
   }

   /**
    * Display the list of folder for current connections
    *
    * @since  9.3.1
    *
    * @param  string $input_id dom id where to insert folder name
    * @return nothing (display)
    */
   function displayFoldersList($input_id = "") {
      $this->connect();

      if (!is_resource($this->marubox)) {
         echo __('Connection errors');

         return false;
      }

      $folders = imap_list($this->marubox, $this->fields['host'], '*');
      if (is_array($folders)) {
         echo "<ul class='select_folder'>";
         foreach ($folders as $folder) {
            if (preg_match("/}/i", $folder)) {
               $arr = explode('}', $folder);
            }
            if (preg_match("/]/i", $folder)) {
               $arr = explode(']/', $folder);
            }
            $folder = trim(stripslashes($arr[1]));
            echo "<li class='pointer' data-input-id='$input_id'>
                     <i class='fa fa-folder'></i>&nbsp;
                     <span class='folder-name'>".imap_mutf7_to_utf8($folder)."</span>
                  </li>";
         }
         echo "</ul>";
      } else if (!empty($this->fields['server_mailbox'])) {
         echo "<ul class='select_folder'>";
         echo "<li>";
         echo sprintf(
            __("No child found for folder '%s'."),
            Html::entities_deep($this->fields['server_mailbox'])
         );
         echo "</li>";
         echo "</ul>";
      }
   }


   /**
    * @param $ID
   **/
   function showGetMessageForm($ID) {

      echo "<br><br><div class='center'>";
      echo "<form name='form' method='post' action='".Toolbox::getItemTypeFormURL(__CLASS__)."'>";
      echo "<table class='tab_cadre'>";
      echo "<tr class='tab_bg_2'><td class='center'>";
      echo "<input type='submit' name='get_mails' value=\""._sx('button', 'Get email tickets now').
             "\" class='submit'>";
      echo "<input type='hidden' name='id' value='$ID'>";
      echo "</td></tr>";
      echo "</table>";
      Html::closeForm();
      echo "</div>";
   }


   function rawSearchOptions() {
      $tab = [];

      $tab[] = [
         'id'                 => 'common',
         'name'               => __('Characteristics')
      ];

      $tab[] = [
         'id'                 => '1',
         'table'              => $this->getTable(),
         'field'              => 'name',
         'name'               => __('Name'),
         'datatype'           => 'itemlink',
         'massiveaction'      => false
      ];

      $tab[] = [
         'id'                 => '2',
         'table'              => $this->getTable(),
         'field'              => 'is_active',
         'name'               => __('Active'),
         'datatype'           => 'bool'
      ];

      $tab[] = [
         'id'                 => '3',
         'table'              => $this->getTable(),
         'field'              => 'host',
         'name'               => __('Connection string'),
         'massiveaction'      => false,
         'datatype'           => 'string'
      ];

      $tab[] = [
         'id'                 => '4',
         'table'              => $this->getTable(),
         'field'              => 'login',
         'name'               => __('Login'),
         'massiveaction'      => false,
         'datatype'           => 'string'
      ];

      $tab[] = [
         'id'                 => '5',
         'table'              => $this->getTable(),
         'field'              => 'filesize_max',
         'name'               => __('Maximum size of each file imported by the mails receiver'),
         'datatype'           => 'integer'
      ];

      $tab[] = [
         'id'                 => '16',
         'table'              => $this->getTable(),
         'field'              => 'comment',
         'name'               => __('Comments'),
         'datatype'           => 'text'
      ];

      $tab[] = [
         'id'                 => '19',
         'table'              => $this->getTable(),
         'field'              => 'date_mod',
         'name'               => __('Last update'),
         'datatype'           => 'datetime',
         'massiveaction'      => false
      ];

      $tab[] = [
         'id'                 => '20',
         'table'              => $this->getTable(),
         'field'              => 'accepted',
         'name'               => __('Accepted mail archive folder (optional)'),
         'datatype'           => 'string'
      ];

      $tab[] = [
         'id'                 => '21',
         'table'              => $this->getTable(),
         'field'              => 'refused',
         'name'               => __('Refused mail archive folder (optional)'),
         'datatype'           => 'string'
      ];

      $tab[] = [
         'id'                 => '22',
         'table'              => $this->getTable(),
         'field'              => 'errors',
         'name'               => __('Connection errors'),
         'datatype'           => 'integer'
      ];

      return $tab;
   }


   /**
    * @param $emails_ids   array
    * @param $action                (default 0)
    * @param $entity                (default 0)
   **/
   function deleteOrImportSeveralEmails($emails_ids = [], $action = 0, $entity = 0) {
      global $DB;

      $query = [
         'FROM'   => NotImportedEmail::getTable(),
         'WHERE'  => [
            'id' => $emails_ids,
         ],
         'ORDER'  => 'mailcollectors_id'
      ];

      $todelete = [];
      foreach ($DB->request($query) as $data) {
         $todelete[$data['mailcollectors_id']][$data['messageid']] = $data;
      }

      foreach ($todelete as $mailcollector_id => $rejected) {
         $collector = new self();
         if ($collector->getFromDB($mailcollector_id)) {
            // Use refused folder in connection string
            $connect_config = Toolbox::parseMailServerConnectString($collector->fields['host']);
            $collector->fields['host'] = Toolbox::constructMailServerConfig(
               [
                  'mail_server'   => $connect_config['address'],
                  'server_port'   => $connect_config['port'],
                  'server_type'   => !empty($connect_config['type']) ? '/' . $connect_config['type'] : '',
                  'server_ssl'    => $connect_config['ssl'] ? '/ssl' : '',
                  'server_cert'   => $connect_config['validate-cert'] ? '/validate-cert' : '/novalidate-cert',
                  'server_tls'    => $connect_config['tls'] ? '/tls' : '',
                  'server_rsh'    => $connect_config['norsh'] ? '/norsh' : '',
                  'server_secure' => $connect_config['secure'] ? '/secure' : '',
                  'server_debug'  => $connect_config['debug'] ? '/debug' : '',

                  'server_mailbox' => $collector->fields[self::REFUSED_FOLDER],
               ]
            );

            $collector->uid          = -1;
            $collector->fetch_emails = 0;
            //Connect to the Mail Box
            $collector->connect();
            // Get Total Number of Unread Email in mail box
            $tot = $collector->getTotalMails(); //Total Mails in Inbox Return integer value

            for ($i=1; $i<=$tot; $i++) {
               $uid = imap_uid($collector->marubox, $i);
               $head = $collector->getHeaders($uid);
               if (isset($rejected[$head['message_id']])) {
                  if ($action == 1) {
                     $tkt = [];
                     $tkt = $collector->buildTicket($uid, ['mailgates_id' => $mailcollector_id,
                                                           'play_rules'   => false]);
                     $tkt['_users_id_requester'] = $rejected[$head['message_id']]['users_id'];
                     $tkt['entities_id']         = $entity;

                     if (!isset($tkt['tickets_id'])) {
                        // New ticket case
                        $ticket = new Ticket();
                        $ticket->add($tkt);
                     } else {
                        // Followup case
                        $fup = new ITILFollowup();

                        $fup_input = $tkt;
                        $fup_input['itemtype'] = Ticket::class;
                        $fup_input['items_id'] = $fup_input['tickets_id'];

                        $fup->add($fup_input);
                     }

                     $folder = self::ACCEPTED_FOLDER;
                  } else {
                     $folder = self::REFUSED_FOLDER;
                  }
                  //Delete email
                  if ($collector->deleteMails($uid, $folder)) {
                     $rejectedmail = new NotImportedEmail();
                     $rejectedmail->delete(['id' => $rejected[$head['message_id']]['id']]);
                  }
                  // Unset managed
                  unset($rejected[$head['message_id']]);
               }
            }

            // Email not present in mailbox
            if (count($rejected)) {
               $clean = ['<' => '',
                              '>' => ''];
               foreach ($rejected as $id => $data) {
                  if ($action == 1) {
                     Session::addMessageAfterRedirect(sprintf(__('Email %s not found. Impossible import.'),
                                                              strtr($id, $clean)),
                                                      false, ERROR);
                  } else { // Delete data in notimportedemail table
                     $rejectedmail = new NotImportedEmail();
                     $rejectedmail->delete(['id' => $data['id']]);
                  }
               }
            }
            imap_expunge($collector->marubox);
            $collector->close_mailbox();
         }
      }
   }


   /**
    * Constructor
    *
    * @param $mailgateID   ID of the mailgate
    * @param $display      display messages in MessageAfterRedirect or just return error (default 0=)
    *
    * @return if $display = false return messages result string
   **/
   function collect($mailgateID, $display = 0) {
      global $CFG_GLPI;

      if ($this->getFromDB($mailgateID)) {
         $this->uid          = -1;
         $this->fetch_emails = 0;
         //Connect to the Mail Box
         $this->connect();
         $rejected = new NotImportedEmail();

         // Clean from previous collect (from GUI, cron already truncate the table)
         $rejected->deleteByCriteria(['mailcollectors_id' => $this->fields['id']]);

         if ($this->marubox) {
            // Get Total Number of Unread Email in mail box
            $tot         = $this->getTotalMails(); //Total Mails in Inbox Return integer value
            $error       = 0;
            $refused     = 0;
            $blacklisted = 0;

            //get messages id
            for ($i=1; ($i <= $tot); $i++) {
               $this->messages_uid[$i] = imap_uid($this->marubox, $i);
            }

            for ($i=1; ($i <= $tot) && ($this->fetch_emails < $this->maxfetch_emails); $i++) {
               $uid = $this->messages_uid[$i];
               $tkt = $this->buildTicket($uid, ['mailgates_id' => $mailgateID,
                                                'play_rules'   => true]);

               $rejinput                      = [];
               $rejinput['mailcollectors_id'] = $mailgateID;
               if (!$tkt['_blacklisted']) {
                  $rejinput['from']              = $tkt['_head'][$this->getRequesterField()];
                  $rejinput['to']                = $tkt['_head']['to'];
                  $rejinput['users_id']          = $tkt['_users_id_requester'];
                  $rejinput['subject']           = $this->textCleaner($tkt['_head']['subject']);
                  $rejinput['messageid']         = $tkt['_head']['message_id'];
               }
               $rejinput['date']              = $_SESSION["glpi_currenttime"];

               $is_user_anonymous = !(isset($tkt['_users_id_requester'])
                                      && ($tkt['_users_id_requester'] > 0));
               $is_supplier_anonymous = !(isset($tkt['_supplier_email'])
                                          && $tkt['_supplier_email']);

               if (isset($tkt['_blacklisted']) && $tkt['_blacklisted']) {
                  $this->deleteMails($uid, self::REFUSED_FOLDER);
                  $blacklisted++;
               } else if (isset($tkt['_refuse_email_with_response'])) {
                  $this->deleteMails($uid, self::REFUSED_FOLDER);
                  $refused++;
                  $this->sendMailRefusedResponse($tkt['_head'][$this->getRequesterField()], $tkt['name']);

               } else if (isset($tkt['_refuse_email_no_response'])) {
                  $this->deleteMails($uid, self::REFUSED_FOLDER);
                  $refused++;

               } else if (isset($tkt['entities_id'])
                          && !isset($tkt['tickets_id'])
                          && ($CFG_GLPI["use_anonymous_helpdesk"]
                              || !$is_user_anonymous
                              || !$is_supplier_anonymous)) {

                  // New ticket case
                  $ticket = new Ticket();

                  if (!$CFG_GLPI["use_anonymous_helpdesk"]
                      && !Profile::haveUserRight($tkt['_users_id_requester'],
                                                 Ticket::$rightname,
                                                 CREATE,
                                                 $tkt['entities_id'])) {
                     $this->deleteMails($uid, self::REFUSED_FOLDER);
                     $refused++;
                     $rejinput['reason'] = NotImportedEmail::NOT_ENOUGH_RIGHTS;
                     $rejected->add($rejinput);
                  } else if ($ticket->add($tkt)) {
                     $this->deleteMails($uid, self::ACCEPTED_FOLDER);
                  } else {
                     $error++;
                     $rejinput['reason'] = NotImportedEmail::FAILED_OPERATION;
                     $rejected->add($rejinput);
                  }

               } else if (isset($tkt['tickets_id'])
                          && ($CFG_GLPI['use_anonymous_followups'] || !$is_user_anonymous)) {

                  // Followup case
                  $ticket = new Ticket();
                  $fup = new ITILFollowup();

                  $fup_input = $tkt;
                  $fup_input['itemtype'] = Ticket::class;
                  $fup_input['items_id'] = $fup_input['tickets_id'];
                  unset($fup_input['tickets_id']);

                  if (!$ticket->getFromDB($tkt['tickets_id'])) {
                     $error++;
                     $rejinput['reason'] = NotImportedEmail::FAILED_OPERATION;
                     $rejected->add($rejinput);
                  } else if (!$CFG_GLPI['use_anonymous_followups']
                             && !$ticket->canUserAddFollowups($tkt['_users_id_requester'])) {
                     $this->deleteMails($uid, self::REFUSED_FOLDER);
                     $refused++;
                     $rejinput['reason'] = NotImportedEmail::NOT_ENOUGH_RIGHTS;
                     $rejected->add($rejinput);
                  } else if ($fup->add($fup_input)) {
                     $this->deleteMails($uid, self::ACCEPTED_FOLDER);
                  } else {
                     $error++;
                     $rejinput['reason'] = NotImportedEmail::FAILED_OPERATION;
                     $rejected->add($rejinput);
                  }

               } else {
                  if (!$tkt['_users_id_requester']) {
                     $rejinput['reason'] = NotImportedEmail::USER_UNKNOWN;

                  } else {
                     $rejinput['reason'] = NotImportedEmail::MATCH_NO_RULE;
                  }
                  $refused++;
                  $rejected->add($rejinput);
                  $this->deleteMails($uid, self::REFUSED_FOLDER);
               }
               $this->fetch_emails++;
            }
            imap_expunge($this->marubox);
            $this->close_mailbox();   //Close Mail Box

            //TRANS: %1$d, %2$d, %3$d, %4$d and %5$d are number of messages
            $msg = sprintf(__('Number of messages: available=%1$d, retrieved=%2$d, refused=%3$d, errors=%4$d, blacklisted=%5$d'),
                           $tot, $this->fetch_emails, $refused, $error, $blacklisted);
            if ($display) {
               Session::addMessageAfterRedirect($msg, false, ($error ? ERROR : INFO));
            } else {
               return $msg;
            }

         } else {
            $msg = __('Could not connect to mailgate server');
            if ($display) {
               Session::addMessageAfterRedirect($msg, false, ERROR);
               GlpiNetwork::addErrorMessageAfterRedirect();
            } else {
               return $msg;
            }
         }

      } else {
         //TRANS: %s is the ID of the mailgate
         $msg = sprintf(__('Could not find mailgate %d'), $mailgateID);
         if ($display) {
            Session::addMessageAfterRedirect($msg, false, ERROR);
            GlpiNetwork::addErrorMessageAfterRedirect();
         } else {
            return $msg;
         }
      }
   }


   /** function buildTicket - Builds,and returns, the major structure of the ticket to be entered.
    *
    * @param @param $uid UID of the message
    * @param $options   array    of possible options
    *
    * @return ticket fields array
    */
   function buildTicket($uid, $options = []) {
      global $CFG_GLPI;

      $play_rules = (isset($options['play_rules']) && $options['play_rules']);
      $head       = $this->getHeaders($uid); // Get Header Info Return Array Of Headers
                                           // **Key Are (subject,to,toOth,toNameOth,from,fromName)
      $tkt                 = [];
      $tkt['_blacklisted'] = false;
      // For RuleTickets
      $tkt['_mailgate']    = $options['mailgates_id'];

      // Use mail date if it's defined
      if ($this->fields['use_mail_date'] && isset($head['date'])) {
         $tkt['date'] = $head['date'];
      }
      // Detect if it is a mail reply
      $glpi_message_match = "/GLPI-([0-9]+)\.[0-9]+\.[0-9]+@\w*/";

      // Check if email not send by GLPI : if yes -> blacklist
      if (!isset($head['message_id'])
          || preg_match($glpi_message_match, $head['message_id'], $match)) {
         $tkt['_blacklisted'] = true;
         return $tkt;
      }
      // manage blacklist
      $blacklisted_emails   = Blacklist::getEmails();
      // Add name of the mailcollector as blacklisted
      $blacklisted_emails[] = $this->fields['name'];
      if (Toolbox::inArrayCaseCompare($head['from'], $blacklisted_emails)) {
         $tkt['_blacklisted'] = true;
         return $tkt;
      }

      // max size = 0 : no import attachments
      if ($this->fields['filesize_max'] > 0) {
         if (is_writable(GLPI_TMP_DIR)) {
            $tkt['_filename'] = $this->getAttached($uid, GLPI_TMP_DIR."/", $this->fields['filesize_max']);
            $tkt['_tag']      = $this->tags;
         } else {
            //TRANS: %s is a directory
            Toolbox::logInFile('mailgate', sprintf(__('%s is not writable'), GLPI_TMP_DIR."/"));
         }
      }

      //  Who is the user ?
      $tkt['_users_id_requester']                              = User::getOrImportByEmail($head[$this->getRequesterField()]);
      $tkt["_users_id_requester_notif"]['use_notification'][0] = 1;
      // Set alternative email if user not found / used if anonymous mail creation is enable
      if (!$tkt['_users_id_requester']) {
         $tkt["_users_id_requester_notif"]['alternative_email'][0] = $head[$this->getRequesterField()];
      }

      // Fix author of attachment
      // Move requester to author of followup
      $tkt['users_id'] = $tkt['_users_id_requester'];

      // Add to and cc as additional observer if user found
      if (count($head['ccs'])) {
         foreach ($head['ccs'] as $cc) {
            if (($cc != $head[$this->getRequesterField()])
                && !Toolbox::inArrayCaseCompare($cc, $blacklisted_emails) // not blacklisted emails
                && (($tmp = User::getOrImportByEmail($cc)) > 0)) {
               $nb = (isset($tkt['_users_id_observer']) ? count($tkt['_users_id_observer']) : 0);
               $tkt['_users_id_observer'][$nb] = $tmp;
               $tkt['_users_id_observer_notif']['use_notification'][$nb] = 1;
               $tkt['_users_id_observer_notif']['alternative_email'][$nb] = $cc;
            }
         }
      }

      if (count($head['tos'])) {
         foreach ($head['tos'] as $to) {
            if (($to != $head[$this->getRequesterField()])
                && !Toolbox::inArrayCaseCompare($to, $blacklisted_emails) // not blacklisted emails
                && (($tmp = User::getOrImportByEmail($to)) > 0)) {
                   $nb = (isset($tkt['_users_id_observer']) ? count($tkt['_users_id_observer']) : 0);
                   $tkt['_users_id_observer'][$nb] = $tmp;
                   $tkt['_users_id_observer_notif']['use_notification'][$nb] = 1;
                   $tkt['_users_id_observer_notif']['alternative_email'][$nb] = $to;
            }
         }
      }

      // Auto_import
      $tkt['_auto_import']           = 1;
      // For followup : do not check users_id = login user
      $tkt['_do_not_check_users_id'] = 1;
      $body                          = $this->getBody($uid);

      // Do it before using charset variable
      $head['subject']               = $this->decodeMimeString($head['subject']);
      $tkt['_head']                  = $head;

      if (!empty($this->charset)
          && !$this->body_converted
          && mb_detect_encoding($body) != 'UTF-8') {
         $body                 = Toolbox::encodeInUtf8($body, $this->charset);
         $this->body_converted = true;
      }

      if (!Toolbox::seems_utf8($body)) {
         $tkt['content'] = Toolbox::encodeInUtf8($body);
      } else {
         $tkt['content'] = $body;
      }

      // prepare match to find ticket id in headers
      // pattern: GLPI-{itemtype}-{items_id}
      // ex: GLPI-Ticket-26739
      $ref_match = "/GLPI-[A-Z]\w+-([0-9]+)/";

      // See In-Reply-To field
      if (isset($head['in_reply_to'])) {
         if (preg_match($ref_match, $head['in_reply_to'], $match)) {
            $tkt['tickets_id'] = intval($match[1]);
         }
      }

      // See in References
      if (!isset($tkt['tickets_id'])
          && isset($head['references'])) {
         if (preg_match($ref_match, $head['references'], $match)) {
            $tkt['tickets_id'] = intval($match[1]);
         }
      }

      // See in title
      if (!isset($tkt['tickets_id'])
          && preg_match('/\[.+#(\d+)\]/', $head['subject'], $match)) {
         $tkt['tickets_id'] = intval($match[1]);
      }

      $tkt['_supplier_email'] = false;
      // Found ticket link
      if (isset($tkt['tickets_id'])) {
         // it's a reply to a previous ticket
         $job = new Ticket();
         $tu  = new Ticket_User();
         $st  = new Supplier_Ticket();

         // Check if ticket  exists and users_id exists in GLPI
         if ($job->getFromDB($tkt['tickets_id'])
             && ($job->fields['status'] != CommonITILObject::CLOSED)
             && ($CFG_GLPI['use_anonymous_followups']
                 || ($tkt['_users_id_requester'] > 0)
                 || $tu->isAlternateEmailForITILObject($tkt['tickets_id'], $head[$this->getRequesterField()])
                 || ($tkt['_supplier_email'] = $st->isSupplierEmail($tkt['tickets_id'],
                                                                    $head[$this->getRequesterField()])))) {

            if ($tkt['_supplier_email']) {
               $tkt['content'] = sprintf(__('From %s'), $head[$this->getRequesterField()])."\n\n".$tkt['content'];
            }

            $header_tag      = NotificationTargetTicket::HEADERTAG;
            $header_pattern  = $header_tag . '.*' . $header_tag;
            $footer_tag      = NotificationTargetTicket::FOOTERTAG;
            $footer_pattern  = $footer_tag . '.*' . $footer_tag;

            $has_header_line = preg_match('/' . $header_pattern . '/s', $tkt['content']);
            $has_footer_line = preg_match('/' . $footer_pattern . '/s', $tkt['content']);

            if ($has_header_line && $has_footer_line) {
               // Strip all contents between header and footer line
               $tkt['content'] = preg_replace(
                  '/' . $header_pattern . '.*' . $footer_pattern . '/s',
                  '',
                  $tkt['content']
               );
            } else if ($has_header_line) {
               // Strip all contents between header line and end of message
               $tkt['content'] = preg_replace(
                  '/' . $header_pattern . '.*$/s',
                  '',
                  $tkt['content']
               );
            } else if ($has_footer_line) {
               // Strip all contents between begin of message and footer line
               $tkt['content'] = preg_replace(
                  '/^.*' . $footer_pattern . '/s',
                  '',
                  $tkt['content']
               );
            }
         } else {
            // => to handle link in Ticket->post_addItem()
            $tkt['_linkedto'] = $tkt['tickets_id'];
            unset($tkt['tickets_id']);
         }
      }

      // Add message from getAttached
      if ($this->addtobody) {
         $tkt['content'] .= $this->addtobody;
      }

      //If files are present and content is html
      if (isset($this->files) && count($this->files) && $this->body_is_html) {
         $tkt['content'] = Ticket::convertContentForTicket($tkt['content'],
                                                           $this->files + $this->altfiles,
                                                           $this->tags);
      }

      // Clean mail content
      $tkt['content'] = $this->cleanMailContent($tkt['content']);

      $tkt['name'] = $this->textCleaner($head['subject']);
      if (!Toolbox::seems_utf8($tkt['name'])) {
         $tkt['name'] = Toolbox::encodeInUtf8($tkt['name']);
      }

      if (!isset($tkt['tickets_id'])) {
         // Which entity ?
         //$tkt['entities_id']=$this->fields['entities_id'];
         //$tkt['Subject']= $head['subject'];   // not use for the moment
         // Medium
         $tkt['urgency']  = "3";
         // No hardware associated
         $tkt['itemtype'] = "";
         // Mail request type

      } else {
         // Reopen if needed
         $tkt['add_reopen'] = 1;
      }

      $tkt['requesttypes_id'] = RequestType::getDefault('mail');

      if ($play_rules) {
         $rule_options['ticket']              = $tkt;
         $rule_options['headers']             = $head;
         $rule_options['mailcollector']       = $options['mailgates_id'];
         $rule_options['_users_id_requester'] = $tkt['_users_id_requester'];
         $rulecollection                      = new RuleMailCollectorCollection();
         $output                              = $rulecollection->processAllRules([], [],
                                                                                 $rule_options);

         // New ticket : compute all
         if (!isset($tkt['tickets_id'])) {
            foreach ($output as $key => $value) {
               $tkt[$key] = $value;
            }

         } else { // Followup only copy refuse data
            $tkt['requesttypes_id'] = RequestType::getDefault('mailfollowup');
            $tobecopied = ['_refuse_email_no_response', '_refuse_email_with_response'];
            foreach ($tobecopied as $val) {
               if (isset($output[$val])) {
                  $tkt[$val] = $output[$val];
               }
            }
         }
      }

      $tkt['content'] = LitEmoji::encodeShortcode($tkt['content']);

      $tkt = Toolbox::addslashes_deep($tkt);
      return $tkt;
   }


   /** Clean mail content : HTML + XSS + blacklisted content
    *
    * @since 0.85
    *
    * @param string $string text to clean
    *
    * @return string cleaned text
   **/
   function cleanMailContent($string) {
      global $DB;

      // Clean HTML
      $string = Html::clean(Html::entities_deep($string), false, 2);

      $br_marker = '==' . mt_rand() . '==';

      // Replace HTML line breaks to marker
      $string = preg_replace('/<br\s*\/?>/', $br_marker, $string);

      // Replace plain text line breaks to marker if content is not html
      // and rich text mode is enabled (otherwise remove them)
      $string = str_replace(
         ["\r\n", "\n", "\r"],
         $this->body_is_html ? '' : $br_marker,
         $string
      );

      // Wrap content for blacklisted items
      $itemstoclean = [];
      foreach ($DB->request('glpi_blacklistedmailcontents') as $data) {
         $toclean = trim($data['content']);
         if (!empty($toclean)) {
            $itemstoclean[] = str_replace(["\r\n", "\n", "\r"], $br_marker, $toclean);
         }
      }
      if (count($itemstoclean)) {
         $string = str_replace($itemstoclean, '', $string);
      }

      $string = str_replace($br_marker, "<br />", $string);

      // Double encoding for > and < char to avoid misinterpretations
      $string = str_replace(['&lt;', '&gt;'], ['&amp;lt;', '&amp;gt;'], $string);

      // Prevent XSS
      $string = Toolbox::clean_cross_side_scripting_deep($string);

      return $string;
   }


   /** function textCleaner - Strip out unwanted/unprintable characters from the subject.
    *
    * @param $text text to clean
    *
    * @return clean text
   **/
   function textCleaner($text) {

      $text = str_replace("=20", "\n", $text);
      $text =  Toolbox::clean_cross_side_scripting_deep($text);
      return $text;
   }


   ///return supported encodings in lowercase.
   function mb_list_lowerencodings() {

      // Encoding not listed
      static $enc = ['gb2312', 'gb18030'];

      if (count($enc) == 2) {
         foreach (mb_list_encodings() as $encoding) {
            $enc[]   = Toolbox::strtolower($encoding);
            $aliases = mb_encoding_aliases($encoding);
            foreach ($aliases as $e) {
               $enc[] = Toolbox::strtolower($e);
            }
         }
      }
      return $enc;
   }


   /**
    * Receive a string with a mail header and returns it decoded to a specified charset.
    * If the charset specified into a piece of text from header
    * isn't supported by "mb", the "fallbackCharset" will be  used to try to decode it.
    *
    * @param $mimeStr         mime     header string
    * @param $inputCharset    input    charset (default 'utf-8')
    * @param $targetCharset   target   charset (default 'utf-8')
    * @param $fallbackCharset charset  used if input charset not supported by mb
    *                                  (default 'iso-8859-1')
    *
    * @return decoded string
   **/
   function decodeMimeString($mimeStr, $inputCharset = 'utf-8', $targetCharset = 'utf-8',
                             $fallbackCharset = 'iso-8859-1') {

      if (function_exists('mb_list_encodings')
          && function_exists('mb_convert_encoding')) {
         $encodings       = $this->mb_list_lowerencodings();
         $inputCharset    = Toolbox::strtolower($inputCharset);
         $targetCharset   = Toolbox::strtolower($targetCharset);
         $fallbackCharset = Toolbox::strtolower($fallbackCharset);
         $decodedStr      = '';
         $mimeStrs        = imap_mime_header_decode($mimeStr);

         for ($n=sizeOf($mimeStrs),$i=0; $i<$n; $i++) {
            $mimeStr          = $mimeStrs[$i];
            $mimeStr->charset = Toolbox::strtolower($mimeStr->charset);

            if ((($mimeStr->charset == 'default') && ($inputCharset == $targetCharset))
                || ($mimeStr->charset == $targetCharset)) {

               $decodedStr .= $mimeStr->text;

            } else {
               if (in_array($mimeStr->charset, $encodings)) {
                  $this->charset = $mimeStr->charset;
               }

               $decodedStr .= mb_convert_encoding($mimeStr->text, $targetCharset,
                                                  (in_array($mimeStr->charset, $encodings)
                                                      ? $mimeStr->charset : $fallbackCharset));
            }
         }
         return $decodedStr;
      }
      return $mimeStr;
   }


   /**
     * Connect to the mail box
   **/
   function connect() {

      if ($this->fields['use_kerberos']) {
         $this->marubox = @imap_open($this->fields['host'], $this->fields['login'],
                                     Toolbox::decrypt($this->fields['passwd'], GLPIKEY),
                                     CL_EXPUNGE, 1);
      } else {
         $try_options = [['DISABLE_AUTHENTICATOR' => 'GSSAPI'],
                              ['DISABLE_AUTHENTICATOR' => 'PLAIN']];
         foreach ($try_options as $option) {
            $this->marubox = @imap_open($this->fields['host'], $this->fields['login'],
                                        Toolbox::decrypt($this->fields['passwd'], GLPIKEY),
                                        CL_EXPUNGE, 1, $option);
            if (is_resource($this->marubox)) {
               break;
            }
         }

      }
      // Reset errors
      if ($this->marubox) {
         // call this to avoid the mailbox is empty error message
         if (imap_num_msg($this->marubox) == 0) {
             $errors = imap_errors();
         }

         if ($this->fields['errors'] > 0) {
            $this->update(['id'     => $this->getID(),
                                'errors' => 0]);
         }
      } else {
            $this->update(['id'     => $this->getID(),
                                'errors' => ($this->fields['errors']+1)]);
      }
   }


   /**
    * get the message structure if not already retrieved
    *
    * @param $mid : Message ID.
   **/
   function getStructure ($uid) {

      if (($uid != $this->uid)
        || !$this->structure) {
         $this->structure = imap_fetchstructure($this->marubox, $uid, FT_UID);

         if ($this->structure) {
            $this->uid = $uid;
         }
      }
   }


   /**
    * @param $uid UID of the message
   **/
   function getAdditionnalHeaders($uid) {

      $head   = [];
      $header = explode("\n", imap_fetchheader($this->marubox, $uid, FT_UID));

      if (is_array($header) && count($header)) {
         foreach ($header as $line) {
            // is line with additional header?
            if (preg_match("/^X-/i", $line)
                || preg_match("/^Auto-Submitted/i", $line)
                || preg_match("/^Received/i", $line)) {
               // separate name and value
               if (preg_match("/^([^:]*): (.*)/i", $line, $arg)) {
                  $key = Toolbox::strtolower($arg[1]);

                  if (!isset($head[$key])) {
                     $head[$key] = '';
                  } else {
                     $head[$key] .= "\n";
                  }

                  $head[$key] .= trim($arg[2]);
               }
            }
         }
      }
      return $head;
   }


   /**
    * This function is use full to Get Header info from particular mail
    *
    * @param $uid UID of the message
    *
    * @return Return Associative array with following keys
    * subject   => Subject of Mail
    * to        => To Address of that mail
    * toOth     => Other To address of mail
    * toNameOth => To Name of Mail
    * from      => From address of mail
    * fromName  => Form Name of Mail
   **/
   function getHeaders($uid) {
      // Get Header info
      //$mail_header  = imap_header($this->marubox, $mid);
      $mail_header = imap_rfc822_parse_headers(imap_fetchheader($this->marubox, $uid, FT_UID));

      $sender       = $mail_header->from[0];
      $to           = $mail_header->to[0];
      $reply_to     = $mail_header->reply_to[0];
      $date         = date("Y-m-d H:i:s", strtotime($mail_header->date));

      $mail_details = [];

      if ((Toolbox::strtolower($sender->mailbox) != 'mailer-daemon')
          && (Toolbox::strtolower($sender->mailbox) != 'postmaster')) {

         // Construct to and cc arrays
         $tos = [];
         $ccs = [];
         if (count($mail_header->to)) {
            foreach ($mail_header->to as $data) {
               $mailto = Toolbox::strtolower($data->mailbox).'@'.$data->host;
               if ($mailto === $this->fields['name']) {
                  $to = $data;
               }
               $tos[] = $mailto;
            }
         }
         if (isset($mail_header->cc) && count($mail_header->cc)) {
            foreach ($mail_header->cc as $data) {
               $ccs[] = Toolbox::strtolower($data->mailbox).'@'.$data->host;
            }
         }

         // secu on subject setting
         if (!isset($mail_header->subject)) {
            $mail_header->subject = '';
         }

         $mail_details = ['from'       => Toolbox::strtolower($sender->mailbox).'@'.$sender->host,
                               'subject'    => $mail_header->subject,
                               'reply-to'   => Toolbox::strtolower($reply_to->mailbox).'@'.$reply_to->host,
                               'to'         => Toolbox::strtolower($to->mailbox).'@'.$to->host,
                               'message_id' => $mail_header->message_id,
                               'tos'        => $tos,
                               'ccs'        => $ccs,
                               'date'       => $date];

         if (isset($mail_header->references)) {
            $mail_details['references'] = $mail_header->references;
         }

         if (isset($mail_header->in_reply_to)) {
            $mail_details['in_reply_to'] = $mail_header->in_reply_to;
         }

         //Add additional headers in X-
         foreach ($this->getAdditionnalHeaders($uid) as $header => $value) {
            $mail_details[$header] = $value;
         }
      }

      return $mail_details;
   }


   /**
    * Get Mime type Internal Private Use
    *
    * @param $structure mail structure
    *
    * @return mime type
   **/
   function get_mime_type(&$structure) {

      // DO NOT REORDER IT
      $primary_mime_type = ["TEXT", "MULTIPART", "MESSAGE", "APPLICATION", "AUDIO",
                                 "IMAGE", "VIDEO", "OTHER"];

      if ($structure->subtype) {
         return $primary_mime_type[intval($structure->type)] . '/' . $structure->subtype;
      }
      return "TEXT/PLAIN";
   }


   /**
    * Get Part Of Message Internal Private Use
    *
    * @param $stream       An IMAP stream returned by imap_open
    * @param $uid          The message UID
    * @param $mime_type    mime type of the mail
    * @param $structure    structure of the mail (false by default)
    * @param $part_number  The part number (false by default)
    *
    * @return data of false if error
   **/
   function get_part($stream, $uid, $mime_type, $structure = false, $part_number = false) {

      if ($structure) {
         if ($mime_type == $this->get_mime_type($structure)) {

            if (!$part_number) {
               $part_number = "1";
            }

            $text = imap_fetchbody($stream, $uid, $part_number, FT_UID);

            if ($structure->encoding == ENCBASE64) {
               $text =  imap_base64($text);
            } else if ($structure->encoding == ENCQUOTEDPRINTABLE) {
               $text =  imap_qprint($text);
            }

            $text = str_replace(["\r\n", "\r"], "\n", $text); // Normalize line breaks

            $charset = null;

            foreach ($structure->parameters as $param) {
               if (strtoupper($param->attribute) == 'CHARSET') {
                  $charset = strtoupper($param->value);
               }
            }

            if (null !== $charset && 'UTF-8' !== $charset) {
               if (in_array($charset, array_map('strtoupper', mb_list_encodings()))) {
                  $text                 = mb_convert_encoding($text, 'UTF-8', $charset);
                  $this->body_converted = true;
               } else {
                  // Convert Windows charsets names
                  if (preg_match('/^WINDOWS-\d{4}$/', $charset)) {
                     $charset = preg_replace('/^WINDOWS-(\d{4})$/', 'CP$1', $charset);
                  }

                  if ($converted_test = iconv($charset, 'UTF-8//TRANSLIT', $text)) {
                     $text                 = $converted_test;
                     $this->body_converted = true;
                  }
               }
            }

            return $text;
         }

         if ($structure->type == TYPEMULTIPART) {
            $prefix = "";

            foreach ($structure->parts as $index => $sub_structure) {
               if ($part_number) {
                  $prefix = $part_number . '.';
               }
               $data = $this->get_part($stream, $uid, $mime_type, $sub_structure,
                                       $prefix . ($index + 1));
               if ($data) {
                  return $data;
               }
            }
         }
      }
      return false;
   }


   /**
    * Used to get total unread mail from that mailbox
    *
    * @return an integer (Total Mail)
   **/
   function getTotalMails() {

      $headers = imap_headers($this->marubox);
      return count($headers);
   }


   /**
    * Summary of getDecodedFetchbody
    * used to get decoded part from email
    *
    * @since 0.90.2
    * @param $structure
    * @param $uid
    * @param $part
    *
    * @return bool|string
   **/
   private function getDecodedFetchbody($structure, $uid, $part) {

      if ($message = imap_fetchbody($this->marubox, $uid, $part, FT_UID)) {
         switch ($structure->encoding) {
            case ENC8BIT :
               $message = imap_8bit($message);
               break;

            case ENCBINARY :
               $message = imap_binary($message);
               break;

            case ENCBASE64 :
               $message = imap_base64($message);
               break;

            case ENCQUOTEDPRINTABLE :
               $message = quoted_printable_decode($message);
               break;
         }
         return $message;
      }

      return false;
   }


   /**
    * Private function : Recursivly get attached documents
    *
    * @param $uid          message uid
    * @param $path         temporary path
    * @param $maxsize      of document to be retrieved
    * @param $structure    of the message or part
    * @param $part         part for recursive
    *
    * Result is stored in $this->files
   **/
   function getRecursiveAttached($uid, $path, $maxsize, $structure, $part = "") {

      if ($structure->type == TYPEMULTIPART) {
         foreach ($structure->parts as $index => $sub_structure) {
            $this->getRecursiveAttached($uid, $path, $maxsize, $sub_structure,
                                        ($part ? $part.".".($index+1) : ($index+1)));
         }

      } else {
         // fix monoparted mail
         if ($part == "") {
            $part = 1;
         }

         $filename = '';

         // get filename of attachment if present
         // if there are any dparameters present in this part
         if ($structure->ifdparameters) {
            if (count($structure->dparameters) > 0) {
               foreach ($structure->dparameters as $dparam) {
                  if ((Toolbox::strtoupper($dparam->attribute) == 'NAME')
                      || (Toolbox::strtoupper($dparam->attribute) == 'FILENAME')) {
                     $filename = $dparam->value;
                  }
               }
            }
         }

         // if there are any parameters present in this part
         if (empty($filename)
             && $structure->ifparameters) {
            if (count($structure->parameters) > 0) {
               foreach ($structure->parameters as $param) {
                  if ((Toolbox::strtoupper($param->attribute) == 'NAME')
                      || (Toolbox::strtoupper($param->attribute) == 'FILENAME')) {
                     $filename = $param->value;
                  }
               }
            }
         }

         // part come without correct filename in [d]parameters - generate trivial one
         // (inline images case for example)
         if ((empty($filename) || !Document::isValidDoc($filename))
             && $structure->type != TYPETEXT
             && $structure->type != TYPEMULTIPART
             && $structure->type != TYPEMESSAGE
             && $structure->subtype) {
            $tmp_filename = "doc_$part.".$structure->subtype;
            if (Document::isValidDoc($tmp_filename)) {
               $filename = $tmp_filename;
            }
         }

         // Embeded email comes without filename - try to get "Subject:" or generate trivial one
         if (empty($filename)
             && $structure->type == TYPEMESSAGE
             && $structure->subtype) {
            $filename = "msg_$part.EML"; // default trivial one :)!
            if (($message = $this->getDecodedFetchbody($structure, $uid, $part))
                    && (preg_match( "/Subject: *([^\r\n]*)/i", $message, $matches))) {
               $filename = "msg_".$part."_".$this->decodeMimeString($matches[1]).".EML";
               $filename = preg_replace( "#[<>:\"\\\\/|?*]#u", "_", $filename);
            }
         }

         // if no filename found, ignore this part
         if (empty($filename)) {
            return false;
         }
         //try to avoid conflict between inline image and attachment
         $i = 2;
         while (in_array($filename, $this->files)) {
            //replace filename with name_(num).EXT by name_(num+1).EXT
            $new_filename = preg_replace("/(.*)_([0-9])*(\.[a-zA-Z0-9]*)$/", "$1_".$i."$3", $filename);
            if ($new_filename !== $filename) {
               $filename = $new_filename;
            } else {
               //the previous regex didn't found _num pattern, so add it with this one
               $filename = preg_replace("/(.*)(\.[a-zA-Z0-9]*)$/", "$1_".$i."$2", $filename);
            }
            $i++;
         }

         $filename = $this->decodeMimeString($filename);

         if ($structure->bytes > $maxsize) {
            $this->addtobody .= "\n\n".sprintf(__('%1$s: %2$s'), __('Too large attached file'),
                                               sprintf(__('%1$s (%2$s)'), $filename,
                                                       Toolbox::getSize($structure->bytes)));
            return false;
         }

         if (!Document::isValidDoc($filename)) {
            //TRANS: %1$s is the filename and %2$s its mime type
            $this->addtobody .= "\n\n".sprintf(__('%1$s: %2$s'), __('Invalid attached file'),
                                               sprintf(__('%1$s (%2$s)'), $filename,
                                                       $this->get_mime_type($structure)));
            return false;
         }

         if ((($structure->type == TYPEMESSAGE) && $structure->subtype)
             || ($message = $this->getDecodedFetchbody($structure, $uid, $part))) {
            if (file_put_contents($path.$filename, $message)) {
               $this->files[$filename] = $filename;
               // If embeded image, we add a tag
               if (($structure->type == TYPEIMAGE)
                   && $structure->subtype) {
                  end($this->files);
                  $tag = Rule::getUuid();
                  $this->tags[$filename]  = $tag;

                  // Link file based on id
                  if (isset($structure->id)) {
                     $clean = ['<' => '',
                                    '>' => ''];

                     $this->altfiles[strtr($structure->id, $clean)] = $filename;
                  }

               }
            }
         } // fetchbody
      } // Single part
   }


   /**
    * Public function : get attached documents in a mail
    *
    * @param $uid       UID of the message
    * @param $path      temporary path
    * @param $maxsize   of document to be retrieved
    *
    * @return array containing extracted filenames in file/_tmp
   **/
   function getAttached($uid, $path, $maxsize) {

      $this->getStructure($uid);
      $this->files     = [];
      $this->altfiles  = [];
      $this->addtobody = "";
      $this->getRecursiveAttached($uid, $path, $maxsize, $this->structure);

      return ($this->files);
   }


   /**
    * Get The actual mail content from this mail
    *
    * @param $uid : mail UID
   **/
   function getBody($uid) {
      // Get Message Body

      $this->getStructure($uid);
      $body = $this->get_part($this->marubox, $uid, "TEXT/HTML", $this->structure);

      if (!empty($body)) {
         $this->body_is_html = true;
      } else {
         $body = $this->get_part($this->marubox, $uid, "TEXT/PLAIN", $this->structure);
         $this->body_is_html = false;
      }

      if ($body == "") {
         return "";
      }

      return $body;
   }


   /**
    * Delete mail from that mail box
    *
    * @param $uid       String    mail UID
    * @param $folder    String   folder to move (delete if empty) (default '')
    *
    * @return Boolean
   **/
   function deleteMails($uid, $folder = '') {

      // Disable move support, POP protocol only has the INBOX folder
      if (strstr($this->fields['host'], "/pop")) {
         $folder = '';
      }

      if (!empty($folder) && isset($this->fields[$folder]) && !empty($this->fields[$folder])) {
         $name = mb_convert_encoding($this->fields[$folder], "UTF7-IMAP", "UTF-8");
         if (imap_mail_move($this->marubox, $uid, $name, CP_UID)) {
            return true;
         }
         // raise an error and fallback to delete
         //TRANS: %1$s is the name of the folder, %2$s is the name of the receiver
         trigger_error(sprintf(__('Invalid configuration for %1$s folder in receiver %2$s'),
                               $folder, $this->getName()));
      }
      return imap_delete($this->marubox, $uid, FT_UID);
   }


   /**
    * Close The Mail Box
   **/
   function close_mailbox() {
      imap_close($this->marubox, CL_EXPUNGE);
   }


   /**
    * @param $name
   **/
   static function cronInfo($name) {

      switch ($name) {
         case 'mailgate' :
            return ['description' => __('Retrieve email (Mails receivers)'),
                         'parameter'   => __('Number of emails to retrieve')];

         case 'mailgateerror' :
            return ['description' => __('Send alarms on receiver errors')];
      }
   }


   /**
    * Cron action on mailgate : retrieve mail and create tickets
    *
    * @param $task
    *
    * @return -1 : done but not finish 1 : done with success
   **/
   static function cronMailgate($task) {
      global $DB;

      NotImportedEmail::deleteLog();
      $iterator = $DB->request([
         'FROM'   => 'glpi_mailcollectors',
         'WHERE'  => ['is_active' => 1]
      ]);

      $max = $task->fields['param'];

      if (count($iterator) > 0) {
         $mc = new self();

         while (($max > 0)
                  && ($data = $iterator->next())) {
            $mc->maxfetch_emails = $max;

            $task->log("Collect mails from ".$data["name"]." (".$data["host"].")\n");
            $message = $mc->collect($data["id"]);

            $task->addVolume($mc->fetch_emails);
            $task->log("$message\n");

            $max -= $mc->fetch_emails;
         }
      }

      if ($max == $task->fields['param']) {
         return 0; // Nothin to do
      } else if ($max > 0) {
         return 1; // done
      }

      return -1; // still messages to retrieve
   }


   /**
    * Send Alarms on mailgate errors
    *
    * @since 0.85
    *
    * @param $task for log
   **/
   static function cronMailgateError($task) {
      global $DB, $CFG_GLPI;

      if (!$CFG_GLPI["use_notifications"]) {
         return 0;
      }
      $cron_status   = 0;

      $iterator = $DB->request([
         'FROM'   => 'glpi_mailcollectors',
         'WHERE'  => [
            'errors'    => ['>', 0],
            'is_active' => 1
         ]
      ]);

      $items = [];
      while ($data = $iterator->next()) {
         $items[$data['id']]  = $data;
      }

      if (count($items)) {
         if (NotificationEvent::raiseEvent('error', new self(), ['items' => $items])) {
            $cron_status = 1;
            if ($task) {
               $task->setVolume(count($items));
            }
         }
      }
      return $cron_status;
   }


   /**
    * @param $width
   **/
   function showSystemInformations($width) {
      global $CFG_GLPI, $DB;

      // No need to translate, this part always display in english (for copy/paste to forum)

      echo "<tr class='tab_bg_2'><th>Notifications</th></tr>\n";
      echo "<tr class='tab_bg_1'><td><pre>\n&nbsp;\n";

      $msg = 'Way of sending emails: ';
      switch ($CFG_GLPI['smtp_mode']) {
         case MAIL_MAIL :
            $msg .= 'PHP';
            break;

         case MAIL_SMTP :
            $msg .= 'SMTP';
            break;

         case MAIL_SMTPSSL :
            $msg .= 'SMTP+SSL';
            break;

         case MAIL_SMTPTLS :
            $msg .= 'SMTP+TLS';
            break;
      }
      if ($CFG_GLPI['smtp_mode'] != MAIL_MAIL) {
         $msg .= " (".(empty($CFG_GLPI['smtp_username']) ? 'anonymous' : $CFG_GLPI['smtp_username']).
                    "@".$CFG_GLPI['smtp_host'].")";
      }
      echo wordwrap($msg."\n", $width, "\n\t\t");
      echo "\n</pre></td></tr>";

      echo "<tr class='tab_bg_2'><th>Mails receivers</th></tr>\n";
      echo "<tr class='tab_bg_1'><td><pre>\n&nbsp;\n";

      foreach ($DB->request('glpi_mailcollectors') as $mc) {
         $msg  = "Name: '".$mc['name']."'";
         $msg .= " Active: " .($mc['is_active'] ? "Yes" : "No");
         echo wordwrap($msg."\n", $width, "\n\t\t");

         $msg  = "\tServer: '". $mc['host']."'";
         $msg .= " Login: '".$mc['login']."'";
         $msg .= " Password: ".(empty($mc['passwd']) ? "No" : "Yes");
         echo wordwrap($msg."\n", $width, "\n\t\t");
      }
      echo "\n</pre></td></tr>";
   }


   /**
    * @param $to        (default '')
    * @param $subject   (default '')
   **/
   function sendMailRefusedResponse($to = '', $subject = '') {
      global $CFG_GLPI;

      $mmail = new GLPIMailer();
      $mmail->AddCustomHeader("Auto-Submitted: auto-replied");
      $mmail->SetFrom($CFG_GLPI["admin_email"], $CFG_GLPI["admin_email_name"]);
      $mmail->AddAddress($to);
      // Normalized header, no translation
      $mmail->Subject  = 'Re: ' . $subject;
      $mmail->Body     = __("Your email could not be processed.\nIf the problem persists, contact the administrator").
                         "\n-- \n".$CFG_GLPI["mailing_signature"];
      $mmail->Send();
   }


   function title() {
      global $CFG_GLPI;

      $buttons = [];
      if (countElementsInTable($this->getTable())) {
         $buttons["notimportedemail.php"] = __('List of not imported emails');
      }

      $errors  = getAllDatasFromTable($this->getTable(), ['errors' => ['>', 0]]);
      $message = '';
      if (count($errors)) {
         $servers = [];
         foreach ($errors as $data) {
            $this->getFromDB($data['id']);
            $servers[] = $this->getLink();
         }

         $message = sprintf(__('Receivers in error: %s'), implode(" ", $servers));
      }

      if (count($buttons)) {
         Html::displayTitle($CFG_GLPI["root_doc"] . "/pics/users.png",
                            _n('Receiver', 'Receivers', Session::getPluralNumber()), $message, $buttons);
      }

   }


   static function getNumberOfMailCollectors() {
      global $DB;

      $result = $DB->request([
         'COUNT'  => 'cpt',
         'FROM'   => 'glpi_mailcollectors'
      ])->next();
      return $result['cpt'];
   }


   /**
    * @since 0.85
   **/
   static function getNumberOfActiveMailCollectors() {
      global $DB;

      $result = $DB->request([
         'COUNT'  => 'cpt',
         'FROM'   => 'glpi_mailcollectors',
         'WHERE'  => ['is_active' => 1]
      ])->next();
      return $result['cpt'];
   }


   /**
    * @param $name
    * @param $value  (default 0)
    * @param $rand
   **/
   static function showMaxFilesize($name, $value = 0, $rand = null) {

      $sizes[0] = __('No import');
      for ($index=1; $index<100; $index++) {
         $sizes[$index*1048576] = sprintf(__('%s Mio'), $index);
      }

      if ($rand === null) {
         $rand = mt_rand();
      }

      Dropdown::showFromArray($name, $sizes, ['value' => $value, 'rand' => $rand]);
   }


   function cleanDBonPurge() {

      // mailcollector for RuleMailCollector, _mailgate for RuleTicket
      Rule::cleanForItemCriteria($this, 'mailcollector');
      Rule::cleanForItemCriteria($this, '_mailgate');
   }

   static public function unsetUndisclosedFields(&$fields) {
      unset($fields['passwd']);
   }

   /**
    * Get the requester field
    *
    * @return string requester field
   **/
   private function getRequesterField() {
      switch ($this->fields['requester_field']) {
         case self::REQUESTER_FIELD_REPLY_TO:
            return "reply-to";

         default:
            return "from";
      }
   }
}
