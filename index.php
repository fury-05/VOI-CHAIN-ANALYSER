<?php
// Start the session to manage error messages and retain form values
session_start();

// Retain form values from GET parameters if available
$min_round_retain = filter_input(INPUT_GET, 'min_round_retain', FILTER_VALIDATE_INT);
$max_round_retain = filter_input(INPUT_GET, 'max_round_retain', FILTER_VALIDATE_INT);
$output_format_retain = filter_input(INPUT_GET, 'output_format_retain'); // No deprecated filter

// Get summary data if passed back
$summary_data = [];
if (isset($_GET['count'])) {
    $summary_data['count'] = filter_input(INPUT_GET, 'count', FILTER_VALIDATE_INT);
    $summary_data['block_count'] = filter_input(INPUT_GET, 'block_count', FILTER_VALIDATE_INT);
    $summary_data['start_date'] = filter_input(INPUT_GET, 'start_date', FILTER_UNSAFE_RAW);
    $summary_data['end_date'] = filter_input(INPUT_GET, 'end_date', FILTER_UNSAFE_RAW);
    $summary_data['pay_count'] = filter_input(INPUT_GET, 'pay_count', FILTER_VALIDATE_INT);
    $summary_data['appl_count'] = filter_input(INPUT_GET, 'appl_count', FILTER_VALIDATE_INT);
    $summary_data['axfer_count'] = filter_input(INPUT_GET, 'axfer_count', FILTER_VALIDATE_INT);
    $summary_data['keyreg_excluded'] = filter_input(INPUT_GET, 'keyreg_excluded', FILTER_VALIDATE_INT);
    $summary_data['zero_pay_excluded'] = filter_input(INPUT_GET, 'zero_pay_excluded', FILTER_VALIDATE_INT);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voi Transaction Analyzer</title>
    <style>
        /* --- CSS Styles (Unchanged from previous version) --- */
        :root {
            --voi-purple: #6B46C1; --voi-purple-darker: #553C9A; --voi-bg-dark: #1A202C;
            --voi-bg-medium: #2D3748; --voi-text-light: #EDF2F7; --voi-text-dark: #1A202C;
            --glass-bg: rgba(45, 55, 72, 0.3); --glass-bg-input: rgba(255, 255, 255, 0.08);
            --glass-border: rgba(255, 255, 255, 0.15); --error-bg: rgba(224, 49, 49, 0.2);
            --error-border: rgba(224, 49, 49, 0.4); --error-text: #FED7D7;
            --success-bg: rgba(56, 161, 105, 0.2); --success-border: rgba(56, 161, 105, 0.4);
            --success-text: #C6F6D5;
        }
        *, *::before, *::after { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif;
            background-color: var(--voi-bg-dark);
            background-image: linear-gradient(135deg, var(--voi-purple-darker) 10%, var(--voi-bg-medium) 90%);
            background-attachment: fixed; color: var(--voi-text-light); padding: 30px 15px; margin: 0;
            min-height: 100vh; display: flex; flex-direction: column; align-items: center; overflow-y: auto;
        }
        .container {
            background-color: var(--glass-bg); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
            padding: 30px 40px; border-radius: 15px; border: 1px solid var(--glass-border);
            max-width: 600px; width: 100%; box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
            margin-top: 20px; margin-bottom: 20px;
        }
        h1 { color: #FFF; text-align: center; margin-top: 0; margin-bottom: 15px; }
        p.instructions { text-align: center; color: var(--voi-text-light); opacity: 0.8; margin-bottom: 30px; font-size: 0.95em; line-height: 1.5; }
        label { display: block; margin-top: 15px; margin-bottom: 5px; font-weight: 600; color: var(--voi-text-light); opacity: 0.9; }
        input[type="number"], select {
            width: 100%; padding: 12px 15px; border: 1px solid var(--glass-border); border-radius: 8px;
            background-color: var(--glass-bg-input); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
            color: var(--voi-text-light); font-size: 1em; transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        input[type="number"]:focus, select:focus { outline: none; border-color: var(--voi-purple); box-shadow: 0 0 0 3px rgba(107, 70, 193, 0.3); }
        select option { background-color: #FFF; color: var(--voi-text-dark); padding: 5px; }
        input[type="number"]::-webkit-inner-spin-button, input[type="number"]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
        input[type="number"] { -moz-appearance: textfield; }
        input[type="submit"] {
            margin-top: 30px; padding: 12px 20px; width: 100%; background-color: rgba(107, 70, 193, 0.6);
            color: white; border: 1px solid rgba(107, 70, 193, 0.8); border-radius: 8px; cursor: pointer;
            font-size: 1.1em; font-weight: 600; backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
            transition: background-color 0.3s ease, box-shadow 0.3s ease;
        }
        input[type="submit"]:hover, input[type="submit"]:focus { background-color: rgba(107, 70, 193, 0.8); box-shadow: 0 4px 20px 0 rgba(0, 0, 0, 0.25); outline: none; }
        .note { font-size: 0.85em; color: var(--voi-text-light); opacity: 0.7; margin-top: 8px; text-align: left; }
        .message-box { margin-top: 30px; padding: 15px 20px; border: 1px solid var(--glass-border); border-radius: 8px; background-color: var(--glass-bg); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); box-shadow: 0 4px 15px 0 rgba(0, 0, 0, 0.1); line-height: 1.6; }
        .error { border-color: var(--error-border); background-color: var(--error-bg); color: var(--error-text); font-weight: 500; }
        .summary { border-color: var(--success-border); background-color: var(--success-bg); color: var(--success-text); }
        .summary h2 { margin-top: 0; font-size: 1.2em; color: #FFF; border-bottom: 1px solid var(--glass-border); padding-bottom: 8px; margin-bottom: 15px; }
        .summary p { margin: 8px 0; }
        .summary strong { color: #FFF; font-weight: 600; }
        .summary hr { border: none; border-top: 1px solid var(--glass-border); margin: 15px 0; }
        .summary em { font-style: normal; opacity: 0.8; }
        /* Loading Indicator Styles */
        #loading-indicator {
            display: none; /* Hidden by default */
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background-color: rgba(0, 0, 0, 0.75); z-index: 9999;
            justify-content: center; align-items: center; text-align: center;
            flex-direction: column; cursor: wait;
        }
        #loading-indicator p { color: var(--voi-text-light); font-size: 1.1em; margin-top: 20px; }
        .spinner {
            border: 6px solid rgba(255, 255, 255, 0.2); border-top: 6px solid var(--voi-purple);
            border-radius: 50%; width: 50px; height: 50px; animation: spin 1s linear infinite;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>

    <div id="loading-indicator">
        <div class="spinner"></div>
        <p>Analyzing Rounds... Please Wait</p>
        <p style="font-size: 0.9em; opacity: 0.7;">(This may take a while for large ranges)</p>
    </div>

    <div class="container">
        <h1>Voi Mainnet Transaction Analyzer</h1>
        <p class="instructions">
            Enter a starting and ending block number (round) to analyze transactions on the Voi Mainnet.
            View a summary or download detailed transaction data as a CSV file (opens in Excel).
            Filters exclude network noise ('keyreg' & 0 Voi 'pay' txns).
        </p>

        <form action="process.php" method="get" id="voi-form">
            <label for="min_round">Start Round (Block):</label>
            <input type="number" id="min_round" name="min_round" required min="0" placeholder="e.g., 6891874" value="<?php echo htmlspecialchars($min_round_retain ?? ''); ?>">

            <label for="max_round">End Round (Block):</label>
            <input type="number" id="max_round" name="max_round" required min="0" placeholder="e.g., 6892874" value="<?php echo htmlspecialchars($max_round_retain ?? ''); ?>">
            <div class="note">Tip: Keep the range reasonably small (e.g., under 10k-20k blocks) to avoid script timeouts, especially for CSV downloads.</div>

            <label for="output_format">Select Output:</label>
            <select name="output_format" id="output_format">
                <option value="count" <?php echo (!$output_format_retain || $output_format_retain == 'count') ? 'selected' : ''; ?>>Show Summary & Count</option>
                <option value="csv" <?php echo ($output_format_retain == 'csv') ? 'selected' : ''; ?>>Download CSV / Excel (Filtered Details)</option>
            </select>

            <br>
            <input type="submit" value="Analyze Rounds">
        </form>

        <?php
        // Display error message from session if it exists
        if (isset($_SESSION['error_message'])) {
            echo "<div class='message-box error'>Error: " . htmlspecialchars($_SESSION['error_message']) . "</div>";
            unset($_SESSION['error_message']); // Clear it after displaying
        }

        // Display summary results if count is passed back (and no session error exists)
        if (isset($summary_data['count']) && !isset($_SESSION['error_message'])) {
             echo "<div class='message-box summary'>";
             echo "<h2>Analysis Summary</h2>";
             // (Summary display lines remain the same as before)
             echo "<p><strong>Round Range:</strong> " . htmlspecialchars($min_round_retain ?? 'N/A') . " to " . htmlspecialchars($max_round_retain ?? 'N/A') . "</p>";
             echo "<p><strong>Number of Blocks Checked:</strong> " . htmlspecialchars($summary_data['block_count'] ?? 'N/A') . "</p>";
             echo "<p><strong>Start Block Time (UTC):</strong> " . htmlspecialchars(urldecode($summary_data['start_date'] ?? 'N/A')) . "</p>";
             echo "<p><strong>End Block Time (UTC):</strong> " . htmlspecialchars(urldecode($summary_data['end_date'] ?? 'N/A')) . "</p>";
             echo "<hr>";
             echo "<p><strong>Total Filtered Transactions:</strong> <strong>" . htmlspecialchars($summary_data['count'] ?? 'N/A') . "</strong></p>";
             echo "<p>&nbsp;&nbsp; - Payment ('pay') Txns: " . htmlspecialchars($summary_data['pay_count'] ?? '0') . "</p>";
             echo "<p>&nbsp;&nbsp; - Application Call ('appl') Txns: " . htmlspecialchars($summary_data['appl_count'] ?? '0') . "</p>";
             echo "<p>&nbsp;&nbsp; - Asset Transfer ('axfer') Txns: " . htmlspecialchars($summary_data['axfer_count'] ?? '0') . "</p>";
             echo "<hr>";
             echo "<p><em>Excluded 'keyreg' Txns:</em> " . htmlspecialchars($summary_data['keyreg_excluded'] ?? '0') . "</p>";
             echo "<p><em>Excluded 0-Amount 'pay' Txns:</em> " . htmlspecialchars($summary_data['zero_pay_excluded'] ?? '0') . "</p>";
             echo "</div>";
        }
        ?>

    </div><script>
        console.log("Loading indicator script started."); // DEBUG

        const form = document.getElementById('voi-form');
        const loadingIndicator = document.getElementById('loading-indicator');

        // --- Check if elements were found ---
        if (!form) console.error("Could not find form element with ID: voi-form");
        if (!loadingIndicator) console.error("Could not find loading indicator element with ID: loading-indicator");
        // --- End Check ---


        if (form && loadingIndicator) {
             console.log("Form and indicator elements found. Adding listener."); // DEBUG
            form.addEventListener('submit', function(event) {
                console.log("Form submitted!"); // DEBUG

                // --- Action: Show the loading indicator ---
                loadingIndicator.style.display = 'flex';
                console.log("Set loading indicator display to flex."); // DEBUG

                // --- REMOVED: All cookie checking logic for now ---
                // We are focusing only on making the indicator appear.
                // If this works, we can re-add the logic to hide it for CSV later.

                // Allow the normal form submission to proceed.
            });
        } else {
             console.error("Could not attach form submit listener because one or more elements were not found."); // DEBUG
        }

        // --- REMOVED: pageshow and beforeunload listeners for simplicity ---
        // We can add them back later if needed.

    </script>
     </body>
</html>