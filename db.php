<?php

// HyperDB
// This file should be installed at ABSPATH/wp-content/db.php

/** Load the wpdb class while preventing instantiation **/
$wpdb = true;
require_once( ABSPATH . WPINC . '/wp-db.php' );

if ( defined('DB_CONFIG_FILE') && file_exists( DB_CONFIG_FILE ) ) {

	/** The config file was defined earlier. **/

} elseif ( file_exists( ABSPATH . 'db-config.php') ) {

	/** The config file resides in ABSPATH. **/
	define( 'DB_CONFIG_FILE', ABSPATH . 'db-config.php' );

} elseif ( file_exists( dirname(ABSPATH) . '/db-config.php' ) && ! file_exists( dirname(ABSPATH) . '/wp-settings.php' ) ) {

	/** The config file resides one level above ABSPATH but is not part of another install. **/
	define( 'DB_CONFIG_FILE', dirname(ABSPATH) . '/db-config.php' );

} else {

	/** Lacking a config file, revert to the standard database class. **/
	$wpdb = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	return;

}

class hyperdb extends wpdb {
	/**
	 * The last table that was queried
	 * @var string
	 */
	var $last_table;

	/**
	 * After any SQL_CALC_FOUND_ROWS query, the query "SELECT FOUND_ROWS()"
	 * is sent and the mysql result resource stored here. The next query
	 * for FOUND_ROWS() will retrieve this. We do this to prevent any
	 * intervening queries from making FOUND_ROWS() inaccessible. You may
	 * prevent this by adding "NO_SELECT_FOUND_ROWS" in a comment.
	 * @var resource
	 */
	var $last_found_rows_result;

	/**
	 * Whether to store queries in an array. Useful for debugging and profiling.
	 * @var bool
	 */
	var $save_queries = false;

	/**
	 * The current mysql link resource
	 * @var resource
	 */
	var $dbh;

	/**
	 * Associative array (dbhname => dbh) for established mysql connections
	 * @var array
	 */
	var $dbhs;

	/**
	 * The multi-dimensional array of datasets and servers
	 * @var array
	 */
	var $hyper_servers = array();

	/**
	 * Optional directory of tables and their datasets
	 * @var array
	 */
	var $hyper_tables = array();

	/**
	 * Optional directory of callbacks to determine datasets from queries
	 * @var array
	 */
	var $hyper_callbacks = array();

	/**
	 * Whether to use mysql_pconnect instead of mysql_connect
	 * @var bool
	 */
	var $persistent = false;

	/**
	 * The maximum number of db links to keep open. The least-recently used
	 * link will be closed when the number of links exceeds this.
	 * @var int
	 */
	var $max_connections = 10;

	/**
	 * Whether to check with fsockopen prior to mysql_connect.
	 * @var bool
	 */
	var $check_tcp_responsiveness = true;

	/**
	 * Send Reads To Masters. This disables slave connections while true.
	 * @var bool
	 */
	var $srtm = false;

	/**
	 * The log of db connections made and the time each one took
	 * @var array
	 */
	var $db_connections;

	/**
	 * The list of unclosed connections sorted by LRU
	 */
	var $open_connections = array();

	/**
	 * The host of the current dbh
	 * @var string
	 */
	var $current_host;

	/**
	 * Lookup array (dbhname => host:port)
	 * @var array
	 */
	var $dbh2host = array();

	/**
	 * The last server used and the database name selected
	 * @var array
	 */
	var $last_used_server;

	/**
	 * Lookup array (dbhname => (server, db name) ) for re-selecting the db
	 * when a link is re-used.
	 * @var array
	 */
	var $used_servers = array();

	/**
	 * Lookup array (dbhname => true) indicating that new links to dbhname
	 * should be sent to the master
	 * @var array
	 */
	var $written_servers = array();

	/**
	 * Triggers __construct() for backwards compatibility with PHP4
	 */
	function db( $args = null ) {
		return $this->__construct($args);
	}

	/**
	 * Gets ready to make database connections
	 * @param array db class vars
	 */
	function __construct( $args = null ) {
		if ( is_array($args) )
			foreach ( get_class_vars(__CLASS__) as $var => $value )
				if ( isset($args[$var]) )
					$this->$var = $args[$var];
	}

	/**
	 * Add the connection parameters for a database
	 */
	function add_database( $db ) {
		extract($db, EXTR_SKIP);
		isset($dataset) or $dataset = 'global';
		isset($read)    or $read = 1;
		isset($write)   or $write = 1;
		unset($db['dataset'], $db['read'], $db['write']);
		$read and $this->hyper_servers[ $dataset ][ 'read' ][ $read ][] = $db;
		$write and $this->hyper_servers[ $dataset ][ 'write' ][ $write ][] = $db;
	}

	/**
	 * Specify the dateset where a table is found
	 */
	function add_table( $dataset, $table ) {
		$this->hyper_tables[ $table ] = $dataset;
	}

	/**
	 * Add a callback to examine queries and determine dataset.
	 */
	function add_callback( $callback ) {
		$this->hyper_callbacks[] = $callback;
	}

	/**
	 * Find the first table name referenced in a query
	 * @param string query
	 * @return string table
	 */
	function get_table_from_query ( $q ) {
		// Remove characters that can legally trail the table name
		$q = rtrim($q, ';/-#');
		// allow (select...) union [...] style queries. Use the first queries table name.
		$q = ltrim($q, "\t ("); 

		// Quickly match most common queries
		if ( preg_match('/^\s*(?:'
				. 'SELECT.*?\s+FROM'
				. '|INSERT(?:\s+IGNORE)?(?:\s+INTO)?'
				. '|REPLACE(?:\s+INTO)?'
				. '|UPDATE(?:\s+IGNORE)?'
				. '|DELETE(?:\s+IGNORE)?(?:\s+FROM)?'
				. ')\s+`?(\w+)`?/is', $q, $maybe) )
			return $maybe[1];

		// Refer to the previous query
		if ( preg_match('/^\s*SELECT.*?\s+FOUND_ROWS\(\)/is', $q) )
			return $this->last_table;

		// SHOW TABLE STATUS LIKE and SHOW TABLE STATUS WHERE Name = 
		if ( preg_match('/^\s*'
				. 'SHOW\s+TABLE\s+STATUS.+(?:LIKE\s+|WHERE\s+Name\s*=\s*)'
				. '\W(\w+)\W/is', $q, $maybe) )
			return $maybe[1];

		// Big pattern for the rest of the table-related queries in MySQL 5.0
		if ( preg_match('/^\s*(?:'
				. '(?:EXPLAIN\s+(?:EXTENDED\s+)?)?SELECT.*?\s+FROM'
				. '|INSERT(?:\s+LOW_PRIORITY|\s+DELAYED|\s+HIGH_PRIORITY)?(?:\s+IGNORE)?(?:\s+INTO)?'
				. '|REPLACE(?:\s+LOW_PRIORITY|\s+DELAYED)?(?:\s+INTO)?'
				. '|UPDATE(?:\s+LOW_PRIORITY)?(?:\s+IGNORE)?'
				. '|DELETE(?:\s+LOW_PRIORITY|\s+QUICK|\s+IGNORE)*(?:\s+FROM)?'
				. '|DESCRIBE|DESC|EXPLAIN|HANDLER'
				. '|(?:LOCK|UNLOCK)\s+TABLE(?:S)?'
				. '|(?:RENAME|OPTIMIZE|BACKUP|RESTORE|CHECK|CHECKSUM|ANALYZE|OPTIMIZE|REPAIR).*\s+TABLE'
				. '|TRUNCATE(?:\s+TABLE)?'
				. '|CREATE(?:\s+TEMPORARY)?\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?'
				. '|ALTER(?:\s+IGNORE)?\s+TABLE'
				. '|DROP\s+TABLE(?:\s+IF\s+EXISTS)?'
				. '|CREATE(?:\s+\w+)?\s+INDEX.*\s+ON'
				. '|DROP\s+INDEX.*\s+ON'
				. '|LOAD\s+DATA.*INFILE.*INTO\s+TABLE'
				. '|(?:GRANT|REVOKE).*ON\s+TABLE'
				. '|SHOW\s+(?:.*FROM|.*TABLE)'
				. ')\s+`?(\w+)`?/is', $q, $maybe) )
			return $maybe[1];
	}

	/**
	 * Determine the likelihood that this query could alter anything
	 * @param string query
	 * @return bool
	 */
	function is_write_query( $q ) {
		// Quick and dirty: only SELECT statements are considered read-only.
		$q = ltrim($q, "\t (");
		$word = strtoupper( substr( trim( $q ), 0, 6 ) );
		return 'SELECT' != $word;
	}

	/**
	 * Set a flag to prevent reading from slaves which might be lagging after a write
	 */
	function send_reads_to_masters() {
		$this->srtm = true;
	}

	/**
	 * Callbacks are specified in the config. They must return a dataset
	 * or an associative array with an element called 'dataset'.
	 */
	function run_callbacks( $query ) {
		$args = array($query, &$this);
		foreach ( $this->hyper_callbacks as $func ) {
			$result = call_user_func_array($func, $args);
			if ( isset($result) )
				return $result;
		}
	}

	/**
	 * Figure out which database server should handle the query, and connect to it.
	 * @param string query
	 * @return resource mysql database connection
	 */
	function &db_connect( $query = '' ) {
		$connect_function = $this->persistent ? 'mysql_pconnect' : 'mysql_connect';
		if ( empty( $this->hyper_servers ) ) {
			if ( is_resource( $this->dbh ) )
				return $this->dbh;
			if (
				!defined('DB_HOST')
				|| !defined('DB_USER')
				|| !defined('DB_PASSWORD')
				|| !defined('DB_NAME') )
				return $this->bail("We were unable to query because there was no database defined.");
			$this->dbh = @ $connect_function(DB_HOST, DB_USER, DB_PASSWORD, true);
			if ( ! is_resource( $this->dbh ) )
				return $this->bail("We were unable to connect to the database. (DB_HOST)");
			if ( ! mysql_select_db(DB_NAME, $this->dbh) )
				return $this->bail("We were unable to select the database.");
			if ( ! empty( $this->charset ) ) {
				$collation_query = "SET NAMES '$this->charset'";
				if ( !empty( $this->collate ) )
					$collation_query .= " COLLATE '$this->collate'";
				mysql_query($collation_query, $this->dbh);
			}
			return $this->dbh;
		}

		if ( empty( $query ) )
			return false;

		$this->last_table = $this->table = $this->get_table_from_query($query);

		if ( isset($this->hyper_tables[$this->table]) ) {
			$dataset = $this->hyper_tables[$this->table];
		} elseif ( null !== $result = $this->run_callbacks($query) ) {
			if ( is_array($result) )
				extract( $result, EXTR_OVERWRITE );
			else
				$dataset = $result;
		}

		if ( !isset($dataset) )
			$dataset = 'global';

		if ( !$dataset )
			return $this->bail("Unable to determine which dataset to query. ($table)");

		if ( isset($write) )
			$this->write = $write;
		else
			$this->write = $this->is_write_query( $query );

		// Avoid reading a slave after writing a master.
		if ( $this->srtm || $this->write || array_key_exists("{$dataset}__w", $this->written_servers) ) {
			$this->write = true;
			$read_dbh = $dataset . '__r';
			$dbhname = $dataset . '__w';
			$operation = 'write';
		} else {
			$dbhname = $dataset . '__r';
			$operation = 'read';
		}

		if ( isset( $this->dbhs[$dbhname] ) && is_resource($this->dbhs[$dbhname]) ) { // We're already connected!
			// Keep this connection at the top of the stack to prevent disconnecting frequently-used connections
			if ( $k = array_search($dbhname, $this->open_connections) ) {
				unset($this->open_connections[$k]);
				$this->open_connections[] = $dbhname;
			}

			// Using an existing connection, select the db we need and if that fails, disconnect and connect anew.
			if ( ( isset($server['name']) && mysql_select_db($server['name'], $this->dbhs[$dbhname]) ) ||
					( isset($this->used_servers[$dbhname]['db']) && mysql_select_db($this->used_servers[$dbhname]['db'], $this->dbhs[$dbhname]) ) ) {
				$this->last_used_server = $this->used_servers[$dbhname];
				$this->current_host = $this->dbh2host[$dbhname];
				return $this->dbhs[$dbhname];
			} else {
				$this->disconnect($dbhname);
			}
		}

		if ( $this->write && defined( "MASTER_DB_DEAD" ) ) {
			return $this->bail("We're updating the database, please try back in 5 minutes. If you are posting to your blog please hit the refresh button on your browser in a few minutes to post the data again. It will be posted as soon as the database is back online again.");
		}

		if ( empty($this->hyper_servers[$dataset][$operation]) )
			return $this->bail("No databases available to $operation $table ($dataset)");

		// Put the groups in order by priority
		ksort($this->hyper_servers[$dataset][$operation]);

		// Make a list of at least three eligible servers, repeating as necessary.
		$servers = array();
		do {
			foreach ( $this->hyper_servers[$dataset][$operation] as $group => $items ) {
				$keys = array_keys($items);
				shuffle($keys);
				foreach ( $keys as $key ) {
					$servers[] = compact('group', 'key');
				}
			}
		} while ( count($servers) > 0 && count($servers) < 3 );

		if ( empty($servers) )
			return $this->bail("No database servers were found to match the query. ($table, $dataset)");

		// at the following index # we have no choice but to connect
		$max_server_index = count($servers) - 1;

		// Connect to a database server
		foreach ( $servers as $group_key ) {
			extract($group_key, EXTR_OVERWRITE);

			// $host, $user, $password, $name, and possibly others
			extract($this->hyper_servers[$dataset][$operation][$group][$key], EXTR_OVERWRITE);

			// Overlay $server if it was extracted from a callback
			if ( isset($server) && is_array($server) )
				extract($server, EXTR_OVERWRITE);

			$this->timer_start();

			// make sure there's always a port #
			if ( !isset($port) )
				list($host, $port) = explode(':', $host);
			if ( empty($port) )
				$port = 3306;

			// Use a default timeout of 200ms.
			if ( !isset($timeout) )
				$timeout = 0.2;

			// connect if necessary or possible
			if ( $this->write || $i == $max_server_index ||
					( !$this->check_tcp_responsiveness ||
					true === $tcp = $this->check_tcp_responsiveness($host, $port, $timeout) ) ) {
				$this->dbhs[$dbhname] = @ $connect_function( "$host:$port", $user, $password );
			} else {
				$this->dbhs[$dbhname] = false;
			}

			if ( is_resource($this->dbhs[$dbhname])
					&& mysql_select_db( $name, $this->dbhs[$dbhname] ) ) {
				++$servers[$i]['connects'];
				$this->db_connections[] = array( "$user@$host:$port", number_format( ( $this->timer_stop() ), 7) );
				$this->dbh2host[$dbhname] = $this->current_host = "$host:$port";
				$this->open_connections[] = $dbhname;
				break;
			} else {
				$error_details = array (
					'referrer' => "{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}",
'server'=>$server,
					'host' => $host,
					'error' => mysql_error(),
					'errno' => mysql_errno(),
					'tcp_responsive' => $tcp === true ? 'true' : $tcp,
				);
				$msg = date( "Y-m-d H:i:s" ) . " Can't select $dbhname - ";
				$msg .= "\n" . print_r($error_details, true);

				$this->print_error( $msg );
			}
		}

		if ( ! is_resource( $this->dbhs[$dbhname] ) )
			return $this->bail("Unable to connect to $host:$port to $operation table '$table' ($dataset)");

		if ( !empty($charset) )
			$collation_query = "SET NAMES '$charset'";
		elseif ( !empty($this->charset) )
			$collation_query = "SET NAMES '$this->charset'";
		if ( !empty($collation_query) && !empty($collate) )
			$collation_query .= " COLLATE '$collate'";
		if ( !empty($collation_query) && !empty($this->collation) )
			$collation_query .= " COLLATE '$this->collation'";
		mysql_query($collation_query, $this->dbhs[$dbhname]);

		$this->last_used_server = array( "server" => $host, "db" => $name );

		$this->used_servers[$dbhname] = $this->last_used_server;

		// Close current and prevent future read-only connections to the written cluster
		if ( $write ) {
			if ( isset($db_clusters[$clustername]['read']) )
				unset( $db_clusters[$clustername]['read'] );

			if ( is_resource($this->dbhs[$read_dbh]) && $this->dbhs[$read_dbh] != $this->dbhs[$dbhname] )
				$this->disconnect( $read_dbh );

			$this->dbhs[$read_dbh] = & $this->dbhs[$dbhname];

			$this->written_servers[$dbhname] = true;
		}

		while ( !$this->persistent && count($this->open_connections) > $this->max_connections ) {
			$oldest_connection = array_shift($this->open_connections);
			if ( $this->dbhs[$oldest_connection] != $this->dbhs[$dbhname] )
				$this->disconnect($oldest_connection);
		}

		return $this->dbhs[$dbhname];
	}

	/**
	 * Disconnect and remove connection from open connections list
	 * @param string $dbhname
	 */
	function disconnect($dbhname) {
		if ( $k = array_search($dbhname, $this->open_connections) )
			unset($this->open_connections[$k]);

		if ( is_resource($this->dbhs[$dbhname]) )
			mysql_close($this->dbhs[$dbhname]);

		unset($this->dbhs[$dbhname]);
	}

	/**
	 * Kill cached query results
	 */
	function flush() {
		$this->last_error = '';
		$this->last_table = '';
		$this->num_rows = 0;
		parent::flush();
	}

	/**
	 * Basic query. See docs for more details.
	 * @param string $query
	 * @return int number of rows
	 */
	function query($query) {
		// some queries are made before the plugins have been loaded, and thus cannot be filtered with this method
		if ( function_exists('apply_filters') )
			$query = apply_filters('query', $query);

		// initialise return
		$return_val = 0;
		$this->flush();

		// Log how the function was called
		$this->func_call = "\$db->query(\"$query\")";

		// Keep track of the last query for debug..
		$this->last_query = $query;

		if ( $this->save_queries )
			$this->timer_start();

		if ( preg_match('/^\s*SELECT\s+FOUND_ROWS(\s*)/i', $query) && is_resource($this->last_found_rows_result) ) {
			$this->result = $this->last_found_rows_result;
		} else {
			$this->dbh = $this->db_connect( $query );

			if ( ! is_resource($this->dbh) )
				return false;

			$this->result = mysql_query($query, $this->dbh);
			++$this->num_queries;

			if ( preg_match('/^\s*SELECT\s+SQL_CALC_FOUND_ROWS\s/i', $query) ) {
				if ( false === strpos($query, "NO_SELECT_FOUND_ROWS") ) {
					$this->last_found_rows_result = mysql_query("SELECT FOUND_ROWS()", $this->dbh);
					++$this->num_queries;
					if ( $this->save_queries )
						$this->queries[] = array( $query, $this->timer_stop(), $this->get_caller() );
				}
			} else {
				$this->last_found_rows_result = null;
			}
		}

		if ( $this->save_queries )
			$this->queries[] = array( $query, $this->timer_stop(), $this->get_caller() );

		// If there is an error then take note of it
		if ( $this->last_error = mysql_error($this->dbh) ) {
			$this->print_error($this->last_error);
			return false;
		}

		if ( preg_match("/^\\s*(insert|delete|update|replace|alter) /i",$query) ) {
			$this->rows_affected = mysql_affected_rows($this->dbh);

			// Take note of the insert_id
			if ( preg_match("/^\\s*(insert|replace) /i",$query) ) {
				$this->insert_id = mysql_insert_id($this->dbh);
			}
			// Return number of rows affected
			$return_val = $this->rows_affected;
		} else {
			$i = 0;
			$this->col_info = array();
			while ($i < @mysql_num_fields($this->result)) {
				$this->col_info[$i] = @mysql_fetch_field($this->result);
				$i++;
			}
			$num_rows = 0;
			$this->last_result = array();
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
	 * Whether or not MySQL database is at least the required minimum version.
	 * The additional argument allows the caller to check a specific database.
	 *
	 * @since 2.5.0
	 * @uses $wp_version
	 *
	 * @return WP_Error
	 */
	function check_database_version( $dbh_or_table = false ) {
		global $wp_version;
		// Make sure the server has MySQL 4.1.2
		$mysql_version = preg_replace( '|[^0-9\.]|', '', $this->db_version( $dbh_or_table ) );
		if ( version_compare($mysql_version, '4.1.2', '<') )
			return new WP_Error( 'database_version', sprintf(__('<strong>ERROR</strong>: WordPress %s requires MySQL 4.1.2 or higher'), $wp_version) );
	}

	/**
	 * This function is called when WordPress is generating the table schema to determine wether or not the current database
	 * supports or needs the collation statements.
	 * The additional argument allows the caller to check a specific database.
	 * @return bool
	 */
	function supports_collation( $dbh_or_table = false ) {
		return $this->has_cap( 'collation', $dbh_or_table );
	}

	/**
	 * Generic function to determine if a database supports a particular feature
	 * The additional argument allows the caller to check a specific database.
	 * @param string $db_cap the feature
	 * @param false|string|resource $dbh_or_table the databaese (the current database, the database housing the specified table, or the database of the mysql resource)
	 * @return bool
	 */
	function has_cap( $db_cap, $dbh_or_table = false ) {
		$version = $this->db_version( $dbh_or_table );

		switch ( strtolower( $db_cap ) ) :
		case 'collation' :
		case 'group_concat' :
		case 'subqueries' :
			return version_compare($version, '4.1', '>=');
			break;
		endswitch;

		return false;
	}

	/**
	 * The database version number
	 * @param false|string|resource $dbh_or_table the databaese (the current database, the database housing the specified table, or the database of the mysql resource)
	 * @return false|string false on failure, version number on success
	 */
	function db_version( $dbh_or_table = false ) {
		if ( !$dbh_or_table && $this->dbh )
			$dbh =& $this->dbh;
		elseif ( is_resource( $dbh_or_table ) )
			$dbh =& $dbh_or_table;
		else
			$dbh = $this->db_connect( "SELECT FROM $dbh_or_table $this->users" );

		if ( $dbh )
			return preg_replace('/[^0-9.].*/', '', mysql_get_server_info( $dbh ));
		return false;
	}

	/**
	 * Get the name of the function that called wpdb.
	 * @return string the name of the calling function
	 */
	function get_caller() {
		// requires PHP 4.3+
		if ( !is_callable('debug_backtrace') )
			return '';

		$bt = debug_backtrace();
		$caller = '';

		foreach ( (array) $bt as $trace ) {
			if ( isset($trace['class']) && is_a( $this, $trace['class'] ) )
				continue;
			elseif ( !isset($trace['function']) )
				continue;
			elseif ( strtolower($trace['function']) == 'call_user_func_array' )
				continue;
			elseif ( strtolower($trace['function']) == 'apply_filters' )
				continue;
			elseif ( strtolower($trace['function']) == 'do_action' )
				continue;

			if ( isset($trace['class']) )
				$caller = $trace['class'] . '::' . $trace['function'];
			else
				$caller = $trace['function'];
			break;
		}
		return $caller;
	}

	/**
	 * Check the responsiveness of a tcp/ip daemon
	 * @return (bool) true when $host:$post responds within $float_timeout seconds, else (bool) false
	 */
	function check_tcp_responsiveness($host, $port, $float_timeout) {
		if ( 1 == 2 && function_exists('apc_store') ) {
			$use_apc = true;
			$apc_key = "{$host}{$port}";
			$apc_ttl = 10;
		} else {
			$use_apc = false;
		}
		if ( $use_apc ) {
			$cached_value=apc_fetch($apc_key);
			switch ( $cached_value ) {
				case 'up':
					$this->tcp_responsive = 'true';
					return true;
				case 'down':
					$this->tcp_responsive = 'false';
					return false;
			}
		}
	        $socket = @ fsockopen($host, $port, $errno, $errstr, $float_timeout);
	        if ( $socket === false ) {
			if ( $use_apc )
				apc_store($apc_key, 'down', $apc_ttl);
			return "[ > $float_timeout ] ($errno) '$errstr'";
		}
		fclose($socket);
		if ( $use_apc )
			apc_store($apc_key, 'up', $apc_ttl);
	        return true;
	}

	// Helper functions for configuration
	
} // class hyperdb

$wpdb = new hyperdb();

require( DB_CONFIG_FILE );

?>
