<?php

define('OBJECT', 'OBJECT', true);
define('ARRAY_A', 'ARRAY_A', false);
define('ARRAY_N', 'ARRAY_N', false);

if (!defined('SAVEQUERIES'))
	define('SAVEQUERIES', true);

class wpdb {
	var $remote_cushion = 10;
	var $max_connections = 10;
	var $srtm = false;
	var $show_errors = true;
	var $num_queries = 0;
	var $last_query;
	var $last_table;
	var $col_info;
	var $queries;
	var $connection_array;
	var $current_host;

	// Our tables
	var $posts;
	var $users;
	var $categories;
	var $post2cat;
	var $comments;
	var $links;
	var $link2cat;
	var $options;
	var $postmeta;

	var $charset;
	var $collate;

	/**
	 * Connects to the database server and selects a database
	 * @param string $dbuser
	 * @param string $dbpassword
	 * @param string $dbname
	 * @param string $dbhost
	 */
	function wpdb($dbuser, $dbpassword, $dbname, $dbhost) {
		return $this->__construct($dbuser, $dbpassword, $dbname, $dbhost);
	}

	function __construct($dbuser, $dbpassword, $dbname, $dbhost) {
		register_shutdown_function(array(&$this, "__destruct"));

		if( defined( "WP_USE_MULTIPLE_DB" ) && CONSTANT( "WP_USE_MULTIPLE_DB" ) == true )
			return true;

		if ( defined('DB_CHARSET') )
			$this->charset = DB_CHARSET;

		if ( defined('DB_COLLATE') )
			$this->collate = DB_COLLATE;

		$this->dbh = @mysql_connect($dbhost, $dbuser, $dbpassword);
		if (!$this->dbh) {
			error_log( date( "Y-m-d H:i:s" ) . " Can't connect " . $dbhost." - $dbuser - $dbpassword\n\n", 3, "/tmp/mysql.txt" );
		}

		if ( !empty($this->charset) && version_compare(mysql_get_server_info(), '4.1.0', '>=') )
 			$this->query("SET NAMES '$this->charset'");

		$this->select($dbname, $this->dbh);
	}

	function __destruct() {
		return true;	
	}

	function select($db, &$dbh) {
		if (!@mysql_select_db($db, $dbh)) {
			//error_log( date( "Y-m-d H:i:s" ) . " Can't select $db - ".mysql_error( $dbh )."\n\n", 3, "/tmp/mysql.txt" );
			$this->handle_error_connecting( $dbh, array( "db" => $db ) );
		}
	}

	/**
	 * Escapes content for insertion into the database, for security
	 *
	 * @param string $string
	 * @return string query safe string
	 */
	function escape($string) {
		return addslashes( $string ); // Disable rest for now, causing problems
		if( !$this->dbh || version_compare( phpversion(), '4.3.0' ) == '-1' )
			return mysql_escape_string( $string );
		else
			return mysql_real_escape_string( $string, $this->dbh );
	}

	// ==================================================================
	//	Print SQL/DB error.

	function print_error($str = '') {
		global $EZSQL_ERROR;
		if (!$str) $str = mysql_error();
		$EZSQL_ERROR[] = array ('query' => $this->last_query, 'error_str' => $str);
		$this->last_error = $str;

		// Is error output turned on or not..
		if ( $this->show_errors ) {
			// If there is an error then take note of it
			$msg = "Mysql Error on http://" . $_SERVER[ 'HTTP_HOST' ] . $_SERVER[ 'REQUEST_URI' ] . "!!!!Error: [" . $str . "]!!!!SQL: " . $this->last_query . "!!!!Request Data: ";
			error_log( date( "Y-m-d H:i:s" ) . " " . $msg."\n\n", 3, "/tmp/mysql.txt" );
		} else {
			return false;
		}
	}

	// ==================================================================
	//	Turn error handling on or off..

	function show_errors() {
		$this->show_errors = true;
	}

	function hide_errors() {
		$this->show_errors = false;
	}

	// ==================================================================
	//	Kill cached query results

	function flush() {
		$this->last_result = array();
		$this->col_info = null;
		$this->last_query = null;
	}

	function get_table_from_query ( $q ) {
		If( substr( $q, -1 ) == ';' )
			$q = substr( $q, 0, -1 );
		if ( preg_match('/^\s*SELECT.*?\s+FROM\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return $maybe[1];
		if ( preg_match('/^\s*UPDATE IGNORE\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return $maybe[1];
		if ( preg_match('/^\s*UPDATE\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return $maybe[1];
		if ( preg_match('/^\s*INSERT INTO\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return $maybe[1];
		if ( preg_match('/^\s*REPLACE INTO\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return $maybe[1];
		if ( preg_match('/^\s*INSERT IGNORE INTO\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return $maybe[1];
		if ( preg_match('/^\s*REPLACE INTO\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return $maybe[1];
		if ( preg_match('/^\s*DELETE\s+FROM\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return $maybe[1];
		if ( preg_match('/^\s*(?:TRUNCATE|RENAME|OPTIMIZE|LOCK|UNLOCK)\s+TABLE\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return $maybe[1];
		if ( preg_match('/^SHOW TABLE STATUS (LIKE|FROM) \'?`?(\w+)\'?`?\s*/is', $q, $maybe) )
			return $maybe[1];
		if ( preg_match('/^SHOW INDEX FROM `?(\w+)`?\s*/is', $q, $maybe) )
			return $maybe[1];
		if ( preg_match('/^\s*CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return $maybe[1];
		if ( preg_match('/^\s*SHOW CREATE TABLE `?(\w+?)`?\s*/is', $q, $maybe) )
			return $maybe[1];
		if ( preg_match('/^SHOW CREATE TABLE (wp_[a-z0-9_]+)/is', $q, $maybe) )
			return $maybe[1];
		if ( preg_match('/^\s*CREATE\s+TABLE\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return $maybe[1];
		if ( preg_match('/^\s*DROP\s+TABLE\s+IF\s+EXISTS\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return $maybe[1];
		if ( preg_match('/^\s*DROP\s+TABLE\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return $maybe[1];
		if ( preg_match('/^\s*DESCRIBE\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return $maybe[1];
		if ( preg_match('/^\s*ALTER\s+TABLE\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return $maybe[1];
		if ( preg_match('/^\s*SELECT.*?\s+FOUND_ROWS\(\)/is', $q) )
			return $this->last_table;

		return '';
	}
	
	function is_write_query( $q ) {
		If( substr( $q, -1 ) == ';' )
			$q = substr( $q, 0, -1 );
		if ( preg_match('/^\s*SELECT.*?\s+FROM\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return false;
		if ( preg_match('/^\s*UPDATE\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return true;
		if ( preg_match('/^\s*INSERT INTO\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return true;
		if ( preg_match('/^\s*REPLACE INTO\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return true;
		if ( preg_match('/^\s*INSERT IGNORE INTO\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return true;
		if ( preg_match('/^\s*DELETE\s+FROM\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return true;
		if ( preg_match('/^\s*OPTIMIZE\s+TABLE\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return true;
		if ( preg_match('/^SHOW TABLE STATUS (LIKE|FROM) \'?`?(\w+)\'?`?\s*/is', $q, $maybe) )
			return true;
		if ( preg_match('/^\s*CREATE\s+TABLE\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return true;
		if ( preg_match('/^\s*SHOW CREATE TABLE `?(\w+?)`?.*/is', $q, $maybe) )
			return true;
		if ( preg_match('/^\s*DROP\s+TABLE\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return true;
		if ( preg_match('/^\s*DROP\s+TABLE\s+IF\s+EXISTS\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return true;
		if ( preg_match('/^\s*ALTER\s+TABLE\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return $maybe[1];
		if ( preg_match('/^\s*DESCRIBE\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return $maybe[1];
		if ( preg_match('/^\s*SHOW\s+INDEX\s+FROM\s+`?(\w+)`?\s*/is', $q, $maybe) )
			return $maybe[1];
		if ( preg_match('/^\s*SELECT.*?\s+FOUND_ROWS\(\)/is', $q) )
			return false;
		if ( preg_match('/^\s*RENAME\s+TABLE\s+/i', $q) )
			return true;
		if ( preg_match('/^\s*TRUNCATE\s|TABLE\s+/i', $q) )
			return true;
		return true;
	}

	function send_reads_to_masters() {
		$this->srtm = true;
	}

	function get_ds_part_from_table($table) {
		global $wpmuBaseTablePrefix, $db_servers;

		if ( substr( $table, 0, strlen( $wpmuBaseTablePrefix ) ) != $wpmuBaseTablePrefix ) {
			return false;
		} else if ( preg_match('/^' . $wpmuBaseTablePrefix . 'ds_([a-z0-9]+)_([0-9]+)_/', $table, $matches) ) {
		// e.g. wp_ds_{$dataset}_{$partition}_stuff
			$dataset = $matches[1];
			$partition = $hash = $matches[2];
		} else if ( preg_match('/^' . $wpmuBaseTablePrefix . '([a-z0-9]+)_([0-9a-f]+)_/', $table, $matches) ) {
		// e.g. wp_{$dataset}_{$padhexmod}_stuff
			$dataset = $matches[1];
			$hash = $matches[2];
			$tableno = hexdec($hash);
			$partitions = count($db_servers[$dataset]);
			$partition = ( $tableno % $partitions ) + 1;
		} else {
			return false;
		}

		return compact('dataset', 'hash', 'partition');
	}

	function &db_connect( $query = '' ) {
		global $db_servers, $db_tables, $current_connection;

		if ( empty( $query ) )
			return false;

		$write = $this->srtm || $this->is_write_query( $query );
		$table = $this->get_table_from_query( $query );
		$this->last_table = $table;
		$partition = 0;

		if ( $ds_part = $this->get_ds_part_from_table($table) ) {
			extract( $ds_part, EXTR_OVERWRITE );
			$dbhname = "dbh_{$dataset}_{$partition}";
			$_server['name'] = "{$dataset}_$hash";
		} else if ( array_key_exists($table, $db_tables) ) {
			$dataset = $db_tables[$table];
			$dbhname = "dbh_$dataset";
		} else {
			$dataset = 'global';
			$dbhname = "dbh_global";
		}

		// Send reads to the master for a few seconds after a write to user db or global db.
		// This ought to help with accidental post regressions and other replication issues.
		if ( $write && $blog_id )
			$this->srtm = true;
		else if ( $write && ( $dataset == 'global' ) && ( $this->blogid > 1 ) )
			$this->srtm = true;
		else if ( $this->srtm )
			$write = true;

		if ( $write ) {
			$read_dbh = $dbhname . '_r';
			$dbhname .= '_w';
			$operation = 'write';
		} else {
			$dbhname .= '_r';
			$operation = 'read';
		}

		$current_connection = "$dbhname";

		if ( isset( $this->$dbhname ) && is_resource($this->$dbhname) ) { // We're already connected!
			// Keep this connection at the top of the stack to prevent disconnecting frequently-used connections
			if ( $k = array_search($dbhname, $this->open_connections) ) {
				unset($this->open_connections[$k]);
				$this->open_connections[] = $dbhname;
			}

			// Using an existing connection where there may be multiple dbs, we should select the one we need here.
			if ( isset($_server['name']) )
				$this->select($_server['name'], $this->$dbhname);

			$this->current_host = $this->dbh2host[$dbhname];

			return $this->$dbhname;
		}

		if ( $write && defined( 'MASTER_DB_DEAD' ) ) { 
			die("We're updating the database, please try back in 5 minutes. If you are posting to your blog please hit the refresh button on your browser in a few minutes to post the data again. It will be posted as soon as the database is back online again.");
		}

		// Group eligible servers by R (plus 10,000 if remote)
		$server_groups = array();
		foreach ( $db_servers[$dataset][$partition] as $server ) {
			// $o = $server['read'] or $server['write']. If false, don't use this server.
			if ( !($o = $server[$operation]) )
				continue;

			if ( $server['dc'] != DATACENTER )
				$o += 10000;

			if ( is_array($_server) )
				$server = array_merge($server, $_server);

			// Try the local hostname first when connecting within the DC
			if ( $server['dc'] == DATACENTER ) {
				$lserver = $server;
				$lserver['host'] = $lserver['lhost'];
				$server_groups[$o - 0.5][] = $lserver;
			}

			$server_groups[$o][] = $server;
		}

		// Randomize each group and add its members to 
		$servers = array();
		ksort($server_groups);
		foreach ( $server_groups as $group ) {
			if ( count($group) > 1 )
				shuffle($group);
			$servers = array_merge($servers, $group);
		}

		// Connect to a database server
		foreach ( $servers as $server ) {
			$this->timer_start();

			$host = $server['host'];

			$this->$dbhname = @mysql_connect( $host, $server['user'], $server['password'] );

			$this->connections[] = "{$server['user']}@$host";

			if ( $this->$dbhname && is_resource($this->$dbhname) )  {
				$current_connection .= " connected to $host in " . number_format( ( $this->timer_stop() * 1000 ), 2) . 'ms';
				$this->connection_array[] = array( $host, number_format( ( $this->timer_stop() ), 7) );
				$this->current_host = $host;
				$this->open_connections[] = $dbhname;
				$this->dbh2host[$dbhname] = $host;
				break;
			}
		} // end foreach ( $servers as $server )

		if ( $this->$dbhname == false || !is_resource($this->$dbhname) )
			$this->handle_error_connecting( $dbhname, array( 'server' => $server, 'error' => mysql_error() ) );

		$this->select( $server['name'], $this->$dbhname );

		$this->last_used_server = array( "server" => $server['host'], "db" => $server['name'] );

		// Close current and prevent future read-only connections to the written cluster
		if ( $write ) {
			if ( isset($db_clusters[$clustername]['read']) )
				unset( $db_clusters[$clustername]['read'] );

			if ( is_resource($this->$read_dbh) && $this->$read_dbh != $this->$dbhname )
				$this->disconnect( $read_dbh );

			$this->$read_dbh = & $this->$dbhname;
		}

		while ( count($this->open_connections) > $this->max_connections )
			$this->disconnect(array_shift($this->open_connections));

		return $this->$dbhname;
	}

	function disconnect($dbhname) {
		if ( $k = array_search($dbhname, $this->open_connections) )
			unset($this->open_connections[$k]);

		if ( is_resource($this->$dbhname) )
			mysql_close($this->$dbhname);
	}

	// ==================================================================
	//	Basic Query	- see docs for more detail

	function query($query) {
		global $current_connection;
		// filter the query, if filters are available
		// NOTE: some queries are made before the plugins have been loaded, and thus cannot be filtered with this method
		if ( function_exists('apply_filters') )
			$query = apply_filters('query', $query);

		// initialise return
		$return_val = 0;
		$this->flush();
		$this->last_error = '';

		// Log how the function was called
		$this->func_call = "\$db->query(\"$query\")";

		// Keep track of the last query for debug..
		$this->last_query = $query;

		$dbh =& $this->db_connect( $query );

		$this->timer_start();

		$this->result = @mysql_query($query, $dbh);
		++$this->num_queries;
		if( defined( 'DO_BACKTRACE' ) )
			$debug = debug_backtrace();
		else
			$debug = false;
		$this->queries[] = array( $query . " <br /> connection: $current_connection", $this->timer_stop(), mysql_affected_rows($dbh), $this->current_host, microtime(), $debug );

		// If there is an error then take note of it..
		if( $dbh ) {
			if ( mysql_error( $dbh ) ) {
				$this->print_error( mysql_error( $dbh ));
				return false;
			}
		}

		if ( preg_match("/^\\s*(insert|delete|update|replace) /i",$query) ) {
			$this->rows_affected = mysql_affected_rows($dbh);
			// Take note of the insert_id
			if ( preg_match("/^\\s*(insert|replace) /i",$query) ) {
				$this->insert_id = mysql_insert_id($dbh);
			}
			// Return number of rows affected
			$return_val = $this->rows_affected;
		} else {
			$i = 0;
			while ($i < @mysql_num_fields($this->result)) {
				$this->col_info[$i] = @mysql_fetch_field($this->result);
				$i++;
			}
			$num_rows = 0;
			while ( $row = @mysql_fetch_object($this->result) ) {
				$this->last_result[$num_rows] = $row;
				$num_rows++;
			}

			@mysql_free_result($this->result);

			// Log number of rows the query returned
			$this->num_rows = $num_rows;

			// Return number of rows selected
			$return_val = $this->num_rows;
		}

		return $return_val;
	}

	/**
	 * Get one variable from the database
	 * @param string $query (can be null as well, for caching, see codex)
	 * @param int $x = 0 row num to return
	 * @param int $y = 0 col num to return
	 * @return mixed results
	 */
	function get_var($query=null, $x = 0, $y = 0) {
		$this->func_call = "\$db->get_var(\"$query\",$x,$y)";
		if ( $query )
			$this->query($query);

		// Extract var out of cached results based x,y vals
		if ( $this->last_result[$y] ) {
			$values = array_values(get_object_vars($this->last_result[$y]));
		}

		// If there is a value return it else return null
		return (isset($values[$x]) && $values[$x]!=='') ? $values[$x] : null;
	}

	/**
	 * Get one row from the database
	 * @param string $query
	 * @param string $output ARRAY_A | ARRAY_N | OBJECT
	 * @param int $y row num to return
	 * @return mixed results
	 */
	function get_row($query = null, $output = OBJECT, $y = 0) {
		$this->func_call = "\$db->get_row(\"$query\",$output,$y)";
		if ( $query )
			$this->query($query);
	
		if ( $output == OBJECT ) {
			return $this->last_result[$y] ? $this->last_result[$y] : null;
		} elseif ( $output == ARRAY_A ) {
			return $this->last_result[$y] ? get_object_vars($this->last_result[$y]) : null;
		} elseif ( $output == ARRAY_N ) {
			return $this->last_result[$y] ? array_values(get_object_vars($this->last_result[$y])) : null;
		} else {
			$this->print_error(" \$db->get_row(string query, output type, int offset) -- Output type must be one of: OBJECT, ARRAY_A, ARRAY_N");
		}
	}

	/**
	 * Gets one column from the database
	 * @param string $query (can be null as well, for caching, see codex)
	 * @param int $x col num to return
	 * @return array results
	 */
	function get_col($query = null , $x = 0) {
		if ( $query )
			$this->query($query);

		// Extract the column values
		for ( $i=0; $i < count($this->last_result); $i++ ) {
			$new_array[$i] = $this->get_var(null, $x, $i);
		}
		return $new_array;
	}

	/**
	 * Return an entire result set from the database
	 * @param string $query (can also be null to pull from the cache)
	 * @param string $output ARRAY_A | ARRAY_N | OBJECT
	 * @return mixed results
	 */
	function get_results($query = null, $output = OBJECT) {
		$this->func_call = "\$db->get_results(\"$query\", $output)";

		if ( $query )
			$this->query($query);

		// Send back array of objects. Each row is an object
		if ( $output == OBJECT ) {
			return $this->last_result;
		} elseif ( $output == ARRAY_A || $output == ARRAY_N ) {
			if ( $this->last_result ) {
				$i = 0;
				foreach( $this->last_result as $row ) {
					$new_array[$i] = (array) $row;
					if ( $output == ARRAY_N ) {
						$new_array[$i] = array_values($new_array[$i]);
					}
					$i++;
				}
				return $new_array;
			} else {
				return null;
			}
		}
	}

	/**
	 * Grabs column metadata from the last query
	 * @param string $info_type one of name, table, def, max_length, not_null, primary_key, multiple_key, unique_key, numeric, blob, type, unsigned, zerofill
	 * @param int $col_offset 0: col name. 1: which table the col's in. 2: col's max length. 3: if the col is numeric. 4: col's type
	 * @return mixed results
	 */
	function get_col_info($info_type = 'name', $col_offset = -1) {
		if ( $this->col_info ) {
			if ( $col_offset == -1 ) {
				$i = 0;
				foreach($this->col_info as $col ) {
					$new_array[$i] = $col->{$info_type};
					$i++;
				}
				return $new_array;
			} else {
				return $this->col_info[$col_offset]->{$info_type};
			}
		}
	}

	/**
	 * Starts the timer, for debugging purposes
	 */
	function timer_start() {
		$mtime = microtime();
		$mtime = explode(' ', $mtime);
		$this->time_start = $mtime[1] + $mtime[0];
		return true;
	}

	/**
	 * Stops the debugging timer
	 * @return int total time spent on the query, in milliseconds
	 */
	function timer_stop($precision = 3) {
		$mtime = microtime();
		$mtime = explode(' ', $mtime);
		$time_end = $mtime[1] + $mtime[0];
		$time_total = $time_end - $this->time_start;
		return $time_total;
	}

	/**
	 * Wraps fatal errors in a nice header and footer and dies.
	 * @param string $message
	 */
	function bail($message) { // Just wraps errors in a nice header and footer
		if ( !$this->show_errors )
			return false;
	header( 'Content-Type: text/html; charset=utf-8');
	echo <<<HEAD
	<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
	<html xmlns="http://www.w3.org/1999/xhtml">
	<head>
		<title>WordPress &rsaquo; Error</title>
		<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
		<style media="screen" type="text/css">
		<!--
		html {
			background: #eee;
		}
		body {
			background: #fff;
			color: #000;
			font-family: Georgia, "Times New Roman", Times, serif;
			margin-left: 25%;
			margin-right: 25%;
			padding: .2em 2em;
		}

		h1 {
			color: #006;
			font-size: 18px;
			font-weight: lighter;
		}

		h2 {
			font-size: 16px;
		}

		p, li, dt {
			line-height: 140%;
			padding-bottom: 2px;
		}

		ul, ol {
			padding: 5px 5px 5px 20px;
		}
		#logo {
			margin-bottom: 2em;
		}
		-->
		</style>
	</head>
	<body>
	<h1 id="logo"><img alt="WordPress" src="http://static.wordpress.org/logo.png" /></h1>
HEAD;
	echo $message;
	echo "</body></html>";
	die();
	}

	function handle_error_connecting( $dbhname, $details ) {
		define( "MYSQLCONNECTIONERROR", true );
		$msg = date( "Y-m-d H:i:s" ) . " Can't select $dbhname - ";
		$msg .= "\n" . print_r($details, true);

		error_log( "$msg\n\n", 3, "/tmp/db-connect.txt" );
	}

}

if ( ! isset($wpdb) )
	$wpdb = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
?>