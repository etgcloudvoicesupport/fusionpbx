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
	Portions created by the Initial Developer are Copyright (C) 2008-2016
	the Initial Developer. All Rights Reserved.

	Contributor(s):
	KonradSC <konrd@yahoo.com>
*/

//includes
	require_once "/var/www/fusionpbx/resources/require.php";
	require_once "resources/check_auth.php";
	require_once "resources/paging.php";	

//check permissions
	if (permission_exists('connectwise_view')) {
		//access granted
	}
	else {
		echo "access denied";
		exit;
	}
	
//add multi-lingual support
	$language = new text;
	$text = $language->get();

//get the http values and set them as variables
	$order_by = check_str($_GET["order_by"]);
	$order = check_str($_GET["order"]);

//handle search term
	$search = check_str($_GET["search"]);
	if (strlen($search) > 0) {
		$sql_mod = "WHERE d.domain_name like '%{$search}%' "; 
	}
	if (strlen($order_by) < 1) {
		$order_by = "domain_name";
		$order = "ASC";
	}

//get all the counts from the database
	$sql = "SELECT \n";
	$sql .= "d.domain_uuid, \n";
	$sql .= "d.domain_name, \n";
	$sql .= "d.domain_description, \n";
	
	//extension
	$sql .= "(\n";
	$sql .= "select count(*) from v_extensions \n";
	$sql .= "where domain_uuid = d.domain_uuid\n";
	$sql .= ") as extension_count, \n";

	//billable extensions
	$sql .= "(\n";
	$sql .= "select count(*) from v_extensions \n";
	$sql .= "where domain_uuid = d.domain_uuid \n";
	$sql .= "AND UPPER(accountcode) NOT LIKE '%DONOTBILL%'
			 AND UPPER(accountcode) NOT LIKE '%DNB%' 
			 AND UPPER(accountcode) NOT LIKE '%DO NOT BILL%' \n";
			 
	$sql .= ") as billable_extension_count, \n";
	
	//faxes
	$sql .= "(\n";
	$sql .= "select count(*) from v_fax \n";
	$sql .= "where domain_uuid = d.domain_uuid\n";
	$sql .= ") as fax_count, \n";

	//emergency_locations
	$sql .= "(\n";
	$sql .= "select count(*) from v_emergency_locations \n";
	$sql .= "where domain_uuid = d.domain_uuid\n";
	$sql .= ") as emergency_location_count, \n";
	
	//destination accountcodes
	$sql .= "(\n";
	$sql .= "select count(DISTINCT destination_accountcode) from v_destinations \n";
	$sql .= "where domain_uuid = d.domain_uuid\n";
	$sql .= ") as destination_accountcode_count, \n";

	//sms_to_email_destinations
	$sql .= "(\n";
	$sql .= "select count(DISTINCT sms_to_email_destination_uuid) from v_sms_to_email_destinations \n";
	$sql .= "where domain_uuid = d.domain_uuid\n";
	$sql .= ") as sms_destinations_count \n";
	
	$sql .= "FROM v_domains as d \n";
	$sql .= $sql_mod; //add search mod from above
	$sql .= "ORDER BY {$order_by} {$order} \n";
	
	$database = new database;
	$domain_counts = $database->select($sql, null, 'all');

	//map each domain to its ConnectWise agreement ids (for Domain Name deep links)
	$cw_company = $_SESSION['connectwise']['company']['text'] ?? 'encoretg';
	$cw_agreement_url = function($agreement_id) use ($cw_company) {
		return 'https://na.myconnectwise.net/v4_6_release/services/system_io/router/openrecord.rails'
			.'?recordType=AgreementFV&recid='.rawurlencode($agreement_id)
			.'&companyName='.rawurlencode($cw_company);
	};
	$domain_agreements_map = [];
	$sql = "SELECT domain_uuid, agreement_id,
				string_agg(DISTINCT accountcode, ', ') AS accountcodes
			FROM v_connectwise_agreements
			WHERE agreement_id IS NOT NULL
			GROUP BY domain_uuid, agreement_id
			ORDER BY agreement_id";
	$agreement_rows = $database->select($sql, null, 'all');
	if (is_array($agreement_rows)) {
		foreach ($agreement_rows as $ar) {
			$domain_agreements_map[$ar['domain_uuid']][] = [
				'id' => (int) $ar['agreement_id'],
				'label' => trim($ar['accountcodes'] ?? ''),
				'url' => $cw_agreement_url($ar['agreement_id']),
			];
		}
	}

	//ConnectWise synced/unmapped counts are loaded lazily per row via AJAX
	//(connectwise_row_counts.php) so the dashboard renders instantly without
	//blocking on the ConnectWise API. The CSV export still needs those values
	//inline, so compute them server-side only when exporting.
	if ($_REQUEST['type'] == "csv") {
	//get connectwise data from API
	$connectwise = new connectwise();
	// $connectwise->debug = true;

	//get connectwise data from db
	$agreement_counts = $connectwise->agreementExtensionCount();
	// echo "<pre>";print_r($agreement_counts);exit;
	//get full list of additions from API by bundling in groups of 50
	// $i=0;
	// foreach ($agreement_counts as $agreement) {
	// 	if (empty($agreement['addition_id']) || $dedup[$agreement['agreement_id']]) {continue;}
		
	// 	$dedup[$agreement['agreement_id']] = true;
	// 	$bundle[] = [ 
	// 		'type' => 'addition', 
	// 		'conditions' => "(agreementStatus='Active')", 
	// 		'parentId' => $agreement['agreement_id']
	// 	];
	// 	if ($i++ == 49) {
	// 		$i=0;
	// 		$full_addition_response[] = $connectwise->bundle($bundle);
	// 		unset($bundle);
	// 	}
	// }
	// if ($i>0) {
	// 	$full_addition_response[] = $connectwise->bundle($bundle);
	// }
	
	// //organize the results
	// if (!empty($full_addition_response)) foreach ($full_addition_response as $single_api_call) {
	// 	if ($single_api_call['_info']['success'] > 0) foreach ($single_api_call['results'] as $sequence) {
	// 		if ($sequence['count'] > 0) foreach ($sequence['entities'] as $addition) {
	// 			$addition_list[$addition['id']] = $addition;
	// 		}
	// 	}
	// }

	foreach($domain_counts as $key => $domain_count) {
		$domain = $domain_count['domain_uuid'];
		$domain_counts[$key]['unmapped'] = 0;
		$domain_counts[$key]['synced'] = 0;
		//get mapped agreements for the current loop's domain_uuid
		$domain_agreements = array_filter($agreement_counts, function ($var) use ($domain) {
			return ($var['domain_uuid'] == $domain);
		});

		//Math the Counts
		if (!empty($domain_agreements)) foreach ($domain_agreements as $domain_agreement) {
			if (empty($domain_agreement['addition_id'])) {
				//add to unmapped if the agreement/addition isn't set up
				$domain_counts[$key]['unmapped'] += $domain_agreement['count'];
				continue;
			};

			$addition_id = $domain_agreement['addition_id'];

			//keep a sum of CW counts
			$domain_counts[$key]['synced'] += $domain_agreement['cw_count'] ?: 0;
			unset($agreement_id,$addition_id,$addition);
		}
		else {
			unset($domain_counts[$key]['unmapped']);
		}

		
		$synced = $domain_counts[$key]['synced'];
		$mapped = $domain_counts[$key]['billable_extension_count'] - ($domain_counts[$key]['unmapped'] ?? $domain_counts[$key]['billable_extension_count']);
		$billable = $domain_counts[$key]['billable_extension_count'];
		$total = $domain_counts[$key]['extension_count'];

		$extras[$key]['highlight'] = "";
		if ($billable != $mapped) {
			$extras[$key]['highlight'] = "style='background-color:#ff4444';";
		}
		elseif ($mapped != $synced) {
			$extras[$key]['highlight'] = "style='background-color:yellow';";
		}
		$extras[$key]['tooltip'] = "title='Total: {$total}&#13;&#10;Billable: {$billable}&#13;&#10;Mapped to CW: {$mapped}&#13;&#10;Synced with CW: {$synced}'";
	}
	} //end CSV-only ConnectWise compute
	
	// echo "<pre>";
	// print(json_encode($domain_counts, JSON_PRETTY_PRINT));exit;

//lookup the domain count
	$database = new database;
	$database->table = "v_domains";
	$where[1]["name"] = "domain_uuid";
	$where[1]["operator"] = "=";
	$where[1]["value"] = "*";	
	$numeric_domain_counts = $database->count();
	unset($database,$result);

//set the http header
	if ($_REQUEST['type'] == "csv") {
	
		//set the headers
			header('Content-type: application/octet-binary');
			header("Content-Disposition: attachment; filename=cw_sync_" . date("Y-m-d") . ".csv");

		//show the column names on the first line
			$z = 0;
			foreach($domain_counts[1] as $key => $val) {
				if ($z == 0) {
					echo '"'.$key.'"';
				}
				else {
					echo ',"'.$key.'"';
				}
				$z++;
			}
			echo "\n";
		
		//add the values to the csv
			$x = 0;
			foreach($domain_counts as $domains) {
				$z = 0;
				foreach($domains as $key => $val) {
					if ($z == 0) {
						echo '"'.$domain_counts[$x][$key].'"';
					}
					else {
						echo ',"'.$domain_counts[$x][$key].'"';
					}
					$z++;
				}
				echo "\n";
				$x++;
			}
			exit;
	}
	
//additional includes
	require_once "resources/header.php";
	$document['title'] = $text['title-connectwise'];

//set the alternating styles
	$c = 0;
	$row_style["0"] = "row_style0";
	$row_style["1"] = "row_style1";
	
//show the content
	echo "<table width=\"100%\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\">\n";
	echo "  <tr>\n";
	echo "	<td align='left' width='100%'>\n";
	echo "		<b>{$text['header-connectwise']}  ({$numeric_domain_counts})</b><br>\n";
	echo "	</td>\n";
	echo "		<td align='right' width='100%' style='vertical-align: top;'>";
	echo "		<form method='get' action=''>\n";
	echo "			<td style='vertical-align: top; text-align: right; white-space: nowrap;'>\n";
	echo "				<input type='text' class='txt' style='width: 150px' name='search' id='search' value='{$search}'>";
	echo "				<input type='submit' class='btn' name='submit' value='{$text['button-search']}'>";
	echo "				<input type='button' class='btn' name='submit' onclick=\"window.open('connectwise_quarterly.php', '_blank')\" value='USF History'>";
	if (permission_exists('connectwise_sync')) {
		echo "				<input type='button' class='btn' id='btn_sync_all' value='Sync All' disabled title='Waiting for row counts to load…'>";
		echo "				<span id='sync_all_status' style='margin-left:8px; font-size:11px;'></span>";
	}
	echo "				<input type='button' class='btn' value='{$text['button-export']}' ";
	echo "onclick=\"window.location='connectwise.php?";
	if (strlen($_SERVER["QUERY_STRING"]) > 0) { 
		echo $_SERVER["QUERY_STRING"]."&type=csv';\">\n";
	} else { 
		echo "type=csv';\">\n";
	}



#	if ($paging_controls_mini != '') {
#		echo 			"<span style='margin-left: 15px;'>{$paging_controls_mini}</span>\n";
#	}
	echo "			</td>\n";
	echo "		</form>\n";	
	echo "  </tr>\n";
	
	
	echo "	<tr>\n";
	echo "		<td colspan='2'>\n";
	echo "			{$text['description-connectwise']}\n";
	echo "		</td>\n";
	echo "	</tr>\n";
	echo "</table>\n";
	echo "<br />";

	echo "<form name='frm' method='post' action='connectwise_delete.php'>\n";
	echo "<table class='tr_hover' width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
	echo "<tr>\n";
	echo th_order_by('domain_name', $text['label-domain_name'], $order_by, $order);
	echo th_order_by('extension_count', $text['label-extensions']." (CW)", $order_by, $order, null, "class='center pct-10' style='text-align: center;'");
	// echo th_order_by('user_count', $text['label-users'], $order_by, $order);
	// echo th_order_by('device_count', $text['label-devices'], $order_by, $order);
	// echo th_order_by('destination_count', $text['label-destinations'], $order_by, $order);
	echo th_order_by('fax_count', $text['label-faxes'], $order_by, $order, null, "class='center pct-10' style='text-align: center;'");
	// echo th_order_by('ivr_count', $text['label-ivrs'], $order_by, $order);
	// echo th_order_by('voicemail_count', $text['label-voicemails'], $order_by, $order);
	// echo th_order_by('ring_group_count', $text['label-ring_groups'], $order_by, $order);
	// echo th_order_by('cc_queue_count', $text['label-cc_queues'], $order_by, $order);	
	// echo th_order_by('contact_count', $text['label-contacts'], $order_by, $order);
	// echo th_order_by('conference_room_count', $text['label-conference_rooms'], $order_by, $order);
	echo th_order_by('sms_destination_count', "SMS", $order_by, $order, null, "class='center pct-10' style='text-align: center;'");
	echo th_order_by('emergency_location_count', "E911", $order_by, $order, null, "class='center pct-10' style='text-align: center;'");
	echo "<th class='center pct-10' style='text-align: center;'>Communicator</th>\n";
	echo "<th class='center pct-10' style='text-align: center;'>Comm Pro</th>\n";
	echo "<th class='center pct-10' style='text-align: center;'>USF</th>\n";
	// echo th_order_by('extension_accountcode_count', $text['label-extension_accountcodes'], $order_by, $order);	
	echo th_order_by('destination_accountcode_count', "Acct Codes", $order_by, $order, null, "class='center pct-10' style='text-align: center;'");
	// echo th_order_by('emergency_location_accountcode_count', $text['label-emergency_location_accountcode'], $order_by, $order);
	echo "<th class='center pct-10' style='text-align: center;'>Sync</th>\n";
	echo "</tr>\n";

	if (isset($domain_counts)) foreach ($domain_counts as $key => $row) {
		
		if (permission_exists('connectwise_view') || permission_exists('connectwise_edit')) {
			$agreements = $domain_agreements_map[$row['domain_uuid']] ?? [];
			$agreements_json = htmlspecialchars(json_encode($agreements), ENT_QUOTES, 'UTF-8');
			$domain_label = escape($row['domain_name']);

			echo "	<tr class='cw-row' data-domain='{$row['domain_uuid']}'>\n";
			echo "	<td valign='top' class='{$row_style[$c]} cw-domain-cell' style='position:relative;'>";
			if (count($agreements) === 1) {
				$url = htmlspecialchars($agreements[0]['url'], ENT_QUOTES, 'UTF-8');
				echo "<a href=\"{$url}\" target=\"_blank\" rel=\"noopener\" title=\"Open ConnectWise Agreement {$agreements[0]['id']}\">{$domain_label}</a>";
			}
			elseif (count($agreements) > 1) {
				echo "<a href=\"#\" class=\"cw-domain-chooser\" data-agreements=\"{$agreements_json}\" title=\"Choose ConnectWise agreement\">{$domain_label} ▾</a>";
			}
			else {
				echo $domain_label;
			}
			echo "</td>\n";
			//Extensions: FusionPBX count with the ConnectWise synced count (filled by AJAX)
			echo "	<td valign='top' class='cw-ext-cell {$row_style[$c]}' style='text-align:center;'><span class='cw-ext-hl' style='padding:2px 4px; border-radius:3px;'><a href='connectwise_extension_accountcodes.php?id={$row['domain_uuid']}'>{$row['extension_count']} (<span class='cw-ext-synced'>&hellip;</span>)</a></span>&nbsp;</td>\n";
			echo "	<td valign='top' class='{$row_style[$c]}' style='text-align:center;'>{$row['fax_count']}&nbsp;</td>\n";
			echo "	<td valign='top' class='{$row_style[$c]}' style='text-align:center;'>{$row['sms_destinations_count']}&nbsp;</td>\n";
			//E911 Locations: FusionPBX count with the ConnectWise synced count (filled by AJAX)
			echo "	<td valign='top' class='cw-e911-cell {$row_style[$c]}' style='text-align:center;'><span class='cw-e911-hl' style='padding:2px 4px; border-radius:3px;'><a href='connectwise_emergency_location_accountcodes.php?id={$row['domain_uuid']}'>{$row['emergency_location_count']} (<span class='cw-e911-synced'>&hellip;</span>)</a></span>&nbsp;</td>\n";
			//Communicator (Ringotel active users) vs ConnectWise quantity (filled by AJAX)
			echo "	<td valign='top' class='cw-comm-cell {$row_style[$c]}' style='text-align:center;'><span class='cw-comm-hl' style='padding:2px 4px; border-radius:3px;'><span class='cw-comm-count'>&hellip;</span> (<span class='cw-comm-synced'>&hellip;</span>)</span>&nbsp;</td>\n";
			echo "	<td valign='top' class='cw-commpro-cell {$row_style[$c]}' style='text-align:center;'><span class='cw-commpro-hl' style='padding:2px 4px; border-radius:3px;'><span class='cw-commpro-count'>&hellip;</span> (<span class='cw-commpro-synced'>&hellip;</span>)</span>&nbsp;</td>\n";
			//USF: current CLD-V-USF amount with calculated new amount from prior-month BDR (filled by AJAX)
			echo "	<td valign='top' class='cw-usf-cell {$row_style[$c]}' style='text-align:center;'><span class='cw-usf-hl' style='padding:2px 4px; border-radius:3px;'><span class='cw-usf-old'>&hellip;</span> (<span class='cw-usf-new'>&hellip;</span>)<span class='cw-usf-sum'></span></span>&nbsp;</td>\n";
			echo "	<td valign='top' class='{$row_style[$c]}' style='text-align:center;'><a href='connectwise_destination_accountcodes.php?id={$row['domain_uuid']}'>{$row['destination_accountcode_count']}</a>&nbsp;</td>\n";
			//inline per-row sync action (populated by AJAX) - matches the top-of-page Sync button
			echo "	<td valign='top' class='cw-sync-cell {$row_style[$c]}' style='white-space:nowrap; text-align:center;'>";
			echo button::create(['type'=>'button','label'=>'Sync','icon'=>'sync','class'=>'+cw-sync-btn','style'=>'display: none;']);
			echo "<span class='cw-sync-status' style='margin-left:5px; font-size:11px;'></span>";
			echo "</td>\n";
			echo "</tr>\n";
			$c = ($c==0) ? 1 : 0;
		}
	}

	echo "</table>";
	echo "</form>";

	if (strlen($paging_controls) > 0) {
		echo "<br />";
		echo $paging_controls."\n";
	}
?>
	<style>
		.cw-agreement-menu {
			position: absolute;
			z-index: 1000;
			background: #fff;
			border: 1px solid #aaa;
			box-shadow: 0 2px 8px rgba(0,0,0,0.2);
			min-width: 220px;
			max-height: 260px;
			overflow-y: auto;
			padding: 4px 0;
			margin-top: 2px;
		}
		.cw-agreement-menu a {
			display: block;
			padding: 4px 10px;
			color: #333;
			text-decoration: none;
			white-space: nowrap;
		}
		.cw-agreement-menu a:hover {
			background: #e8f0fe;
		}
		.cw-agreement-menu .cw-agreement-meta {
			color: #666;
			font-size: 11px;
			margin-left: 6px;
		}
	</style>
	<script>
	//lazily load each domain's ConnectWise counts and wire the inline Sync button
	$(document).ready(function() {

		//Domain Name → ConnectWise AgreementFV (chooser when multiple agreements)
		$(document).on('click', '.cw-domain-chooser', function(e) {
			e.preventDefault();
			e.stopPropagation();
			var $link = $(this);
			var $cell = $link.closest('.cw-domain-cell');
			$('.cw-agreement-menu').remove();

			var agreements = [];
			try { agreements = JSON.parse($link.attr('data-agreements') || '[]'); }
			catch (err) { agreements = []; }
			if (!agreements.length) { return; }

			var $menu = $('<div class="cw-agreement-menu"></div>');
			$.each(agreements, function(i, a) {
				var $a = $('<a></a>')
					.attr({ href: a.url, target: '_blank', rel: 'noopener' })
					.text('Agreement ' + a.id);
				if (a.label) {
					$a.append($('<span class="cw-agreement-meta"></span>').text('(' + a.label + ')'));
				}
				$menu.append($a);
			});
			$cell.append($menu);
		});
		$(document).on('click', function() { $('.cw-agreement-menu').remove(); });
		$(document).on('click', '.cw-agreement-menu', function(e) { e.stopPropagation(); });

		var concurrency = 4; //limit simultaneous ConnectWise API calls
		var rowsTotal = $('tr.cw-row').length;
		var rowsLoaded = 0;
		var syncAllRunning = false;

		function highlight(value) {
			if (value === 'red') { return '#ff4444'; }
			if (value === 'yellow') { return 'yellow'; }
			return '';
		}

		function eligibleSyncButtons() {
			//visible, enabled Sync buttons = domains currently eligible to sync
			return $('tr.cw-row .cw-sync-btn:visible').filter(function() {
				return !$(this).prop('disabled');
			});
		}

		function refreshSyncAllButton() {
			var $btn = $('#btn_sync_all');
			if (!$btn.length || syncAllRunning) { return; }
			var n = eligibleSyncButtons().length;
			if (rowsLoaded < rowsTotal) {
				$btn.prop('disabled', true).attr('title', 'Waiting for row counts to load\u2026 (' + rowsLoaded + '/' + rowsTotal + ')');
				return;
			}
			if (n > 0) {
				$btn.prop('disabled', false).attr('title', 'Sync ' + n + ' eligible domain' + (n === 1 ? '' : 's'));
			}
			else {
				$btn.prop('disabled', true).attr('title', 'No domains eligible for sync');
			}
		}

		//apply a counts payload (from either endpoint) to a row
		function applyCounts(row, data) {
			var $row = $(row);

			var ext = data.extension || {};
			$row.find('.cw-ext-synced').text(ext.synced != null ? ext.synced : '?');
			var $extCell = $row.find('.cw-ext-hl');
			$extCell.css('background-color', highlight(ext.highlight));
			if (ext.tooltip) { $extCell.attr('title', ext.tooltip); }

			var e911 = data.e911 || {};
			$row.find('.cw-e911-synced').text(e911.synced != null ? e911.synced : '?');
			var $e911Cell = $row.find('.cw-e911-hl');
			$e911Cell.css('background-color', highlight(e911.highlight));
			if (e911.tooltip) { $e911Cell.attr('title', e911.tooltip); }

			var comm = data.communicator || {};
			$row.find('.cw-comm-count').text(comm.count != null ? comm.count : '0');
			$row.find('.cw-comm-synced').text(comm.synced != null ? comm.synced : '0');
			var $commCell = $row.find('.cw-comm-hl');
			$commCell.css('background-color', highlight(comm.highlight));
			if (comm.tooltip) { $commCell.attr('title', comm.tooltip); }

			var commpro = data.communicator_pro || {};
			$row.find('.cw-commpro-count').text(commpro.count != null ? commpro.count : '0');
			$row.find('.cw-commpro-synced').text(commpro.synced != null ? commpro.synced : '0');
			var $commproCell = $row.find('.cw-commpro-hl');
			$commproCell.css('background-color', highlight(commpro.highlight));
			if (commpro.tooltip) { $commproCell.attr('title', commpro.tooltip); }

			var usf = data.usf || {};
			function usfAmt(v) {
				if (v == null) { return 'n/a'; }
				return Number(v).toFixed(2);
			}
			$row.find('.cw-usf-old').text(usfAmt(usf.old));
			$row.find('.cw-usf-new').text(usfAmt(usf.new));
			$row.find('.cw-usf-sum').text(usf.is_sum ? '*' : '');
			var $usfCell = $row.find('.cw-usf-hl');
			$usfCell.css('background-color', highlight(usf.highlight));
			if (usf.tooltip) { $usfCell.attr('title', usf.tooltip); }

			var $btn = $row.find('.cw-sync-btn');
			$btn.show();
			if (data.eligible_to_sync) {
				$btn.prop('disabled', false);
				$btn.css({'opacity': '1', 'cursor': 'pointer'});
				$btn.attr('title', data.sync_tooltip || 'Sync');
			}
			else {
				$btn.prop('disabled', true);
				$btn.css({'opacity': '0.4', 'cursor': 'not-allowed'});
				$btn.attr('title', 'Not eligible for sync');
			}
			refreshSyncAllButton();
		}

		function markError(row, message) {
			$(row).find('.cw-ext-synced, .cw-e911-synced, .cw-comm-count, .cw-comm-synced, .cw-commpro-count, .cw-commpro-synced, .cw-usf-old, .cw-usf-new').text('!');
			$(row).find('.cw-sync-status').css('color', 'red').text(message || 'load error');
		}

		function loadRow(row) {
			var domain = $(row).attr('data-domain');
			return $.getJSON('connectwise_row_counts.php', { id: domain })
				.done(function(data) {
					if (data && !data.error) { applyCounts(row, data); }
					else { markError(row, (data && data.error) ? data.error : null); }
				})
				.fail(function(xhr) {
					var message = (xhr && xhr.responseJSON && (xhr.responseJSON.message || xhr.responseJSON.error)) || null;
					markError(row, message);
				})
				.always(function() {
					rowsLoaded++;
					refreshSyncAllButton();
				});
		}

		//throttled queue so we don't flood the ConnectWise API on page load
		var queue = $('tr.cw-row').toArray();
		function pump() {
			if (!queue.length) { return; }
			var row = queue.shift();
			loadRow(row).always(pump);
		}
		for (var i = 0; i < concurrency; i++) { pump(); }
		refreshSyncAllButton();

		//sync a single row; returns a jqXHR promise
		function syncRow($row) {
			var $btn = $row.find('.cw-sync-btn');
			var domain = $row.attr('data-domain');
			var $status = $row.find('.cw-sync-status');

			$btn.prop('disabled', true);
			$status.css('color', '').text('Syncing\u2026');

			return $.ajax({ url: 'connectwise_sync_row.php', method: 'POST', dataType: 'json', data: { id: domain } })
				.done(function(data) {
					if (!data || data.error) {
						var msg = (data && data.message) ? data.message : ((data && data.error) ? data.error : 'sync error');
						$status.css('color', 'red').text(msg);
						$btn.prop('disabled', false);
						return;
					}
					applyCounts($row, data.after ? data.after : data);
					var s = data.summary || {};
					var updated = s.updated || 0;

					//list each updated addition's signed change, mirroring the Sync tooltip
					var deltas = [];
					var errors = [];
					if (data.results) {
						$.each(data.results, function(i, r) {
							if (r.result === 'success' || r.result === 'mismatch') {
								var d = Math.round((r.new_count - r.old_count) * 100) / 100;
								deltas.push((d > 0 ? '+' : '') + d + (r.type ? ' ' + r.type : ''));
							} else if (r.result === 'failed') {
								errors.push('<b>' + (r.type || 'Error') + '</b>: ' + (r.message || 'Unknown error'));
							}
						});
					}

					var noun = (updated === 1) ? 'addition' : 'additions';
					var message = 'Updated ' + updated + ' ' + noun;
					if (deltas.length) {
						var cap = 8;
						var list = deltas.slice(0, cap).join(', ');
						if (deltas.length > cap) { list += ', \u2026(' + (deltas.length - cap) + ' more)'; }
						message += ': ' + list;
					}
					
					$status.empty();
					$status.css('color', s.failed ? 'red' : 'green').text(message);
					
					if (s.failed) {
						var errLink = $('<span>')
							.text(s.failed + ' failed')
							.css({'color':'red', 'text-decoration':'underline', 'cursor':'pointer'})
							.on('click', function(e) {
								e.preventDefault();
								$('.cw-error-modal-overlay').remove(); // remove any existing overlays
								var overlay = $('<div>')
									.addClass('cw-error-modal-overlay')
									.css({
										position: 'fixed',
										top: 0, left: 0, width: '100%', height: '100%',
										backgroundColor: 'rgba(0,0,0,0.5)',
										zIndex: 9999
									});
								var modal = $('<div>')
									.css({
										position: 'absolute',
										top: '50%',
										left: '50%',
										transform: 'translate(-50%, -50%)',
										background: '#fff',
										border: '1px solid #ccc',
										padding: '25px',
										boxShadow: '0 5px 15px rgba(0,0,0,0.5)',
										maxWidth: '600px',
										minWidth: '400px',
										color: '#333',
										borderRadius: '4px'
									})
									.html('<h3 style="margin-top:0">Sync Errors</h3>' + errors.join('<br><br>'));
								
								var closeBtn = $('<button>')
									.addClass('btn btn-default')
									.text('Close')
									.css({marginTop: '20px'})
									.on('click', function() { overlay.remove(); });
								
								modal.append($('<br>')).append(closeBtn);
								overlay.append(modal);
								$('body').append(overlay);
							});
						$status.append(document.createTextNode(' \u2013 '));
						$status.append(errLink);
					}
					
					if (s.skipped) {
						$status.append(document.createTextNode(' \u2013 ' + s.skipped + ' skipped'));
					}

					$btn.prop('disabled', false);
				})
				.fail(function(xhr) {
					var msg = (xhr && xhr.responseJSON && (xhr.responseJSON.message || xhr.responseJSON.error)) || 'sync error';
					$status.css('color', 'red').text(msg);
					$btn.prop('disabled', false);
				});
		}

		//inline per-row sync
		$(document).on('click', '.cw-sync-btn', function() {
			if (syncAllRunning) { return; }
			syncRow($(this).closest('tr.cw-row'));
		});

		//Sync All: run per-row sync for every currently eligible domain
		$('#btn_sync_all').on('click', function() {
			if (syncAllRunning) { return; }
			var $targets = eligibleSyncButtons();
			if (!$targets.length) { return; }

			var rows = $targets.map(function() {
				return $(this).closest('tr.cw-row').get(0);
			}).get();

			syncAllRunning = true;
			var $btn = $('#btn_sync_all');
			var $status = $('#sync_all_status');
			var total = rows.length;
			var done = 0;
			var failed = 0;
			$btn.prop('disabled', true);
			$status.css('color', '').text('Syncing 0/' + total + '\u2026');

			var syncConcurrency = 2;
			var q = rows.slice();
			function syncPump() {
				if (!q.length) { return; }
				var row = q.shift();
				syncRow($(row))
					.fail(function() { failed++; })
					.always(function() {
						done++;
						$status.text('Syncing ' + done + '/' + total + '\u2026');
						if (done >= total) {
							syncAllRunning = false;
							$status.css('color', failed ? 'red' : 'green')
								.text('Sync All complete: ' + (total - failed) + ' ok' + (failed ? ', ' + failed + ' failed' : ''));
							refreshSyncAllButton();
							return;
						}
						syncPump();
					});
			}
			for (var si = 0; si < syncConcurrency; si++) { syncPump(); }
		});
	});
	</script>
<?php

//show the footer
	require_once "resources/footer.php";
?>