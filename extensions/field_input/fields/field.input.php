<?php

	require_once(TOOLKIT . '/class.xslproc.php');

	Class fieldInput extends Field {
		public function __construct(){
			parent::__construct();
			$this->_name = __('Text Input');
			$this->_required = true;
			$this->properties()->required = 'no';
		}

		public function allowDatasourceOutputGrouping(){
			return true;
		}

		public function allowDatasourceParamOutput(){
			return true;
		}

		public function groupRecords($records){

			if(!is_array($records) || empty($records)) return;

			$groups = array($this->properties()->element_name => array());

			foreach($records as $r){
				$data = $r->getData($this->properties()->id);

				$value = $data['value'];
				$handle = Lang::createHandle($value);

				if(!isset($groups[$this->properties()->element_name][$handle])){
					$groups[$this->properties()->element_name][$handle] = array('attr' => array('handle' => $handle, 'value' => $value),
																		 'records' => array(), 'groups' => array());
				}

				$groups[$this->properties()->element_name][$handle]['records'][] = $r;

			}

			return $groups;
		}

		public function displayDatasourceFilterPanel(SymphonyDOMElement &$wrapper, $data=NULL, MessageStack $errors=NULL){
			$h4 = Symphony::Parent()->Page->createElement('h4', $this->properties()->label);
			$h4->appendChild(
				Symphony::Parent()->Page->createElement('i', $this->Name())
			);

			$wrapper->appendChild($h4);

			$group = Symphony::Parent()->Page->createElement('div');
			$group->setAttribute('class', 'group');

			$label = Widget::Label(__('Operation'));
			$label->appendChild(Widget::Select(
				'fields[filter]'
				//. (!is_null($fieldnamePrefix) ? "[{$fieldnamePrefix}]" : NULL)
				. '[' . $this->properties()->element_name . '][operation]',
				//. (!is_null($fieldnamePostfix) ? "[{$fieldnamePostfix}]" : NULL),
				//(!is_null($data) ? General::sanitize($data) : NULL)
				array(
					array('is', false, 'Is'),
					array('is-not', ($data['operation'] == 'is-not'), 'Is Not'),
					array('contains', ($data['operation'] == 'contains'), 'Contains'),
					array('matches-expression', ($data['operation'] == 'matches-expression'), 'Matches Expression'),
				)
			));
			$group->appendChild($label);

			$label = Widget::Label(__('Value'));
			$label->appendChild(Widget::Input(
				'fields[filter]'
				//. (!is_null($fieldnamePrefix) ? "[{$fieldnamePrefix}]" : NULL)
				. '[' . $this->properties()->element_name . '][value]',
				//. (!is_null($fieldnamePostfix) ? "[{$fieldnamePostfix}]" : NULL),
				(!is_null($data) && isset($data['value']) ? General::sanitize($data['value']) : NULL)
			));
			$group->appendChild($label);

			$wrapper->appendChild($group);
		}

		public function displayPublishPanel(SymphonyDOMElement $wrapper, $data=NULL, $flagWithError=NULL, $entry_id=NULL){
			$value = General::sanitize($data['value']);
			$label = Widget::Label($this->properties()->label);
			if($this->properties()->required != 'yes') $label->appendChild(Symphony::Parent()->Page->createElement('i', __('Optional')));
			$label->appendChild(Widget::Input('fields['.$this->properties()->element_name.']', (strlen($value) != 0 ? $value : NULL)));

			if($flagWithError != NULL) $wrapper->appendChild(Widget::wrapFormElementWithError($label, $flagWithError));
			else $wrapper->appendChild($label);
		}

		public function isSortable(){
			return true;
		}

		public function canFilter(){
			return true;
		}

		public function canImport(){
			return true;
		}

		public function buildSortingSQL(&$joins, &$where, &$sort, $order='ASC'){
			$joins .= "LEFT OUTER JOIN `tbl_entries_data_".$this->properties()->id."` AS `ed` ON (`e`.`id` = `ed`.`entry_id`) ";
			$sort = 'ORDER BY ' . (in_array(strtolower($order), array('random', 'rand')) ? 'RAND()' : "`ed`.`value` $order");
		}

		public function buildDSRetrivalSQL($data, &$joins, &$where, $andOperation = false) {
			$field_id = $this->properties()->id;

			if (self::isFilterRegex($data[0])) {
				self::$key++;
				$pattern = str_replace('regexp:', '', $this->escape($data[0]));
				$joins .= "
					LEFT JOIN
						`tbl_entries_data_{$field_id}` AS t{$field_id}_{self::$key}
						ON (e.id = t{$field_id}_{self::$key}.entry_id)
				";
				$where .= "
					AND (
						t{$field_id}_{self::$key}.value REGEXP '{$pattern}'
						OR t{$field_id}_{self::$key}.handle REGEXP '{$pattern}'
					)
				";

			} elseif ($andOperation) {
				foreach ($data as $value) {
					self::$key++;
					$value = $this->escape($value);
					$joins .= "
						LEFT JOIN
							`tbl_entries_data_{$field_id}` AS t{$field_id}_{self::$key}
							ON (e.id = t{$field_id}_{self::$key}.entry_id)
					";
					$where .= "
						AND (
							t{$field_id}_{self::$key}.value = '{$value}'
							OR t{$field_id}_{self::$key}.handle = '{$value}'
						)
					";
				}

			} else {
				if (!is_array($data)) $data = array($data);

				foreach ($data as &$value) {
					$value = $this->escape($value);
				}

				self::$key++;
				$data = implode("', '", $data);
				$joins .= "
					LEFT JOIN
						`tbl_entries_data_{$field_id}` AS t{$field_id}_{self::$key}
						ON (e.id = t{$field_id}_{self::$key}.entry_id)
				";
				$where .= "
					AND (
						t{$field_id}_{self::$key}.value IN ('{$data}')
						OR t{$field_id}_{self::$key}.handle IN ('{$data}')
					)
				";
			}

			return true;
		}

		private function __applyValidationRules($data){
			$rule = $this->properties()->validator;
			return ($rule ? General::validateString($data, $rule) : true);
		}

		private function __replaceAmpersands($value) {
			return preg_replace('/&(?!(#[0-9]+|#x[0-9a-f]+|amp|lt|gt);)/i', '&amp;', trim($value));
		}

		public function checkPostFieldData($data, &$message, $entry_id=NULL){

			$message = NULL;

			$handle = Lang::createHandle($data);

			if($this->properties()->required == 'yes' && strlen($data) == 0){
				$message = __("'%s' is a required field.", array($this->properties()->label));
				return self::ERROR_MISSING_FIELDS;
			}

			if(!$this->__applyValidationRules($data)){
				$message = __("'%s' contains invalid data. Please check the contents.", array($this->properties()->label));
				return self::ERROR_INVALID_FIELDS;
			}

			return self::STATUS_OK;

		}

		public function processRawFieldData($data, &$status, $simulate = false, $entry_id = null) {

			$status = self::STATUS_OK;

			if (strlen(trim($data)) == 0) return array();

			$result = array(
				'value' => $data
			);

			$result['handle'] = Lang::createHandle($result['value']);

			return $result;
		}

		public function canPrePopulate(){
			return true;
		}

		public function appendFormattedElement(&$wrapper, $data, $encode=false){

			$value = $data['value'];

			if($encode === true){
				$value = General::sanitize($value);
			}

			else{
				include_once(TOOLKIT . '/class.xslproc.php');

				if(!General::validateXML($data['value'], $errors)){
					$value = html_entity_decode($data['value'], ENT_QUOTES, 'UTF-8');
					$value = $this->__replaceAmpersands($value);

					if(!General::validateXML($value, $errors)){
						$value = General::sanitize($data['value']);
					}
				}
			}

			$wrapper->appendChild(
				Symphony::Parent()->Page->createElement(
					$this->properties()->element_name, $value, array('handle' => $data['handle'])
				)
			);
		}

		public function commit(){

			if(!parent::commit()) return false;

			$field_id = $this->properties()->id;
			$handle = $this->handle();

			if($field_id === false) return false;

			$fields = array(
				'field_id' => $field_id,
				'validator' => ($fields['validator'] == 'custom' ? NULL : $this->properties()->validator)
			);

			Symphony::Database()->delete('tbl_fields_' . $handle, array($field_id), "`field_id` = %d LIMIT 1");
			$field_id = Symphony::Database()->insert('tbl_fields_' . $handle, $fields);

			return ($field_id == 0 || !$field_id) ? false : true;
		}

		public function setFromPOST($postdata){
			parent::setFromPOST($postdata);
			if(strlen(trim($this->properties()->validator)) == 0) unset($this->properties()->validator);
		}

		public function displaySettingsPanel(&$wrapper, $errors = null) {
			parent::displaySettingsPanel($wrapper, $errors);

			$this->buildValidationSelect($wrapper, $this->properties()->validator, 'validator');

			$options_list = Symphony::Parent()->Page->createElement('ul');
			$options_list->setAttribute('class', 'options-list');

			$this->appendRequiredCheckbox($options_list);
			$this->appendShowColumnCheckbox($options_list);

			$wrapper->appendChild($options_list);

		}

		public function createTable(){
			return Symphony::Database()->query(
				sprintf(
					'CREATE TABLE IF NOT EXISTS `tbl_data_%s_%s` (
						`id` int(11) unsigned NOT NULL auto_increment,
						`entry_id` int(11) unsigned NOT NULL,
						`handle` varchar(255) default NULL,
						`value` varchar(255) default NULL,
						PRIMARY KEY  (`id`),
						KEY `entry_id` (`entry_id`),
						KEY `handle` (`handle`),
						KEY `value` (`value`)
					)',
					$this->properties()->section,
					$this->properties()->element_name
				)
			);
		}
	}

	return 'fieldInput';