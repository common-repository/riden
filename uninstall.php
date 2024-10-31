<?php

	if ( ! defined('WP_UNINSTALL_PLUGIN') ) {
		die;
	}

	//	Ensure that any options we have stored are deleted.
	//	TODO: This should be handled by the rsc_riden_wp
	//		class and option names shouldn't be hardcoded here.
	delete_option('rsc-riden-wp-options');
	delete_option('rsc_riden_uuid');
	delete_option('rsc_riden_whitelist');
	delete_option('rsc_riden_blacklist');
	delete_option('rsc_riden_whitelistbl');
	delete_option('rsc_riden_blacklistbl');
	delete_option('rsc_riden_threshold');

?>