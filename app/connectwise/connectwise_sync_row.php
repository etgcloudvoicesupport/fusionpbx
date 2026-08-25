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
*/

//AJAX endpoint: syncs all out-of-sync, active, mapped additions (Extensions and
//E911 Locations) for a single domain, then returns the refreshed counts as JSON
//so connectwise.php can update the row inline without reloading the page.

//includes
	require_once "/var/www/fusionpbx/resources/require.php";
	require_once "resources/check_auth.php";

//don't abort mid-sync if the client navigates away
	ignore_user_abort(true);
	set_time_limit(0);

//emit JSON only
	header('Content-Type: application/json');

//check permissions
	if (!permission_exists('connectwise_sync')) {
		http_response_code(403);
		echo json_encode(['error' => 'access denied']);
		exit;
	}

//require POST to perform a sync
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		http_response_code(405);
		echo json_encode(['error' => 'method not allowed']);
		exit;
	}

//validate the domain_uuid
	$domain_uuid = $_POST['id'] ?? '';
	if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $domain_uuid)) {
		http_response_code(400);
		echo json_encode(['error' => 'invalid domain id']);
		exit;
	}

	$logfile = $_SESSION['connectwise']['cw_sync_logfile']['text'] ?: "/var/log/fusionpbx/cw_sync.log";

//release the session lock to prevent blocking concurrent AJAX requests
	session_write_close();
	//buffer output so stray notices/warnings cannot corrupt the JSON body
	ob_start();

	//release the session lock so concurrent AJAX requests for this user don't queue up
	session_write_close();

	try {
		$connectwise = new connectwise();

		//ringotel counts feed the Communicator sync targets; isolate failures so the
		//extension/e911 sync still proceeds if Ringotel is unreachable
		$ringotel = null;
		try {
			$ringotel_client = new ringotel_client();
			$ringotel = $ringotel_client->domainActiveUsers($domain_uuid);
		}
		catch (\Throwable $re) {
			error_log("connectwise_sync_row.php ringotel domain {$domain_uuid}: ".$re->getMessage());
			$ringotel = null;
		}

		//figure out what needs syncing for this domain (active + differing) and
		//what should be skipped (differing but not on an active agreement)
		$before = $connectwise->domainSummary($domain_uuid, $ringotel);

		$work = [];
		foreach ($before['extension']['out_of_sync'] as $entry)        { $work[] = ['type' => 'Extensions', 'entry' => $entry]; }
		foreach ($before['e911']['out_of_sync'] as $entry)             { $work[] = ['type' => 'E911 Locations', 'entry' => $entry]; }
		foreach ($before['communicator']['out_of_sync'] as $entry)     { $work[] = ['type' => 'Communicator', 'entry' => $entry]; }
		foreach ($before['communicator_pro']['out_of_sync'] as $entry) { $work[] = ['type' => 'Communicator Pro', 'entry' => $entry]; }
		foreach (($before['usf']['out_of_sync'] ?? []) as $entry)      { $work[] = ['type' => 'USF', 'entry' => $entry]; }

		$skipped_items = [];
		foreach ($before['extension']['inactive'] as $entry) { $skipped_items[] = ['type' => 'Extensions', 'entry' => $entry]; }
		foreach ($before['e911']['inactive'] as $entry)      { $skipped_items[] = ['type' => 'E911 Locations', 'entry' => $entry]; }

		$summary = ['updated' => 0, 'failed' => 0, 'skipped' => 0, 'delta' => 0];
		$results = [];

		//perform the syncs
		foreach ($work as $item) {
			$type  = $item['type'];
			$entry = $item['entry'];
			$delta = $entry['new_count'] - $entry['old_count'];

			if ($type === 'USF') {
				$res = $connectwise->syncUsfAddition($entry['agreement_id'], $entry['addition_id'], $entry['old_count'], $entry['new_count']);
			}
			else {
				$res = $connectwise->syncAddition($entry['agreement_id'], $entry['addition_id'], $entry['old_count'], $entry['new_count']);
			}

			if ($res['result'] === 'success' || $res['result'] === 'mismatch') {
				$summary['updated']++;
				$summary['delta'] += $delta;
				$message = ($res['result'] === 'success')
					? "Synced {$entry['old_count']} \u{2192} {$entry['new_count']}; CW now {$res['cw_new']}"
					: "Sent {$entry['old_count']} \u{2192} {$entry['new_count']}; CW reports {$res['cw_new']}";
			}
			else {
				$summary['failed']++;
				$message = $res['error'];
			}

			$results[] = [
				'type'         => $type,
				'domain_name'  => $entry['domain_name'],
				'agreement_id' => $entry['agreement_id'],
				'addition_id'  => $entry['addition_id'],
				'old_count'    => $entry['old_count'],
				'new_count'    => $entry['new_count'],
				'result'       => $res['result'],
				'http_status'  => $res['http_status'],
				'message'      => $message,
				'response_json'=> $res['response_json'] ?? '{}'
			];

			$logMessage = "type:{$type}; domain:{$entry['domain_name']}; agreement_id:{$entry['agreement_id']}; addition_id:{$entry['addition_id']}; old_count:{$entry['old_count']}; new_count:{$entry['new_count']}; result:{$res['result']}; http_status:{$res['http_status']}; API_response:{$res['response_json']}\n";
			file_put_contents($logfile, $logMessage, FILE_APPEND);
		}

		//record skipped (inactive) items
		foreach ($skipped_items as $item) {
			$type  = $item['type'];
			$entry = $item['entry'];
			$summary['skipped']++;
			$results[] = [
				'type'         => $type,
				'domain_name'  => $entry['domain_name'],
				'agreement_id' => $entry['agreement_id'],
				'addition_id'  => $entry['addition_id'],
				'old_count'    => $entry['old_count'],
				'new_count'    => $entry['new_count'],
				'result'       => 'skipped',
				'http_status'  => null,
				'message'      => 'Skipped: addition not on an active agreement (inactive/expired or removed)',
			];
			$logMessage = "type:{$type}; domain:{$entry['domain_name']}; agreement_id:{$entry['agreement_id']}; addition_id:{$entry['addition_id']}; old_count:{$entry['old_count']}; new_count:{$entry['new_count']}; result:skipped; reason:addition_not_on_active_agreement\n";
			file_put_contents($logfile, $logMessage, FILE_APPEND);
		}

		//refresh the summary so the row reflects the new ConnectWise quantities
		$after = $connectwise->domainSummary($domain_uuid, $ringotel);

		//verify the success against the fresh lookup
		foreach ($results as &$res) {
			if ($res['result'] === 'success' || $res['result'] === 'mismatch') {
				$type_key = '';
				if ($res['type'] === 'Extensions') $type_key = 'extension';
				elseif ($res['type'] === 'E911 Locations') $type_key = 'e911';
				elseif ($res['type'] === 'Communicator') $type_key = 'communicator';
				elseif ($res['type'] === 'Communicator Pro') $type_key = 'communicator_pro';
				elseif ($res['type'] === 'USF') $type_key = 'usf';

				$still_out_of_sync = false;
				if (!empty($type_key) && !empty($after[$type_key]['out_of_sync'])) {
					foreach ($after[$type_key]['out_of_sync'] as $oos) {
						if ($oos['addition_id'] == $res['addition_id']) {
							$still_out_of_sync = true;
							break;
						}
					}
				}

				if ($still_out_of_sync) {
					//revert the success
					$res['result'] = 'failed';

					//diagnose phantom success
					$diag = "";
					$api_resp = json_decode($res['response_json'] ?? '{}', true);
					if ($api_resp) {
						$reasons = [];
						if (isset($api_resp['quantity']) && (float)$api_resp['quantity'] == 0) {
							$reasons[] = "Quantity is 0";
						}
						if (isset($api_resp['cancelledDate']) && !empty($api_resp['cancelledDate'])) {
							$reasons[] = "Addition is cancelled";
						}
						if (isset($api_resp['unitPrice']) && bccomp((string)$api_resp['unitPrice'], (string)$res['new_count'], 2) !== 0) {
							$reasons[] = "API returned Unit Price " . $api_resp['unitPrice'];
						}
						if (!empty($reasons)) {
							$diag = " (Agreement {$res['agreement_id']}: " . implode(", ", $reasons) . ")";
						}
					}

					$res['message'] = "Sync appeared successful, but fresh lookup shows it is still out of sync." . $diag;
					unset($res['response_json']);
					
					$summary['updated']--;
					$summary['failed']++;
					$delta = $res['new_count'] - $res['old_count'];
					$summary['delta'] -= $delta;
				}
				else {
					unset($res['response_json']);
				}
			}
		}
		unset($res);

		ob_end_clean();
		echo json_encode([
			'domain_uuid'      => $domain_uuid,
			'summary'          => $summary,
			'results'          => $results,
			'after'            => $after,
		]);
	}
	catch (\Throwable $e) {
		ob_end_clean();
		http_response_code(500);
		error_log("connectwise_sync_row.php domain {$domain_uuid}: ".$e->getMessage());
		echo json_encode(['error' => 'sync failed', 'message' => $e->getMessage()]);
	}

?>
