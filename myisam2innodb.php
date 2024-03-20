#!/usr/bin/php
<?php
        /*
        *  MyISAM Checker / Converter v1.3
        *  The goal of this script is to check/convert from MyISAM to InnoDB to prevent table-level locking.
        *
        *  ! If old wordpress tables exist, it will be considered a wordpress database, etc.
        *
        *  Jacob Murphy / Poxydoxy 2021
        *
        *  + Added support for limiting to single databases
        *  + Added support for summarising tables
        *  + Added support for wordpress limiting/filtering
        *  + Cleaned up argument menu for a cleaner usage guide
        *  + Added support for backups and delay
        *  - ToDo: Use PDO instead of MySQLi
        *  - ToDo: Use PHP for generating backups instead of MySqlDump
        *
        *  Adjustable settings below
        */

        $sql_server = "localhost";
        $username = "root";
        $password = "password";
        $email_address = "myemail@mydomain.com.au"; // Email to recieve report

        $databases_to_skip = array( // databases to skip, CASE SENSITIVE!
                "information_schema",
                "performance_schema",
                "sys",
                "mysql",
        );
        
        // Variables set by the --flags
        $run_script = false; // Normally only runs if --run is provided
        $convert_db = false; // Convert to InnoDB once MyISAM is found?
        $summary = false; // Limit result to one result per database?
        $only_bad = false; // Only show if MyISAM is found?
        $send_email = false; // Send an email once finished?
        $limit_single = ""; // Limit to a single database?
        $limit_not_wp = false; // Limit to only wordpress?
        $limit_only_wp = false; // Limit to not wordpress?
        $backup_database = false; // Backup the database?
        $delay_conversions = false; // Delay database conversions?

        $hide_skip_from_console = true;
        $hide_skip_from_email = true;

        // Non-Adjustable stuff below, do not adjust
        if(isset($argv)){
                foreach ($argv as $option_key => $option) {
                        switch (strtolower($option)) {
                                case '--run':
                                        # Run the script
                                        $run_script = true;
                                        break;
                                case '--fix':
                                        # We're going to convert to InnoDB, implies --run
                                        $convert_db = true;
                                        $run_script = true;
                                        break;
                                case '--single':
                                        # Limit to a single database
                                        if(!array_key_exists($option_key+1, $argv)){
                                                echo "Make sure to provide the database name when using --single.\n";
                                                echo "Exiting.\n";
                                                exit;
                                        }
                                        $limit_single = $argv[$option_key+1];
                                        if($limit_single == null){ $limit_single = ""; }
                                        break;
                                case '--summary':
                                        # Limit to one result result per database
                                        $summary = true;
                                        break;
                                case '--onlybad':
                                        # Only show MyISAM tables
                                        $only_bad = true;
                                        break;
                                case '--email':
                                        # Send the email at the end
                                        $send_email = true;
                                        break;
                                case '--notwordpress':
                                        # Limit to databases that are not wordpress
                                        if($limit_not_wp && $limit_only_wp){
                                                echo "You can't set --onlywordpress and --notwordpress together, Baka!\n";
                                                echo "Exiting.\n";
                                                exit;
                                        } else {
                                                $limit_not_wp = true;
                                        }
                                        break;
                                case '--onlywordpress':
                                        # Limit to databases that are wordpress
                                        if($limit_not_wp && $limit_only_wp){
                                                echo "You can't set --onlywordpress and --notwordpress together, Baka!\n";
                                                echo "Exiting.\n";
                                                exit;
                                        } else {
                                                $limit_only_wp = true;
                                        }
                                        break;
                                case '--backup':
                                        # Backup the database to local folder
                                        $backup_database = true;
                    break;
                case '--delay':
                                        # Adds a delay between table conversions
                                        $delay_conversions = true;
                    break;
                                default:
                                        if(count($argv) <= 1 && !$run_script){
                                                # Show arguments
                                                echo "         |>\n";
                                                echo "PoxyDoxy |> MyISAM to InnoDB Converter, Settings are found inside.\n";
                                                echo "         |>\n";
                                                echo "\n";
                                                echo "Arguments include:\n";
                                                echo "  1. Choose one Action (required)\n";
                                                echo "    --run            | run the script, does *not* convert/alter , default settings used if no arguments provided.\n";
                                                echo "    --fix            | implies --run, converts the tables from MyISAM to InnoDB.\n\n";
                                                echo "  2. Choose one database filter (optional)\n";
                                                echo "    none (default)   | By default, scans all databases.\n";
                                                echo "    --single db_name | only checks/converts the supplied database name.\n";
                                                echo "    --onlywordpress  | limit to databases that *are* wordpress.\n";
                                                echo "    --notwordpress   | limit to databases that are *not* wordpress.\n\n";
                                                echo "  3. Formatting of the output, choose any/all (optional)\n";
                                                echo "    --summary        | summarise each database in a single line.\n";
                                                echo "    --onlybad        | only show MyISAM tables.\n";
                                                echo "    --email          | sends an email report to the email inside this script.\n\n";
                                                echo "  4. Other, choose any/all (optional)\n";
                                                echo "    --backup         | backup the database before converting.\n";
                                                echo "    --delay          | adds a 5s delay between table conversions.\n";
                                                echo "\n";
                                        }
                                        break;
                        }
                }
        }

        if($run_script){
                // message(string, print?, sendmail?);
                $message_log = array(); // messages will be stored here for mailtime
                $myisam_found = 0;
                $myisam_converted = 0;
                $databases_checked = 0; // For end of scan summary stats
                $databases_with_myisam = 0; // For end of scan summary stats
                $errors_found = false; // Will trip if single issue is found
                $scan_start = date("d/m/Y h:ia");
                $scan_end = null;
                $wordpress_scan_style = ""; // Text for end of scan results if wordpress limiting
                $backup_folder_name = "myisam2innodb_backups"; // Folder name to be created for backups
                $delay_seconds = 5; // Delay to wait after each conversion
                $fulldir = __DIR__ . DIRECTORY_SEPARATOR . $backup_folder_name . DIRECTORY_SEPARATOR;

                // Connect to sql server
                $mysqli = new mysqli($sql_server, $username, $password);
                if (mysqli_connect_errno()) {
                    echo "Could not connect to mysql server.\n";
                    echo "This is likely a config error, please check username/password.\n";
                    exit;
                }

                // Count databases
                $database_sql = "SHOW DATABASES;";
                if($limit_single != ""){
                        $database_sql = "SHOW DATABASES LIKE '" .  mysqli_real_escape_string($mysqli, $limit_single) . "';";
                }

                $database_result = mysqli_query($mysqli, $database_sql);
                $database_count = mysqli_num_rows($database_result);
                $database_names = mysqli_fetch_all($database_result);

                if($database_count == 0){
                        if($limit_single != ""){
                                echo "Could not find database: " . $limit_single . "\n";
                                echo "Please check your database name and try again.\n";
                        } else {
                                echo "No databases found!\n";
                                echo "This is likely a config error, exiting.\n";
                        }
                        exit;
                }

                if($database_count >= 1){
                        message("PoxyDoxy |> MyISAM Check / Convert Script\n", true, false);
                        message("Scanning " . $database_count . ($database_count > 1 ? " databases" : " database") . "\n", true, false);
                        if($backup_database){
                                message("Backup Location: " . $fulldir . "\r\n\n", true, false);
                        }
                        // check one database at a time
                        foreach ($database_names as $database_key => $database) {
                                $database_name = $database[0];

                                $single_database_myisam_found = 0; // For per database --summary
                                $single_database_myisam_converted = 0; // For per database --summary

                                $msg = "";
                                if(in_array($database_name, $databases_to_skip)){
                                        message("Skipping: " . $database_name . "\r\n", !$hide_skip_from_console, !$hide_skip_from_email);
                                        continue;
                                } else {
                                        $msg .= "Checking: " . $database_name;
                                        $databases_checked++;
                                }

                                // Build list of tables to check
                                $tablesql = "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE  TABLE_SCHEMA = \"" . mysqli_real_escape_string($mysqli, $database_name) . "\"";
                                $tables = mysqli_query($mysqli, $tablesql);
                                $database_tables_count = mysqli_num_rows($tables);
                                $database_tables = mysqli_fetch_all($tables);

                                if($limit_only_wp){ // Wordpress Only?
                                        if(!wordpress_check($database_tables)){ continue; }
                                }

                                if($limit_not_wp){ // Not Wordpress?
                                        if(wordpress_check($database_tables)){ continue; }
                                }

                                message($msg . " (" . $database_tables_count . ($database_tables_count > 1 ? " tables" : " table") . ")\r\n", !$only_bad, !$only_bad);

                                if($delay_conversions){
                                        sleep($delay_seconds);
                                }

                                // Check each table under database
                                $backup_created = false;
                                $backup_failed = false;
                                foreach ($database_tables as $table_key => $table) {
                                        $table_name = $table[0];
                                        $table_engine = $table[1];

                                        if(strtolower($table_engine) == strtolower("MyISAM")){
                                                // table level locking is bad and you should feel bad
                                                $msg = "[!] MyISAM found: " . $database_name . "-> ". $table_name;
                                                $myisam_found++;
                                                $single_database_myisam_found++;

                                                if($backup_database && $backup_failed){
                                                        if(!$summary){
                                                                $msg .= " (backup failed)";
                                                                message($msg . "\r\n", true, true);
                                                        }
                                                        continue;
                                                }

                                                if($backup_database){
                                                        if(!$backup_created){
                                                                // Backup the database before converting
                                                                $fullfile = $fulldir . $database_name . "_" . date("d-m-Y_H-i") . ".sql";
                                                                if (!file_exists($fulldir)) {
                                                                        mkdir($fulldir, 0755, true);
                                                                }
                                                                if (!file_exists($fulldir)) {
                                                                        $backup_failed = true;
                                                                        message("Error: Could not create backup folder." . "\r\n", true, true);
                                                                        continue;
                                                                }
                                                                $backup_result = null;
                                                                $backup_return_value = null;
                                                                exec("mysqldump -u$username -p'$password' $database_name > $fullfile", $backup_result, $backup_return_value);
                                                                //echo "mysqldump -u$username -p'$password' $database_name > $fullfile";
                                                                if($backup_return_value != 0){
                                                                        // Backup failed, skipping database conversion.
                                                                        $backup_failed = true;
                                                                } else {
                                                                        $backup_created = true;
                                                                }
                                                        }
                                                }

                                                if($convert_db){
                                                        if(!$backup_database | !$backup_failed){ // If we're not backing up the database or the backup didn't fail
                                                                // convert from MyISAM to InnoDB
                                                                $altersql = "ALTER TABLE `" . mysqli_real_escape_string($mysqli, $database_name) . "`.`". mysqli_real_escape_string($mysqli, $table_name) . "` ENGINE=InnoDB;";
                                                                $altered = mysqli_query($mysqli, $altersql);

                                                                if($altered == 1){
                                                                        $myisam_converted++;
                                                                        $single_database_myisam_converted++;
                                                                        $msg .= " (converted to InnoDB)";
                                                                } else {
                                                                        $errors_found = true;
                                                                        $msg .= " (convert to InnoDB FAILED!!!)";
                                                                }
                                                        }
                                                }

                                                if(!$summary){ // Print each found result if not limiting to one result per database
                                                        message($msg . "\r\n", true, true);
                                                } else {
                                                        if($errors_found){ // Error found, add this individual result regardless of --summary
                                                                message($msg . "\r\n", true, true);
                                                        }
                                                }
                                        }
                                } // finsh checking tables

                                if($single_database_myisam_found >= 1){ $databases_with_myisam ++; } // Counts databases with at least 1 MyISAM found

                                if($summary){ // Build summary if we limit to one summary result per database
                                        if($single_database_myisam_found > 0){ // bad folder found for this database
                                                $msg = "[!] MyISAM found for " . $database_name . " (found " . $single_database_myisam_found;
                                                if($backup_failed){
                                                        $msg .= ", skipped because backup failed";
                                                } else {
                                                        if($convert_db){
                                                                $msg .= ", converted " . $single_database_myisam_converted;
                                                        }
                                                }

                                                $msg .= ")";
                                                message($msg . "\r\n", true, true);
                                        }
                                }
                        }
                        $scan_end = date("d/m/Y h:ia");

                        // End of scan summary
                        message("\n", true, true);
                        $wp_string_insert = "";
                        if($limit_only_wp){ $wp_string_insert = " wordpress"; message("Results limited to Wordpress databases only!\n\n", true, true); }
                        if($limit_not_wp){ $wp_string_insert = " non-wordpress"; message("Results limited to Non-Wordpress databases only!\n\n", true, true); }
                        message("Finished scanning " . $databases_checked . ($databases_checked > 1 ? " databases" : " database") . ".\n", true, true);
                        message($databases_with_myisam  . $wp_string_insert . ($databases_with_myisam != 1 ? " databases" : " database") . " (" . round((($databases_with_myisam / $databases_checked) * 100), 2) . "%)" . " contained MyISAM.\n", true, true);
                        message($myisam_found  . $wp_string_insert . " MyISAM tables found.\n", true, true);
                        message($myisam_converted  . $wp_string_insert . " MyISAM tables converted.\n", true, true);
                        message("Scan Start: " . $scan_start . "\n", true, true);
                        message("Scan Finish: " . $scan_end . "\n", true, true);

                        // Send email once all databases have been scanned
                        if($send_email){
                                if($only_bad && ($myisam_found == 0)){ return; } // no need for email if nothing found
                                // newlines act strange with ( and [!] so using HTML encoding with <br> for newlines
                                $datetime = date("d/m/Y");
                                $message = "PoxyDoxy |> MyISAM Check / Convert Script\n";
                                $message .= "Results for " . $datetime . "\n\n";
                                if($backup_database){
                                        $message .= "Backup Location: " . $fulldir . "\n";
                                }
                                foreach ($message_log as $mkey => $message_value) {
                                        $message .= trim($message_value) . "\n";
                                }
                                $message = nl2br($message);

                                $headers = "MIME-Version: 1.0" . "\r\n";
                                $headers .= "Content-Type: text/html;charset=utf-8";
                                mail($email_address, 'MyISAM Check / Convert Script - Report ' . $datetime, $message, $headers);
                        }
                } else {
                        message("ERROR: Could not find any databases.\nStopping scan.\r\n", true, true);
                }
        }

        function message($line, $required_print, $required_email){
                global $message_log;
                global $only_bad;
                if($required_email){
                        // Only store if required for mail later
                        array_push($message_log, $line);
                }
                if($required_print){
                        // Only echo line if required, or --onlybad is not set
                        echo $line;
                }
        }

        function wordpress_check($database_tables){
            // table names that might indicate wordpress
            $wordpress_indicators = array(
                    "_comments",
                    "_commentmeta",
                    "_posts",
                    "_postmeta",
                    "_users",
            );
            $wordpress_indicator_count = 0;
            foreach ($database_tables as $table_key => $table){
                    $table_name = $table[0];
                    foreach ($wordpress_indicators as $wp_key => $wp_name){
                            if(strpos($table_name, $wp_name) !== false){
                                    $wordpress_indicator_count++;
                            }
                    }
            }
            return $wordpress_indicator_count >= 5;
        }

?>