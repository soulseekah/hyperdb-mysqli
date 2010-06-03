=== HyperDB ===
Contributors: matt, andy, ryan, mdawaffe, automattic
Tags: mysql, scaling, performance, availability, WordPress.com
Requires at least: 2.3
Tested up to: 2.8.5
Stable tag: trunk

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

It is based on the code currently used in production on WordPress.com with dozens of DB servers spanning multiple datacenters.

== Installation ==

Nothing goes in the plugins directory.

1. *WordPress MU only:* add this line near the top of `wp-config.php`:

`define('WPMU', true);`

2. Upload `db.php` to the `/wp-content/` directory. At this point, HyperDB is active. It will use the database connection constants until you complete the final steps.

3. Upload `db-settings.php` in the directory that holds `wp-config.php`

4. Edit the db settings according to the directions in that file.

5. Add this line near the top of `wp-config.php`:

`require('db-settings.php');`

Any value of `WP_USE_MULTIPLE_DB` will be ignored by HyperDB. If you wish to switch off multiple DB, remove the 'require' statement from step 5.

== Frequently Asked Questions ==

= Question? =

Answer.
