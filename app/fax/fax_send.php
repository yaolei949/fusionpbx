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
	James Rose <james.o.rose@gmail.com>
	Luis Daniel Lucio Quiroz <dlucio@okay.com.mx>
	Errol Samuels <voiptology@gmail.com>
*/

if (!isset($included)) { $included = false; }

if (stristr(PHP_OS, 'WIN')) { $IS_WINDOWS = true; } else { $IS_WINDOWS = false; }

if (!$included) {

	//includes
		include "root.php";
		require_once "resources/require.php";
		require_once "resources/check_auth.php";

	//check permissions
		if (permission_exists('fax_send')) {
			//access granted
		}
		else {
			echo "access denied";
			exit;
		}

	//add multi-lingual support
		$language = new text;
		$text = $language->get();

	//get the fax_extension and save it as a variable
		if (strlen($_REQUEST["fax_extension"]) > 0) {
			$fax_extension = $_REQUEST["fax_extension"];
		}

	//pre-populate the form
		if (is_uuid($_REQUEST['id']) && $_POST["persistformvar"] != "true") {
			$fax_uuid = $_REQUEST["id"];
			if (if_group("superadmin") || if_group("admin")) {
				//show all fax extensions
				$sql = "select fax_uuid, fax_extension, fax_caller_id_name, fax_caller_id_number, ";
				$sql .= "fax_toll_allow, accountcode, fax_send_greeting ";
				$sql .= "from v_fax ";
				$sql .= "where domain_uuid = :domain_uuid ";
				$sql .= "and fax_uuid = :fax_uuid ";
				$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
				$parameters['fax_uuid'] = $fax_uuid;
			}
			else {
				//show only assigned fax extensions
				$sql = "select f.fax_uuid, f.fax_extension, f.fax_caller_id_name, f.fax_caller_id_number, ";
				$sql .= "f.fax_toll_allow, f.accountcode, f.fax_send_greeting ";
				$sql .= "from v_fax as f, v_fax_users as u ";
				$sql .= "where f.fax_uuid = u.fax_uuid ";
				$sql .= "and f.domain_uuid = :domain_uuid ";
				$sql .= "and f.fax_uuid = :fax_uuid ";
				$sql .= "and u.user_uuid = :user_uuid ";
				$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
				$parameters['fax_uuid'] = $fax_uuid;
				$parameters['user_uuid'] = $_SESSION['user_uuid'];
			}
			$database = new database;
			$row = $database->select($sql, $parameters, 'row');
			if (is_array($row) && @sizeof($row) != 0) {
				//set database fields as variables
					$fax_uuid = $row["fax_uuid"];
					$fax_extension = $row["fax_extension"];
					$fax_caller_id_name = $row["fax_caller_id_name"];
					$fax_caller_id_number = $row["fax_caller_id_number"];
					$fax_toll_allow = $row["fax_toll_allow"];
					$fax_accountcode = $row["accountcode"];
					$fax_send_greeting = $row["fax_send_greeting"];
			}
			else {
				if (!if_group("superadmin") && !if_group("admin")) {
					echo "access denied";
					exit;
				}
			}
			unset($sql, $parameters, $row);

			$fax_send_mode = $_SESSION['fax']['send_mode']['text'];
			if(strlen($fax_send_mode) == 0){
				$fax_send_mode = 'direct';
			}
		}

	//set the fax directory
		$fax_dir = $_SESSION['switch']['storage']['dir'].'/fax/'.$_SESSION['domain_name'];

	// set fax cover font to generate pdf
		$fax_cover_font = $_SESSION['fax']['cover_font']['text'];
}
else {
	require_once "resources/classes/event_socket.php";
}

if (!function_exists('correct_path')) {
	function correct_path($p) {
		global $IS_WINDOWS;
		if ($IS_WINDOWS) {
			return str_replace('/', '\\', $p);
		}
		return $p;
	}
}

if (!function_exists('gs_cmd')) {
	function gs_cmd($args) {
		global $IS_WINDOWS;
		if ($IS_WINDOWS) {
			return 'gswin32c '.$args;
		}
		return 'gs '.$args;
	}
}

if (!function_exists('fax_enqueue')) {
	function fax_enqueue($fax_uuid, $fax_file, $wav_file, $reply_address, $fax_uri, $fax_dtmf, $dial_string){
		global $db_type;

		$fax_task_uuid = uuid();
		$dial_string .= "fax_task_uuid='" . $fax_task_uuid . "',";
		$description = ''; //! @todo add description
		if ($db_type == "pgsql") {
			$date_utc_now_sql  = "NOW() at time zone 'utc'";
		}
		if ($db_type == "mysql") {
			$date_utc_now_sql  = "UTC_TIMESTAMP()";
		}
		if ($db_type == "sqlite") {
			$date_utc_now_sql  = "datetime('now')";
		}

		$array['fax_tasks'][0]['fax_task_uuid'] = $fax_task_uuid;
		$array['fax_tasks'][0]['fax_uuid'] = $fax_uuid;
		$array['fax_tasks'][0]['task_next_time'] = $date_utc_now_sql;
		$array['fax_tasks'][0]['task_lock_time'] = null;
		$array['fax_tasks'][0]['task_fax_file'] = $fax_file;
		$array['fax_tasks'][0]['task_wav_file'] = $wav_file;
		$array['fax_tasks'][0]['task_uri'] = $fax_uri;
		$array['fax_tasks'][0]['task_dial_string'] = $dial_string;
		$array['fax_tasks'][0]['task_dtmf'] = $fax_dtmf;
		$array['fax_tasks'][0]['task_interrupted'] = 'false';
		$array['fax_tasks'][0]['task_status'] = 0;
		$array['fax_tasks'][0]['task_no_answer_counter'] = 0;
		$array['fax_tasks'][0]['task_no_answer_retry_counter'] = 0;
		$array['fax_tasks'][0]['task_retry_counter'] = 0;
		$array['fax_tasks'][0]['task_reply_address'] = $reply_address;
		$array['fax_tasks'][0]['task_description'] = $description;

		$p = new permissions;
		$p->add('fax_task_add', 'temp');

		$database = new database;
		$database->app_name = 'fax';
		$database->app_uuid = '24108154-4ac3-1db6-1551-4731703a4440';
		$database->save($array);
		$message = $database->message;
		unset($array);

		$p->delete('fax_task_add', 'temp');

		if ($message['message'] == 'OK' && $message['code'] == 200) {
			$response = 'Enqueued';
		}
		else {
			$response = 'Fail Enqueue';

			echo $message['message'].' ['.$message['code']."]<br />\n";
			if (is_array($message['error']) && @sizeof($message['error']) != 0) {
				foreach ($message['error'] as $error) {
					echo "<pre>".$error."</pre><br /><br />\n";
				}
			}
		}
		return $response;
	}
}

if (!function_exists('fax_split_dtmf')) {
	function fax_split_dtmf(&$fax_number, &$fax_dtmf){
		$tmp = array();
		$fax_dtmf = '';
		if (preg_match('/^\s*(.*?)\s*\((.*)\)\s*$/', $fax_number, $tmp)){
			$fax_number = $tmp[1];
			$fax_dtmf = $tmp[2];
		}
	}
}

//get the fax extension
	if (strlen($fax_extension) > 0) {
		//set the fax directories. example /usr/local/freeswitch/storage/fax/329/inbox
			$dir_fax_inbox = $fax_dir.'/'.$fax_extension.'/inbox';
			$dir_fax_sent = $fax_dir.'/'.$fax_extension.'/sent';
			$dir_fax_temp = $fax_dir.'/'.$fax_extension.'/temp';

		//make sure the directories exist
			if (!is_dir($_SESSION['switch']['storage']['dir'])) {
				event_socket_mkdir($_SESSION['switch']['storage']['dir']);
			}
			if (!is_dir($_SESSION['switch']['storage']['dir'].'/fax')) {
				event_socket_mkdir($_SESSION['switch']['storage']['dir'].'/fax');
			}
			if (!is_dir($_SESSION['switch']['storage']['dir'].'/fax/'.$_SESSION['domain_name'])) {
				event_socket_mkdir($_SESSION['switch']['storage']['dir'].'/fax/'.$_SESSION['domain_name']);
			}
			if (!is_dir($fax_dir.'/'.$fax_extension)) {
				event_socket_mkdir($fax_dir.'/'.$fax_extension);
			}
			if (!is_dir($dir_fax_inbox)) {
				event_socket_mkdir($dir_fax_inbox);
			}
			if (!is_dir($dir_fax_sent)) {
				event_socket_mkdir($dir_fax_sent);
			}
			if (!is_dir($dir_fax_temp)) {
				event_socket_mkdir($dir_fax_temp);
			}
	}

//clear file status cache
	clearstatcache();

//send the fax
	$continue = false;
	if (!$included) {
		if (($_POST['action'] == "send")) {
			$fax_numbers = $_POST['fax_numbers'];
			$fax_uuid = $_POST["id"];
			$fax_caller_id_name = $_POST['fax_caller_id_name'];
			$fax_caller_id_number = $_POST['fax_caller_id_number'];
			$fax_header = $_POST['fax_header'];
			$fax_sender = $_POST['fax_sender'];
			$fax_recipient = $_POST['fax_recipient'];
			$fax_subject = $_POST['fax_subject'];
			$fax_message = $_POST['fax_message'];
			$fax_resolution = $_POST['fax_resolution'];
			$fax_page_size = $_POST['fax_page_size'];
			$fax_footer = $_POST['fax_footer'];

			//validate the token
				$token = new token;
				if (!$token->validate($_SERVER['PHP_SELF'])) {
					message::add($text['message-invalid_token'],'negative');
					header('Location: fax_send.php'.(is_uuid($fax_uuid) ? '?id='.$fax_uuid : null));
					exit;
				}

			$continue = true;
		}
	}
	else {
		//all necessary local and session variables should
		//be already set by now by file including this one
		$continue = true;
	}

//cleanup numbers
	if (isset($fax_numbers)) {
		foreach ($fax_numbers as $index => $fax_number) {
			fax_split_dtmf($fax_number, $fax_dtmf);
			$fax_number = preg_replace("~[^0-9]~", "", $fax_number);
			$fax_dtmf   = preg_replace("~[^0-9Pp*#]~", "", $fax_dtmf);
			if ($fax_number != ''){
				if ($fax_dtmf != '') {$fax_number .= " (" . $fax_dtmf . ")";}
				$fax_numbers[$index] = $fax_number;
			}
			else{
				unset($fax_numbers[$index]);
			}
		}
		sort($fax_numbers);
	}

	if ($continue) {
		//determine page size
		switch ($fax_page_size) {
			case 'a4' :
				$page_width = 8.3; //in
				$page_height = 11.7; //in
				break;
			case 'legal' :
				$page_width = 8.5; //in
				$page_height = 14; //in
				break;
			case 'letter' :
			default	:
				$page_width = 8.5; //in
				$page_height = 11; //in
		}

		//set resolution
		switch ($fax_resolution) {
			case 'fine':
				$gs_r = '204x196';
				$gs_g = ((int) ($page_width * 204)).'x'.((int) ($page_height * 196));
				break;
			case 'superfine':
				$gs_r = '204x392';
				$gs_g = ((int) ($page_width * 204)).'x'.((int) ($page_height * 392));
				break;
			case 'normal':
			default:
				$gs_r = '204x98';
				$gs_g = ((int) ($page_width * 204)).'x'.((int) ($page_height * 98));
				break;
		}

		//process uploaded or emailed files (if any)
		$fax_page_count = 0;
		$_files = (!$included) ? $_FILES['fax_files'] : $emailed_files;
		unset($tif_files);
		foreach ($_files['tmp_name'] as $index => $fax_tmp_name) {
			$uploaded_file = (!$included) ? is_uploaded_file($fax_tmp_name) : true;
			if ( $uploaded_file && $_files['error'][$index] == 0 && $_files['size'][$index] > 0 ) {
				//get the file extension
				$fax_file_extension = strtolower(pathinfo($_files['name'][$index], PATHINFO_EXTENSION));
				if ($fax_file_extension == "tiff") { $fax_file_extension = "tif"; }

				//block unauthorized files
				$disallowed_file_extensions = explode(',','sh,ssh,so,dll,exe,bat,vbs,zip,rar,z,tar,tbz,tgz,gz');
				if (in_array($fax_file_extension, $disallowed_file_extensions) || $fax_file_extension == '') { continue; }

				$fax_name = $_files['name'][$index];
				$fax_name = preg_replace('/\\.[^.\\s]{3,4}$/', '', $fax_name);
				$fax_name = str_replace(" ", "_", $fax_name);

				//lua doesn't seem to like special chars with env:GetHeader
				$fax_name = str_replace(";", "_", $fax_name);
				$fax_name = str_replace(",", "_", $fax_name);
				$fax_name = str_replace("'", "_", $fax_name);
				$fax_name = str_replace("!", "_", $fax_name);
				$fax_name = str_replace("@", "_", $fax_name);
				$fax_name = str_replace("#", "_", $fax_name);
				$fax_name = str_replace("$", "_", $fax_name);
				$fax_name = str_replace("%", "_", $fax_name);
				$fax_name = str_replace("^", "_", $fax_name);
				$fax_name = str_replace("`", "_", $fax_name);
				$fax_name = str_replace("~", "_", $fax_name);
				$fax_name = str_replace("&", "_", $fax_name);
				$fax_name = str_replace("(", "_", $fax_name);
				$fax_name = str_replace(")", "_", $fax_name);
				$fax_name = str_replace("+", "_", $fax_name);
				$fax_name = str_replace("=", "_", $fax_name);

				$attachment_file_name = $_files['name'][$index];
				rename($dir_fax_temp.'/'.$attachment_file_name, $dir_fax_temp.'/'.$fax_name.'.'.$fax_file_extension);
				unset($attachment_file_name);

				if (!$included) {
					//check if directory exists
					if (!is_dir($dir_fax_temp)) {
						event_socket_mkdir($dir_fax_temp);
					}
					//move uploaded file
					move_uploaded_file($_files['tmp_name'][$index], $dir_fax_temp.'/'.$fax_name.'.'.$fax_file_extension);
				}

				//convert uploaded file to pdf, if necessary
				if ($fax_file_extension != "pdf" && $fax_file_extension != "tif") {
					chdir($dir_fax_temp);
					$command = $IS_WINDOWS ? '' : 'export HOME=/tmp && ';
					$command .= 'libreoffice --headless --convert-to pdf --outdir '.$dir_fax_temp.' '.$dir_fax_temp.'/'.$fax_name.'.'.$fax_file_extension;
					exec($command);
					@unlink($dir_fax_temp.'/'.$fax_name.'.'.$fax_file_extension);
				}

				//convert uploaded pdf to tif
				if (file_exists($dir_fax_temp.'/'.$fax_name.'.pdf')) {
					chdir($dir_fax_temp);

					//$cmd = gs_cmd("-q -sDEVICE=psmono -r".$gs_r." -g".$gs_g." -dNOPAUSE -dBATCH -dSAFER -sOutputFile=".correct_path($fax_name).".pdf -- ".correct_path($fax_name).".pdf -c quit");
					// echo($cmd . "<br/>\n");
					//exec($cmd);

					//convert pdf to tif
					$cmd = gs_cmd("-q -sDEVICE=tiffg32d -r".$gs_r." -g".$gs_g." -dBATCH -dPDFFitPage -dNOPAUSE -sOutputFile=".correct_path($fax_name).".tif -- ".correct_path($fax_name).".pdf -c quit");
					// echo($cmd . "<br/>\n");
					exec($cmd);
					@unlink($dir_fax_temp.'/'.$fax_name.'.pdf');
				}

				$cmd = "tiffinfo ".correct_path($dir_fax_temp.'/'.$fax_name).".tif | grep \"Page Number\" | grep -c \"P\"";
				// echo($cmd . "<br/>\n");
				$tif_page_count = exec($cmd);
				if ($tif_page_count != '') {
					$fax_page_count += $tif_page_count;
				}

				//add file to array
				$tif_files[] = $dir_fax_temp.'/'.$fax_name.'.tif';
			} //if
		} //foreach

		// unique id for this fax
		$fax_instance_uuid = uuid();

		//generate cover page, merge with pdf
		if ($fax_subject != '' || $fax_message != '') {

			//load pdf libraries
			require_once("resources/tcpdf/tcpdf.php");
			require_once("resources/fpdi/fpdi.php");

			// initialize pdf
			$pdf = new FPDI('P', 'in');
			$pdf->SetAutoPageBreak(false);
			$pdf->setPrintHeader(false);
			$pdf->setPrintFooter(false);
			$pdf->SetMargins(0, 0, 0, true);

			if (strlen($fax_cover_font) > 0) {
				if (substr($fax_cover_font, -4) == '.ttf') {
					$pdf_font = TCPDF_FONTS::addTTFfont($fax_cover_font);
				}
				else {
					$pdf_font = $fax_cover_font;
				}
			}

			if (!$pdf_font) {
				$pdf_font = 'times';
			}

			//add blank page
			$pdf->AddPage('P', array($page_width, $page_height));

			// content offset, if necessary
			$x = 0;
			$y = 0;

			//logo
			$display_logo = false;
			if (!is_array($_SESSION['fax']['cover_logo'])) {
				$logo = $_SERVER['DOCUMENT_ROOT'].PROJECT_PATH."/app/fax/resources/images/logo.jpg";
				$display_logo = true;
			}
			else if (is_null($_SESSION['fax']['cover_logo']['text'])) {
				$logo = ''; //explicitly empty
			}
			else if ($_SESSION['fax']['cover_logo']['text'] != '') {
				if (substr($_SESSION['fax']['cover_logo']['text'], 0, 4) == 'http') {
					$logo = $_SESSION['fax']['cover_logo']['text'];
				}
				else if (substr($_SESSION['fax']['cover_logo']['text'], 0, 1) == '/') {
					if (substr($_SESSION['fax']['cover_logo']['text'], 0, strlen($_SERVER['DOCUMENT_ROOT'])) != $_SERVER['DOCUMENT_ROOT']) {
						$logo = $_SERVER['DOCUMENT_ROOT'].$_SESSION['fax']['cover_logo']['text'];
					}
					else {
						$logo = $_SESSION['fax']['cover_logo']['text'];
					}
				}
			}
			if (isset($logo) && $logo) {
				$logo_filename = strtolower(pathinfo($logo, PATHINFO_BASENAME));
				$logo_fileext = pathinfo($logo_filename, PATHINFO_EXTENSION);
				if (in_array($logo_fileext, ['gif','jpg','jpeg','png','bmp'])) {
					if (!file_exists($dir_fax_temp.'/'.$logo_filename)) {
						$raw = file_get_contents($logo);
						if (file_put_contents($dir_fax_temp.'/'.$logo_filename, $raw)) {
							$logo = $dir_fax_temp.'/'.$logo_filename;
							$display_logo = true;
						}
						else {
							unset($logo);
						}
					}
					else {
						$logo = $dir_fax_temp.'/'.$logo_filename;
						$display_logo = true;
					}
				}
				else {
					unset($logo);
				}
			}

			if ($display_logo) {
				$pdf->Image($logo, 0.5, 0.4, 2.5, 0.9, null, null, 'N', true, 300, null, false, false, 0, true);
			}
			else {
				//set position for header text, if enabled
				$pdf->SetXY($x + 0.5, $y + 0.4);
			}

			//header
			if ($fax_header != '') {
				$pdf->SetLeftMargin(0.5);
				$pdf->SetFont($pdf_font, "", 10);
				$pdf->Write(0.3, $fax_header);
			}

			//fax, cover sheet
			$pdf->SetTextColor(0,0,0);
			$pdf->SetFont($pdf_font, "B", 55);
			$pdf->SetXY($x + 4.55, $y + 0.25);
			$pdf->Cell($x + 3.50, $y + 0.4, $text['label-fax-fax'], 0, 0, 'R', false, null, 0, false, 'T', 'T');
			$pdf->SetFont($pdf_font, "", 12);
			$pdf->SetFontSpacing(0.0425);
			$pdf->SetXY($x + 4.55, $y + 1.0);
			$pdf->Cell($x + 3.50, $y + 0.4, $text['label-fax-cover-sheet'], 0, 0, 'R', false, null, 0, false, 'T', 'T');
			$pdf->SetFontSpacing(0);

			//field labels
			$pdf->SetFont($pdf_font, "B", 12);
			if ($fax_recipient != '' || sizeof($fax_numbers) > 0) {
				$pdf->Text($x + 0.5, $y + 2.0, strtoupper($text['label-fax-recipient']).":");
			}
			if ($fax_sender != '' || $fax_caller_id_number != '') {
				$pdf->Text($x + 0.5, $y + 2.3, strtoupper($text['label-fax-sender']).":");
			}
			if ($fax_page_count > 0) {
				$pdf->Text($x + 0.5, $y + 2.6, strtoupper($text['label-fax-attached']).":");
			}
			if ($fax_subject != '') {
				$pdf->Text($x + 0.5, $y + 2.9, strtoupper($text['label-fax-subject']).":");
			}

			//field values
			$pdf->SetFont($pdf_font, "", 12);
			$pdf->SetXY($x + 2.0, $y + 1.95);
			if ($fax_recipient != '') {
				$pdf->Write(0.3, $fax_recipient);
			}
			if (sizeof($fax_numbers) > 0) {
				$fax_number_string = ($fax_recipient != '') ? ' (' : null;
				$fax_number_string .= format_phone($fax_numbers[0]);
				if (sizeof($fax_numbers) > 1) {
					for ($n = 1; $n <= sizeof($fax_numbers); $n++) {
						if ($n == 4) { break; }
						$fax_number_string .= ', '.format_phone($fax_numbers[$n]);
					}
				}
				$fax_number_string .= (sizeof($fax_numbers) > 4) ? ', +'.(sizeof($fax_numbers) - 4) : null;
				$fax_number_string .= ($fax_recipient != '') ? ')' : null;
				$pdf->Write(0.3, $fax_number_string);
			}
			$pdf->SetXY($x + 2.0, $y + 2.25);
			if ($fax_sender != '') {
				$pdf->Write(0.3, $fax_sender);
				if ($fax_caller_id_number != '') {
					$pdf->Write(0.3, '  ('.format_phone($fax_caller_id_number).')');
				}
			}
			else {
				if ($fax_caller_id_number != '') {
					$pdf->Write(0.3, format_phone($fax_caller_id_number));
				}
			}
			if ($fax_page_count > 0) {
				$pdf->Text($x + 2.0, $y + 2.6, $fax_page_count.' '.$text['label-fax-page'.(($fax_page_count > 1) ? 's' : null)]);
			}
			if ($fax_subject != '') {
				$pdf->Text($x + 2.0, $y + 2.9, $fax_subject);
			}

			//message
			if ($fax_message != '') {
				$pdf->SetAutoPageBreak(true, 0.6);
				$pdf->SetTopMargin(0.6);
				$pdf->SetFont($pdf_font, "", 12);
				$pdf->SetXY($x + 0.75, $y + 3.65);
				$pdf->MultiCell(7, 5.40, $fax_message, 0, 'L', false);
			}

			$pages = $pdf->getNumPages();

			if ($pages > 1) {
				# save ynew for last page
				$yn = $pdf->GetY();

				# First page
				$pdf->setPage(1, 0);
				$pdf->Rect($x + 0.5, $y + 3.4, 7.5, $page_height - 3.9, 'D');

				# 2nd to N-th page
				for ($n = 2; $n < $pages; $n++) {
					$pdf->setPage($n, 0);
					$pdf->Rect($x + 0.5, $y + 0.5, 7.5, $page_height - 1, 'D');
				}

				#Last page
				$pdf->setPage($pages, 0);
				$pdf->Rect($x + 0.5, 0.5, 7.5, $yn, 'D');
				$y = $yn;
				unset($yn);
			}
			else {
				$pdf->Rect($x + 0.5, $y + 3.4, 7.5, 6.25, 'D');
				$y = $pdf->GetY();
			}

			//footer
			if ($fax_footer != '') {
				$pdf->SetAutoPageBreak(true, 0.6);
				$pdf->SetTopMargin(0.6);
				$pdf->SetFont("helvetica", "", 8);
				$pdf->SetXY($x + 0.5, $y + 0.6);
				$pdf->MultiCell(7.5, 0.75, $fax_footer, 0, 'C', false);
			}
			$pdf->SetAutoPageBreak(false);
			$pdf->SetTopMargin(0);

			// save cover pdf
			$pdf->Output($dir_fax_temp.'/'.$fax_instance_uuid.'_cover.pdf', "F");	// Display [I]nline, Save to [F]ile, [D]ownload

			//convert pdf to tif, add to array of pages, delete pdf
			if (file_exists($dir_fax_temp.'/'.$fax_instance_uuid.'_cover.pdf')) {
				chdir($dir_fax_temp);

				$cmd = gs_cmd("-q -sDEVICE=tiffg32d -r".$gs_r." -g".$gs_g." -dBATCH -dPDFFitPage -dNOPAUSE -sOutputFile=".correct_path($fax_instance_uuid)."_cover.tif -- ".correct_path($fax_instance_uuid)."_cover.pdf -c quit");
				// echo($cmd . "<br/>\n");
				exec($cmd);
				if (is_array($tif_files) && sizeof($tif_files) > 0) {
					array_unshift($tif_files, $dir_fax_temp.'/'.$fax_instance_uuid.'_cover.tif');
				}
				else {
					$tif_files[] = $dir_fax_temp.'/'.$fax_instance_uuid.'_cover.tif';
				}
				@unlink($dir_fax_temp.'/'.$fax_instance_uuid.'_cover.pdf');
			}
		}

		//combine tif files into single multi-page tif
		if (is_array($tif_files) && sizeof($tif_files) > 0) {
			$cmd = "tiffcp -c none ";
			foreach ($tif_files as $tif_file) {
				$cmd .= correct_path($tif_file) . ' ';
			}
			$cmd .= correct_path($dir_fax_temp.'/'.$fax_instance_uuid.'.tif');
			//echo($cmd . "<br/>\n");
			exec($cmd);

			foreach ($tif_files as $tif_file) {
				@unlink($tif_file);
			}

			//generate pdf (a work around, as tiff2pdf was improperly inverting the colors)
			$cmd = 'tiff2pdf -u i -p '.$fax_page_size.
				' -w '.$page_width.
				' -l '.$page_height.
				' -f -o '.
				correct_path($dir_fax_temp.'/'.$fax_instance_uuid.'.pdf').' '.
				correct_path($dir_fax_temp.'/'.$fax_instance_uuid.'.tif');
			// echo($cmd . "<br/>\n");
			exec($cmd);

			chdir($dir_fax_temp);

			//$cmd = gs_cmd("-q -sDEVICE=psmono -r".$gs_r." -g".$gs_g." -dNOPAUSE -dBATCH -dSAFER -sOutputFile=".correct_path($fax_instance_uuid).".pdf -- ".correct_path($fax_instance_uuid).".pdf -c quit");
			// echo($cmd . "<br/>\n");
			//exec($cmd);

			//convert pdf to tif
			$cmd = gs_cmd('-q -sDEVICE=tiffg32d -r'.$gs_r.' -g'.$gs_g.' -dBATCH -dPDFFitPage -dNOPAUSE -sOutputFile='.
				correct_path($fax_instance_uuid.'_temp.tif').
				' -- '.$fax_instance_uuid.'.pdf -c quit');
			// echo($cmd . "<br/>\n");
			exec($cmd);

			@unlink($dir_fax_temp.'/'.$fax_instance_uuid.".pdf");

			$cmd = 'tiff2pdf -u i -p '.$fax_page_size.
				' -w '.$page_width.
				' -l '.$page_height.
				' -f -o '.
				correct_path($dir_fax_temp.'/'.$fax_instance_uuid.'.pdf').' '.
				correct_path($dir_fax_temp.'/'.$fax_instance_uuid.'_temp.tif');
			// echo($cmd . "<br/>\n");
			exec($cmd);

			@unlink($dir_fax_temp.'/'.$fax_instance_uuid."_temp.tif");
		}
		else {
			if (!$included) {
				//nothing to send, redirect the browser
				message::add($text['message-invalid-fax'], 'negative', 4000);
				header("Location: fax_send.php?id=".$fax_uuid);
				exit;
			}
		}

		//preview, if requested
		if (($_REQUEST['submit'] != '') && ($_REQUEST['submit'] == 'preview')) {
			unset($file_type);
			if (file_exists($dir_fax_temp.'/'.$fax_instance_uuid.'.pdf')) {
				$file_type = 'pdf';
				$content_type = 'application/pdf';
				@unlink($dir_fax_temp.'/'.$fax_instance_uuid.".tif");
			}
			else if (file_exists($dir_fax_temp.'/'.$fax_instance_uuid.'.tif')) {
				$file_type = 'tif';
				$content_type = 'image/tiff';
				@unlink($dir_fax_temp.'/'.$fax_instance_uuid.".pdf");
			}
			if ($file_type != '') {
				//push download
				$fd = fopen($dir_fax_temp.'/'.$fax_instance_uuid.'.'.$file_type, "rb");
				header("Content-Type: application/force-download");
				header("Content-Type: application/octet-stream");
				header("Content-Type: application/download");
				header("Content-Description: File Transfer");
				header('Content-Disposition: attachment; filename="'.$fax_instance_uuid.'.'.$file_type.'"');
				header("Content-Type: ".$content_type);
				header('Accept-Ranges: bytes');
				header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
				header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // date in the past
				header("Content-Length: ".filesize($dir_fax_temp.'/'.$fax_instance_uuid.'.'.$file_type));
				fpassthru($fd);
				@unlink($dir_fax_temp.'/'.$fax_instance_uuid.".".$file_type);
			}
			exit;
		}

		//get some more info to send the fax
		$mailfrom_address = (isset($_SESSION['fax']['smtp_from']['text'])) ? $_SESSION['fax']['smtp_from']['text'] : $_SESSION['email']['smtp_from']['text'];

		$sql = "select * from v_fax where fax_uuid = :fax_uuid ";
		$parameters['fax_uuid'] = $fax_uuid;
		$database = new database;
		$row = $database->select($sql, $parameters, 'row');
		$mailto_address_fax = $row["fax_email"];
		$fax_prefix = $row["fax_prefix"];
		unset($sql, $parameters, $row);

		if (!$included) {
			$sql = "select user_email from v_users where user_uuid = :user_uuid ";
			$parameters['user_uuid'] = $_SESSION['user_uuid'];
			$database = new database;
			$mailto_address_user = $database->select($sql, $parameters, 'column');
			unset($sql, $parameters);
		}
		else {
			//use email-to-fax from address
		}

		if ($mailto_address_fax != '' && $mailto_address_user != $mailto_address_fax) {
			$mailto_address = $mailto_address_fax.",".$mailto_address_user;
		}
		else {
			$mailto_address = $mailto_address_user;
		}

		//send the fax
		$fax_file = $dir_fax_temp."/".$fax_instance_uuid.".tif";
		$tmp_dial_string  = "for_fax=1,";
		$tmp_dial_string .= "accountcode='"                  . $fax_accountcode         . "',";
		$tmp_dial_string .= "sip_h_X-accountcode='"          . $fax_accountcode         . "',";
		$tmp_dial_string .= "domain_uuid="                   . $_SESSION["domain_uuid"] . ",";
		$tmp_dial_string .= "domain_name="                   . $_SESSION["domain_name"] . ",";
		$tmp_dial_string .= "origination_caller_id_name='"   . $fax_caller_id_name      . "',";
		$tmp_dial_string .= "origination_caller_id_number='" . $fax_caller_id_number    . "',";
		$tmp_dial_string .= "fax_ident='"                    . $fax_caller_id_number    . "',";
		$tmp_dial_string .= "fax_header='"                   . $fax_caller_id_name      . "',";
		$tmp_dial_string .= "fax_file='"                     . $fax_file                . "',";
		foreach ($fax_numbers as $fax_number) {

			$fax_number = trim($fax_number);
			fax_split_dtmf($fax_number, $fax_dtmf);

			//prepare the fax command
			if (strlen($fax_toll_allow) > 0) {
				$channel_variables["toll_allow"] = $fax_toll_allow;
			}
			$route_array = outbound_route_to_bridge($_SESSION['domain_uuid'], $fax_prefix . $fax_number, $channel_variables);
			if (count($route_array) == 0) {
				//send the internal call to the registered extension
				$fax_uri = "user/".$fax_number."@".$_SESSION['domain_name'];
				$fax_variables = "";
			}
			else {
				//send the external call
				$fax_uri = $route_array[0];
				$fax_variables = "";
				foreach($_SESSION['fax']['variable'] as $variable) {
					$fax_variables .= $variable.",";
				}
			}

			if ($fax_send_mode != 'queue') {
				$dial_string = $tmp_dial_string;
				$dial_string .= $fax_variables;
				$dial_string .= "mailto_address='"     . $mailto_address   . "',";
				$dial_string .= "mailfrom_address='"   . $mailfrom_address . "',";
				$dial_string .= "fax_uri=" . $fax_uri  . ",";
				$dial_string .= "fax_retry_attempts=1" . ",";
				$dial_string .= "fax_retry_limit=20"   . ",";
				$dial_string .= "fax_retry_sleep=180"  . ",";
				$dial_string .= "fax_verbose=true"     . ",";
				$dial_string .= "fax_use_ecm=off"      . ",";
				$dial_string .= "api_hangup_hook='lua fax_retry.lua'";
				$dial_string  = "{" . $dial_string . "}" . $fax_uri." &txfax('".$fax_file."')";

				$fp = event_socket_create($_SESSION['event_socket_ip_address'], $_SESSION['event_socket_port'], $_SESSION['event_socket_password']);
				if ($fp) {
					$cmd = "api originate " . $dial_string;
					//send the command to event socket
					$response = event_socket_request($fp, $cmd);
					$response = str_replace("\n", "", $response);
					$uuid = str_replace("+OK ", "", $response);
				}
				fclose($fp);
			}
			else { // enqueue
				$wav_file = ''; //! @todo add custom message
				$dial_string = $tmp_dial_string;
				$response = fax_enqueue($fax_uuid, $fax_file, $wav_file, $mailto_address, $fax_uri, $fax_dtmf, $dial_string);
			}
		}

		//wait for a few seconds
		sleep(5);

		//move the generated tif (and pdf) files to the sent directory
		if (file_exists($dir_fax_temp.'/'.$fax_instance_uuid.".tif")) {
			copy($dir_fax_temp.'/'.$fax_instance_uuid.".tif", $dir_fax_sent.'/'.$fax_instance_uuid.".tif");
		}

		if (file_exists($dir_fax_temp.'/'.$fax_instance_uuid.".pdf")) {
			copy($dir_fax_temp.'/'.$fax_instance_uuid.".pdf ", $dir_fax_sent.'/'.$fax_instance_uuid.".pdf");
		}

		if (!$included && is_uuid($fax_uuid)) {
			//redirect the browser
			message::add($response, 'default');
			if (isset($_SESSION['fax']['send_mode']['text']) && $_SESSION['fax']['send_mode']['text'] == 'queue') {
				header("Location: fax_active.php?id=".$fax_uuid);
			}
			else {
				header("Location: fax_files.php?id=".$fax_uuid."&box=sent");
			}
			exit;
		}

	} //end upload and send fax


if (!$included) {

	//create token
		$object = new token;
		$token = $object->create($_SERVER['PHP_SELF']);

	//show the header
		$document['title'] = $text['title-new_fax'];
		require_once "resources/header.php";

	//javascript to toggle input/select boxes, add fax numbers
		echo "<script language='JavaScript' type='text/javascript' src='".PROJECT_PATH."/resources/javascript/reset_file_input.js'></script>\n";
		echo "<script language='JavaScript' type='text/javascript'>";

		echo "	function toggle(field) {";
		echo "		if (field == 'fax_recipient') {";
		echo "			document.getElementById('fax_recipient_select').selectedIndex = 0;";
		echo "			$('#fax_recipient_select').toggle();";
		echo "			$('#fax_recipient').toggle();";
		echo "			if ($('#fax_recipient').is(':visible')) { $('#fax_recipient').trigger('focus'); } else { $('#fax_recipient_select').trigger('focus'); }";
		echo "		}";
		echo "	}";

		echo "	function contact_load(obj_sel) {";
		echo "		obj_sel.style.display='none';";
		echo "		document.getElementById('fax_recipient').style.display='';";
		echo "		var selected_option_value = obj_sel.options[obj_sel.selectedIndex].value;";
		echo "		var selected_option_values = selected_option_value.split('|', 2);";
		echo "		document.getElementById('fax_recipient').value = selected_option_values[1];";
		echo "		document.getElementById('fax_number').value = selected_option_values[0];";
		echo "		$('#fax_recipient').css({width: '50%'});";
		echo "		$('#fax_number').css({width: '120px'});";
		echo "	}";

		echo "	function list_selected_files(file_input_number) {";
		echo "		var inp = document.getElementById('fax_files_'+file_input_number);";
		echo "		var files_selected = [];";
		echo "		for (var i = 0; i < inp.files.length; ++i) {";
		echo "			var file_name = inp.files.item(i).name;";
		echo "			files_selected.push(file_name);";
		echo "		}";
		echo "		document.getElementById('file_list_'+file_input_number).innerHTML = '';";
		echo "		if (files_selected.length > 1) {";
		echo "			document.getElementById('file_list_'+file_input_number).innerHTML = '<strong>".$text['label-selected']."</strong>: ';";
		echo "			document.getElementById('file_list_'+file_input_number).innerHTML += files_selected.join(', ');";
		echo "			document.getElementById('file_list_'+file_input_number).innerHTML += '<br />';";
		echo "		}";
		echo "	}";

		echo "	function add_fax_number() {\n";
		echo "		var newdiv = document.createElement('div');\n";
		echo "		newdiv.innerHTML = \"<input type='text' name='fax_numbers[]' class='formfld' style='width: 150px; min-width: 150px; max-width: 150px; margin-top: 3px;' maxlength='25'>\";";
		echo "		document.getElementById('fax_numbers').appendChild(newdiv);";
		echo "	}\n";

		echo "</script>";

	//show the content
		echo "<form method='post' name='frm' id='frm' enctype='multipart/form-data'>\n";

		echo "<div class='action_bar' id='action_bar'>\n";
		echo "	<div class='heading'><b>".$text['header-new_fax']."</b></div>\n";
		echo "	<div class='actions'>\n";
		echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$_SESSION['theme']['button_icon_back'],'id'=>'btn_back','style'=>'margin-right: 15px;','link'=>'fax.php']);
		echo button::create(['type'=>'submit','label'=>$text['button-preview'],'icon'=>'eye','name'=>'submit','value'=>'preview']);
		echo button::create(['type'=>'submit','label'=>$text['button-send'],'icon'=>'paper-plane','id'=>'btn_save','name'=>'submit','value'=>'send','style'=>'margin-left: 15px;']);
		echo "	</div>\n";
		echo "	<div style='clear: both;'></div>\n";
		echo "</div>\n";

		echo $text['description-2']." ".(if_group('superadmin') ? $text['description-3'] : null)."\n";
		echo "<br /><br />\n";

		echo "<table width='100%' border='0' cellspacing='0' cellpadding='0'>\n";

		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap>\n";
		echo "	".$text['label-fax-header']."\n";
		echo "</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<input type='text' name='fax_header' class='formfld' style='' value='".$_SESSION['fax']['cover_header']['text']."'>\n";
		echo "	<br />\n";
		echo "	".$text['description-fax-header']."\n";
		echo "</td>\n";
		echo "</tr>\n";

		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap>\n";
		echo "	".$text['label-fax-sender']."\n";
		echo "</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<input type='text' name='fax_sender' class='formfld' style='' value='".escape($fax_caller_id_name)."'>\n";
		echo "	<br />\n";
		echo "	".$text['description-fax-sender']."\n";
		echo "</td>\n";
		echo "</tr>\n";

		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap>\n";
		echo "	".$text['label-fax-recipient']."\n";
		echo "</td>\n";
		echo "<td class='vtable' align='left'>\n";
		//retrieve current user's assigned groups (uuids)
		foreach ($_SESSION['groups'] as $group_data) {
			$user_group_uuids[] = $group_data['group_uuid'];
		}
		//add user's uuid to group uuid list to include private (non-shared) contacts
		$user_group_uuids[] = $_SESSION["user_uuid"];
		$sql = "select ";
		$sql .= "c.contact_organization, ";
		$sql .= "c.contact_name_given, ";
		$sql .= "c.contact_name_family, ";
		$sql .= "c.contact_nickname, ";
		$sql .= "cp.phone_number ";
		$sql .= "from ";
		$sql .= "v_contacts as c, ";
		$sql .= "v_contact_phones as cp ";
		$sql .= "where ";
		$sql .= "c.contact_uuid = cp.contact_uuid ";
		$sql .= "and c.domain_uuid = :domain_uuid ";
		$sql .= "and cp.domain_uuid = :domain_uuid ";
		$sql .= "and cp.phone_type_fax = 1 ";
		$sql .= "and cp.phone_number is not null ";
		$sql .= "and cp.phone_number <> '' ";
		if (is_array($user_group_uuids) && @sizeof($user_group_uuids) != 0) {
			//only show contacts assigned to current user's group(s) and those not assigned to any group
			$sql .= "and (";
			$sql .= "	c.contact_uuid in ( ";
			$sql .= "		select contact_uuid from v_contact_groups ";
			$sql .= "		where (";
			foreach ($user_group_uuids as $index => $user_group_uuid) {
				$sql .= $or;
				$sql .= "		group_uuid = :group_uuid_".$index." ";
				$parameters['group_uuid_'.$index] = $user_group_uuid;
				$or = " or ";
			}
			unset($user_group_uuids, $index, $user_group_uuid, $or);
			$sql .= "		) ";
			$sql .= "		and domain_uuid = :domain_uuid ";
			$sql .= "	) ";
			$sql .= "	or ";
			$sql .= "	c.contact_uuid not in ( ";
			$sql .= "		select contact_uuid from v_contact_groups ";
			$sql .= "		where domain_uuid = :domain_uuid ";
			$sql .= "	) ";
			$sql .= ") ";
		}
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		$database = new database;
		$contacts = $database->select($sql, $parameters, 'all');
		if (is_array($contacts) && @sizeof($contacts) != 0) {
			foreach ($contacts as &$row) {
				if ($row['contact_organization'] != '') {
					$contact_option_label = $row['contact_organization'];
				}
				if ($row['contact_name_given'] != '' || $row['contact_name_family'] != '' || $row['contact_nickname'] != '') {
					$contact_option_label .= ($row['contact_organization'] != '') ? "," : null;
					$contact_option_label .= ($row['contact_name_given'] != '') ? (($row['contact_organization'] != '') ? " " : null).$row['contact_name_given'] : null;
					$contact_option_label .= ($row['contact_name_family'] != '') ? (($row['contact_organization'] != '' || $row['contact_name_given'] != '') ? " " : null).$row['contact_name_family'] : null;
					$contact_option_label .= ($row['contact_nickname'] != '') ? (($row['contact_organization'] != '' || $row['contact_name_given'] != '' || $row['contact_name_family'] != '') ? " (".$row['contact_nickname'].")" : $row['contact_nickname']) : null;
				}
				$contact_option_value_recipient = $contact_option_label;
				$contact_option_value_faxnumber = $row['phone_number'];
				$contact_option_label .= ":&nbsp;&nbsp;".escape(format_phone($row['phone_number']));
				$contact_labels[] = $contact_option_label;
				$contact_values[] = $contact_option_value_faxnumber."|".$contact_option_value_recipient;
				unset($contact_option_label);
			}
			if (is_array($contact_labels)) {
				asort($contact_labels, SORT_NATURAL); // sort by name(s)
			}
			echo "	<select class='formfld' style='display: none;' id='fax_recipient_select' onchange='contact_load(this);'>\n";
			echo "		<option value=''></option>\n";
			foreach ($contact_labels as $index => $contact_label) {
				echo "	<option value=\"".escape($contact_values[$index])."\">".$contact_label."</option>\n";
			}
			echo "	</select>\n";
		}
		unset($sql, $parameters, $row);
		echo "	<input type='text' name='fax_recipient' id='fax_recipient' class='formfld' style='max-width: 250px;' value=''>\n";
		if (is_array($contacts)) {
			echo "	<input type='button' id='btn_toggle_recipient' class='btn' name='' alt='".$text['button-back']."' value='&#9665;' onclick=\"toggle('fax_recipient');\">\n";
		}
		echo "	<br />\n";
		echo "	".$text['description-fax-recipient']."\n";
		echo "</td>\n";
		echo "</tr>\n";

		echo "<tr>\n";
		echo "<td class='vncellreq' valign='top' align='left' nowrap>\n";
		echo "		".$text['label-fax-number']."\n";
		echo "</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<table cellpadding='0' cellspacing='0' border='0'>";
		echo "		<tr>";
		echo "			<td id='fax_numbers'>";
		echo "				<input type='text' name='fax_numbers[]' id='fax_number' class='formfld' style='width: 150px; min-width: 150px; max-width: 150px;' maxlength='25'>\n";
		echo "			</td>";
		echo "			<td style='vertical-align: bottom;'>";
		echo "				<a href='javascript:void(0);' onclick='add_fax_number();'>$v_link_label_add</a>";
		echo "			</td>";
		echo "		</tr>";
		echo "	</table>";
		echo "	".$text['description-fax-number']."\n";
		echo "</td>\n";
		echo "</tr>\n";

		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap>\n";
		echo "	".$text['label-fax_files']."\n";
		echo "</td>\n";
		echo "<td class='vtable' align='left'>\n";
		for ($f = 1; $f <= 3; $f++) {
			echo "	<span id='fax_file_".$f."' ".(($f > 1) ? "style='display: none;'" : null).">";
			echo "	<input name='fax_files[]' id='fax_files_".$f."' type='file' class='formfld fileinput' style='margin-right: 3px; ".(($f > 1) ? "margin-top: 3px;" : null)."' onchange=\"".(($f < 3) ? "document.getElementById('fax_file_".($f+1)."').style.display='';" : null)." list_selected_files(".$f.");\" multiple='multiple'>";
			echo button::create(['type'=>'button','label'=>$text['button-clear'],'icon'=>$_SESSION['theme']['button_icon_reset'],'onclick'=>"reset_file_input('fax_files_".$f."'); document.getElementById('file_list_".$f."').innerHTML='';"]);
			echo 	"<br />";
			echo "	<span id='file_list_".$f."'></span>";
			echo "	</span>\n";
		}
		echo "	".$text['description-fax_files']."\n";
		echo "</td>\n";
		echo "</tr>\n";

		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap>\n";
		echo "		".$text['label-fax-resolution']."\n";
		echo "</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<select name='fax_resolution' class='formfld'>\n";
		echo "		<option value='normal' ".(($_SESSION['fax']['resolution']['text'] == 'normal') ? 'selected' : null).">".$text['option-fax-resolution-normal']."</option>\n";
		echo "		<option value='fine' ".(($_SESSION['fax']['resolution']['text'] == 'fine') ? 'selected' : null).">".$text['option-fax-resolution-fine']."</option>\n";
		echo "		<option value='superfine' ".(($_SESSION['fax']['resolution']['text'] == 'superfine') ? 'selected' : null).">".$text['option-fax-resolution-superfine']."</option>\n";
		echo "	</select>\n";
		echo "	<br />\n";
		echo "	".$text['description-fax-resolution']."\n";
		echo "</td>\n";
		echo "</tr>\n";

		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap>\n";
		echo "		".$text['label-fax-page-size']."\n";
		echo "</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<select name='fax_page_size' class='formfld'>\n";
		echo "		<option value='letter' ".(($_SESSION['fax']['page_size']['text'] == 'letter') ? 'selected' : null).">Letter</option>\n";
		echo "		<option value='legal' ".(($_SESSION['fax']['page_size']['text'] == 'legal') ? 'selected' : null).">Legal</option>\n";
		echo "		<option value='a4' ".(($_SESSION['fax']['page_size']['text'] == 'a4') ? 'selected' : null).">A4</option>\n";
		echo "	</select>\n";
		echo "	<br />\n";
		echo "	".$text['description-fax-page-size']."\n";
		echo "</td>\n";
		echo "</tr>\n";

		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap>\n";
		echo "	".$text['label-fax-subject']."\n";
		echo "</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<input type='text' name='fax_subject' class='formfld' style='' value=''>\n";
		echo "	<br />\n";
		echo "	".$text['description-fax-subject']."\n";
		echo "</td>\n";
		echo "</tr>\n";

		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap>\n";
		echo "		".$text['label-fax-message']."\n";
		echo "</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<textarea type='text' name='fax_message' class='formfld' style='width: 65%; height: 175px;'></textarea>\n";
		echo "<br />\n";
		echo "	".$text['description-fax-message']."\n";
		echo "</td>\n";
		echo "</tr>\n";

		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap>\n";
		echo "	".$text['label-fax-footer']."\n";
		echo "</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<textarea type='text' name='fax_footer' class='formfld' style='width: 65%; height: 100px;'>".$_SESSION['fax']['cover_footer']['text']."</textarea>\n";
		echo "	<br />\n";
		echo "	".$text['description-fax-footer']."\n";
		echo "</td>\n";
		echo "</tr>\n";

		echo "</table>";
		echo "<br /><br />\n";

		echo "<input type='hidden' name='fax_caller_id_name' value='".escape($fax_caller_id_name)."'>\n";
		echo "<input type='hidden' name='fax_caller_id_number' value='".escape($fax_caller_id_number)."'>\n";
		echo "<input type='hidden' name='fax_extension' value='".escape($fax_extension)."'>\n";
		echo "<input type='hidden' name='id' value='".escape($fax_uuid)."'>\n";
		echo "<input type='hidden' name='action' value='send'>\n";
		echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";

		echo "</form>\n";

	//show the footer
		require_once "resources/footer.php";

}

// used for initial element alignment during pdf generation
/*
function showgrid($pdf) {
	// generate a grid for placement
	for ($x=0; $x<=8.5; $x+=0.1) {
		for ($y=0; $y<=11; $y+=0.1) {
			$pdf->SetTextColor(0,0,0);
			$pdf->SetFont("courier", "", 3);
			$pdf->Text($x-0.01,$y-0.01,".");
		}
	}
	for ($x=0; $x<=9; $x+=1) {
		for ($y=0; $y<=11; $y+=1) {
			$pdf->SetTextColor(255,0,0);
			$pdf->SetFont("times", "", 10);
			$pdf->Text($x-.02,$y-.01,".");
			$pdf->SetFont("courier", "", 4);
			$pdf->Text($x+0.01,$y+0.035,$x.",".$y);
		}
	}
}
*/

?>
