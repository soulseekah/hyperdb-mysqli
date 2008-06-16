<?php

// This tells HyperDB to use the settings below instead of the definitions in wp-config.php.
define( 'WP_USE_MULTIPLE_DB', true );

// If you have multiple datacenters you can come up with your own datacenter
// detection logic (php_uname?). This helps ensure the web servers try to
// connect to the nearest database servers first, then distant ones.
define( 'DATACENTER', '' );

if ( !defined('SAVEQUERIES') )
	define('SAVEQUERIES', false);

/**
 * A trick used by WordPress.com is .lan hostnames mapped to local IPs. Not required.
 *
 * @param unknown_type $hostname
 * @return unknown
 */
function localize_hostname($hostname) {
	return str_replace('.com', '.lan', $hostname);
}

function localize_hostnames($array) {
	return array_map('localize_hostname', $array);
}

/**
 * This generates the array of servers.
 *
 * @param string $ds Dataset: the name of the dataset. Just use "global" if you don't need horizontal partitioning.
 * @param int $part Partition: the vertical partition number (1, 2, 3, etc.). Use "0" if you don't need vertical partitioning.
 * @param string $dc Datacenter: where the database server is located. Airport codes are convenient. Use whatever.
 * @param int $read Read order: lower number means use this for more reads. Zero means no reads (e.g. for masters).
 * @param bool $write Write flag: is this server writable?
 * @param string $host Internet address: host:port of server on internet. 
 * @param string $lhost Local address: host:port of server for use when in same datacenter. Leave empty if no local address exists.
 * @param string $name Database name.
 * @param string $user Database user.
 * @param string $password Database password.
 */
function add_db_server($ds, $part, $dc, $read, $write, $host, $lhost, $name, $user, $password) {
	global $db_servers;

	if ( empty( $lhost ) )
		$lhost = $host;

	$server = compact('ds', 'part', 'dc', 'read', 'write', 'host', 'lhost', 'name', 'user', 'password');

	$db_servers[$ds][$part][] = $server;
}

// Database servers grouped by dataset. (Totally tabular, dude!)
// R can be 0 (no reads) or a positive integer indicating the order
// in which to attempt communication (all locals, then all remotes)

//dataset, partition, datacenter, R, W,             internet host:port,     internal network host:port,   database,        user,        password

// Next line populates 'global' dataset from wp-config.php for instant compatibility. Remove it when you put your settings here.
add_db_server('global', 0,    '', 1, 1,                        DB_HOST,                        DB_HOST,    DB_NAME,     DB_USER,     DB_PASSWORD);

/*
add_db_server(  'misc', 0, 'lax', 1, 1,     'misc.db.example.com:3722',     'misc.db.example.lan:3722',  'wp-misc',  'miscuser',  'miscpassword');
add_db_server('global', 0, 'nyc', 1, 1,'global.mysql.example.com:3509','global.mysql.example.lan:3509','global-db','globaluser','globalpassword');
*/

/**
 * Map a table to a dataset.
 *
 * @param string $ds
 * @param string $table
 */
function add_db_table($ds, $table) {
	global $db_tables;

	$db_tables[$table] = $ds;
}

// Three kinds of db connections are supported:
//  1. global (e.g. wp_users)
//  2. partitioned (e.g. wp_data_3e_views)
//  3. external (e.g. misc)

// When a query is tried, HyperDB extracts the table name to find it in a
// dataset. If the table is not added to a dataset here, and if it is not
// a partitioned table, HyperDB will assume it is in the "global" dataset.

// External tables MUST be listed below with their dataset
// or wpdb with assume they are global and fail to find them.

// Global tables MAY be added here to minimize computation.

// Tables in partitioned datasets MUST NOT be added here.

// ** NO DUPLICATE TABLE NAMES ALLOWED **
// Also be careful that your non-partitioned tables don't match the patterns in get_ds_part_from_table!

// add_db_table('misc', 'my_stuff');
// add_db_table('misc', 'hot_data');
// add_db_table('misc', 'bbq_sauces');

?>