<?php
/**
 * ---------------------------------------------------------------------
 * Formcreator is a plugin which allows creation of custom forms of
 * easy access.
 * ---------------------------------------------------------------------
 * LICENSE
 *
 * This file is part of Formcreator.
 *
 * Formcreator is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Formcreator is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Formcreator. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 * @author    Thierry Bugier
 * @author    Jérémy Moreau
 * @copyright Copyright © 2011 - 2019 Teclib'
 * @license   http://www.gnu.org/licenses/gpl.txt GPLv3+
 * @link      https://github.com/pluginsGLPI/formcreator/
 * @link      https://pluginsglpi.github.io/formcreator/
 * @link      http://plugins.glpi-project.org/#/plugin/formcreator
 * ---------------------------------------------------------------------
 */

class PluginFormcreatorTextareaField extends PluginFormcreatorTextField
{
   public function displayField($canEdit = true) {
      global $CFG_GLPI;

      $id           = $this->fields['id'];
      $rand         = mt_rand();
      $fieldName    = 'formcreator_field_' . $id;
      $domId        = $fieldName . '_' . $rand;
      $required = $this->fields['required'] ? ' required' : '';
      $useRichText = version_compare(PluginFormcreatorCommon::getGlpiVersion(), 9.4) >= 0 || $CFG_GLPI['use_rich_text'];
      if ($canEdit) {
         if ($useRichText) {
            $value = nl2br($this->value);
         } else {
            $value = $this->value;
         }
         echo Html::textarea([
            'name'            => $fieldName,
            'rand'            => $rand,
            'value'           => $value,
            'rows'            => 5,
            'display'         => false,
            'enable_richtext' => $useRichText,
         ]);
         echo Html::scriptBlock("$(function() {
            pluginFormcreatorInitializeTextarea('$fieldName', '$rand');
         });");
      } else {
         if ($useRichText) {
            echo Toolbox::getHtmlToDisplay($this->value);
         } else {
            echo nl2br($this->value);
         }
      }
   }

   public static function getName() {
      return __('Textarea', 'formcreator');
   }

   public function serializeValue() {
      if ($this->value === null || $this->value === '') {
         return '';
      }

      return Toolbox::addslashes_deep($this->value);
   }

   public function deserializeValue($value) {
      $this->value = ($value !== null && $value !== '')
                  ? $value
                  : '';
      //$this->value = str_replace('\r\n', "\r\n", $this->value);
   }

   public function getValueForDesign() {
      if ($this->value === null) {
         return '';
      }

      return $this->value;
   }

   public function isValid() {
      // If the field is required it can't be empty
      if ($this->isRequired() && $this->value == '') {
         Session::addMessageAfterRedirect(
            __('A required field is empty:', 'formcreator') . ' ' . $this->getLabel(),
            false,
            ERROR);
         return false;
      }

      // All is OK
      return true;
   }

   public function prepareQuestionInputForSave($input) {
      $this->value = str_replace('\r\n', "\r\n", $input['default_values']);
      return $input;
   }

   public function getValueForTargetText($richText) {
      return $this->value;
   }

   public static function getJSFields() {
      $prefs = self::getPrefs();
      return "tab_fields_fields['textarea'] = 'showFields(".implode(', ', $prefs).");';";
   }

   public function equals($value) {
      return $this->value == $value;
   }

   public function notEquals($value) {
      return !$this->equals($value);
   }

   public function greaterThan($value) {
      return $this->value > $value;
   }

   public function lessThan($value) {
      return !$this->greaterThan($value) && !$this->equals($value);
   }

   public function isAnonymousFormCompatible() {
      return true;
   }
}
