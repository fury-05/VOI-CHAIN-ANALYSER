# VOI Transaction Analyzer (PHP)
Transaction analyser for VOI chain

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)

A web-based tool built with PHP to fetch, filter, summarize, and export transaction data from the Voi Mainnet blockchain based on specified block round ranges.

## Features

* Analyze transactions within a specific block round range on Voi Mainnet.
* Displays a summary on the webpage including:
    * Total number of blocks checked in the range.
    * Timestamp range (UTC) of the start and end blocks provided.
    * Total count of **filtered** transactions.
    * Breakdown counts for common filtered transaction types (`pay`, `appl`, `axfer`).
    * Counts of **excluded** transactions (`keyreg`, 0-amount `pay`).
* Download filtered transaction details as a **CSV file** (compatible with Excel, Google Sheets, etc.), which includes the summary information in the header.
* Responsive user interface with a Voi-inspired theme and glassmorphism effects.
* Basic loading indicator during data fetching.
* Automatically handles API pagination to retrieve all transactions within the specified round range.
* Includes simple retry logic for potentially transient API connection errors.
* Configurable API endpoint, request limits, and timeouts.

## Technology Stack

* **Frontend:** HTML, CSS, JavaScript (vanilla - for loading indicator)
* **Backend:** PHP (Requires v7.4+, tested lightly on 8.x - aware of deprecations)
    * Requires the `cURL` extension for API requests.
    * Uses PHP Sessions for error handling between requests.
* **API:** Voi Public Indexer API (Defaults to using [Nodely.io](https://nodely.io/)'s free Voi Mainnet endpoint)

## Prerequisites

Before you set up the analyzer, ensure you have the following:

1.  **Web Server:** A web server (like Apache, Nginx, Caddy, etc.) configured to run PHP.
2.  **PHP:** PHP version 7.4 or newer installed and configured with the web server.
3.  **PHP cURL Extension:** The `php_curl` extension must be enabled in your `php.ini` file. This is usually enabled by default on most hosting providers and standard PHP installations. You can check with `phpinfo()`.

## Installation / Setup

1.  **Get the Code:**
    * Clone the repository: `git clone <repository_url>`
    * OR Download the source code ZIP file and extract it.
2.  **Upload Files:** Upload the `index.php` and `process.php` files to a directory accessible by your web server (e.g., inside `public_html`, `htdocs`, `www`, or a subdirectory).
3.  **Check Permissions:** Ensure the web server has read permissions for both files.
4.  **Verify cURL:** Double-check that the PHP cURL extension is enabled on your server environment.
5.  **Access:** Open `index.php` in your web browser using the appropriate URL (e.g., `http://yourdomain.com/voi-analyzer/index.php` or `http://localhost/voi-analyzer/index.php`).

## Configuration

Most settings can be adjusted directly within the `process.php` file:

* `$indexer_address`: (Line ~11) The URL of the Voi Indexer API endpoint. Defaults to Nodely's Mainnet endpoint. Change this if you use a different provider or run your own indexer.
* `$api_limit`: (Line ~13) Maximum transactions to fetch per API request page. Defaults to `500`. Lowering this (e.g., to `250`) might help avoid memory or timeout errors on servers with limited resources or for very large round ranges, but will increase the number of API calls.
* `$max_retries`: (Line ~14) Number of times the script will automatically retry a failed API call (due to network errors like connection resets). Defaults to `2`.
* `$retry_delay_ms`: (Line ~15) Time in milliseconds to wait before retrying a failed API call. Defaults to `1500` (1.5 seconds).
* `set_time_limit(300)`: (Line ~82) Attempts to set the PHP maximum execution time for the script to 300 seconds (5 minutes). Your web server configuration (`php.ini`'s `max_execution_time`) might override this or have a lower hard limit. Increase cautiously if needed for very large ranges, but be aware of server resource usage.
* `error_reporting(E_ALL & ~E_DEPRECATED);`: (Line ~6) Controls PHP error reporting level *within the script*. It attempts to hide deprecation notices that could interfere with CSV output. **For production, it is strongly recommended to set `display_errors = Off` in your main `php.ini` configuration** and enable `log_errors`.

## How to Use

1.  Navigate to the `index.php` page in your web browser.
2.  **Enter Round Range:**
    * Enter the specific starting block round number in the "Start Round" field.
    * Enter the specific ending block round number in the "End Round" field. Ensure End Round >= Start Round.
3.  **Select Output Format:**
    * `Show Summary & Count`: Displays the analysis summary directly on the web page after processing.
    * `Download CSV / Excel`: Initiates a download of a CSV file containing the summary and the full details of all **filtered** transactions found in the range. This file can be opened in Excel, Google Sheets, LibreOffice Calc, etc.
4.  **Analyze:** Click the "Analyze Rounds" button.
5.  **Wait:** The loading indicator will appear. Processing can take time, especially for large round ranges or when downloading CSVs with many transactions. Avoid reloading the page.
6.  **View/Save Results:**
    * If you chose "Show Summary", the page will reload displaying the results below the form.
    * If you chose "Download CSV", your browser will prompt you to save the file. The loading indicator on the page should disappear shortly after the download begins.

## How It Works (Technical Overview)

1.  **Frontend (`index.php`):**
    * Displays the HTML form for entering the round range and selecting output format.
    * Uses JavaScript to show a basic loading spinner overlay when the form is submitted.
    * For CSV downloads, uses JavaScript and a cookie mechanism to try and hide the spinner after the download is initiated by the backend.
    * Displays summary results or error messages passed back from `process.php` via GET parameters or PHP Sessions.

2.  **Backend (`process.php`):**
    * Receives `min_round`, `max_round`, and `output_format` from the GET request and validates them.
    * **Block Timestamps:** Makes API calls to `/v2/blocks/{round}` for the start and end rounds to get their actual timestamps for display.
    * **Transaction Fetching:** Enters a loop to fetch transactions from the `/v2/transactions` API endpoint using the specified rounds and limit.
        * **Pagination:** Uses the `next-token` provided by the API to fetch subsequent pages until all transactions in the range are retrieved.
        * **Retries:** Includes logic to automatically retry API calls a few times if certain network errors occur.
    * **Filtering & Counting:** Iterates through the raw transactions fetched:
        * Excludes transactions with type `keyreg`.
        * Excludes `pay` type transactions where the `amount` is 0.
        * Counts excluded types.
        * Counts included types (`pay`, `appl`, `axfer`, etc.).
        * Stores transactions that pass the filters temporarily if generating a CSV.
    * **Output Generation:**
        * **Count:** Redirects back to `index.php`, passing the calculated counts, block timestamps, and other summary data as GET parameters. Stores fatal errors in the PHP Session before redirecting.
        * **CSV:** Sets the `downloadStatus` cookie, sends appropriate HTTP headers (`Content-Type`, `Content-Disposition`), prints the UTF-8 BOM, writes the summary data rows, writes the header row, and then iterates through the filtered transactions, writing each transaction's details as a row using `fputcsv`.

## Troubleshooting Common Issues

* **Timeout Errors:** The script takes longer than the server's configured `max_execution_time`.
    * **Solution:** Analyze a smaller range of blocks. Try increasing `set_time_limit(300)` in `process.php` (if allowed), or modify the server's `php.ini`.
* **Memory Errors (`Allowed memory size exhausted`):** The number of transactions fetched exceeds PHP's `memory_limit`.
    * **Solution:** Analyze a smaller range of blocks. Try lowering `$api_limit` in `process.php` (e.g., to 250 or 100). Increase `memory_limit` in `php.ini` if you have server access. *Future Enhancement:* Modify CSV generation to stream directly without storing filtered results in memory first.
* **API Errors / Connection Reset / cURL Error 56:** Network problems between your server and the indexer, or the indexer node is overloaded/down/rate-limiting.
    * **Solution:** The script has basic retries. Wait and try again later. Check the status of the indexer provider (e.g., Nodely's Discord/website). Reduce `$api_limit`. Check your server's network connectivity (`ping`, `traceroute` to the indexer address).
* **Block Not Found Errors:** You entered a round number that doesn't exist or hasn't been indexed yet by the API provider.
    * **Solution:** Double-check your round numbers against a Voi block explorer.
* **CSV File Issues:** Errors appear at the top of the CSV, or characters look wrong.
    * **Solution:** Ensure `display_errors = Off` is set in your production `php.ini`. The script adds a UTF-8 BOM for Excel compatibility; ensure your spreadsheet software interprets UTF-8 correctly.
* **Loading Spinner Never Stops (CSV):** The cookie mechanism failed, or `process.php` encountered an error *before* setting the cookie or sending the file.
    * **Solution:** Check for errors returned via Session on the main page. Check browser developer console for JS errors. Check PHP error logs on the server. Increase the JS timeout if downloads are genuinely slow.

## Contributing

Contributions are welcome! Please feel free to fork the repository, make your changes in a separate branch, and submit a pull request. Describe your changes clearly.

## Future Enhancements / TODO

* Implement date range input with accurate date-to-round mapping (this is complex and may require different techniques like background jobs or external services).
* Develop a more robust progress indicator (e.g., using Server-Sent Events or polling a background job status).
* Add user options to select which transaction types to filter/include.
* Add configuration to easily switch between Voi Mainnet and Testnet endpoints.
* Implement filtering by Sender or Receiver address.
* Add PDF export option using a library like FPDF or TCPDF (would require Composer).
* Refactor CSV generation to stream directly during processing to drastically reduce memory usage for very large exports.
* Improve UI/UX, add more error handling feedback.
* Implement server-side logging for errors and requests.

## License

This project is licensed under the **GNU General Public License v3.0**.

See the [LICENSE](LICENSE) file for details (you would need to create a LICENSE file containing the GPLv3 text) or visit [https://www.gnu.org/licenses/gpl-3.0](https://www.gnu.org/licenses/gpl-3.0).

## Acknowledgements

* **Voi Network:** The blockchain platform.
* **Nodely.io:** Providing the public Voi Indexer API endpoint used in the default configuration.
