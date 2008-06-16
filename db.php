<?php

define('OBJECT', 'OBJECT', true);
define('OBJECT_K', 'OBJECT_K', false);
define('ARRAY_A', 'ARRAY_A', false);
define('ARRAY_N', 'ARRAY_N', false);

if (!defined('SAVEQUERIES'))
	define('SAVEQUERIES', true);

class wpdb {
	var $show_errors = true;
	var $suppress_errors = false;
	var $last_error = '';
	var $num_queries = 0;
	var $last_query;
	var $col_info;
	var $queries;
	var $prefix = '';

	// Our tables
	var $posts;
	var $users;
	var $categories;
	var $post2cat;
	var $comments;
	var $links;
	var $options;
	var $postmeta;
	var $usermeta;
	var $terms;
	var $term_taxonomy;
	var $term_relationships;
	var $tables = array('users', 'usermeta', 'posts', 'categories', 'post2cat', 'comments', 'links', 'link2cat', 'options',
			'postmeta', 'terms', 'term_taxonomy', 'term_relationships');
	var $charset;
	var $collate;

	// HyperDB
	var $multiple_db = false;
	var $max_connections = 10;
	var $srtm = false;
	var $last_table;
	var $connection_array;
	var $current_host;

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

		if ( defined('WP_DEBUG') and WP_DEBUG == true )
			$this->show_errors();

		if( defined( "WP_USE_MULTIPLE_DB" ) && CONSTANT( "WP_USE_MULTIPLE_DB" ) == true ) {
			$this->multiple_db = true;
			return true;
		}

		if ( defined('DB_CHARSET') )
			$this->charset = DB_CHARSET;

		if ( defined('DB_COLLATE') )
			$this->collate = DB_COLLATE;

		$this->dbh = @mysql_connect($dbhost, $dbuser, $dbpassword);
		if (!$this->dbh) {
			$this->print_error( "Can't connect " . $dbhost );
		}

		// TODO: collation unreachable in multi-db mode!
		if ( !empty($this->charset) && version_compare(mysql_get_server_info(), '4.1.0', '>=') )
 			$this->query("SET NAMES '$this->charset'");

		$this->select($dbname, $this->dbh);
	}

	function __destruct() {
		return true;	
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

	/**
	 * Selects a database using the current class's $this->dbh
	 * @param string $db name
	 */
	function select($db, &$dbh) {
		return mysql_select_db($db, $dbh);
	}

	/**
	 * Escapes content for insertion into the database, for security
	 *
	 * @param string $string
	 * @return string query safe string
	 */
	function escape($string) {
		return addslashes( $string );
		// Disable rest for now, causing problems
		/*
		if( !$this->dbh || version_compare( phpversion(), '4.3.0' ) == '-1' )
			return mysql_escape_string( $string );
		else
			return mysql_real_escape_string( $string, $this->dbh );
		*/
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
		return @vsprintf($query, $args);
	}

	/**
	 * Print SQL/DB error
	 *
	 * @param string $str Error string
	 */
	function print_error($str = '') {
		global $EZSQL_ERROR;

		if ( empty($str) )
			$str = $this->last_error;

		$EZSQL_ERROR[] = array ('query' => $this->last_query, 'error_str' => $str);

		if ( $this->suppress_errors )
			return false;

		$error_str = "WordPress database error $str for query $this->last_query";

		if ( $caller = $this->get_caller() )
			$error_str .= " made by $caller";

		$log_file = @ini_get('error_log');
		if ( !empty($log_file) && ('syslog' != $log_file) && !is_writable($log_file) )
			@error_log($error_str, 0);

		// Is error output turned on or not
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

	/**
	 * Turn error output on or off
	 *
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
	 *
	 * @return bool previous setting of show_errors
	 */
	function hide_errors() {
		return $this->show_errors(false);
	}

	/**
	 * Turn error logging on or off
	 *
	 * @param bool $suppress
	 * @return bool previous setting
	 */
	function suppress_errors( $suppress = true ) {
		$errors = $this->suppress_errors;
		$this->suppress_errors = $suppress;
		return $errors;
	}

	/**
	 * Kill cached query results
	 */
	function flush() {
		$this->last_result = array();
		$this->col_info = null;
		$this->last_query = null;
		$this->last_error = '';
	}

	/**
	 * Find the first table name referenced in a query
	 *
	 * @param string query
	 * @return string table
	 */
	function get_table_from_query ( $q ) {
		// Remove characters that can legally trail the table name
		rtrim($q, ';/-#');

		// Quickly match most common queries
		if ( preg_match('/^\s*(?:'
				. 'SELECT.*?\s+FROM'
				. '|INSERT(?: IGNORE)?(?: INTO)?'
				. '|REPLACE(?: INTO)?'
				. '|UPDATE(?: IGNORE)?'
				. '|DELETE(?: IGNORE)?(?: FROM)?'
				. ')\s+`?(\w+)`?/is', $q, $maybe) )
			return $maybe[1];

		// Refer to the previous query
		if ( preg_match('/^\s*SELECT.*?\s+FOUND_ROWS\(\)/is', $q) )
			return $this->last_table;

		// Big pattern for the rest of the table-related queries in MySQL 5.0
		if ( preg_match(str_replace(' ', '\s+', '/^\s*(?:'
				. '(?:EXPLAIN (?:EXTENDED )?)?SELECT.*?\s+FROM'
				. '|INSERT(?: LOW_PRIORITY| DELAYED| HIGH_PRIORITY)?(?: IGNORE)?(?: INTO)?'
				. '|REPLACE(?: LOW_PRIORITY| DELAYED)?(?: INTO)?'
				. '|UPDATE(?: LOW_PRIORITY)?(?: IGNORE)?'
				. '|DELETE(?: LOW_PRIORITY| QUICK| IGNORE)*(?: FROM)?'
				. '|DESCRIBE|DESC|EXPLAIN|HANDLER'
				. '|(?:LOCK|UNLOCK) TABLE(?:S)?'
				. '|(?:RENAME|OPTIMIZE|BACKUP|RESTORE|CHECK|CHECKSUM|ANALYZE|OPTIMIZE|REPAIR).* TABLE'
				. '|TRUNCATE(?: TABLE)?'
				. '|CREATE(?: TEMPORARY)? TABLE(?: IF NOT EXISTS)?'
				. '|ALTER(?: IGNORE)?'
				. '|DROP TABLE(?: IF EXISTS)?'
				. '|CREATE(?: \w+)? INDEX.* ON'
				. '|DROP INDEX.* ON'
				. '|LOAD DATA.*INFILE.*INTO TABLE'
				. '|(?:GRANT|REVOKE).*ON TABLE)'
				. 'SHOW (?:.*FROM|.*TABLE|'
				. ')\s+`?(\w+)`?/is'), $q, $maybe) )
			return $maybe[1];

		return '';
	}

	/**
	 * Determine the likelihood that this query could alter anything
	 *
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
	 *
	 * @param unknown_type $table
	 * @return unknown
	 */
	function get_ds_part_from_table($table) {
		global $db_servers;

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
			$partitions = count($db_servers[$dataset]);
			$partition = ( $tableno % $partitions ) + 1;
		} else {
			return false;
		}

		return compact('dataset', 'hash', 'partition');
	}

	/**
	 * Figure out which database server should handle the query, and connect to it.
	 *
	 * @param string query
	 * @return resource mysql database connection
	 */
	function &db_connect( $query = '' ) {
		global $db_servers, $db_tables, $current_connection;

		if ( ! $this->multiple_db )
			return true;

		if ( empty( $query ) )
			return false;

		$write = $this->is_write_query( $query );
		$table = $this->get_table_from_query( $query );
		$this->last_table = $table;
		$partition = 0;

		if ( is_array($db_tables) && array_key_exists($table, $db_tables) ) {
			$dataset = $db_tables[$table];
			$dbhname = "dbh_$dataset";
		} else if ( $ds_part = $this->get_ds_part_from_table($table) ) {
			extract( $ds_part, EXTR_OVERWRITE );
			$dbhname = "dbh_{$dataset}_{$partition}";
			$_server['name'] = "{$dataset}_$hash";
		} else {
			$dataset = 'global';
			$dbhname = "dbh_global";
		}

		// Send reads to the master after a write to cope with replication lag
		if ( $write || $this->srtm )
			$write = $this->srtm = true;

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

			// Using an existing connection, select the db we need and if that fails, disconnect and connect anew.
			if ( ( isset($_server['name']) && $this->select($_server['name'], $this->$dbhname) ) ||
					( isset($this->used_servers[$dbhname]['db']) && $this->select($this->used_servers[$dbhname]['db'], $this->$dbhname) ) ) {
				$this->current_host = $this->dbh2host[$dbhname];
				return $this->$dbhname;
			} else {
				$this->disconnect($dbhname);
			}
		}

		if ( $write && defined( "MASTER_DB_DEAD" ) ) {
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

		// at the following index # we have no choice but to connect
		$max_server_index = count($servers) - 1;

		// Connect to a database server
		foreach ( $servers as $server_index => $server ) {
			$this->timer_start();

			$host = $server['host'];

			// make sure there's always a port #
			$pieces = explode(':', $host);
			if ( count($pieces) == 1 )
				$pieces[1] = 3306;

			// reduce the timeout if the host is on the lan
			$mctime = 0.2; // Default
			if ( strtolower(substr($pieces[0], -3)) == 'lan' )
				$mctime = 0.05;

			// connect if necessary or possible
			if ( $write || $server_index == $max_server_index || $this->check_tcp_responsiveness($pieces[0],$pieces[1],$mctime) ) {
				$this->$dbhname = false;
				$try_count = 0;
				while ( $this->$dbhname === false ) {
					$try_count++;
					$this->$dbhname = @mysql_connect( $host, $server['user'], $server['password'] );
					if ( $try_count == 4 ) {
						break;
					} else {
						if ( $this->$dbhname === false )
							// Possibility of waiting up to 3 seconds!
							usleep( (500000 * $try_count) );
					}
				}
			} else {
				$this->$dbhname = false;
			}

			$this->connections[] = "{$server['user']}@$host";

			if ( $this->$dbhname && is_resource($this->$dbhname) )  {
				$current_connection .= " connected to $host in " . number_format( ( $this->timer_stop() * 1000 ), 2) . 'ms';
				$this->connection_array[] = array( $host, number_format( ( $this->timer_stop() ), 7) );
				$this->current_host = $host;
				$this->open_connections[] = $dbhname;
				$this->dbh2host[$dbhname] = $host;
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

		if ( ! $this->select( $server['name'], $this->$dbhname ) ) {
			$server_for_log = $server;
			unset($server_for_log['user']);
			unset($server_for_log['password']);
			return $this->handle_error_connecting($dbhname, array('query'=>$query, 'dbhname'=>$dbhname, 'server'=>$server_for_log, 'error'=>mysql_error()));
		}

		$this->last_used_server = array( "server" => $server['host'], "db" => $server['name'] );

		$this->used_servers[$dbhname] = $this->last_used_server;

		// Close current and prevent future read-only connections to the written cluster
		if ( $write ) {
			if ( isset($db_clusters[$clustername]['read']) )
				unset( $db_clusters[$clustername]['read'] );

			if ( is_resource($this->$read_dbh) && $this->$read_dbh != $this->$dbhname )
				$this->disconnect( $read_dbh );

			$this->$read_dbh = & $this->$dbhname;
		}

		while ( count($this->open_connections) > $this->max_connections ) {
			$oldest_connection = array_shift($this->open_connections);
			if ( $this->$oldest_connection != $this->$dbhname )
				$this->disconnect($oldest_connection);
		}

		return $this->$dbhname;
	}

	/**
	 * Disconnect and remove connection from open connections list
	 *
	 * @param string $dbhname
	 */
	function disconnect($dbhname) {
		if ( $k = array_search($dbhname, $this->open_connections) )
			unset($this->open_connections[$k]);

		if ( is_resource($this->$dbhname) )
			mysql_close($this->$dbhname);

		unset($this->$dbhname);
	}

	/**
	 * Basic query. See docs for more details.
	 *
	 * @param string $query
	 * @return int number of rows
	 */
	function query($query) {
		global $current_connection;
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

		if ( $this->multiple_db )
			$this->dbh =& $this->db_connect( $query );

		if ( ! is_resource($this->dbh) )
			return false;

		if (SAVEQUERIES)
			$this->timer_start();

		$this->result = @mysql_query($query, $this->dbh);
		++$this->num_queries;

		if (SAVEQUERIES)
			$this->queries[] = array( $query, $this->timer_stop(), $this->get_caller() );

		// If there is an error then take note of it
		if ( $this->last_error = mysql_error($this->dbh) ) {
			$this->print_error($this->last_error);
			return false;
		}

		if ( preg_match("/^\\s*(insert|delete|update|replace) /i",$query) ) {
			$this->rows_affected = mysql_affected_rows($this->dbh);
			// Take note of the insert_id
			if ( preg_match("/^\\s*(insert|replace) /i",$query) ) {
				$this->insert_id = mysql_insert_id($this->dbh);
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
		} elseif ( $output == OBJECT_K ) {
			// Return an array of row objects with keys from column 1
			// (Duplicates are discarded)
			foreach ( $this->last_result as $row ) {
				$key = array_shift( get_object_vars( $row ) );
				if ( !isset( $new_array[ $key ] ) )
					$new_array[ $key ] = $row;
			}
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
		$mysql_version = preg_replace('|[^0-9\.]|', '', @mysql_get_server_info($this->dbh));
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
			if ( @$trace['class'] == __CLASS__ )
				continue;
			elseif ( strtolower(@$trace['function']) == 'call_user_func_array' )
				continue;
			elseif ( strtolower(@$trace['function']) == 'apply_filters' )
				continue;
			elseif ( strtolower(@$trace['function']) == 'do_action' )
				continue;

			$caller = $trace['function'];
			break;
		}
		return $caller;
	}

	function handle_error_connecting( $dbhname, $details ) {
		define( "MYSQLCONNECTIONERROR", true );
		$msg = date( "Y-m-d H:i:s" ) . " Can't select $dbhname - ";
		$msg .= "\n" . print_r($details, true);

		error_log( "$msg\n\n", 3, "/tmp/db-connect.txt" );
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
	        $socket = @fsockopen($host, $port, $errno, $errstr, $float_timeout);
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
}

if ( ! isset($wpdb) )
	$wpdb = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
?>