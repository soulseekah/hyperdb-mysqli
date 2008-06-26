<?php

define('OBJECT', 'OBJECT', true);
define('OBJECT_K', 'OBJECT_K', false);
define('ARRAY_K', 'ARRAY_K', false);
define('ARRAY_A', 'ARRAY_A', false);
define('ARRAY_N', 'ARRAY_N', false);

if (!defined('SAVEQUERIES'))
	define('SAVEQUERIES', false);

if ( !class_exists('db') ) :
class db {
	/**
	 * Should errors be shown in output?
	 * @var bool
	 */
	var $show_errors = true;

	/**
	 * Should errors not be logged at all?
	 * @var bool
	 */
	var $suppress_errors = false;

	/**
	 * The most recent error caused by a query
	 * @var string
	 */
	var $last_error;

	/**
	 * The number of queries made
	 * @var int
	 */
	var $num_queries = 0;

	/**
	 * The last query that was made
	 * @var string
	 */
	var $last_query;

	/**
	 * After any SQL_CALC_FOUND_ROWS query, the query "SELECT FOUND_ROWS()"
	 * is sent and the mysql result resource stored here. The next query
	 * for FOUND_ROWS() will retrieve this. We do this to prevent any
	 * intervening queries from making FOUND_ROWS() inaccessible.
	 * @var resource
	 */
	var $last_found_rows_result;

	/**
	 * The results of mysql_fetch_field() on the last query result
	 * @var array
	 */
	var $col_info;
	
	/**
	 * The query log
	 * @var array
	 */
	var $queries = array();

	/**
	 * Whether to use the query log
	 * @var bool
	 */
	var $save_queries = false;

	/**
	 * The character set applied by a "SET NAMES" query after connecting
	 * @var string
	 */
	var $charset;

	/**
	 * The collation to go with the character set
	 */
	var $collate;

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
	 * If true, skip all the multi-db stuff
	 * @var bool
	 */
	var $single_db = false;

	/**
	 * The connection info for a single db
	 * @var array
	 */
	var $db_server = array();

	/**
	 * The multi-dimensional array of datasets, partitions, and servers
	 * @var array
	 */
	var $db_servers = array();

	/**
	 * Optional directory of tables and their datasets
	 * @var array
	 */
	var $db_tables = array();

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
	function db($args = array()) {
		return $this->__construct($args);
	}

	/**
	 * Gets ready to make database connections
	 * @param array db class vars
	 */
	function __construct($args = null ) {
		if ( is_array($args) )
			foreach ( get_class_vars(__CLASS__) as $var => $value )
				if ( isset($args[$var]) )
					$this->$var = $args[$var];
		if ( ! $this->single_db ) {
			if ( empty($this->db_servers) && isset($GLOBALS['db_servers']) && is_array($GLOBALS['db_servers']) )
				$this->db_servers =& $GLOBALS['db_servers'];
			if ( empty($this->db_tables) && isset($GLOBALS['db_tables']) && is_array($GLOBALS['db_tables']) )
				$this->db_tables =& $GLOBALS['db_tables'];
		}
		if ( empty($this->db_servers) ) {
			if ( empty($this->db_server) )
				$this->bail("No database servers have been set up.");
			else
				$this->single_db = true;
		}
	}

	/**
	 * Escapes content for insertion into the database, for security
	 * @param string $string
	 * @return string query safe string
	 */
	function escape($string) {
		return addslashes( $string );
	}

	/**
	 * Escapes content by reference for insertion into the database, for security
	 * @param string $s
	 */
	function escape_by_ref(&$s) {
		$s = $this->escape($s);
	}

	/**
	 * Prepares a SQL query for safe use, using sprintf() syntax
	 */
	function prepare($args=NULL) {
		if ( NULL === $args )
			return;
		$args = func_get_args();
		$query = array_shift($args);
		$query = str_replace("'%s'", '%s', $query); // in case someone mistakenly already singlequoted it
		$query = str_replace('"%s"', '%s', $query); // doublequote unquoting
		$query = str_replace('%s', "'%s'", $query); // quote the strings
		array_walk($args, array(&$this, 'escape_by_ref'));
		return vsprintf($query, $args);
	}

	/**
	 * Print SQL/DB error
	 * @param string $str Error string
	 */
	function print_error($str = '') {
		if ( empty($str) )
			$str = $this->last_error;

		if ( $this->suppress_errors )
			return false;

		$error_str = "WordPress database error $str for query $this->last_query";

		if ( $caller = $this->get_caller() )
			$error_str .= " made by $caller";

		$log_file = ini_get('error_log');
		if ( !empty($log_file) && ('syslog' != $log_file) && !is_writable($log_file) )
			error_log($error_str, 0);

		// Is error output turned on or not
		if ( !$this->show_errors )
			return false;

		$str = htmlspecialchars($str, ENT_QUOTES);
		$query = htmlspecialchars($this->last_query, ENT_QUOTES);

		// If there is an error then take note of it
		print "<div id='error'>
		<p class='dberror'><strong>Database error:</strong> [$str]<br />
		<code>$query</code></p>
		</div>";
	}

	/**
	 * Turn error output on or off
	 * @param bool $show
	 * @return bool previous setting
	 */
	function show_errors( $show = true ) {
		$errors = $this->show_errors;
		$this->show_errors = $show;
		return $errors;
	}

	/**
	 * Turn error output off
	 * @return bool previous setting of show_errors
	 */
	function hide_errors() {
		return $this->show_errors(false);
	}

	/**
	 * Turn error logging on or off
	 * @param bool $suppress
	 * @return bool previous setting
	 */
	function suppress_errors( $suppress = true ) {
		$errors = $this->suppress_errors;
		$this->suppress_errors = $suppress;
		return $errors;
	}

	/**
	 * Find the first table name referenced in a query
	 * @param string query
	 * @return string table
	 */
	function get_table_from_query ( $q ) {
		// Remove characters that can legally trail the table name
		rtrim($q, ';/-#');

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
				. '|ALTER(?:\s+IGNORE)?'
				. '|DROP\s+TABLE(?:\s+IF\s+EXISTS)?'
				. '|CREATE(?:\s+\w+)?\s+INDEX.*\s+ON'
				. '|DROP\s+INDEX.*\s+ON'
				. '|LOAD\s+DATA.*INFILE.*INTO\s+TABLE'
				. '|(?:GRANT|REVOKE).*ON\s+TABLE)'
				. 'SHOW\s+(?:.*FROM|.*TABLE|'
				. ')\s+`?(\w+)`?/is', $q, $maybe) )
			return $maybe[1];

		// All unmatched queries automatically fall to the global master
		return '';
	}

	/**
	 * Determine the likelihood that this query could alter anything
	 * @param string query
	 * @return bool
	 */
	function is_write_query( $q ) {
		// Quick and dirty: only send SELECT statements to slaves
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
	 * Get the dataset and partition from the table name. E.g.:
	 * wp_ds_{$dataset}_{$partition}_tablename where $partition is ctype_digit
	 * wp_{$dataset}_{$hash}_tablename where $hash is 1-3 chars of ctype_xdigit
	 * @param unknown_type $table
	 * @return unknown
	 */
	function get_ds_part_from_table($table) {
		if ( substr( $table, 0, strlen( $this->prefix ) ) != $this->prefix ) {
			return false;
		} else if ( preg_match('/^' . $this->prefix . 'ds_([a-z0-9]+)_([0-9]+)_/', $table, $matches) ) {
		// e.g. wp_ds_{$dataset}_{$partition}_stuff
			$dataset = $matches[1];
			$partition = $hash = $matches[2];
		} else if ( preg_match('/^' . $this->prefix . '([a-z0-9]+)_([0-9a-f]{1,3})_/', $table, $matches) ) {
		// e.g. wp_{$dataset}_{$padhexmod}_stuff
			$dataset = $matches[1];
			$hash = $matches[2];
			$tableno = hexdec($hash);
			$partitions = count($this->db_servers[$dataset]);
			$partition = ( $tableno % $partitions ) + 1;
		} else {
			return false;
		}

		return compact('dataset', 'hash', 'partition');
	}

	/**
	 * Figure out which database server should handle the query, and connect to it.
	 * @param string query
	 * @return resource mysql database connection
	 */
	function &db_connect( $query = '' ) {
		$connect_function = $this->persistent ? 'mysql_pconnect' : 'mysql_connect';
		if ( $this->single_db ) {
			if ( is_resource( $this->dbh ) )
				return $this->dbh;
			$this->dbh = $connect_function($this->db_server['host'], $this->db_server['user'], $this->db_server['password'], true);
			if ( ! is_resource( $this->dbh ) )
				$this->bail("We were unable to connect to the database at {$this->db_server['host']}.");
			if ( ! mysql_select_db($this->db_server['name'], $this->dbh) )
				$this->bail("We were unable to select the database.");
			if ( !empty( $this->charset ) ) {
				$collation_query = "SET NAMES '$this->charset'";
				if ( !empty( $this->collate ) )
					$collation_query .= " COLLATE '$this->collate'";
				mysql_query($collation_query, $this->dbh);
			}
			return $this->dbh;
		} else {
			if ( empty( $query ) )
				return false;

			$write = $this->is_write_query( $query );
			$table = $this->get_table_from_query( $query );
			$this->last_table = $table;
			$partition = 0;

			if ( is_array($this->db_tables) && array_key_exists($table, $this->db_tables) ) {
				$dataset = $this->db_tables[$table];
				$dbhname = $dataset;
			} else if ( $ds_part = $this->get_ds_part_from_table($table) ) {
				extract( $ds_part, EXTR_OVERWRITE );
				$dbhname = "{$dataset}_{$partition}";
				$_server['name'] = "{$dataset}_$hash";
			} else {
				$dbhname = $dataset = 'global';
			}

			if ( $this->srtm || $write || array_key_exists("{$dbhname}_w", $this->written_servers) ) {
				$read_dbh = $dbhname . '_r';
				$dbhname .= '_w';
				$operation = 'write';
			} else {
				$dbhname .= '_r';
				$operation = 'read';
			}

			if ( isset( $this->dbhs[$dbhname] ) && is_resource($this->dbhs[$dbhname]) ) { // We're already connected!
				// Keep this connection at the top of the stack to prevent disconnecting frequently-used connections
				if ( $k = array_search($dbhname, $this->open_connections) ) {
					unset($this->open_connections[$k]);
					$this->open_connections[] = $dbhname;
				}

				// Using an existing connection, select the db we need and if that fails, disconnect and connect anew.
				if ( ( isset($_server['name']) && mysql_select_db($_server['name'], $this->dbhs[$dbhname]) ) ||
						( isset($this->used_servers[$dbhname]['db']) && mysql_select_db($this->used_servers[$dbhname]['db'], $this->dbhs[$dbhname]) ) ) {
					$this->last_used_server = $this->used_servers[$dbhname];
					$this->current_host = $this->dbh2host[$dbhname];
					return $this->dbhs[$dbhname];
				} else {
					$this->disconnect($dbhname);
				}
			}

			if ( $write && defined( "MASTER_DB_DEAD" ) ) {
				$this->bail("We're updating the database, please try back in 5 minutes. If you are posting to your blog please hit the refresh button on your browser in a few minutes to post the data again. It will be posted as soon as the database is back online again.");
			}

			// Group eligible servers by R (plus 10,000 if remote)
			$server_groups = array();
			foreach ( $this->db_servers[$dataset][$partition] as $server ) {
				// $o = $server['read'] or $server['write']. If false, don't use this server.
				if ( !($o = $server[$operation]) )
					continue;

				if ( $server['dc'] != DATACENTER )
					$o += 10000;

				if ( isset($_server) && is_array($_server) )
					$server = array_merge($server, $_server);

				// Try the local hostname first when connecting within the DC
				if ( $server['dc'] == DATACENTER && isset($server['lhost']) ) {
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

			// at the following index # we have no choice but to connect
			$max_server_index = count($servers) - 1;

			// Connect to a database server
			foreach ( $servers as $server_index => $server ) {
				$this->timer_start();

				// make sure there's always a port #
				list($host, $port) = explode(':', $server['host']);
				if ( empty($port) )
					$port = 3306;

				// reduce the timeout if the host is on the lan
				$mctime = 0.2; // Default
				if ( strtolower(substr($host, -3)) == 'lan' )
					$mctime = 0.05;

				// connect if necessary or possible
				if ( $write || $server_index == $max_server_index || $this->check_tcp_responsiveness($host, $port, $mctime) ) {
					$this->dbhs[$dbhname] = false;
					$try_count = 0;
					while ( $this->dbhs[$dbhname] === false ) {
						$try_count++;
						$this->dbhs[$dbhname] = $connect_function( "$host:$port", $server['user'], $server['password'] );
						if ( $try_count == 4 ) {
							break;
						} else {
							if ( $this->dbhs[$dbhname] === false )
								// Possibility of waiting up to 3 seconds!
								usleep( (500000 * $try_count) );
						}
					}
				} else {
					$this->dbhs[$dbhname] = false;
				}

				if ( $this->dbhs[$dbhname] && is_resource($this->dbhs[$dbhname]) ) {
					$this->db_connections[] = array( "{$server['user']}@$host:$port", number_format( ( $this->timer_stop() ), 7) );
					$this->dbh2host[$dbhname] = $this->current_host = "$host:$port";
					$this->open_connections[] = $dbhname;
					break;
				} else {
					$error_details = array (
						'referrer' => "{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}",
						'host' => $host,
						'error' => mysql_error(),
						'errno' => mysql_errno(),
						'tcp_responsive' => $this->tcp_responsive,
					);
					$msg = date( "Y-m-d H:i:s" ) . " Can't select $dbhname - ";
					$msg .= "\n" . print_r($error_details, true);

					$this->print_error( $msg );
				}
			} // end foreach ( $servers as $server )

			if ( ! is_resource( $this->dbhs[$dbhname] ) )
				return $this->bail("Unable to connect to $host:$port while querying table '$table' ($dbhname)");
			if ( ! mysql_select_db( $server['name'], $this->dbhs[$dbhname] ) )
				return $this->bail("Connected to $host:$port but unable to select database '{$server['name']}' while querying table '$table' ($dbhname)");

			if ( !empty($server['charset']) )
				$collation_query = "SET NAMES '{$server['charset']}'";
			elseif ( !empty($this->charset) )
				$collation_query = "SET NAMES '$this->charset'";
			if ( !empty($collation_query) && !empty($server['collate']) )
				$collation_query .= " COLLATE '{$server['collate']}'";
			if ( !empty($collation_query) && !empty($this->collation) )
				$collation_query .= " COLLATE '$this->collation'";
			mysql_query($collation_query, $this->dbhs[$dbhname]);

			$this->last_used_server = array( "server" => $server['host'], "db" => $server['name'] );

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

			while ( count($this->open_connections) > $this->max_connections ) {
				$oldest_connection = array_shift($this->open_connections);
				if ( $this->dbhs[$oldest_connection] != $this->dbhs[$dbhname] )
					$this->disconnect($oldest_connection);
			}
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
		$this->last_result = array();
		$this->col_info = null;
		$this->last_query = null;
		$this->last_error = '';
		$this->num_rows = 0;
	}

	/**
	 * Basic query. See docs for more details.
	 * @param string $query
	 * @return int number of rows
	 */
	function query($query) {
		// filter the query, if filters are available
		// NOTE: some queries are made before the plugins have been loaded, and thus cannot be filtered with this method
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
				$this->last_found_rows_result = mysql_query("SELECT FOUND_ROWS()", $this->dbh);
				++$this->num_queries;
			}
		}

		if ( $this->save_queries )
			$this->queries[] = array( $query, $this->timer_stop(), $this->get_caller() );

		// If there is an error then take note of it
		if ( $this->last_error = mysql_error($this->dbh) ) {
			$this->print_error($this->last_error);
			return false;
		}

		if ( is_resource($this->result) ) {
			$i = 0;
			while ($i < mysql_num_fields($this->result)) {
				$this->col_info[$i] = mysql_fetch_field($this->result);
				$i++;
			}
			$num_rows = 0;
			while ( $row = mysql_fetch_object($this->result) ) {
				$this->last_result[$num_rows] = $row;
				$num_rows++;
			}

			mysql_free_result($this->result);

			// Log number of rows the query returned
			$this->num_rows = $num_rows;

			// Return number of rows selected
			$return_val = $this->num_rows;
		} else {
			$this->rows_affected = mysql_affected_rows($this->dbh);
			// Take note of the insert_id
			if ( preg_match("/^\\s*(insert|replace) /i",$query) ) {
				$this->insert_id = mysql_insert_id($this->dbh);
			}
			// Return number of rows affected
			$return_val = $this->rows_affected;
		}

		return $return_val;
	}

	/**
	 * Insert an array of data into a table
	 * @param string $table WARNING: not sanitized!
	 * @param array $data should not already be SQL-escaped
	 * @return mixed results of $this->query()
	 */
	function insert($table, $data) {
		$data = add_magic_quotes($data);
		$fields = array_keys($data);
		return $this->query("INSERT INTO $table (`" . implode('`,`',$fields) . "`) VALUES ('".implode("','",$data)."')");
	}

	/**
	 * Update a row in the table with an array of data
	 * @param string $table WARNING: not sanitized!
	 * @param array $data should not already be SQL-escaped
	 * @param array $where a named array of WHERE column => value relationships.  Multiple member pairs will be joined with ANDs.  WARNING: the column names are not currently sanitized!
	 * @return mixed results of $this->query()
	 */
	function update($table, $data, $where){
		$data = add_magic_quotes($data);
		$bits = $wheres = array();
		foreach ( array_keys($data) as $k )
			$bits[] = "`$k` = '$data[$k]'";

		if ( is_array( $where ) )
			foreach ( $where as $c => $v )
				$wheres[] = "$c = '" . $this->escape( $v ) . "'";
		else
			return false;
		return $this->query( "UPDATE $table SET " . implode( ', ', $bits ) . ' WHERE ' . implode( ' AND ', $wheres ) . ' LIMIT 1' );
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
		if ( !empty( $this->last_result[$y] ) ) {
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
		else
			return null;

		if ( !isset($this->last_result[$y]) )
			return null;

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

		$new_array = array();
		// Extract the column values
		for ( $i=0; $i < count($this->last_result); $i++ ) {
			$new_array[$i] = $this->get_var(null, $x, $i);
		}
		return $new_array;
	}

	/**
	 * Return an entire result set from the database
	 * @param string $query (can also be null to pull from the cache)
	 * @param string $output ARRAY_A | ARRAY_N | OBJECT_K | OBJECT
	 * @return mixed results
	 */
	function get_results($query = null, $output = OBJECT) {
		$this->func_call = "\$db->get_results(\"$query\", $output)";

		if ( $query )
			$this->query($query);
		else
			return null;

		if ( $output == OBJECT ) {
			// Return an integer-keyed array of row objects
			return $this->last_result;
		} elseif ( $output == OBJECT_K || $output == ARRAY_K ) {
			// Return an array of row objects with keys from column 1
			// (Duplicates are discarded)
			$key = $this->col_info[0]->name;
			foreach ( $this->last_result as $row )
				if ( !isset( $new_array[ $row->$key ] ) )
					$new_array[ $row->$key ] = $row;
			if ( $output == ARRAY_K )
				return array_map('get_object_vars', $new_array);
			return $new_array;
		} elseif ( $output == ARRAY_A || $output == ARRAY_N ) {
			// Return an integer-keyed array of...
			if ( $this->last_result ) {
				$i = 0;
				foreach( $this->last_result as $row ) {
					if ( $output == ARRAY_N ) {
						// ...integer-keyed row arrays
						$new_array[$i] = array_values( get_object_vars( $row ) );
					} else {
						// ...column name-keyed row arrays
						$new_array[$i] = get_object_vars( $row );
					}
					++$i;
				}
				return $new_array;
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
	function timer_stop() {
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
		if ( !$this->show_errors ) {
			if ( class_exists('WP_Error') )
				$this->error = new WP_Error('500', $message);
			else
				$this->error = $message;
			return false;
		}
		wp_die($message);
	}

	/**
	 * Checks wether of not the database version is high enough to support the features WordPress uses
	 * @global $wp_version
	 */
	function check_database_version()
	{
		global $wp_version;
		// Make sure the server has MySQL 4.0
		$mysql_version = preg_replace('|[^0-9\.]|', '', mysql_get_server_info($this->dbh));
		if ( version_compare($mysql_version, '4.0.0', '<') )
			return new WP_Error('database_version',sprintf(__('<strong>ERROR</strong>: WordPress %s requires MySQL 4.0.0 or higher'), $wp_version));
	}

	/**
	 * This function is called when WordPress is generating the table schema to determine wether or not the current database
	 * supports or needs the collation statements.
	 */
	function supports_collation()
	{
		return ( version_compare(mysql_get_server_info($this->dbh), '4.1.0', '>=') );
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

		foreach ( $bt as $trace ) {
			if ( isset($trace['class']) && $trace['class'] == __CLASS__ )
				continue;
			elseif ( !isset($trace['function']) )
				continue;
			elseif ( strtolower($trace['function']) == 'call_user_func_array' )
				continue;
			elseif ( strtolower($trace['function']) == 'apply_filters' )
				continue;
			elseif ( strtolower($trace['function']) == 'do_action' )
				continue;

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
	        $socket = fsockopen($host, $port, $errno, $errstr, $float_timeout);
	        if ( $socket === false ) {
			if ( $use_apc )
				apc_store($apc_key, 'down', $apc_ttl);
			$this->tcp_responsive = "false [ > $float_timeout] ($errno) '$errstr'";
	                return false;
		}
		fclose($socket);
		if ( $use_apc )
			apc_store($apc_key, 'up', $apc_ttl);
		$this->tcp_responsive = 'true';
	        return true;
	}
} // class db
endif;

if ( !class_exists( 'wpdb' ) ) :
if ( defined('WPMU') ) :
class wpdb extends db {
	var $prefix = '';
	var $ready = true;
	var $blogid = 0;
	var $siteid = 0;
	var $global_tables = array('blogs', 'signups', 'site', 'sitemeta', 'users', 'usermeta', 'sitecategories', 'registration_log', 'blog_versions');
	var $blog_tables = array('posts', 'categories', 'post2cat', 'comments', 'links', 'link2cat', 'options',
			'postmeta', 'terms', 'term_taxonomy', 'term_relationships');
	var $blogs, $signups, $site, $sitemeta, $users, $usermeta, $sitecategories, $registration_log, $blog_versions, $posts, $categories, $post2cat, $comments, $links, $link2cat, $options, $postmeta, $terms, $term_taxonomy, $term_relationships;

	function wpdb($dbuser, $dbpassword, $dbname, $dbhost) {
		return $this->__construct($dbuser, $dbpassword, $dbname, $dbhost);
	}

	function __construct($dbuser, $dbpassword, $dbname, $dbhost) {
		$args = array();

		if ( defined('WP_DEBUG') and WP_DEBUG == true )
			$args['show_errors'] = true;

		if ( defined('DB_CHARSET') )
			$args['charset'] = DB_CHARSET;
		else
			$args['charset'] = 'utf8';

		if ( defined('DB_COLLATE') )
			$args['collate'] = DB_COLLATE;
		elseif ( $args['charset'] == 'utf8' )
			$args['collate'] = 'utf8_general_ci';

		$args['save_queries'] = (bool) constant('SAVEQUERIES');

		$args['db_server'] = array(
			'user'     => $dbuser,
			'password' => $dbpassword,
			'name'     => $dbname,
			'host'     => $dbhost
		);

		return parent::__construct($args);
	}

	function set_prefix($prefix) {

		if ( preg_match('|[^a-z0-9_]|i', $prefix) )
			return new WP_Error('invalid_db_prefix', /*WP_I18N_DB_BAD_PREFIX*/'Invalid database prefix'/*/WP_I18N_DB_BAD_PREFIX*/);

		$old_prefix = $this->base_prefix;
		$this->base_prefix = $prefix;
		foreach ( $this->global_tables as $table )
			$this->$table = $prefix . $table;

		if ( empty($this->blogid) )
			return $old_prefix;

		$this->prefix = $this->base_prefix . $this->blogid . '_';

		foreach ( $this->blog_tables as $table )
			$this->$table = $this->prefix . $table;

		if ( defined('CUSTOM_USER_TABLE') )
			$this->users = CUSTOM_USER_TABLE;

		if ( defined('CUSTOM_USER_META_TABLE') )
			$this->usermeta = CUSTOM_USER_META_TABLE;

		return $old_prefix;
	}

	function set_blog_id($blog_id, $site_id = '') {
		if ( !empty($site_id) )
			$this->siteid = $site_id;

		$old_blog_id = $this->blogid;
		$this->blogid = $blog_id;

		$this->prefix = $this->base_prefix . $this->blogid . '_';

		foreach ( $this->blog_tables as $table )
			$this->$table = $this->prefix . $table;

		return $old_blog_id;
	}

	function print_error($str = '') {
		global $EZSQL_ERROR;

		if (!$str) $str = mysql_error($this->dbh);
		$EZSQL_ERROR[] = array ('query' => $this->last_query, 'error_str' => $str);

		if ( $this->suppress_errors )
			return false;

		if ( $caller = $this->get_caller() )
			$error_str = sprintf(/*WP_I18N_DB_QUERY_ERROR_FULL*/'WordPress database error %1$s for query %2$s made by %3$s'/*/WP_I18N_DB_QUERY_ERROR_FULL*/, $str, $this->last_query, $caller);
		else
			$error_str = sprintf(/*WP_I18N_DB_QUERY_ERROR*/'WordPress database error %1$s for query %2$s'/*/WP_I18N_DB_QUERY_ERROR*/, $str, $this->last_query);

		$log_error = true;
		if ( ! function_exists('error_log') )
			$log_error = false;

		$log_file = @ini_get('error_log');
		if ( !empty($log_file) && ('syslog' != $log_file) && !is_writable($log_file) )
			$log_error = false;

		if ( $log_error )
			@error_log($error_str, 0);

		// Is error output turned on or not..
		if ( !$this->show_errors )
			return false;

		// If there is an error then take note of it
		$msg = "WordPress database error: [$str]\n{$this->query}\n";
		if( defined( 'ERRORLOGFILE' ) )
			error_log( $msg, 3, CONSTANT( 'ERRORLOGFILE' ) );
		if( defined( 'DIEONDBERROR' ) )
			die( $msg );
	}
}
else :
class wpdb extends db {
	var $prefix = '';
	var $tables = array('users', 'usermeta', 'posts', 'categories', 'post2cat', 'comments', 'links', 'link2cat', 'options',
			'postmeta', 'terms', 'term_taxonomy', 'term_relationships');
	var $users, $usermeta, $posts, $categories, $post2cat, $comments, $links, $link2cat, $options, $postmeta, $terms, $term_taxonomy, $term_relationships;

	var $ready = true;

	function wpdb($dbuser, $dbpassword, $dbname, $dbhost) {
		return $this->__construct($dbuser, $dbpassword, $dbname, $dbhost);
	}

	function __construct($dbuser, $dbpassword, $dbname, $dbhost) {
		$args = array();

		if ( defined('WP_DEBUG') and WP_DEBUG == true )
			$args['show_errors'] = true;

		if ( defined('DB_CHARSET') )
			$args['charset'] = DB_CHARSET;

		if ( defined('DB_COLLATE') )
			$args['collate'] = DB_COLLATE;

		$args['save_queries'] = (bool) constant('SAVEQUERIES');

		$args['db_server'] = array(
			'user'     => $dbuser,
			'password' => $dbpassword,
			'name'     => $dbname,
			'host'     => $dbhost
		);

		return parent::__construct($args);
	}

	function set_prefix($prefix) {
		if ( preg_match('|[^a-z0-9_]|i', $prefix) )
			return new WP_Error('invalid_db_prefix', 'Invalid database prefix'); // No gettext here

		$old_prefix = $this->prefix;
		$this->prefix = $prefix;

		foreach ( $this->tables as $table )
			$this->$table = $this->prefix . $table;

		if ( defined('CUSTOM_USER_TABLE') )
			$this->users = CUSTOM_USER_TABLE;

		if ( defined('CUSTOM_USER_META_TABLE') )
			$this->usermeta = CUSTOM_USER_META_TABLE;

		return $old_prefix;
	}

	function print_error($str = '') {
		global $EZSQL_ERROR;

		if (!$str) $str = mysql_error($this->dbh);
		$EZSQL_ERROR[] = array ('query' => $this->last_query, 'error_str' => $str);

		if ( $this->suppress_errors )
			return false;

		if ( $caller = $this->get_caller() )
			$error_str = sprintf(/*WP_I18N_DB_QUERY_ERROR_FULL*/'WordPress database error %1$s for query %2$s made by %3$s'/*/WP_I18N_DB_QUERY_ERROR_FULL*/, $str, $this->last_query, $caller);
		else
			$error_str = sprintf(/*WP_I18N_DB_QUERY_ERROR*/'WordPress database error %1$s for query %2$s'/*/WP_I18N_DB_QUERY_ERROR*/, $str, $this->last_query);

		$log_error = true;
		if ( ! function_exists('error_log') )
			$log_error = false;

		$log_file = @ini_get('error_log');
		if ( !empty($log_file) && ('syslog' != $log_file) && !is_writable($log_file) )
			$log_error = false;

		if ( $log_error )
			@error_log($error_str, 0);

		// Is error output turned on or not..
		if ( !$this->show_errors )
			return false;

		$str = htmlspecialchars($str, ENT_QUOTES);
		$query = htmlspecialchars($this->last_query, ENT_QUOTES);

		// If there is an error then take note of it
		print "<div id='error'>
		<p class='wpdberror'><strong>WordPress database error:</strong> [$str]<br />
		<code>$query</code></p>
		</div>";
	}
} // class wpdb
endif;
endif;

if ( ! isset($wpdb) )
	$wpdb = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);

?>