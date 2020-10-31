<?php
/*
	FusionPBX
	Version: MPL 1.1

	The contents of this file are subject to the Mozilla Public License Version
	1.1 (the "License"); you may not use this file except in compliance with
	the License. You may obtain a copy of the License at
	http://www.mozilla.org/MPL/

	Software distributed under the License is distributed on an "AS IS" basis,
	WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
	for the specific language governing rights and limitations under the
	License.

	The Original Code is FusionPBX

	The Initial Developer of the Original Code is
	Mark J Crane <markjcrane@fusionpbx.com>
	Portions created by the Initial Developer are Copyright (C) 2008-2019
	the Initial Developer. All Rights Reserved.

	Contributor(s):
	Mark J Crane <markjcrane@fusionpbx.com>
	Luis Daniel Lucio Quiroz <dlucio@okay.com.mx>

	Original version of Call Block was written by Gerrit Visser <gerrit308@gmail.com>
*/

//includes
	require_once "root.php";
	require_once "resources/require.php";
	require_once "resources/check_auth.php";

//check permissions
	if (!permission_exists('call_block_edit') && !permission_exists('call_block_add')) {
		echo "access denied"; exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//action add or update
	if (is_uuid($_REQUEST["id"])) {
		$action = "update";
		$call_block_uuid = $_REQUEST["id"];
	}
	else {
		$action = "add";
	}

//get http post variables and set them to php variables
	if (count($_POST) > 0) {
		$extension_uuid = $_POST["extension_uuid"];
		$call_block_name = $_POST["call_block_name"];
		$call_block_country_code = $_POST["call_block_country_code"];
		$call_block_number = $_POST["call_block_number"];
		$call_block_enabled = $_POST["call_block_enabled"];
		$call_block_description = $_POST["call_block_description"];
		
		$action_array = explode(':', $_POST["call_block_action"]);
		$call_block_app = $action_array[0];
		$call_block_data = $action_array[1];
	}

//handle the http post
	if (count($_POST) > 0 && strlen($_POST["persistformvar"]) == 0) {

		//handle action
			if ($_POST['action'] != '') {
				switch ($_POST['action']) {
					case 'delete':
						if (permission_exists('call_block_delete') && is_uuid($call_block_uuid)) {
							//prepare
								$array[0]['checked'] = 'true';
								$array[0]['uuid'] = $call_block_uuid;
							//delete
								$obj = new call_block;
								$obj->delete($array);
						}
						break;
					case 'add':
						$xml_cdrs = $_POST['xml_cdrs'];
						if (permission_exists('call_block_add') && is_array($xml_cdrs) && @sizeof($xml_cdrs) != 0) {
							$obj = new call_block;
							$obj->extension_uuid = $extension_uuid;
							$obj->call_block_app = $call_block_app;
							$obj->call_block_data = $call_block_data;
							$obj->add($xml_cdrs);
						}
						break;
				}

				header('Location: call_block.php');
				exit;
			}

		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'],'negative');
				header('Location: call_block.php');
				exit;
			}

		//check for all required data
			$msg = '';
			//if (strlen($call_block_name) == 0) { $msg .= $text['label-provide-name']."<br>\n"; }
			//if (strlen($call_block_number) == 0) { $msg .= $text['label-provide-number']."<br>\n"; }
			if (strlen($call_block_enabled) == 0) { $msg .= $text['label-provide-enabled']."<br>\n"; }
			if (strlen($msg) > 0 && strlen($_POST["persistformvar"]) == 0) {
				require_once "resources/header.php";
				require_once "resources/persist_form_var.php";
				echo "<div align='center'>\n";
				echo "<table><tr><td>\n";
				echo $msg."<br />";
				echo "</td></tr></table>\n";
				persistformvar($_POST);
				echo "</div>\n";
				require_once "resources/footer.php";
				return;
			}

		//add or update the database
			if (is_array($_POST) && sizeof($_POST) != 0 && $_POST["persistformvar"] != "true") {

				//ensure call block is enabled in the dialplan
					if ($action == "add" || $action == "update") {
						$sql = "select dialplan_uuid from v_dialplans where true ";
						$sql .= "and domain_uuid = :domain_uuid ";
						$sql .= "and app_uuid = 'b1b31930-d0ee-4395-a891-04df94599f1f' ";
						$sql .= "and dialplan_enabled <> 'true' ";
						$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
						$database = new database;
						$rows = $database->select($sql, $parameters);

						if (is_array($rows) && sizeof($rows) != 0) {
							foreach ($rows as $index => $row) {
								$array['dialplans'][$index]['dialplan_uuid'] = $row['dialplan_uuid'];
								$array['dialplans'][$index]['dialplan_enabled'] = 'true';
							}

							$p = new permissions;
							$p->add('dialplan_edit', 'temp');

							$database = new database;
							$database->save($array);
							unset($array);

							$p->delete('dialplan_edit', 'temp');
						}
					}

				//if user doesn't have call block all then use the assigned extension_uuid
					if (!permission_exists('call_block_all')) {
						$extension_uuid = $_SESSION['user']['extension'][0]['extension_uuid'];
					}

				//save the data to the database
					if ($action == "add") {
						$array['call_block'][0]['call_block_uuid'] = uuid();
						$array['call_block'][0]['domain_uuid'] = $_SESSION['domain_uuid'];
						if (is_uuid($extension_uuid)) {
							$array['call_block'][0]['extension_uuid'] = $extension_uuid;
						}
						$array['call_block'][0]['call_block_name'] = $call_block_name;
						$array['call_block'][0]['call_block_country_code'] = $call_block_country_code;
						$array['call_block'][0]['call_block_number'] = $call_block_number;
						$array['call_block'][0]['call_block_count'] = 0;
						$array['call_block'][0]['call_block_app'] = $call_block_app;
						$array['call_block'][0]['call_block_data'] = $call_block_data;
						$array['call_block'][0]['call_block_enabled'] = $call_block_enabled;
						$array['call_block'][0]['date_added'] = time();
						$array['call_block'][0]['call_block_description'] = $call_block_description;

						$database = new database;
						$database->app_name = 'call_block';
						$database->app_uuid = '9ed63276-e085-4897-839c-4f2e36d92d6c';
						$database->save($array);
						$response = $database->message;
						unset($array);

						message::add($text['label-add-complete']);
						header("Location: call_block.php");
						return;
					}
					if ($action == "update") {
						$sql = "select c.call_block_country_code, c.call_block_number, d.domain_name ";
						$sql .= "from v_call_block as c ";
						$sql .= "join v_domains as d on c.domain_uuid = d.domain_uuid ";
						$sql .= "where c.domain_uuid = :domain_uuid ";
						$sql .= "and c.call_block_uuid = :call_block_uuid ";
						$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
						$parameters['call_block_uuid'] = $call_block_uuid;
						$database = new database;
						$result = $database->select($sql, $parameters);
						if (is_array($result) && sizeof($result) != 0) {
							//set the domain_name
							$domain_name = $result[0]["domain_name"];

							//clear the cache
							$cache = new cache;
							$cache->delete("app:call_block:".$domain_name.":".$call_block_country_code.$call_block_number);
						}
						unset($sql, $parameters);

						$array['call_block'][0]['call_block_uuid'] = $call_block_uuid;
						$array['call_block'][0]['domain_uuid'] = $_SESSION['domain_uuid'];
						if (is_uuid($extension_uuid)) {
							$array['call_block'][0]['extension_uuid'] = $extension_uuid;
						}
						$array['call_block'][0]['call_block_name'] = $call_block_name;
						$array['call_block'][0]['call_block_country_code'] = $call_block_country_code;
						$array['call_block'][0]['call_block_number'] = $call_block_number;
						$array['call_block'][0]['call_block_app'] = $call_block_app;
						$array['call_block'][0]['call_block_data'] = $call_block_data;
						$array['call_block'][0]['call_block_enabled'] = $call_block_enabled;
						$array['call_block'][0]['date_added'] = time();
						$array['call_block'][0]['call_block_description'] = $call_block_description;

						$database = new database;
						$database->app_name = 'call_block';
						$database->app_uuid = '9ed63276-e085-4897-839c-4f2e36d92d6c';
						$database->save($array);
						$response = $database->message;
						unset($array);

						message::add($text['label-update-complete']);
						header("Location: call_block.php");
						return;
					}
			}
	}

//pre-populate the form
	if (count($_GET) > 0 && $_POST["persistformvar"] != "true") {
		$call_block_uuid = $_GET["id"];
		$sql = "select * from v_call_block ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and call_block_uuid = :call_block_uuid ";
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		$parameters['call_block_uuid'] = $call_block_uuid;
		$database = new database;
		$row = $database->select($sql, $parameters, 'row');
		if (is_array($row) && sizeof($row) != 0) {
			$extension_uuid = $row["extension_uuid"];
			$call_block_name = $row["call_block_name"];
			$call_block_country_code = $row["call_block_country_code"];
			$call_block_number = $row["call_block_number"];
			$call_block_app = $row["call_block_app"];
			$call_block_data = $row["call_block_data"];
			$call_block_enabled = $row["call_block_enabled"];
			$call_block_description = $row["call_block_description"];
		}
		unset($sql, $parameters, $row);
	}

//get the extensions
	if (permission_exists('call_block_all') || permission_exists('call_block_extension')) {
		$sql = "select extension_uuid, extension, number_alias, user_context, description from v_extensions ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and enabled = 'true' ";
		$sql .= "order by extension asc ";
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		$database = new database;
		$extensions = $database->select($sql, $parameters);
	}

//get the voicemails
	$sql = "select voicemail_uuid, voicemail_id, voicemail_description ";
	$sql .= "from v_voicemails ";
	$sql .= "where domain_uuid = :domain_uuid ";
	$sql .= "and voicemail_enabled = 'true' ";
	$sql .= "order by voicemail_id asc ";
	$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	$database = new database;
	$voicemails = $database->select($sql, $parameters);

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//show the header
	$document['title'] = $text['title-call-block'];
	require_once "resources/header.php";

//show the content
	echo "<form method='post' name='frm' id='frm'>\n";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'>";
	if ($action == "add") {
		echo "<b>".$text['label-edit-add']."</b>\n";
	}
	if ($action == "update") {
		echo "<b>".$text['label-edit-edit']."</b>\n";
	}

	echo 	"</div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$_SESSION['theme']['button_icon_back'],'id'=>'btn_back','collapse'=>'hide-xs','style'=>'margin-right: 15px;','link'=>'call_block.php']);
	if ($action == 'update' && permission_exists('call_block_delete')) {
		echo button::create(['type'=>'button','label'=>$text['button-delete'],'icon'=>$_SESSION['theme']['button_icon_delete'],'name'=>'btn_delete','collapse'=>'hide-xs','style'=>'margin-right: 15px;','onclick'=>"modal_open('modal-delete','btn_delete');"]);
	}
	echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$_SESSION['theme']['button_icon_save'],'id'=>'btn_save','collapse'=>'hide-xs']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	if ($action == 'update' && permission_exists('call_block_delete')) {
		echo modal::create(['id'=>'modal-delete','type'=>'delete','actions'=>button::create(['type'=>'submit','label'=>$text['button-continue'],'icon'=>'check','id'=>'btn_delete','style'=>'float: right; margin-left: 15px;','collapse'=>'never','name'=>'action','value'=>'delete','onclick'=>"modal_close();"])]);
	}

	if ($action == "add") {
		echo $text['label-add-note']."\n";
	}
	if ($action == "update") {
		echo $text['label-edit-note']."\n";
	}
	echo "<br /><br />\n";

	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	if (permission_exists('call_block_all')) {
		echo "<tr>\n";
		echo "<td width='30%' class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
		echo "	".$text['label-extension']."\n";
		echo "</td>\n";
		echo "<td width='70%' class='vtable' align='left'>\n";
		echo "	<select class='formfld' name='extension_uuid'>\n";
		echo "		<option value=''>".$text['label-all']."</option>\n";
		if (is_array($extensions) && sizeof($extensions) != 0) {
			foreach ($extensions as $row) {
				$selected = $extension_uuid == $row['extension_uuid'] ? "selected='selected'" : null;
				echo "	<option value='".urlencode($row["extension_uuid"])."' ".$selected.">".escape($row['extension'])." ".escape($row['description'])."</option>\n";
			}
		}
		echo "	</select>\n";
		echo "<br />\n";
		echo $text['description-extension']."\n";
		echo "\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-name']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='call_block_name' maxlength='255' value=\"".escape($call_block_name)."\">\n";
	echo "<br />\n";
	echo $text['description-call_block_name']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-country_code']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='call_block_country_code' maxlength='6' value=\"".escape($call_block_country_code)."\">\n";
	echo "<br />\n";
	echo $text['description-country_code']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-number']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='call_block_number' maxlength='255' value=\"".escape($call_block_number)."\">\n";
	echo "<br />\n";
	echo $text['description-call_block_number']."\n";
	echo "<br />\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-action']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	function call_block_action_select($label = false) {
		global $select_margin, $text, $call_block_app, $call_block_data, $extensions, $voicemails;
		echo "<select class='formfld' style='".$select_margin."' name='call_block_action'>\n";
		if ($label) {
			echo "	<option value='' disabled='disabled'>".$text['label-action']."</option>\n";
		}
		if ($call_block_app == "reject") {
			echo "	<option value='reject' selected='selected'>".$text['label-reject']."</option>\n";
		}
		else {
			echo "	<option value='reject' >".$text['label-reject']."</option>\n";
		}
		if ($call_block_app == "busy") {
			echo "	<option value='busy' selected='selected'>".$text['label-busy']."</option>\n";
		}
		else {
			echo "	<option value='busy'>".$text['label-busy']."</option>\n";
		}
		if ($call_block_app == "hold") {
			echo "	<option value='hold' selected='selected'>".$text['label-hold']."</option>\n";
		}
		else {
			echo "	<option value='hold'>".$text['label-hold']."</option>\n";
		}
		if (permission_exists('call_block_extension')) {
			if (is_array($extensions) && sizeof($extensions) != 0) {
				echo "	<optgroup label='".$text['label-extension']."'>\n";
				foreach ($extensions as &$row) {
					$selected = ($call_block_app == 'extension' && $call_block_data == $row['extension']) ? "selected='selected'" : null;
					echo "		<option value='extension:".urlencode($row["extension"])."' ".$selected.">".escape($row['extension'])." ".escape($row['description'])."</option>\n";
				}
				echo "	</optgroup>\n";
			}
		}
		if (permission_exists('call_block_voicemail')) {
			if (is_array($voicemails) && sizeof($voicemails) != 0) {
				echo "	<optgroup label='".$text['label-voicemail']."'>\n";
				foreach ($voicemails as &$row) {
					$selected = ($call_block_app == 'voicemail' && $call_block_data == $row['voicemail_id']) ? "selected='selected'" : null;
					echo "		<option value='voicemail:".urlencode($row["voicemail_id"])."' ".$selected.">".escape($row['voicemail_id'])." ".escape($row['voicemail_description'])."</option>\n";
				}
				echo "	</optgroup>\n";
			}
		}
		echo "	</select>";
	}
	call_block_action_select();
	echo "<br />\n";
	echo $text['description-action']."\n";
	echo "\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-enabled']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='call_block_enabled'>\n";
	echo "		<option value='true' ".(($call_block_enabled == "true") ? "selected" : null).">".$text['label-true']."</option>\n";
	echo "		<option value='false' ".(($call_block_enabled == "false") ? "selected" : null).">".$text['label-false']."</option>\n";
	echo "	</select>\n";
	echo "<br />\n";
	echo $text['description-enable']."\n";
	echo "\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-description']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='call_block_description' maxlength='255' value=\"".escape($call_block_description)."\">\n";
	echo "<br />\n";
	echo $text['description-description']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "</table>";
	echo "<br><br>";

	if ($action == "update") {
		echo "<input type='hidden' name='call_block_uuid' value='".escape($call_block_uuid)."'>\n";
	}
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";

	echo "</form>";

//get recent calls from the db (if not editing an existing call block record)
	if (!is_uuid($_REQUEST["id"])) {

		if (permission_exists('call_block_all')) {
			$sql = "select caller_id_number, caller_id_name, caller_id_number, start_epoch, direction, hangup_cause, duration, billsec, xml_cdr_uuid ";
			$sql .= "from v_xml_cdr where true ";
			$sql .= "and domain_uuid = :domain_uuid ";
			$sql .= "and direction != 'outbound' ";
			$sql .= "order by start_stamp desc ";
			$sql .= limit_offset($_SESSION['call_block']['recent_call_limit']['text']);
			$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
			$database = new database;
			$result = $database->select($sql, $parameters);
			unset($sql, $parameters);
		}

		if (!permission_exists('call_block_all') && is_array($_SESSION['user']['extension'])) {
			foreach ($_SESSION['user']['extension'] as $assigned_extension) {
				$assigned_extensions[$assigned_extension['extension_uuid']] = $assigned_extension['user'];
			}

			$sql = "select caller_id_number, caller_id_name, caller_id_number, start_epoch, direction, hangup_cause, duration, billsec, xml_cdr_uuid ";
			$sql .= "from v_xml_cdr ";
			$sql .= "where domain_uuid = :domain_uuid ";
				if (is_array($assigned_extensions) && sizeof($assigned_extensions) != 0) {
					$x = 0;
					foreach ($assigned_extensions as $assigned_extension_uuid => $assigned_extension) {
						$sql_where_array[] = "extension_uuid = :extension_uuid_".$x;
						//$sql_where_array[] = "caller_id_number = :caller_id_number_".$x;
						//$sql_where_array[] = "destination_number = :destination_number_1_".$x;
						//$sql_where_array[] = "destination_number = :destination_number_2_".$x;
						$parameters['extension_uuid_'.$x] = $assigned_extension_uuid;
						//$parameters['caller_id_number_'.$x] = $assigned_extension;
						//$parameters['destination_number_1_'.$x] = $assigned_extension;
						//$parameters['destination_number_2_'.$x] = '*99'.$assigned_extension;
						$x++;
					}
					if (is_array($sql_where_array) && sizeof($sql_where_array) != 0) {
						$sql .= "and (".implode(' or ', $sql_where_array).") ";
					}
					unset($sql_where_array);
				}
			$sql .= "order by start_stamp desc";
			$sql .= limit_offset($_SESSION['call_block']['recent_call_limit']['text']);
			$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
			$database = new database;
			$result = $database->select($sql, $parameters, 'all');
		}

		echo "<form id='form_list' method='post'>\n";
		echo "<input type='hidden' id='action' name='action' value='add'>\n";

		echo "<div class='action_bar' id='action_bar_sub'>\n";
		echo "	<div class='heading'><b id='heading_sub'>".$text['heading-recent_calls']."</b></div>\n";
		echo "	<div class='actions'>\n";
		echo button::create(['type'=>'button','id'=>'action_bar_sub_button_back','label'=>$text['button-back'],'icon'=>$_SESSION['theme']['button_icon_back'],'collapse'=>'hide-xs','style'=>'display: none;','link'=>'call_block.php']);
		if ($result) {
			$select_margin = 'margin-left: 15px;';
			if (permission_exists('call_block_all')) {
				echo 	"<select class='formfld' style='".$select_margin."' name='extension_uuid'>\n";
				echo "		<option value='' disabled='disabled'>".$text['label-extension']."</option>\n";
				echo "		<option value='' selected='selected'>".$text['label-all']."</option>\n";
				if (is_array($extensions) && sizeof($extensions) != 0) {
					foreach ($extensions as $row) {
						$selected = $extension_uuid == $row['extension_uuid'] ? "selected='selected'" : null;
						echo "	<option value='".urlencode($row["extension_uuid"])."' ".$selected.">".escape($row['extension'])." ".escape($row['description'])."</option>\n";
					}
				}
				echo "	</select>";
				unset($select_margin);
			}
			call_block_action_select(true);
			echo button::create(['type'=>'button','label'=>$text['button-block'],'icon'=>'ban','collapse'=>'hide-xs','onclick'=>"modal_open('modal-block','btn_block');"]);
		}
		echo 	"</div>\n";
		echo "	<div style='clear: both;'></div>\n";
		echo "</div>\n";

		if ($result) {
			echo modal::create(['id'=>'modal-block','type'=>'general','message'=>$text['confirm-block'],'actions'=>button::create(['type'=>'button','label'=>$text['button-continue'],'icon'=>'check','id'=>'btn_block','style'=>'float: right; margin-left: 15px;','collapse'=>'never','onclick'=>"modal_close(); list_form_submit('form_list');"])]);
		}

		echo "<table class='list'>\n";
		echo "<tr class='list-header'>\n";
		echo "	<th class='checkbox'>\n";
		echo "		<input type='checkbox' id='checkbox_all' name='checkbox_all' onclick='list_all_toggle();' ".($result ?: "style='visibility: hidden;'").">\n";
		echo "	</th>\n";
		echo "<th style='width: 1%;'>&nbsp;</th>\n";
		echo th_order_by('caller_id_name', $text['label-name'], $order_by, $order);
		echo th_order_by('caller_id_number', $text['label-number'], $order_by, $order);
		echo th_order_by('start_stamp', $text['label-called-on'], $order_by, $order);
		echo th_order_by('duration', $text['label-duration'], $order_by, $order, null, "class='right hide-sm-dn'");
		echo "</tr>";

		if (is_array($result) && @sizeof($result) != 0) {
			$x = 0;
			foreach ($result as $row) {
				$list_row_onclick_uncheck = "if (!this.checked) { document.getElementById('checkbox_all').checked = false; }";
				$list_row_onclick_toggle = "onclick=\"document.getElementById('checkbox_".$x."').checked = document.getElementById('checkbox_".$x."').checked ? false : true; ".$list_row_onclick_uncheck."\"";
				if (strlen($row['caller_id_number']) >= 7) {
					if ($_SESSION['domain']['time_format']['text'] == '24h') {
						$tmp_start_epoch = date('j M Y', $row['start_epoch'])." <span class='hide-sm-dn'>".date('H:i:s', $row['start_epoch']).'</span>';
					}
					else {
						$tmp_start_epoch = date('j M Y', $row['start_epoch'])." <span class='hide-sm-dn'>".date('h:i:s a', $row['start_epoch']).'</span>';
					}
					echo "<tr class='list-row' href='".$list_row_url."'>\n";
					echo "	<td class='checkbox'>\n";
					echo "		<input type='checkbox' name='xml_cdrs[$x][checked]' id='checkbox_".$x."' value='true' onclick=\"".$list_row_onclick_uncheck."\">\n";
					echo "		<input type='hidden' name='xml_cdrs[$x][uuid]' value='".escape($row['xml_cdr_uuid'])."' />\n";
					echo "	</td>\n";
					if (
						file_exists($_SERVER["DOCUMENT_ROOT"]."/themes/".$_SESSION['domain']['template']['name']."/images/icon_cdr_inbound_voicemail.png") &&
						file_exists($_SERVER["DOCUMENT_ROOT"]."/themes/".$_SESSION['domain']['template']['name']."/images/icon_cdr_inbound_answered.png") &&
						file_exists($_SERVER["DOCUMENT_ROOT"]."/themes/".$_SESSION['domain']['template']['name']."/images/icon_cdr_local_failed.png") &&
						file_exists($_SERVER["DOCUMENT_ROOT"]."/themes/".$_SESSION['domain']['template']['name']."/images/icon_cdr_local_answered.png")
						) {
						echo "	<td class='center' ".$list_row_onclick_toggle.">";
						switch ($row['direction']) {
							case "inbound" :
								if ($row['billsec'] == 0) {
									echo "<img src='/themes/".$_SESSION['domain']['template']['name']."/images/icon_cdr_inbound_voicemail.png' style='border: none;' alt='".$text['label-inbound']." ".$text['label-missed']."'>\n";
								}
								else {
									echo "<img src='/themes/".$_SESSION['domain']['template']['name']."/images/icon_cdr_inbound_answered.png' style='border: none;' alt='".$text['label-inbound']."'>\n";
								}
								break;
							case "local" :
								if ($row['billsec'] == 0) {
									echo "<img src='/themes/".$_SESSION['domain']['template']['name']."/images/icon_cdr_local_failed.png' style='border: none;' alt='".$text['label-local']." ".$text['label-failed']."'>\n";
								}
								else {
									echo "<img src='/themes/".$_SESSION['domain']['template']['name']."/images/icon_cdr_local_answered.png' style='border: none;' alt='".$text['label-local']."'>\n";
								}
								break;
						}
						echo "	</td>\n";
					}
					else {
						echo "	<td ".$list_row_onclick_toggle.">&nbsp;</td>";
					}
					echo "	<td ".$list_row_onclick_toggle.">".$row['caller_id_name']." </td>\n";
					echo "	<td ".$list_row_onclick_toggle.">".format_phone($row['caller_id_number'])."</td>\n";
					echo "	<td class='no-wrap' ".$list_row_onclick_toggle.">".$tmp_start_epoch."</td>\n";
					$seconds = ($row['hangup_cause'] == "ORIGINATOR_CANCEL") ? $row['duration'] : $row['billsec'];  //if they cancelled, show the ring time, not the bill time.
					echo "	<td class='right hide-sm-dn' ".$list_row_onclick_toggle.">".gmdate("G:i:s", $seconds)."</td>\n";
					echo "</tr>\n";
					$x++;
				}
			}
			unset($result);

		}

		echo "</table>\n";
		echo "<br />\n";
		echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
		echo "</form>\n";

	}

//make sub action bar sticky
	echo "<script>\n";
	echo "	window.addEventListener('scroll', function(){\n";
	echo "		action_bar_scroll('action_bar_sub', 480, heading_modify, heading_restore);\n";
	echo "	}, false);\n";

	echo "	function heading_modify() {\n";
	echo "		document.getElementById('heading_sub').innerHTML = \"".$text['heading-block_recent_calls']."\";\n";
	echo "		document.getElementById('action_bar_sub_button_back').style.display = 'inline-block';\n";
	echo "	}\n";

	echo "	function heading_restore() {\n";
	echo "		document.getElementById('heading_sub').innerHTML = \"".$text['heading-recent_calls']."\";\n";
	echo "		document.getElementById('action_bar_sub_button_back').style.display = 'none';\n";
	echo "	}\n";

	echo "</script>\n";

//include the footer
	require_once "resources/footer.php";

?>