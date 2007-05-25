=== HyperDB ===
Contributors: matt, andy, ryan, mdawaffe
Tags: mysql, scaling, performance, availibility
Requires at least: 2.2
Tested up to: 2.3-alpha

HyperDB is an advanced database class that supports replication, failover, and federation.

== Description ==

HyperDB is a very advanced database class that replaces WP's built-in DB functions. It supports:

* Read and write servers (replication)
* Local and remote datacenters
* Private and public networks
* Different tables on different databases/hosts
* Smart post-write master reads
* Failover for downed host
* Advanced statistics for profiling

It is based on the code currently used in production with 30+ DB servers on WordPress.com.

== Installation ==

This section describes how to install the plugin and get it working.

e.g.

1. Upload `plugin-name.php` to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Place `<?php do_action('plugin_name_hook'); ?>` in your templates


