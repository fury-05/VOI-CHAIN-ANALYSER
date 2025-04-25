<?php
// Start the session FIRST to manage error messages
session_start();

// Adjust Error Reporting: Report all except E_DEPRECATED
error_reporting(E_ALL & ~E_DEPRECATED);
// Consider for production: ini_set('display_errors', 0); and log_errors = On

// --- Configuration ---
$indexer_address = "https://mainnet-idx.voi.nodely.dev";
$api_limit = 1000; // Max results per API page

// --- Get Input from Form ---
$min_round = filter_input(INPUT_GET, 'min_round', FILTER_VALIDATE_INT, ["options" => ["min_range" => 0]]);
$max_round = filter_input(INPUT_GET, 'max_round', FILTER_VALIDATE_INT, ["options" => ["min_range" => 0]]);
$output_format_raw = filter_input(INPUT_GET, 'output_format'); // Get raw value
$valid_formats = ['count', 'csv'];
$output_format = (in_array($output_format_raw, $valid_formats)) ? $output_format_raw : 'count'; // Validate

// --- Build redirect URL parameters (used for errors or count display) ---
$redirect_params_array = [
    'min_round_retain' => $min_round,
    'max_round_retain' => $max_round,
    'output_format_retain' => $output_format
];

// Clear any previous error message from session before starting processing
unset($_SESSION['error_message']);

// --- Input Validation ---
if ($min_round === false || $max_round === false || $max_round < $min_round) {
    $_SESSION['error_message'] = "Invalid round range provided.";
    header('Location: index.php?' . http_build_query($redirect_params_array));
    exit;
}

// --- Function to Fetch Data from API using cURL (Robust) ---
function fetch_api_data($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15); // Connection timeout
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);      // Data receiving timeout (Increased slightly)
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'VOI_PHP_Transaction_Fetcher/1.3'); // Version bump
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1); // Verify SSL cert
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2); // Verify host name
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json')); // Request JSON

    $response_json = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_errno = curl_errno($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_errno) {
        throw new Exception("API Network Error (cURL Error {$curl_errno}): " . $curl_error);
    }
    // Allow 204 No Content as potentially valid empty response for blocks sometimes
    if ($http_code >= 400) {
         $error_details = json_decode($response_json, true);
         $message = $error_details['message'] ?? substr((string)$response_json, 0, 250); // Limit error msg length
         if ($http_code == 404 && stripos((string)$message, 'block') !== false && stripos((string)$message, 'not found') !== false) {
             throw new Exception("Block data not found (HTTP Status: {$http_code}). Round may not exist or is not indexed yet.");
         }
         throw new Exception("API Server Error (HTTP Status: {$http_code}): " . $message);
    }
    // Handle empty response for non-204 codes
    if ($response_json === false || ($response_json === '' && $http_code != 204)) {
        throw new Exception("API Request Failed: Received empty response (HTTP {$http_code}).");
    }

    $response_data = json_decode($response_json, true); // Decode as associative array
    // Check for JSON decoding errors only if the response wasn't empty
    if ($response_json !== '' && json_last_error() !== JSON_ERROR_NONE) {
         throw new Exception("API Data Error: Invalid format received (" . json_last_error_msg() . ").");
    }

    // Return decoded data (can be null or empty array)
    return $response_data ?: []; // Ensure array return
}

// --- Set higher execution time limit ---
set_time_limit(300); // Attempt to set to 5 minutes

// --- Calculate Block Count ---
$total_blocks = $max_round - $min_round + 1;

// --- Fetch Start and End Block Timestamps ---
$start_block_timestamp_unix = null;
$end_block_timestamp_unix = null;
$start_block_date_str = 'N/A';
$end_block_date_str = 'N/A';
// $fetch_error = null; // Using specific error variables below

try {
    // Fetch Start Block
    $start_block_url = $indexer_address . "/v2/blocks/" . $min_round;
    $start_block_data = fetch_api_data($start_block_url);
    $start_block_timestamp_unix = $start_block_data['timestamp'] ?? null;
    if ($start_block_timestamp_unix) {
        $start_block_date_str = gmdate('Y-m-d H:i:s', $start_block_timestamp_unix) . ' UTC';
    } elseif (empty($start_block_data) && $min_round > 0) {
         throw new Exception("Start block {$min_round} data not found or empty.");
    }

    // Fetch End Block
    if ($min_round != $max_round) {
        $end_block_url = $indexer_address . "/v2/blocks/" . $max_round;
        $end_block_data = fetch_api_data($end_block_url);
        $end_block_timestamp_unix = $end_block_data['timestamp'] ?? null;
         if ($end_block_timestamp_unix) {
            $end_block_date_str = gmdate('Y-m-d H:i:s', $end_block_timestamp_unix) . ' UTC';
        } elseif (empty($end_block_data)) {
             throw new Exception("End block {$max_round} data not found or empty.");
        }
    } else {
        $end_block_timestamp_unix = $start_block_timestamp_unix;
        $end_block_date_str = $start_block_date_str;
    }

} catch (Exception $e) {
    $_SESSION['error_message'] = "Error fetching block details: " . $e->getMessage();
    header('Location: index.php?' . http_build_query($redirect_params_array));
    exit;
}


// --- Fetch All Transactions (Handles Pagination) ---
$all_transactions_raw = [];
$next_page_token = null;
$page_count = 0;
$fetch_tx_error = null; // Use separate variable for transaction fetch errors

try {
    do {
        $page_count++;
        $query_params = [
            'min-round' => $min_round, 'max-round' => $max_round, 'limit' => $api_limit
        ];
        if ($next_page_token) { $query_params['next'] = $next_page_token; }

        $url = $indexer_address . "/v2/transactions?" . http_build_query($query_params);
        $response_data = fetch_api_data($url);

        $transactions_on_page = $response_data['transactions'] ?? [];
        if (!empty($transactions_on_page)) {
             foreach ($transactions_on_page as $tx) { $all_transactions_raw[] = $tx; }
        } // Allow loop even if page is empty, check token below

        $next_page_token = $response_data['next-token'] ?? null;
        if ($next_page_token) { usleep(50000); } // 50ms sleep if paging

    } while ($next_page_token);

} catch (Exception $e) {
    $fetch_tx_error = $e->getMessage();
}

// --- Filter Transactions and Count Types ---
$filtered_transactions = [];
$summary_counts = [
    'total_raw' => count($all_transactions_raw), 'filtered' => 0,
    'pay_filtered' => 0, 'appl_filtered' => 0, 'axfer_filtered' => 0,
    'acfg_filtered' => 0, 'afrz_filtered' => 0, 'keyreg_excluded' => 0,
    'zero_pay_excluded' => 0
];

// Proceed with filtering even if there was a fetch error (using partial data)
foreach ($all_transactions_raw as $tx) {
    $tx_type = $tx['tx-type'] ?? null;
    // Exclusion Filters
    if ($tx_type === 'keyreg') { $summary_counts['keyreg_excluded']++; continue; }
    if ($tx_type === 'pay') {
        if (($tx['payment-transaction']['amount'] ?? -1) === 0) {
            $summary_counts['zero_pay_excluded']++; continue;
        }
    }
    // If not excluded, count filtered types
    $filtered_transactions[] = $tx;
    $summary_counts['filtered']++;
    switch ($tx_type) {
        case 'pay': $summary_counts['pay_filtered']++; break;
        case 'appl': $summary_counts['appl_filtered']++; break;
        case 'axfer': $summary_counts['axfer_filtered']++; break;
        case 'acfg': $summary_counts['acfg_filtered']++; break;
        case 'afrz': $summary_counts['afrz_filtered']++; break;
    }
}

// --- Generate Output Based on Format ---

// Handle fetch error AFTER processing partial data but BEFORE final output/redirect
if ($fetch_tx_error) {
     $_SESSION['error_message'] = "Error during transaction fetch (results might be incomplete): " . $fetch_tx_error;
     // If count was requested, still redirect to show partial results/error
     if ($output_format == 'count') {
         // Add summary data collected so far
         $redirect_params_array['count'] = $summary_counts['filtered'];
         $redirect_params_array['block_count'] = $total_blocks;
         $redirect_params_array['start_date'] = urlencode($start_block_date_str);
         $redirect_params_array['end_date'] = urlencode($end_block_date_str);
         $redirect_params_array['pay_count'] = $summary_counts['pay_filtered'];
         $redirect_params_array['appl_count'] = $summary_counts['appl_filtered'];
         $redirect_params_array['axfer_count'] = $summary_counts['axfer_filtered'];
         $redirect_params_array['keyreg_excluded'] = $summary_counts['keyreg_excluded'];
         $redirect_params_array['zero_pay_excluded'] = $summary_counts['zero_pay_excluded'];
         header('Location: index.php?' . http_build_query($redirect_params_array));
         exit;
     } else { // For CSV, send an error text file
         header('Content-Type: text/plain');
         header('Content-Disposition: attachment; filename="ERROR_fetching_transactions.txt"');
         echo "An error occurred while fetching transactions, CSV could not be generated.\n";
         echo "Round Range: {$min_round} - {$max_round}\n";
         echo "Error: " . $fetch_tx_error . "\n\n";
         echo "Partial Filtered Count: " . $summary_counts['filtered']; // Show partial count if available
         exit;
     }
}

// --- Output Generation (if no fetch error) ---
switch ($output_format) {
    case 'csv':
        // Set cookie just before sending headers
        setcookie('downloadStatus', 'complete', ['expires' => time() + 15, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);

        $filename = "voi_filtered_txns_rounds_{$min_round}_to_{max_round}_" . date('YmdHis') . ".csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM

        // CSV Summary Section
        fputcsv($output, ["Voi Mainnet Transaction Analysis Summary"]);
        fputcsv($output, ["Parameter", "Value"]);
        fputcsv($output, ["Round Range", "{$min_round} - {$max_round}"]);
        fputcsv($output, ["Number of Blocks", $total_blocks]);
        fputcsv($output, ["Start Block Timestamp (UTC)", $start_block_date_str]);
        fputcsv($output, ["End Block Timestamp (UTC)", $end_block_date_str]);
        fputcsv($output, ["Total Filtered Transactions", $summary_counts['filtered']]);
        fputcsv($output, ["- Payment ('pay') Txns", $summary_counts['pay_filtered']]);
        fputcsv($output, ["- Application Call ('appl') Txns", $summary_counts['appl_filtered']]);
        fputcsv($output, ["- Asset Transfer ('axfer') Txns", $summary_counts['axfer_filtered']]);
        fputcsv($output, ["- Asset Config ('acfg') Txns", $summary_counts['acfg_filtered']]);
        fputcsv($output, ["- Asset Freeze ('afrz') Txns", $summary_counts['afrz_filtered']]);
        fputcsv($output, ["Excluded 'keyreg' Txns", $summary_counts['keyreg_excluded']]);
        fputcsv($output, ["Excluded 0-Amount 'pay' Txns", $summary_counts['zero_pay_excluded']]);
        fputcsv($output, ["Raw Transactions Checked", $summary_counts['total_raw']]);
        fputcsv($output, []); // Blank Separator Row

        // CSV Headers
        $headers = ['Tx ID', 'Type', 'Round', 'Timestamp (UTC)', 'Sender', 'Receiver', 'Amount', 'Asset ID', 'Fee (microVOI)', 'Note'];
        fputcsv($output, $headers);

        // CSV Data Rows
        foreach ($filtered_transactions as $tx) {
            $tx_id = $tx['id'] ?? ''; $tx_type = $tx['tx-type'] ?? ''; $round = $tx['confirmed-round'] ?? '';
            $timestamp = isset($tx['round-time']) ? gmdate('Y-m-d H:i:s', $tx['round-time']) : '';
            $sender = $tx['sender'] ?? '';
            $receiver = $tx['payment-transaction']['receiver'] ?? ($tx['asset-transfer-transaction']['receiver'] ?? ($tx['application-transaction']['accounts'][0] ?? ''));
            $amount = $tx['payment-transaction']['amount'] ?? ($tx['asset-transfer-transaction']['amount'] ?? ''); if ($amount === '') $amount = '0';
            $asset_id = $tx['asset-transfer-transaction']['asset-id'] ?? ($tx['asset-config-transaction']['asset-id'] ?? ''); if ($asset_id === '') $asset_id = ($tx_type === 'pay' ? 'VOI' : '');
            $fee = $tx['fee'] ?? '0';
            $note_raw = $tx['note'] ?? ''; $note_decoded = $note_raw ? base64_decode($note_raw, true) : '';
            $note_printable = ($note_decoded !== false && mb_check_encoding($note_decoded, 'UTF-8')) ? $note_decoded : ($note_raw ? '(Base64/Binary)' : '');
            $rowData = [ $tx_id, $tx_type, $round, $timestamp, $sender, $receiver, $amount, $asset_id, $fee, $note_printable ];
            fputcsv($output, $rowData);
        }
        fclose($output);
        exit;

    case 'count':
    default:
        // Show Count & Summary Only
        $redirect_params_array['count'] = $summary_counts['filtered'];
        $redirect_params_array['block_count'] = $total_blocks;
        $redirect_params_array['start_date'] = urlencode($start_block_date_str);
        $redirect_params_array['end_date'] = urlencode($end_block_date_str);
        $redirect_params_array['pay_count'] = $summary_counts['pay_filtered'];
        $redirect_params_array['appl_count'] = $summary_counts['appl_filtered'];
        $redirect_params_array['axfer_count'] = $summary_counts['axfer_filtered']; // Pass axfer count
        $redirect_params_array['keyreg_excluded'] = $summary_counts['keyreg_excluded'];
        $redirect_params_array['zero_pay_excluded'] = $summary_counts['zero_pay_excluded'];

        header('Location: index.php?' . http_build_query($redirect_params_array));
        exit;
}

?>