=== HyperDB ===
Contributors: matt, andy, ryan, mdawaffe
Tags: mysql, scaling, performance, availibility
Requires at least: 2.2
Tested up to: 2.5

HyperDB is an advanced database class that supports partitioning, replication, failover, and federation.

== Description ==

HyperDB is a very advanced database class that replaces WP's built-in DB functions. It supports:

* Read and write servers (replication)
* Local and remote datacenters
* Private and public networks
* Different tables on different databases/hosts
* Smart post-write master reads
* Failover for downed host
* Advanced statistics for profiling

It is based on the code currently used in production with dozens of DB servers on WordPress.com.

== Installation ==

Nothing goes in the plugins directory.

1. Upload `db.php` to the `/wp-content/` directory.
1. Upload `db-settings.php` in the directory that holds `wp-config.php`
1. Edit the db settings according to the directions in that file.
1. Add this line to `wp-config.php` after ABSPATH is defined: {{{require('db-settings.php');}}}
