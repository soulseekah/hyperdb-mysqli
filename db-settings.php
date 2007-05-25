<?php

// Where are we? Put new IP substrings here.
$dc_ips = array(
	'123.213.111.' => 'nyc',
	'10.1.1.'  => 'nyc',

	'231.113.213.'  => 'lax',
	'10.5.0.'  => 'lax',

);
foreach ( $dc_ips as $dc_ip => $dc ) {
	if ( substr($_SERVER['SERVER_ADDR'], 0, strlen($dc_ip)) == $dc_ip ) {
		define( 'DATACENTER', $dc );
		break;
	}
}

define('SAVEQUERIES', false);

function localize_hostname($hostname) {
	return str_replace('.com', '.lan', $hostname);
}

function localize_hostnames($array) {
	return array_map('localize_hostname', $array);
}


function add_db_server($ds, $part, $dc, $read, $write, $host, $lhost, $name, $user, $password) {
	global $db_servers;

	$server = compact('ds', 'part', 'dc', 'read', 'write', 'host', 'name', 'user', 'password');

	if ( !empty($lhost) )
		$server['lhost'] = $lhost;

	$db_servers[$ds][$part][] = $server;
}

function add_db_table($ds, $table) {
	global $db_tables;

	$db_tables[$table] = $ds;
}

function get_dataset_from_blogid($id) {

	if ( !$id )
		return false;

	$key = substr(md5($id), 0, 1); // 0-f (hex string)
	$key = hexdec($key) + 1; // 1-16 (int)
	return $key;
}

// Database servers grouped by dataset. (Totally tabular, dude!)
// R can be 0 (no reads) or a positive integer indicating the order
// in which to attempt communication (all locals, then all remotes)

// database and user are ignored for blog datasets

//dataset, partition, datacenter, R, W,                 internet host:port,         internal network host:port,      database,          user,            password
add_db_server('misc', 0, 'lax', 1, 1,  'misc.db.example.com:3722',  'misc.db.example.lan:3722', 'wp-misc', 'miscuser',        'miscpassword');

add_db_server('global', 0, 'nyc', 1, 1,'global.mysql.example.com:3509','global.mysql.example.lan:3509', 'global-db', 'globaluser',  'globalpassword');


// Three kinds of db connections are supported:
//  1. global (e.g. wp_users)
//  2. partitioned (e.g. wp_data_3e_views)
//  3. external (e.g. misc)

// External tables MUST be listed below with their dataset
// or wpdb with assume they are global and fail to find them.

// Tables in the global and partitioned databases do not have to be listed.

// ** NO DUPLICATE TABLE NAMES ALLOWED **
add_db_table('misc', 'my_stuff');
add_db_table('misc', 'hot_data');
add_db_table('misc', 'bbq_sauces');

?>