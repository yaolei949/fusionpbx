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
	Portions created by the Initial Developer are Copyright (C) 2008-2020
	the Initial Developer. All Rights Reserved.

	Contributor(s):
	Mark J Crane <markjcrane@fusionpbx.com>
	Luis Daniel Lucio Quiroz <dlucio@okay.com.mx>
*/

//includes
	require_once "root.php";
	require_once "resources/require.php";
	require_once "resources/check_auth.php";

//check permissions
	if (permission_exists('conference_room_add') || permission_exists('conference_room_edit')) {
		//access granted
	}
	else {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//action add or update
	if (is_uuid($_REQUEST["id"])) {
		$action = "update";
		$conference_room_uuid = $_REQUEST["id"];
	}
	else {
		$action = "add";
	}

//get http post variables and set them to php variables
	if (count($_POST) > 0) {
		$conference_center_uuid = $_POST["conference_center_uuid"];
		$meeting_uuid = $_POST["meeting_uuid"];
		$conference_room_name = $_POST['conference_room_name'];
		$moderator_pin = $_POST["moderator_pin"];
		$participant_pin = $_POST["participant_pin"];
		$profile = $_POST["profile"];
		$record = $_POST["record"];
		$user_uuid = $_POST["user_uuid"];
		$max_members = $_POST["max_members"];
		$start_datetime = $_POST["start_datetime"];
		$stop_datetime = $_POST["stop_datetime"];
		$wait_mod = $_POST["wait_mod"];
		$moderator_endconf = $_POST["moderator_endconf"];
		$announce_name = $_POST["announce_name"];
		$announce_recording = $_POST["announce_recording"];
		$announce_count = $_POST["announce_count"];
		$sounds = $_POST["sounds"];
		$mute = $_POST["mute"];
		$created = $_POST["created"];
		$created_by = $_POST["created_by"];
		$enabled = $_POST["enabled"];
		$description = $_POST["description"];

		//remove any pin number formatting
		$moderator_pin = preg_replace('{\D}', '', $moderator_pin);
		$participant_pin = preg_replace('{\D}', '', $participant_pin);
	}

//get the conference centers array and set a default conference center
	$sql = "select * from v_conference_centers ";
	$sql .= "where domain_uuid = :domain_uuid ";
	$sql .= "order by conference_center_name asc ";
	$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	$database = new database;
	$conference_centers = $database->select($sql, $parameters, 'all');
	if (!is_uuid($conference_center_uuid)) {
		$conference_center_uuid = $conference_centers[0]["conference_center_uuid"];
	}
	unset($sql, $parameters);

//get the conference profiles
	$sql = "select * ";
	$sql .= "from v_conference_profiles ";
	$sql .= "where profile_enabled = 'true' ";
	$sql .= "and profile_name <> 'sla' ";
	$database = new database;
	$conference_profiles = $database->select($sql, null, 'all');
	unset ($sql);

//set the default
	if ($profile === "") { $profile = "default"; }

//define fucntion get_meeting_pin - used to find a unique pin number
	function get_meeting_pin($length, $meeting_uuid) {
		$pin = generate_password($length,1);
		$sql = "select count(*) from v_meetings ";
		$sql .= "where domain_uuid = :domain_uuid ";
		//$sql .= "and meeting_uuid <> :meeting_uuid ";
		$sql .= "and (moderator_pin = :pin or participant_pin = :pin) ";
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		//$parameters['meeting_uuid'] = $meeting_uuid;
		$parameters['pin'] = $pin;
		$database = new database;
		$num_rows = $database->select($sql, $parameters, 'column');
		if ($num_rows == 0) {
			return $pin;
		}
		else {
			get_meeting_pin($length, $uuid);
		}
		unset($sql, $parameters);
	}

//record announcment
	if ($record == "true") {
		//prepare the values
			$default_language = 'en';
			$default_dialect = 'us';
			$default_voice = 'callie';
			$switch_cmd = "conference ".$meeting_uuid."@".$_SESSION['domain_name']." play ".$_SESSION['switch']['sounds']['dir']."/".$default_language."/".$default_dialect."/".$default_voice."/ivr/ivr-recording_started.wav";
		//connect to event socket
			$fp = event_socket_create($_SESSION['event_socket_ip_address'], $_SESSION['event_socket_port'], $_SESSION['event_socket_password']);
			if ($fp) {
				$switch_result = event_socket_request($fp, 'api '.$switch_cmd);
			}
	}

//generate the pins
	$sql = "select conference_center_pin_length ";
	$sql .= "from v_conference_centers ";
	$sql .= "where domain_uuid = :domain_uuid ";
	if (is_uuid($conference_center_uuid)) {
		$sql .= "and conference_center_uuid = :conference_center_uuid ";
		$parameters['conference_center_uuid'] = $conference_center_uuid;
	}
	$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	$database = new database;
	$row = $database->select($sql, $parameters, 'row');
	if (is_array($row) && sizeof($row) != 0) {
		$pin_length = $row['conference_center_pin_length'];
	}
	unset($sql, $parameters);
	if (strlen($moderator_pin) == 0) {
		$moderator_pin = get_meeting_pin($pin_length, $meeting_uuid);
	}
	if (strlen($participant_pin) == 0) {
		$participant_pin = get_meeting_pin($pin_length, $meeting_uuid);
	}

//delete the user
	if ($_GET["a"] == "delete" && permission_exists('conference_room_delete')) {
		if (strlen($_REQUEST["meeting_user_uuid"]) > 0) {
			//set the variables
				$meeting_user_uuid = $_REQUEST["meeting_user_uuid"];
				$conference_room_uuid = $_REQUEST["conference_room_uuid"];
			//delete the extension from the ring_group
				$array['meeting_users'][0]['meeting_user_uuid'] = $meeting_user_uuid;
				$array['meeting_users'][0]['domain_uuid'] = $_SESSION['domain_uuid'];
				$database = new database;
				$database->app_name = 'conference_centers';
				$database->app_uuid = '8d083f5a-f726-42a8-9ffa-8d28f848f10e';
				$database->delete($array);
				unset($array);
		}

		message::add($text['message-delete']);
		header("Location: conference_room_edit.php?id=".escape($conference_room_uuid));
		return;
	}


if (count($_POST) > 0 && strlen($_POST["persistformvar"]) == 0) {

	$msg = '';
	if ($action == "update") {
		$conference_room_uuid = $_POST["conference_room_uuid"];
	}

	//validate the token
		$token = new token;
		if (!$token->validate($_SERVER['PHP_SELF'])) {
			message::add($text['message-invalid_token'],'negative');
			header('Location: conference_rooms.php');
			exit;
		}

	//check for a unique pin number and length
		if (strlen($moderator_pin) > 0 || strlen($participant_pin) > 0) {
			//make sure the moderator pin number is unique
				$sql = "select count(*) from v_meetings ";
				$sql .= "where domain_uuid = :domain_uuid ";
				if (is_uuid($meeting_uuid)) {
					$sql .= "and meeting_uuid <> :meeting_uuid ";
					$parameters['meeting_uuid'] = $meeting_uuid;
				}
				$sql .= "and (";
				$sql .= "moderator_pin = :moderator_pin ";
				$sql .= "or participant_pin = :moderator_pin ";
				$sql .= ") ";
				$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
				$parameters['moderator_pin'] = $moderator_pin;
				$database = new database;
				$num_rows = $database->select($sql, $parameters, 'column');
				if ($num_rows > 0) {
					$msg .= $text['message-unique_moderator_pin']."<br />\n";
				}
				unset($sql, $parameters);

			//make sure the participant pin number is unique
				$sql = "select count(*) from v_meetings ";
				$sql .= "where domain_uuid = :domain_uuid ";
				if (is_uuid($meeting_uuid)) {
					$sql .= "and meeting_uuid <> :meeting_uuid ";
					$parameters['meeting_uuid'] = $meeting_uuid;
				}
				$sql .= "and (";
				$sql .= "moderator_pin = :participant_pin ";
				$sql .= "or participant_pin = :participant_pin ";
				$sql .= ") ";
				$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
				$parameters['participant_pin'] = $participant_pin;
				$num_rows = $database->select($sql, $parameters, 'column');
				if ($num_rows > 0) {
					$msg .= $text['message-unique_participant_pin']."<br />\n";
				}
				unset($sql, $parameters);

			//additional checks
				if ($moderator_pin == $participant_pin) {
					$msg .= $text['message-non_unique_pin']."<br />\n";
				}
				if (strlen($moderator_pin) < $pin_length || strlen($participant_pin) < $pin_length) {
					$msg .= $text['message-minimum_pin_length']." ".$pin_length."<br />\n";
				}
		}

	//check for all required data
		//if (strlen($conference_center_uuid) == 0) { $msg .= "Please provide: Conference UUID<br>\n"; }
		//if (strlen($max_members) == 0) { $msg .= "Please provide: Max Members<br>\n"; }
		//if (strlen($start_datetime) == 0) { $msg .= "Please provide: Start Date/Time<br>\n"; }
		//if (strlen($stop_datetime) == 0) { $msg .= "Please provide: Stop Date/Time<br>\n"; }
		//if (strlen($wait_mod) == 0) { $msg .= "Please provide: Wait for the Moderator<br>\n"; }
		//if (strlen($profile) == 0) { $msg .= "Please provide: Conference Profile<br>\n"; }
		//if (strlen($announce) == 0) { $msg .= "Please provide: Announce<br>\n"; }
		//if (strlen($enter_sound) == 0) { $msg .= "Please provide: Enter Sound<br>\n"; }
		//if (strlen($mute) == 0) { $msg .= "Please provide: Mute<br>\n"; }
		//if (strlen($sounds) == 0) { $msg .= "Please provide: Sounds<br>\n"; }
		//if (strlen($created) == 0) { $msg .= "Please provide: Created<br>\n"; }
		//if (strlen($created_by) == 0) { $msg .= "Please provide: Created By<br>\n"; }
		//if (strlen($enabled) == 0) { $msg .= "Please provide: Enabled<br>\n"; }
		//if (strlen($description) == 0) { $msg .= "Please provide: Description<br>\n"; }
		if (strlen($msg) > 0 && strlen($_POST["persistformvar"]) == 0) {
			$document['title'] = $text['title-conference_room'];
			require_once "resources/header.php";
			require_once "resources/persist_form_var.php";
			echo "<div align='center'>\n";
			echo "<table><tr><td>\n";
			echo $msg."<br />";
			echo "</td></tr></table>\n";
			persistformvar($_POST);
			echo "</div>\n";
			require_once "resources/footer.php";
			exit;
		}

	//add or update the database
		if ($_POST["persistformvar"] != "true") {

			if ($action == "add" && permission_exists('conference_room_add')) {
				//set default values
					if (strlen($profile) == 0) { $profile = 'default'; }
					if (strlen($record) == 0) { $record = 'false'; }
					if (strlen($max_members) == 0) { $max_members = 0; }
					if (strlen($wait_mod) == 0) { $wait_mod = 'true'; }
					if (strlen($moderator_endconf) == 0) { $moderator_endconf = 'false'; }
					if (strlen($announce_name) == 0) { $announce_name = 'true'; }
					if (strlen($announce_recording) == 0) { $announce_recording = 'true'; }
					if (strlen($announce_count) == 0) { $announce_count = 'true'; }
					if (strlen($mute) == 0) { $mute = 'false'; }
					if (strlen($enabled) == 0) { $enabled = 'true'; }
					if (strlen($sounds) == 0) { $sounds = 'false'; }

				//add a meeting
					$meeting_uuid = uuid();
					$array['meetings'][0]['meeting_uuid'] = $meeting_uuid;
					$array['meetings'][0]['domain_uuid'] = $_SESSION['domain_uuid'];
					$array['meetings'][0]['moderator_pin'] = $moderator_pin;
					$array['meetings'][0]['participant_pin'] = $participant_pin;
					$array['meetings'][0]['enabled'] = $enabled;
					$array['meetings'][0]['description'] = $description;

					$p = new permissions;
					$p->add('meeting_add', 'temp');

					$database = new database;
					$database->app_name = 'conference_centers';
					$database->app_uuid = '8d083f5a-f726-42a8-9ffa-8d28f848f10e';
					$database->save($array);
					unset($array);

					$p->delete('meeting_add', 'temp');

				//add a conference room
					$conference_room_uuid = uuid();
					$array['conference_rooms'][0]['conference_room_uuid'] = $conference_room_uuid;
					$array['conference_rooms'][0]['conference_center_uuid'] = $conference_center_uuid;
					$array['conference_rooms'][0]['domain_uuid'] = $_SESSION['domain_uuid'];
					$array['conference_rooms'][0]['meeting_uuid'] = $meeting_uuid;
					$array['conference_rooms'][0]['conference_room_name'] = $conference_room_name;
					$array['conference_rooms'][0]['profile'] = $profile;
					$array['conference_rooms'][0]['record'] = $record;
					$array['conference_rooms'][0]['max_members'] = $max_members;
					$array['conference_rooms'][0]['start_datetime'] = $start_datetime;
					$array['conference_rooms'][0]['stop_datetime'] = $stop_datetime;
					$array['conference_rooms'][0]['wait_mod'] = $wait_mod;
					$array['conference_rooms'][0]['moderator_endconf'] = $moderator_endconf;
					$array['conference_rooms'][0]['announce_name'] = $announce_name;
					$array['conference_rooms'][0]['announce_recording'] = $announce_recording;
					$array['conference_rooms'][0]['announce_count'] = $announce_count;
					$array['conference_rooms'][0]['sounds'] = $sounds;
					$array['conference_rooms'][0]['mute'] = $mute;
					$array['conference_rooms'][0]['created'] = 'now()';
					$array['conference_rooms'][0]['created_by'] = $_SESSION['user_uuid'];
					$array['conference_rooms'][0]['enabled'] = $enabled;
					$array['conference_rooms'][0]['description'] = $description;

					$database = new database;
					$database->app_name = 'conference_centers';
					$database->app_uuid = '8d083f5a-f726-42a8-9ffa-8d28f848f10e';
					$database->save($array);
					unset($array);

				//assign the logged in user to the meeting
					if (is_uuid($_SESSION["user_uuid"])) {
						$meeting_user_uuid = uuid();
						$array['meeting_users'][0]['meeting_user_uuid'] = $meeting_user_uuid;
						$array['meeting_users'][0]['domain_uuid'] = $_SESSION['domain_uuid'];
						$array['meeting_users'][0]['meeting_uuid'] = $meeting_uuid;
						$array['meeting_users'][0]['user_uuid'] = $_SESSION["user_uuid"];

						$p = new permissions;
						$p->add('meeting_user_add', 'temp');

						$database = new database;
						$database->app_name = 'conference_centers';
						$database->app_uuid = '8d083f5a-f726-42a8-9ffa-8d28f848f10e';
						$database->save($array);
						unset($array);

						$p->delete('meeting_user_add', 'temp');
					}

				message::add($text['message-add']);
			}

			if ($action == "update" && permission_exists('conference_room_edit')) {
				//get the meeting_uuid
					if (count($_GET) > 0 && $_POST["persistformvar"] != "true") {
						$conference_room_uuid = $_GET["id"];
						$sql = "select * from v_conference_rooms ";
						$sql .= "where domain_uuid = :domain_uuid ";
						$sql .= "and conference_room_uuid = :conference_room_uuid ";
						$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
						$parameters['conference_room_uuid'] = $conference_room_uuid;
						$database = new database;
						$row = $database->select($sql, $parameters, 'row');
						if (is_array($row) && sizeof($row) != 0) {
							$meeting_uuid = $row["meeting_uuid"];
						}
						unset($sql, $parameters, $row);
					}

				//update conference meetings
					$array['meetings'][0]['meeting_uuid'] = $meeting_uuid;
					$array['meetings'][0]['domain_uuid'] = $_SESSION['domain_uuid'];
					$array['meetings'][0]['moderator_pin'] = $moderator_pin;
					$array['meetings'][0]['participant_pin'] = $participant_pin;
					$array['meetings'][0]['enabled'] = $enabled;
					$array['meetings'][0]['description'] = $description;

					$p = new permissions;
					$p->add('meeting_edit', 'temp');

					$database = new database;
					$database->app_name = 'conference_centers';
					$database->app_uuid = '8d083f5a-f726-42a8-9ffa-8d28f848f10e';
					$database->save($array);
					unset($array);

					$p->delete('meeting_edit', 'temp');

				//update the conference room
					$array['conference_rooms'][0]['conference_room_uuid'] = $conference_room_uuid;
					$array['conference_rooms'][0]['domain_uuid'] = $_SESSION['domain_uuid'];
					$array['conference_rooms'][0]['conference_center_uuid'] = $conference_center_uuid;
					$array['conference_rooms'][0]['conference_room_name'] = $conference_room_name;
					if (strlen($profile) > 0) {
						$array['conference_rooms'][0]['profile'] = $profile;
					}
					if (strlen($record) > 0) {
						$array['conference_rooms'][0]['record'] = $record;
					}
					if (strlen($max_members) > 0) {
						$array['conference_rooms'][0]['max_members'] = $max_members;
					}
					$array['conference_rooms'][0]['start_datetime'] = $start_datetime;
					$array['conference_rooms'][0]['stop_datetime'] = $stop_datetime;
					if (strlen($wait_mod) > 0) {
						$array['conference_rooms'][0]['wait_mod'] = $wait_mod;
					}
					if (strlen($moderator_endconf) > 0) {
						$array['conference_rooms'][0]['moderator_endconf'] = $moderator_endconf;
					}
					if (strlen($announce_name) > 0) {
						$array['conference_rooms'][0]['announce_name'] = $announce_name;
					}
					if (strlen($announce_name) > 0) {
						$array['conference_rooms'][0]['announce_recording'] = $announce_recording;
					}
					if (strlen($announce_name) > 0) {
						$array['conference_rooms'][0]['announce_count'] = $announce_count;
					}
					if (strlen($mute) > 0) {
						$array['conference_rooms'][0]['mute'] = $mute;
					}
					$array['conference_rooms'][0]['sounds'] = $sounds;
					if (strlen($enabled) > 0) {
						$array['conference_rooms'][0]['enabled'] = $enabled;
					}
					$array['conference_rooms'][0]['description'] = $description;

					$database = new database;
					$database->app_name = 'conference_centers';
					$database->app_uuid = '8d083f5a-f726-42a8-9ffa-8d28f848f10e';
					$database->save($array);
					unset($array);

				//set message
					message::add($text['message-update']);
			}

			//assign the user to the meeting
				if (is_uuid($user_uuid)) {
					$meeting_user_uuid = uuid();
					$array['meeting_users'][0]['meeting_user_uuid'] = $meeting_user_uuid;
					$array['meeting_users'][0]['domain_uuid'] = $_SESSION['domain_uuid'];
					$array['meeting_users'][0]['meeting_uuid'] = $meeting_uuid;
					$array['meeting_users'][0]['user_uuid'] = $user_uuid;

					$p = new permissions;
					$p->add('meeting_user_add', 'temp');

					$database = new database;
					$database->app_name = 'conference_centers';
					$database->app_uuid = '8d083f5a-f726-42a8-9ffa-8d28f848f10e';
					$database->save($array);
					unset($array);

					$p->delete('meeting_user_add', 'temp');

					message::add($text['message-add']);
				}

			//redirect
				header("Location: conference_room_edit.php?id=".escape($conference_room_uuid));
				exit;

		}
}

//pre-populate the form
	if (count($_GET) > 0 && $_POST["persistformvar"] != "true") {
		//get the conference room details
			$conference_room_uuid = $_REQUEST["id"];
			$sql = "select * from v_conference_rooms as r, v_meetings as m ";
			$sql .= "where r.domain_uuid = :domain_uuid ";
			$sql .= "and r.meeting_uuid = m.meeting_uuid ";
			$sql .= "and r.conference_room_uuid = :conference_room_uuid ";
			$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
			$parameters['conference_room_uuid'] = $conference_room_uuid;
			$database = new database;
			$row = $database->select($sql, $parameters, 'row');
			if (is_array($row) && sizeof($row) != 0) {
				$conference_center_uuid = $row["conference_center_uuid"];
				$meeting_uuid = $row["meeting_uuid"];
				$moderator_pin = $row["moderator_pin"];
				$participant_pin = $row["participant_pin"];
				$conference_room_name = $row["conference_room_name"];
				$profile = $row["profile"];
				$record = $row["record"];
				$max_members = $row["max_members"];
				$start_datetime = $row["start_datetime"];
				$stop_datetime = $row["stop_datetime"];
				$wait_mod = $row["wait_mod"];
				$moderator_endconf = $row["moderator_endconf"];
				$announce_name = $row["announce_name"];
				$announce_recording = $row["announce_recording"];
				$announce_count = $row["announce_count"];
				$sounds = $row["sounds"];
				$mute = $row["mute"];
				$created = $row["created"];
				$created_by = $row["created_by"];
				$enabled = $row["enabled"];
				$description = $row["description"];
			}
			unset($sql, $parameters, $row);
	}

//get the users assigned to this meeting
	$sql = "select u.username, u.user_uuid, m.meeting_user_uuid ";
	$sql .= "from v_users as u, v_meeting_users as m ";
	$sql .= "where u.user_uuid = m.user_uuid  ";
	$sql .= "and m.domain_uuid = :domain_uuid ";
	$sql .= "and m.meeting_uuid = :meeting_uuid ";
	$sql .= "order by u.username asc ";
	$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	$parameters['meeting_uuid'] = $meeting_uuid;
	$database = new database;
	$rows = $database->select($sql, $parameters, 'all');
	if (is_array($rows) && @sizeof($rows) != 0) {
		foreach ($rows as $row) {
			$meeting_users[$row['user_uuid']]['username'] = $row['username'];
			$meeting_users[$row['user_uuid']]['meeting_user_uuid'] = $row['meeting_user_uuid'];
		}
	}
	unset($sql, $parameters);

//get the users array
	$sql = "select user_uuid, username from v_users ";
	$sql .= "where domain_uuid = :domain_uuid ";
	if (is_array($meeting_users) && @sizeof($meeting_users) != 0) {
		$sql .= "and user_uuid not in ('".implode("','", array_keys($meeting_users))."') ";
	}
	$sql .= "order by username asc ";
	$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	$database = new database;
	$users = $database->select($sql, $parameters, 'all');
	unset($sql, $parameters);

//set default profile
	if (strlen($profile) == 0) { $profile = 'default'; }

//get default pins
	if (strlen($moderator_pin) == 0) {
		$moderator_pin = get_meeting_pin($pin_length, $meeting_uuid);
	}
	if (strlen($participant_pin) == 0) {
		$participant_pin = get_meeting_pin($pin_length, $meeting_uuid);
	}

//format the pins
	if (strlen($moderator_pin) == 9)  {
		$moderator_pin = substr($moderator_pin, 0, 3) ."-".  substr($moderator_pin, 3, 3) ."-". substr($moderator_pin, -3)."\n";
	}
	if (strlen($participant_pin) == 9)  {
		$participant_pin = substr($participant_pin, 0, 3) ."-".  substr($participant_pin, 3, 3) ."-". substr($participant_pin, -3)."\n";
	}

//set default values
	if (strlen($record) == 0) { $record = 'false'; }
	if (strlen($max_members) == 0) { $max_members = 0; }
	if (strlen($wait_mod) == 0) { $wait_mod = 'true'; }
	if (strlen($moderator_endconf) == 0) { $moderator_endconf = 'false'; }
	if (strlen($announce_name) == 0) { $announce_name = 'true'; }
	if (strlen($announce_recording) == 0) { $announce_recording = 'true'; }
	if (strlen($announce_count) == 0) { $announce_count = 'true'; }
	if (strlen($mute) == 0) { $mute = 'false'; }
	if (strlen($sounds) == 0) { $sounds = 'false'; }
	if (strlen($enabled) == 0) { $enabled = 'true'; }

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//show the header
	$document['title'] = $text['title-conference_room'];
	require_once "resources/header.php";

//show the content
	echo "<form method='post' name='frm' id='frm'>\n";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-conference_room']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$_SESSION['theme']['button_icon_back'],'id'=>'btn_back','link'=>'conference_rooms.php']);
	if (is_uuid($meeting_uuid)) {
		echo button::create(['type'=>'button','label'=>$text['button-view'],'icon'=>$_SESSION['theme']['button_icon_view'],'style'=>'margin-left: 15px;','link'=>'../conferences_active/conference_interactive.php?c='.urlencode($meeting_uuid)]);
		echo button::create(['type'=>'button','label'=>$text['button-sessions'],'icon'=>'list','link'=>'conference_sessions.php?id='.urlencode($meeting_uuid)]);
	}
	echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$_SESSION['theme']['button_icon_save'],'id'=>'btn_save','style'=>'margin-left: 15px;']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	echo "<tr>\n";
	echo "<td width='30%' class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-conference_name']."</td>\n";
	echo "<td width='70%' class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='conference_center_uuid'>\n";
	foreach ($conference_centers as &$row) {
		if ($conference_center_uuid == $row["conference_center_uuid"]) {
			echo "		<option value='".escape($row["conference_center_uuid"])."' selected='selected'>".escape($row["conference_center_name"])."</option>\n";
		}
		else {
			echo "		<option value='".escape($row["conference_center_uuid"])."'>".escape($row["conference_center_name"])."</option>\n";
		}
	}
	echo "	</select>\n";
	echo "	<br />\n";
	echo "\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "	<tr>";
	echo "		<td class='vncell' valign='top'>".$text['label-room-name']."</td>";
	echo "		<td class='vtable' align='left'>";
	echo "  		<input class='formfld' type='text' name='conference_room_name' maxlength='255' value='".escape($conference_room_name)."'>\n";
	echo "			<br />\n";
	echo "			".$text['description-room-name']."\n";
	echo "		</td>";
	echo "	</tr>";

	echo "	<tr>";
	echo "		<td class='vncell' valign='top'>".$text['label-moderator-pin']."</td>";
	echo "		<td class='vtable' align='left'>";
	echo "  		<input class='formfld' type='number' name='moderator_pin' maxlength='255' value='".escape($moderator_pin)."'>\n";
	echo "			<br />\n";
	echo "			".$text['description-moderator_pin']."\n";
	echo "		</td>";
	echo "	</tr>";

	echo "	<tr>";
	echo "		<td class='vncell' valign='top'>".$text['label-participant-pin']."</td>";
	echo "		<td class='vtable' align='left'>";
	echo "  		<input class='formfld' type='number' name='participant_pin' maxlength='255' value='".escape($participant_pin)."'>\n";
	echo "			<br />\n";
	echo "			".$text['description-participant-pin']."\n";
	echo "		</td>";
	echo "	</tr>";

	if (if_group("superadmin") || if_group("admin")) {
		echo "	<tr>";
		echo "		<td class='vncell' valign='top'>".$text['label-users']."</td>";
		echo "		<td class='vtable' align='left'>";
		if ($action == "update" && is_array($meeting_users) && @sizeof($meeting_users) != 0) {
			echo "			<table border='0' style='width : 235px;'>\n";
			foreach ($meeting_users as $user_uuid => $meeting_user) {
				echo "			<tr>\n";
				echo "				<td class='vtable'>".escape($meeting_user['username'])."</td>\n";
				echo "				<td style='width: 25px;' align='right'>\n";
				if (permission_exists('conference_room_delete')) {
					echo "					<a href='conference_room_edit.php?meeting_user_uuid=".escape($meeting_user['meeting_user_uuid'])."&conference_room_uuid=".escape($conference_room_uuid)."&a=delete' alt='delete' onclick=\"return confirm(".$text['confirm-delete'].")\">$v_link_label_delete</a>\n";
				}
				echo "				</td>\n";
				echo "			</tr>\n";
			}
			echo "			</table>\n";
			echo "			<br />\n";
		}
		if (permission_exists('conference_room_add') && is_array($users) && @sizeof($users) != 0) {
			echo "			<select name='user_uuid' class='formfld' style='width: auto;'>\n";
			echo "				<option value=''></option>\n";
			foreach ($users as $user) {
				echo "			<option value='".escape($user['user_uuid'])."'>".escape($user['username'])."</option>\n";
			}
			echo "			</select>";
			if ($action == "update") {
				echo button::create(['type'=>'submit','label'=>$text['button-add'],'icon'=>$_SESSION['theme']['button_icon_add']]);
			}
			unset($users);
			echo "			<br>\n";
		}
		echo "			".$text['description-users']."\n";
		echo "		</td>";
		echo "	</tr>";
	}

	if (permission_exists('conference_room_profile')) {
		echo "<tr>\n";
		echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-profile']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<select class='formfld' name='profile'>\n";
		foreach ($conference_profiles as $row) {
			if ($profile === $row['profile_name']) {
					echo "	<option value='". escape($row['profile_name']) ."' selected='selected'>". escape($row['profile_name']) ."</option>\n";
			}
			else {
					echo "	<option value='". escape($row['profile_name']) ."'>". escape($row['profile_name']) ."</option>\n";
			}
		}
		echo "	</select>\n";
		echo "	<br />\n";
		echo "	".$text['description-profile']."\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	if (permission_exists('conference_room_record')) {
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-record']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<select class='formfld' name='record'>\n";
		echo "	<option value=''></option>\n";
		if ($record == "true") {
			echo "	<option value='true' selected='selected'>".$text['label-true']."</option>\n";
		}
		else {
			echo "	<option value='true'>".$text['label-true']."</option>\n";
		}
		if ($record == "false") {
			echo "	<option value='false' selected='selected'>".$text['label-false']."</option>\n";
		}
		else {
			echo "	<option value='false'>".$text['label-false']."</option>\n";
		}
		echo "	</select>\n";
		echo "<br />\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	if (permission_exists('conference_room_max_members')) {
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-max-members']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "  <input class='formfld' type='text' name='max_members' maxlength='255' value='".escape($max_members)."'>\n";
		echo "<br />\n";
		echo "\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' nowrap='nowrap' width='30%'>".$text['label-schedule']."</td>\n";
	echo "<td class='vtable' width='70%' align='left' style='position: relative; min-width: 275px;'>\n";
	echo "		<input type='text' class='formfld datetimepicker' data-toggle='datetimepicker' data-target='#start_datetime' onblur=\"$(this).datetimepicker('hide');\" style='min-width: 115px; width: 115px; max-width: 115px;' name='start_datetime' id='start_datetime' placeholder='".$text['label-from']."' value='".escape($start_datetime)."'>\n";
	echo "		<input type='text' class='formfld datetimepicker' data-toggle='datetimepicker' data-target='#stop_datetime' onblur=\"$(this).datetimepicker('hide');\" style='min-width: 115px; width: 115px; max-width: 115px;' name='stop_datetime' id='stop_datetime' placeholder='".$text['label-to']."' value='".escape($stop_datetime)."'>\n";
	echo "	<br>".$text['description-schedule'];
	echo "</td>\n";
	echo "</tr>\n";

	if (permission_exists('conference_room_wait_mod')) {
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-wait_for_moderator']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<select class='formfld' name='wait_mod'>\n";
		echo "	<option value=''></option>\n";
		if ($wait_mod == "true") {
			echo "	<option value='true' selected='selected'>".$text['label-true']."</option>\n";
		}
		else {
			echo "	<option value='true'>".$text['label-true']."</option>\n";
		}
		if ($wait_mod == "false") {
			echo "	<option value='false' selected='selected'>".$text['label-false']."</option>\n";
		}
		else {
			echo "	<option value='false'>".$text['label-false']."</option>\n";
		}
		echo "	</select>\n";
		echo "<br />\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	if (permission_exists('conference_room_moderator_endconf')) {
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-moderator_endconf']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<select class='formfld' name='moderator_endconf'>\n";
		echo "	<option value=''></option>\n";
		if ($moderator_endconf == "true") {
			echo "	<option value='true' selected='selected'>".$text['label-true']."</option>\n";
		}
		else {
			echo "	<option value='true'>".$text['label-true']."</option>\n";
		}
		if ($moderator_endconf == "false") {
			echo "	<option value='false' selected='selected'>".$text['label-false']."</option>\n";
		}
		else {
			echo "	<option value='false'>".$text['label-false']."</option>\n";
		}
		echo "	</select>\n";
		echo "<br />\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	if (permission_exists('conference_room_announce_name')) {
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-announce_name']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<select class='formfld' name='announce_name'>\n";
		echo "	<option value=''></option>\n";
		if ($announce_name == "true") {
			echo "	<option value='true' selected='selected'>".$text['label-true']."</option>\n";
		}
		else {
			echo "	<option value='true'>".$text['label-true']."</option>\n";
		}
		if ($announce_name == "false") {
			echo "	<option value='false' selected='selected'>".$text['label-false']."</option>\n";
		}
		else {
			echo "	<option value='false'>".$text['label-false']."</option>\n";
		}
		echo "	</select>\n";
		echo "<br />\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	if (permission_exists('conference_room_announce_count')) {
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-announce_count']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<select class='formfld' name='announce_count'>\n";
		echo "	<option value=''></option>\n";
		if ($announce_count == "true") {
			echo "	<option value='true' selected='selected'>".$text['label-true']."</option>\n";
		}
		else {
			echo "	<option value='true'>".$text['label-true']."</option>\n";
		}
		if ($announce_count == "false") {
			echo "	<option value='false' selected='selected'>".$text['label-false']."</option>\n";
		}
		else {
			echo "	<option value='false'>".$text['label-false']."</option>\n";
		}
		echo "	</select>\n";
		echo "<br />\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	if (permission_exists('conference_room_announce_recording')) {
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-announce_recording']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<select class='formfld' name='announce_recording'>\n";
		echo "	<option value=''></option>\n";
		if ($announce_recording == "true") {
			echo "	<option value='true' selected='selected'>".$text['label-true']."</option>\n";
		}
		else {
			echo "	<option value='true'>".$text['label-true']."</option>\n";
		}
		if ($announce_recording == "false") {
			echo "	<option value='false' selected='selected'>".$text['label-false']."</option>\n";
		}
		else {
			echo "	<option value='false'>".$text['label-false']."</option>\n";
		}
		echo "	</select>\n";
		echo "<br />\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	//echo "<tr>\n";
	//echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	//echo "	".$text['label-enter-sound']."\n";
	//echo "</td>\n";
	//echo "<td class='vtable' align='left'>\n";
	//echo "	<input class='formfld' type='text' name='enter_sound' maxlength='255' value=\"".escape($enter_sound)."\">\n";
	//echo "<br />\n";
	//echo "\n";
	//echo "</td>\n";
	//echo "</tr>\n";

	if (permission_exists('conference_room_mute')) {
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-mute']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<select class='formfld' name='mute'>\n";
		echo "	<option value=''></option>\n";
		if ($mute == "true") {
			echo "	<option value='true' selected='selected'>".$text['label-true']."</option>\n";
		}
		else {
			echo "	<option value='true'>".$text['label-true']."</option>\n";
		}
		if ($mute == "false") {
			echo "	<option value='false' selected='selected'>".$text['label-false']."</option>\n";
		}
		else {
			echo "	<option value='false'>".$text['label-false']."</option>\n";
		}
		echo "	</select>\n";
		echo "<br />\n";
		echo "\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	if (permission_exists('conference_room_enabled')) {
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-enabled']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<select class='formfld' name='enabled'>\n";
		echo "	<option value=''></option>\n";
		if ($enabled == "true") {
			echo "	<option value='true' selected='selected'>".$text['label-true']."</option>\n";
		}
		else {
			echo "	<option value='true'>".$text['label-true']."</option>\n";
		}
		if ($enabled == "false") {
			echo "	<option value='false' selected='selected'>".$text['label-false']."</option>\n";
		}
		else {
			echo "	<option value='false'>".$text['label-false']."</option>\n";
		}
		echo "	</select>\n";
		echo "<br />\n";
		echo "\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	if (permission_exists('conference_room_sounds')) {
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-sounds']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<select class='formfld' name='sounds'>\n";
		echo "	<option value=''></option>\n";
		if ($sounds == "true") {
			echo "	<option value='true' selected='selected'>".$text['label-true']."</option>\n";
		}
		else {
			echo "	<option value='true'>".$text['label-true']."</option>\n";
		}
		if ($sounds == "false") {
			echo "	<option value='false' selected='selected'>".$text['label-false']."</option>\n";
		}
		else {
			echo "	<option value='false'>".$text['label-false']."</option>\n";
		}
		echo "	</select>\n";
		echo "<br />\n";
		echo "\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-description']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='description' maxlength='255' value=\"".escape($description)."\">\n";
	echo "<br />\n";
	echo "\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "</table>\n";
	echo "<br><br>\n";

	if ($action == "update") {
		echo "<input type='hidden' name='conference_center_uuid' value='".escape($conference_center_uuid)."'>\n";
		echo "<input type='hidden' name='meeting_uuid' value='".escape($meeting_uuid)."'>\n";
		echo "<input type='hidden' name='conference_room_uuid' value='".escape($conference_room_uuid)."'>\n";
	}
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";

	echo "</form>";

//include the footer
	require_once "resources/footer.php";

?>
