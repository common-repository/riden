<?php

/**
 * RIDEN WP
 *
 * @package     RIDEN
 * @author      Kevin Nadsady, Ray Anderson, Rob Sheldon
 * @copyright   2018 RIDEN Software Corp.
 * @license     GPL-2.0+
 *
 * @wordpress-plugin
 * Plugin Name: RIDEN
 * Plugin URI:  https://riden.io
 * Description: Protect your site from spammers and bots with RIDEN’s "neighborhood watch".
 * Version:     0.1
 * Author:      RIDEN Software Corp.
 * Text Domain: riden
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

function_exists('is_admin') or die('Must be invoked via WP Core!');

if ( is_admin() ) {
	require_once plugin_dir_path(__FILE__) . 'admin/rsc-riden-admin.php';
}


// fire up the class
$rsc_riden_wp = new rsc_riden_wp();


/**
 * rsc_riden_wp
 *
 * Class containing methods for determining
 * whether the requesting IP address is in jail.
 */
class rsc_riden_wp {

	// {{{ CLASS CONSTANTS

	/**
	 * Direction of the request.
	 *
	 * @var string
	 */
	const DIRECTION = 'in';

	/**
	 * For this application, we are only concered with CATEGORY_WEB.
	 *
	 * @var integer
	 */
	const CATEGORY_WEB = 0x400000; // 1 << 22 -- web    vulnerability scan, i.e. wordpress

	// }}}

	// {{{ CLASS VARIABLES

	/**
	 * Flag set to true if init() has been called.
	 *
	 * @var boolean
	 */
	private $inited = false;

	/**
	 * Internal storage for RIDEN options.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Default values for internal storage.
	 *
	 * @var array
	 */
	private $defaults = array(
		'uuid'        => '',
		'whitelist'   => array('127.0.0.1', '127.0.0.0/8', '192.168.3.0/24'),
		'blacklist'   => array(),
		'whitelistbl' => array(),
		'blacklistbl' => array(
			array(
				'domain'  => 'rtidefense.net',
				'format'  => 'riden',
				'reverse' => true,
			)
		),
		'threshold'   => 127,
	);

	/**
	 * String formats for DNS lookups.
	 *
	 * @var array
	 */
	public $lookup_formats;

	/**
	 * Raw requesting IP address.
	 *
	 * @var string
	 */
	private $raw_ip;

	/**
	 * Requesting IP address converted to a long integer.
	 *
	 * @var string
	 */
	private $long_ip;

	/**
	 * Raw requesting IP address, split into an array.
	 *
	 * @var string
	 */
	private $arr_ip = array();

	/**
	 * Array of responses to ignore from misconfigured DNS.
	 *
	 * @var array
	 */
	private $dns_ignore = array();

	// }}}

	/**
	 * Constructor.
	 */
	public function __construct() {
		# moved from constant to class var for < 5.6 compatibility
		$this->lookup_formats = array(
			'riden'    => '%s.%s.%u.%u.%u.%u.%s',
			'standard' => '%u.%u.%u.%u.%s',
		);

		//  WP hooks and so forth follow.
		add_action('init', array($this, 'init'));
		register_activation_hook(__FILE__, array($this, 'activate'));
	}

	/**
	 * __get()
	 *
	 * Magic method for retrieving variables from WP storage.
	 *
	 */
	public function __get( $var ) {
		//  debug_backtrace() is used to prevent this function
		//  from being called outside this class.
		$stack_trace = debug_backtrace();
		array_shift($stack_trace);
		if ( empty($stack_trace) || ! array_key_exists('object', $stack_trace[0]) || $stack_trace[0]['object'] !== $this ) {
			throw new Exception("'$var' is not a publicly-accessible property");
		}
		switch ( $var ) {
			case 'uuid':
			case 'whitelist':
			case 'blacklist':
			case 'whitelistbl':
			case 'blacklistbl':
			case 'threshold':
				if ( ! array_key_exists($var, $this->options) ) {
					$this->options[ $var ] = get_option('rsc_riden_' . $var);
					if ( false === $this->options[ $var ] ) {
						//  Wordpress doesn't give us a way to check for the existence
						//  of an option before trying to retrieve its value, so we
						//  have to be careful here that "false" is not a non-default
						//  value for any option.
						$this->options[ $var ] = $this->defaults[ $var ];
						update_option('rsc_riden_' . $var, $this->defaults[ $var ]);
					}
				}
				return $this->options[ $var ];
		}
		throw new Exception("Uknown property: '$var'");
	}

	/**
	 * __set()
	 *
	 * Magic method for saving variables in WP storage.
	 *
	 */
	public function __set( $var, $value ) {
		//  debug_backtrace() is used to prevent this function
		//  from being called outside this class.
		$stack_trace = debug_backtrace();
		array_shift($stack_trace);
		if ( empty($stack_trace) || ! array_key_exists('object', $stack_trace[0]) || $stack_trace[0]['object'] !== $this ) {
			throw new Exception("'$var' is not a publicly-accessible property");
		}
		switch ( $var ) {
			case 'uuid':
			case 'whitelist':
			case 'blacklist':
			case 'whitelistbl':
			case 'blacklistbl':
			case 'threshold':
				if ( ! array_key_exists($var, $this->options) ) {
					$this->options[ $var ] = get_option('rsc_riden_' . $var);
				}
				if ( $this->options[ $var ] !== $value ) {
					update_option('rsc_riden_' . $var, $value);
					$this->options[ $var ] = $value;
				}
				return;
		}
		throw new Exception("Uknown property: '$var'");
	}

	/**
	 * __isset()
	 *
	 * Magic method used implicitly by empty().
	 *
	 */
	public function __isset( $var ) {
		//  debug_backtrace() is used to prevent this function
		//  from being called outside this class.
		$stack_trace = debug_backtrace();
		array_shift($stack_trace);
		if ( empty($stack_trace) || ! array_key_exists('object', $stack_trace[0]) || $stack_trace[0]['object'] !== $this ) {
			return false;
		}
		switch ( $var ) {
			case 'uuid':
			case 'whitelist':
			case 'blacklist':
			case 'whitelistbl':
			case 'blacklistbl':
			case 'threshold':
				if ( ! array_key_exists($var, $this->options) ) {
					$this->options[ $var ] = get_option('rsc_riden_' . $var);
				}
				return false !== $this->options[ $var ];
		}
		return false;
	}

	/**
	 * _log()
	 *
	 * Write a message to the Wordpress debug log.
	 *
	 */
	private function _log( $message ) {
		//	With Wordpress debugging turned on
		//	(see https://codex.wordpress.org/Debugging_in_WordPress)
		//	you should be able to find logged messages in
		//	wp-content/debug.log.
		if ( defined('WP_DEBUG') && true === WP_DEBUG ) {
			error_log('RIDEN: ' . $message);
		}
	}

	/**
	 * init()
	 *
	 * Initialize the plugin and either block the incoming request
	 * with a 403 Forbidden error, or allow processing to continue.
	 *
	 */
	public function init() {

		if ( $this->inited )
			return;

		$this->inited = true;

		if ( false === $this->uuid ) {
			//  No UUID configured; return without doing anything.
			return;
		}

		$this->raw_ip = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP);
		if ( false === $this->raw_ip ) {
			$this->_log('Invalid IP: ' . $_SERVER['REMOTE_ADDR']);
			header($_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden');
			die('Forbidden');
		}

		# $this->raw_ip  = '83.240.186.238'; // currently in riden for a web attack
		# $this->raw_ip  = '85.93.20.245'; //  currently in riden for a scan attack
		$this->long_ip = ip2long($this->raw_ip);
		$this->arr_ip  = explode('.', $this->raw_ip);

		$this->_log('raw_ip:  ' . print_r($this->raw_ip, true));
		$this->_log('long_ip: ' . print_r($this->long_ip, true));
		$this->_log('arr_ip:  ' . print_r($this->arr_ip, true));

		# automatically add to the whitelist if the user is logged in and is admin
		if ( current_user_can('administrator') && ! $this->in_list($this->whitelist, 'whitelist') ) {
			//	The syntax here is weird because it's required for working with
			//	__set(). This is a major pitfall of using __get()/__set().
			$this->whitelist = $this->whitelist + array(count($this->whitelist) => $this->raw_ip);
		}

		$this->_log('whitelist:   ' . print_r($this->whitelist, true));
		$this->_log('blacklist:   ' . print_r($this->blacklist, true));
		$this->_log('whitelistbl: ' . print_r($this->whitelistbl, true));
		$this->_log('blacklistbl: ' . print_r($this->blacklistbl, true));
		$this->_log('uuid: '        . print_r($this->uuid, true));

		// this is a workaround for ISP DNS hijacking
		$this->dns_ignore = get_site_transient('rsc_riden_dns_ignore');
		if ( false === $this->dns_ignore ) {
			$this->dns_ignore = gethostbynamel('foo.google.com.');
			if ( ! is_array($this->dns_ignore) ) {
				$this->dns_ignore = array();
			}
			set_site_transient('rsc_riden_dns_ignore', $this->dns_ignore, 1 * HOUR_IN_SECONDS);
		}
		$this->_log('rwp_dns_ignore: ' . print_r($this->dns_ignore, true));

		// check each list for the IP -- if in jail, redirect to 403
		// ignore requests for 127.0.0.1 and 127.0.1.1! (apache alive tester stuff)
		if ( '127.0.0.1' != $this->raw_ip  && '127.0.1.1' != $this->raw_ip && $this->block_request() ) {
			header($_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden');
			ob_end_clean();
			//  TODO: Return really simple HTML with link to
			//  https://www.riden.io/unblock.php
			die('Forbidden');
		}
	}

	/**
	 * activate()
	 *
	 * Run when the plugin is activated.
	 *
	 */
	public function activate() {
		if ( empty($this->uuid) ) {
			//  Get a new RIDEN UUID.
			$response = wp_remote_post('https://riden.io/api/account');
			if ( ! is_wp_error($response) && 200 == $response['response']['code'] ) {
				$response = json_decode($response['body'], true);
				if ( array_key_exists('uuid', $response) && 1 === preg_match('/^[0-9A-Fa-f]{32}$/', $response['uuid']) ) {
					$this->uuid = $response['uuid'];
				}
			}
		}
		//	Check other settings too and initialize them with
		//	defaults if they are not present.
		if ( false === $this->whitelist ) {
			$this->whitelist = $this->defaults['whitelist'];
		}
		if ( false === $this->blacklist ) {
			$this->blacklist = $this->defaults['blacklist'];
		}
		if ( false === $this->whitelistbl ) {
			$this->whitelistbl = $this->defaults['whitelistbl'];
		}
		if ( false === $this->blacklistbl ) {
			$this->blacklistbl = $this->defaults['blacklistbl'];
		}
		if ( false === $this->threshold ) {
			$this->threshold = $this->defaults['threshold'];
		}
	}

	/**
	 * block_request()
	 *
	 * Check the requesting IP against the search lists.
	 *
	 * @return boolean -- whether to block the requesting IP
	 */
	public function block_request() {

		$cached = get_site_transient('rsc_riden_ip_cache:' . $this->raw_ip);
		if ( $cached == 'allow' ) {
			$this->_log('Found in cache: ' . $this->raw_ip . ' -> ' . $cached);
			return false;
		} elseif ( $cached == 'deny' ) {
			$this->_log('Found in cache: ' . $this->raw_ip . ' -> ' . $cached);
			return true;
		}

		// check whitelist first
		if ( ! empty($this->whitelist) && $this->in_list($this->whitelist, 'whitelist') ) {
			$this->_log($this->raw_ip . ' found in whitelist; access granted');
			set_site_transient('rsc_riden_ip_cache:' . $this->raw_ip, 'allow', 5 * MINUTE_IN_SECONDS);
			return false;
		}

		// check whitelistbl next
		if ( ! empty($this->whitelistbl) && $this->in_listbl($this->whitelistbl, 'whitelistbl') ) {
			$this->_log($this->raw_ip . ' found in whitelistbl; access granted');
			set_site_transient('rsc_riden_ip_cache:' . $this->raw_ip, 'allow', 5 * MINUTE_IN_SECONDS);
			return false;
		}

		// check blacklist next
		if ( ! empty($this->blacklist) && $this->in_list($this->blacklist, 'blacklist') ) {
			$this->_log($this->raw_ip . ' found in blacklist; access denied');
			set_site_transient('rsc_riden_ip_cache:' . $this->raw_ip, 'deny', 5 * MINUTE_IN_SECONDS);
			return true;
		}

		// check blacklistbl last
		if ( ! empty($this->blacklistbl) && $this->in_listbl($this->blacklistbl, 'blacklistbl') ) {
			$this->_log($this->raw_ip . ' found in blacklistbl; access denied');
			set_site_transient('rsc_riden_ip_cache:' . $this->raw_ip, 'deny', 5 * MINUTE_IN_SECONDS);
			return true;
		}

		$this->_log($this->raw_ip . ' not found -- fall through, access granted');
		return false;

	}

	/**
	 * in_list()
	 *
	 * Checks if the requesting IP resides within the
	 * provided list (whitelist or blacklist).
	 *
	 * @access private
	 *
	 * @param array  $list -- the list to search
	 * @param string $name -- the name of the list to search
	 *
	 * @return boolean -- true if requesting IP found in list, false otherwise
	 */
	private function in_list( $list, $name ) {

		$this->_log("checking $name: " . print_r($list, true));

		if ( isset($list) && is_array($list) ) {
			foreach ( $list as $k => $ip_cidr ) {
				if ( $this->check_ip_cidr($k, $ip_cidr) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * in_listbl()
	 *
	 * Checks if the requesting IP resides within the
	 * provided list (whitelistbl or blacklistbl).
	 *
	 * @access private
	 *
	 * @param array  $list -- the list to search (note: we're operating on the actual array)
	 * @param string $name -- the name of the list to search
	 *
	 * @return boolean -- true if requesting IP found in list, false otherwise
	 */
	private function in_listbl( $list, $name ) {

		$this->_log("checking $name: " . print_r($list, true));

		if ( isset($list) && is_array($list) ) {

			foreach ( $list as $listbl_arr ) {

				$domain  = $listbl_arr['domain' ];
				$format  = $listbl_arr['format' ];
				$reverse = $listbl_arr['reverse'];
				$ip_addr = $reverse ? $this->reverse_ip('array') : $this->arr_ip;

				// make sure there's a trailing dot for the DNS query
				if ( '.' != substr($domain, -1) ) {
					$domain .= '.';
				}

				$qry_str = $this->get_lookup_format($format, $ip_addr, $domain);

				if ( $this->lookup_ip($qry_str, $domain) ) {

					// only block those on the blacklistbl
					// (whitelistbl classification not yet implemented)
					if ( 'blacklistbl' == $name ) {
						return true;
					}
					
					$this->_log('IP found, but whitelistbl not yet implemented!');

				} else {
					continue;
				}
			}
		}

		return false;
	}

	/**
	 * check_ip_cidr()
	 *
	 * Checks if the parameter is a plain IP address,
	 * or a network range. Then determines whether
	 * the requesting IP matches or falls within the IP/CIDR.
	 *
	 * @access private
	 *
	 * @param integer $k       -- the key of the current IP array element we're checking (just for debugging)
	 * @param string  $ip_cidr -- the IP address or network range
	 *
	 * @return boolean -- true if a match is found, false otherwise
	 */
	private function check_ip_cidr( $k, $ip_cidr ) {

		if ( false === strpos($ip_cidr, '/') ) {
			// plain IP address
			if ( ip2long($ip_cidr) == $this->long_ip ) {
				$this->_log("[$k] IP match found!");
				return true;
			}
		} else {
			// network range
			if ( $this->ip_in_range($ip_cidr) ) {
				$this->_log("[$k] IP found in net range!");
				return true;
			}
		}

		$this->_log("[$k] IP does not match or not found in net range");
		return false;
	}

	/**
	 * lookup_ip()
	 *
	 * @access private
	 *
	 * @param string $qry_str     -- the DNS query string
	 * @param string $domain_name -- the domain we're searching in
	 *
	 * @return boolean -- true if found in RIDEN, false otherwise
	 */
	private function lookup_ip( $qry_str, $domain_name ) {

		$hosts = gethostbynamel($qry_str);

		$this->_log('qry_str: ' . print_r($qry_str, true));
		$this->_log('gethostbynamel() results: ' . print_r($hosts, true));

		if ( ! $hosts ) {
			$this->_log('host not found [1]');
			return false;
		}

		foreach ( $hosts as $host ) {

			if ( in_array($host, $this->dns_ignore) ) {
				continue;
			}

			// we found them in a domain other than RIDEN's
			if ( 'rtidefense.net.' != $domain_name ) {
				$this->_log('host found in list!');
				return true;
			}

			// check RIDEN
			if ( $this->in_riden($host) ) {
				return true;
			}

		}

		$this->_log('host not found [2]');
		return false;
	}

	/**
	 * in_riden()
	 *
	 * Checks if the derived host of the requesting IP
	 * is in RIDEN. Only blocks if they are in for web attacks.
	 *
	 * @access private
	 *
	 * @param string $host -- 127.X.X.X
	 *
	 * @return boolean -- true if they're in RIDEN for web attacks, false otherwise
	 */
	private function in_riden( $host ) {

		$host_arr = explode('.', $host);

		if ( ! is_array($host_arr) || empty($host_arr) || 4 != count($host_arr) ) {
			$this->_log('bad host IP');
			return false;
		}

		$iplng = ip2long($host);

		// we'll only block them if they're in RIDEN for web attacks
		if ( self::CATEGORY_WEB == ($iplng & self::CATEGORY_WEB) ) {
			$this->_log($this->raw_ip . ' host found in RIDEN for web attack');
			return true;
		}

		// OR if their threshold is < than the value of the reputation score
		$b4 = (int) $host_arr[3];
		if ( '127.0.0.' == substr($host, 0, 8) && 0 < $b4 && 0 < $this->threshold && $this->threshold < $b4 ) {
			# block due to reputation score
			$this->_log($this->raw_ip . " host found in RIDEN for negative reputation: score: $b4, thresh: {$this->threshold}");
			return true;
		}

		$this->_log($this->raw_ip . ' host not found in RIDEN for web attack or reputation score');
		return false;

	}


	/**
	 * get_lookup_format()
	 *
	 * Builds the DNS query string according to the specified format.
	 *
	 * @access private
	 *
	 * @param string $format  -- the name of the format to be used
	 * @param array  $ip_addr -- the requesting IP
	 * @param string $domain  -- the domain to lookup
	 *
	 * @return string
	 */
	private function get_lookup_format( $format, $ip_addr, $domain ) {

		$tpl = $this->lookup_formats[$format];

		switch ( $format ) {
			case 'riden':
				$format = 'riden';
				$args = array($this->uuid, self::DIRECTION, $ip_addr[0], $ip_addr[1], $ip_addr[2], $ip_addr[3], $domain);
				break;
			case 'standard':
			default:
				$args = array($ip_addr[0], $ip_addr[1], $ip_addr[2], $ip_addr[3], $domain);
				break;
		}

		$ret = vsprintf($tpl, $args);

		$this->_log("$format format: " . print_r($ret, true));
		return $ret;
	}

	/**
	 * reverse_ip()
	 *
	 * Reverses the given IP and returns it split as an array,
	 * or whole as a string depending on the parameter value.
	 *
	 * @access private
	 *
	 * @param string $ret_type -- the datatype in which the IP should be returned
	 *
	 * @return boolean
	 */
	private function reverse_ip( $ret_type = '' ) {

		$octet_arr = $this->arr_ip;

		if ( ! is_array($octet_arr) || empty($octet_arr) ) {
			$this->_log('bad octet array');
			return false;
		}

		foreach ( $octet_arr as $k => $v ) {
			$octet_arr[$k] = (int) $v;
		}

		$reversed = array_reverse($octet_arr);

		switch ( $ret_type ) {
			case 'array':
				return $reversed;
			case 'string':
				return implode('.', $reversed);
		}
		return false;
	}

	/**
	 * ip_cidr_check()
	 *
	 * Checks if the requesting IP resides
	 * within the provided network range.
	 *
	 * NOTE: this function currently not being used.
	 *
	 * @access private
	 *
	 * @param string $cidr -- the network range
	 *
	 * @return boolean -- true if IP is in range, false otherwise
	 */
	private function ip_cidr_check( $cidr ) {

		list($net, $mask) = split('/', $cidr);

		$ip_net  = ip2long($net);
		$ip_mask = ~((1 << (32 - $mask)) - 1);

		$ip_ip = $this->long_ip;

		$ip_ip_net = $ip_ip & $ip_mask;

		return ( $ip_ip_net == $ip_net );
	}

	/**
	 * ip_in_range()
	 *
	 * Checks if the requesting IP resides
	 * within the provided network range.
	 *
	 * @access private
	 *
	 * @param string $range -- the IP/CIDR netmask eg. 127.0.0.0/24
	 *
	 * @return boolean -- true if IP is in range, false otherwise
	 */
	private function ip_in_range( $range ) {

		list($range, $netmask) = explode('/', $range, 2);

		$range_decimal    = ip2long($range);
		$ip_decimal       = $this->long_ip;
		$wildcard_decimal = pow(2, (32 - $netmask)) - 1;
		$netmask_decimal  = ~ $wildcard_decimal;

		return ( ($ip_decimal & $netmask_decimal) == ($range_decimal & $netmask_decimal) );
	}

}
