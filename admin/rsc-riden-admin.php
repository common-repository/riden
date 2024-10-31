<?php

class rsc_riden_settings
{
	/**
	 * Holds the values to be used in the fields callbacks
	 */
	private $options;
	private $plugin_slug  = 'riden-settings';
	private $section_slug = 'riden-settings-section';
	private $stats_slug   = 'riden-stats-section';
	private $setting_slug = 'rsc-riden-wp-options';

	/**
	 * Start up
	 */
	public function __construct() {
		$this->options = array(
			'uuid'        => '',
			'whitelist'   => '',
			'blacklist'   => '',
			'whitelistbl' => '',
			'threshold'   => '',
		);
		add_action('admin_menu', array($this, 'add_riden_plugin_page'));
		add_action('admin_init', array($this, 'riden_page_init'));
	}

	/**
	 * Add options page
	 */
	public function add_riden_plugin_page() {
		# This page will be under "Settings"
		add_options_page(
			'RIDEN',
			'RIDEN Settings',
			'manage_options',
			$this->plugin_slug,
			array( $this, 'riden_create_admin_page' )
		);
	}

	/**
	 * Options page callback
	 */
	public function riden_create_admin_page() {

		if ( isset($_POST['submit']) && 'Save Changes' == $_POST['submit'] && isset($_POST[$this->setting_slug]) ) {

			$tmp_arr = array(
				'whitelist'   => $_POST[$this->setting_slug]['whitelist'],
				'blacklist'   => $_POST[$this->setting_slug]['blacklist'],
				'blacklistbl' => array(array(
					'domain'  => 'rtidefense.net',
					'format'  => 'riden',
					'reverse' => true,
				)),
				'threshold'   => $_POST[$this->setting_slug]['threshold'],
				'uuid'        => $_POST[$this->setting_slug]['client_uuid'],
			);

			$bad_setting_count = $this->sanitize($tmp_arr);

			# ok save the setting
			$tmp_arr['whitelist'] = explode(',', $tmp_arr['whitelist']);
			$tmp_arr['blacklist'] = explode(',', $tmp_arr['blacklist']);

			foreach ( $tmp_arr as $key => $value ) {
				update_option('rsc_riden_' . $key, $value);
			}

			if ( $bad_setting_count > 0 ) {
				echo '<div class="notice notice-error"><p>Some settings have not been saved.</p></div>';
			} else {
				echo '<div class="notice notice-success is-dismissible"><p>Your changes have been saved.</p></div>';
			}

		}

		foreach ( $this->options as $key => $value ) {
			$this->options[$key] = get_option('rsc_riden_' . $key);
		}
		$this->options['whitelist'] = implode(',', $this->options['whitelist']);
		$this->options['blacklist'] = implode(',', $this->options['blacklist']);
		?>
		<div class="wrap">
			<h1>RIDEN Settings</h1>
			<form method="post">
			<?php
				# This prints out all hidden setting fields
				settings_fields($this->plugin_slug);
				do_settings_sections($this->plugin_slug);
				submit_button();
			?>
			</form>
		</div>
		<?php
	}

	/**
	 * Register and add settings
	 */
	public function riden_page_init() {

		register_setting(
			$this->plugin_slug,
			$this->setting_slug,
			null  # array('sanitize_callback' => array($this, 'sanitize'))             # sanitize function
		);

		add_settings_section(
			$this->stats_slug,                    # ID
			'RIDEN Statistics',                   # Title
			array($this, 'print_stats_info'),     # Callback
			$this->plugin_slug                    # Page
		);

		add_settings_section(
			$this->section_slug,                  # ID
			'RIDEN Options',                      # Title
			array($this, 'print_section_info'),   # Callback
			$this->plugin_slug                    # Page
		);

		# whitelist field
		add_settings_field(
			'whitelist',
			'Whitelist (comma (,) separated list)',
			array($this, 'whitelist_callback'),
			$this->plugin_slug,
			$this->section_slug
		);

		# blacklist field
		add_settings_field(
			'blacklist',
			'Blacklist (comma (,) separated list)',
			array($this, 'blacklist_callback'),
			$this->plugin_slug,
			$this->section_slug
		);

		# threshold
		add_settings_field(
			'threshold',
			'threshold score (127 is 50%)',
			array($this, 'threshold_callback'),
			$this->plugin_slug,
			$this->section_slug
		);

		# UUID
		add_settings_field(
			'client_uuid',
			'Your RIDEN UUID',
			array($this, 'uuid_callback'),
			$this->plugin_slug,
			$this->section_slug
		);

	}

	/**
	 * Sanitize each setting field as needed
	 *
	 * @param array $input Contains all settings fields as array keys
	 */
	public function sanitize( &$input ) {

		$bad_setting_count = 0;

		if ( 1 !== preg_match('/^[0-9\/,.]*$/', $input['whitelist']) ) {
			echo '<div class="notice notice-error">Whitelist not saved. It should be a comma-separated list of IPv4 addresses (CIDR notation is okay).</div>';
			$input['whitelist'] = get_option('rsc_riden_whitelist');
			$bad_setting_count++;
		}

		if ( 1 !== preg_match('/^[0-9\/,.]*$/', $input['blacklist']) ) {
			echo '<div class="notice notice-error">Blacklist not saved. It should be a comma-separated list of IPv4 addresses (CIDR notation is okay).</div>';
			$input['blacklist'] = get_option('rsc_riden_blacklist');
			$bad_setting_count++;
		}

		if ( 1 !== preg_match('/^[A-F0-9]{32}$/', $input['uuid']) ) {
			echo '<div class="notice notice-error">UUID not saved. It should be 32 characters long and only contain 0-9 and A-F.</div>';
			$input['uuid'] = get_option('rsc_riden_uuid');
			$bad_setting_count++;
		}

		$input['threshold'] = (int) $input['threshold'];
		if ( $input['threshold'] < 0 || $input['threshold'] > 255 ) {
			echo '<div class="notice notice-error">Threshold not saved. It should be a number between 0 and 255.</div>';
			$input['threshold'] == get_option('rsc_riden_threshold');
			$bad_setting_count++;
		}

		return $bad_setting_count;

	}

	/**
	 * Print the Section text
	 */
	public function print_section_info() {
		print 'Enter your settings below:';
	}

	/**
	 * Print the Stats text
	 */
	public function print_stats_info() {

		define('RIDEN_API_URL', 'https://riden.io/api');

		$uuid = $this->options['uuid'];
		if ( !$uuid ) {
			echo '<div class="notice notice-error">ERROR UUID not set</div>';
			return false;
		}

		$dateStart      = date('M j Y', strtotime('30 days ago'));
		$dayNumStart    = (int) date('j', strtotime('30 days ago'));
		$dateEnd        = date('M j');
		$queryDateStart = date('Y-m-d', strtotime('30 days ago'));
		$queryDateEnd   = date('Y-m-d');

		$results = array();
		foreach ( array('submissions', 'queries', 'assists', 'blocks') as $metric ) {
			$url = RIDEN_API_URL . "/account/$uuid/stats/$metric?start_date=$queryDateStart&end_date=$queryDateEnd";
			$response = wp_remote_get($url);
			if ( is_wp_error($response) ) {
				$error_message = $response->get_error_message();
				if ( false !== stripos($error_message, 'operation timed out') ) {
					echo '<div class="notice notice-error">Graphs are currently unavailable because the RIDEN API is busy. Please check back again in a few minutes.</div>';
				} else {
					echo '<div class="notice notice-error">API request failed: "' . $response->get_error_message() . '". URL: ' . $url . '</div>';
				}
				return false;
			}
			elseif ( 200 !== $response['response']['code'] ) {
				echo '<div class="notice notice-error">RIDEN API returned an error: "' . $response['response']['code'] . ' ' . $response['response']['message'] . '". URL: ' .  $url . '</div>';
				return false;
			}
			$results[ $metric ] = json_decode($response['body'], true);
		}

		//	Reporting.
		$submissions = $results['submissions'];
		$assists = $results['assists'];
		//	Protection.
		$queries = $results['queries'];
		$blocks = $results['blocks'];

		$dayCnt = 0;
		$labels = array();
		$submissions_data = array();
		$queries_data     = array();
		$assists_data     = array();
		$blocks_data      = array();

		for ( $i = $dayNumStart; $i <= $dayNumStart + 30; $i++ ) {

			if ( $i == $dayNumStart ) {
				$labels[$i] = $dateStart;
			} elseif ( $i == $dayNumStart + 30 ) {
				$labels[$i] = $dateEnd;
			} else {
				$labels[$i] = date('j', strtotime($dateStart . ' +' . $dayCnt . 'days'));
			}

			$dataXrefDate = date('Y-m-d', strtotime($dateStart . ' +' . $dayCnt . 'days'));

			$date_index = array_search($dataXrefDate, array_column($submissions, 'interval'));
			if ( false !== $date_index ) {
				$submissions_data[ $i ] = $submissions[ $date_index ]['valid'];
			} else {
				$submissions_data[ $i ] = 0;
			}

			$date_index = array_search($dataXrefDate, array_column($queries, 'interval'));
			if ( false !== $date_index ) {
				$queries_data[ $i ] = $queries[ $date_index ]['queries'];
			} else {
				$queries_data[ $i ] = 0;
			}

			$date_index = array_search($dataXrefDate, array_column($assists, 'interval'));
			if ( false !== $date_index ) {
				$assists_data[ $i ] = $assists[ $date_index ]['assists'];
			} else {
				$assists_data[ $i ] = 0;
			}

			$date_index = array_search($dataXrefDate, array_column($blocks, 'interval'));
			if ( false !== $date_index ) {
				$blocks_data[ $i ] = $blocks[ $date_index ]['blocks'];
			} else {
				$blocks_data[ $i ] = 0;
			}

			$dayCnt++;
		}

		//	Display graphs depending on what the user has enabled.
		//	The top 2 graphs are "Protection" graphs, the bottom 2 are "Reporting" graphs.

		echo '<script src="' . plugin_dir_url(__FILE__) . 'js/chart.bundle.min.js"></script>';
		echo '<div>';

		if ( count($queries) || count($blocks) ) { ?>

		<style type="text/css">

			.userFormWrapper {
				background      : #fefefe none repeat scroll 0 0;
				border          : 1px solid #ebebeb;
				color           : #555;
				display         : flex;
				flex-flow       : row wrap;
				justify-content : space-around;
				margin          : 0 auto 20px;
				max-width       : 600px;
				min-width       : 245px;
				padding         : 75px 30px 30px;
				position        : relative;
			}

			.userFormWrapper > *, {
				flex-basis: 100%;
				text-align: center;
			}

			.userFormWrapper > ol {
				margin-bottom : 60px;
				padding-left  : 20px;
				text-align    : left;
			}

			.jsChartWrapper {
				border         : 1px solid #ebebeb;
				display        : inline-block;
				margin         : 0 0 20px;
				position       : relative;
				vertical-align : top;
				width          : 48%;
			}

			.chartJsHeadingIcon, .chartJsHeadingTxt, .userFormWrapper > .userFormTitle, .userFormWrapper > .userFormTitle > i {
				background-color : #f5f5f5;
				height           : 55px;
				position         : absolute;
				top              : 0;
			}

			.chartJsHeadingIcon{ border-bottom: 1px solid #ebebeb !important; }

			.chartJsHeadingIcon, div.userFormWrapper > div.userFormTitle > i.userFormTitleIco {
				border-bottom : none;
				border-right  : 1px solid #ebebeb;
				font-size     : 30px;
				height        : 67px;
				left          : 0;
				width         : 65px;
				z-index       : 2;
			}

			.chartJsHeadingTxt {
				font-family : "Open Sans",Arial,sans-serif;
				font-size   : 18px;
				height      : 55px;
				left        : 10%;
				padding-top : 14px;
				width       : 90%;
				z-index     : 9;
			}

			.jsChartType1{
				margin-top: 40px;
			}

			.chartJsNoData {
				display          : block;
				border           : 1px solid #ddd;
				padding          : 10px;
				border-radius    : 5px;
				background-color : #f8f8f8;
			}

			.chartJsFooterTxt {
				display : inline-block;
				height  : 60px;
			}

			/*********** WordPress Icons for Charts ***********/

			.dashicons-editor-help {
				content     : "\f223";
				padding-top : 10px;
			}
			.dashicons-shield-alt {
				content     : "\f334";
				padding-top : 10px;
			}

		</style>

			<table style="width: 940px;">
				<tr>
					<td width="50%" align="center">

						<div class="userFormWrapper jsChartWrapper" style="width: 400px">
							<div class="chartJsHeadingIcon"><span class="dashicons dashicons-editor-help" style="margin-left: 5px"></span></i></div>
							<div class="chartJsHeadingTxt" style="text-align: left; padding-left: 10px">My Queries: Last 30 Days</div>
							<canvas id="myChart2" class="jsChartType1" width="250" height="250" style="float: left"></canvas>
							<p class="chartJsFooterTxt">Shows how many times your server has checked connection requests with RIDEN.</p>
						</div>

					</td><td width="50%" align="center">

						<div class="userFormWrapper jsChartWrapper" style="width: 400px">
							<div class="chartJsHeadingIcon"><span class="dashicons dashicons-shield-alt" style="margin-left: 5px"></span></i></div>
							<div class="chartJsHeadingTxt" style="text-align: left; padding-left: 10px">My Blocks: Last 30 Days</div>
							<canvas id="myChart4" width="250" class="jsChartType1" height="250"></canvas>
							<p class="chartJsFooterTxt">Shows how many IP connection requests to your server were blocked by RIDEN.</p>
						</div>

					</td>
				</tr>
			</table>

			<script>
				Chart.defaults.global.legend.display = false;

				var ctx = document.getElementById("myChart2");
				var myChart2 = new Chart(ctx, {
					type: 'line',
					data: {
						labels: ["<?php echo implode('","', $labels); ?>"],
						datasets: [{
							data: [<?php echo implode(',', $queries_data); ?>],
							backgroundColor: ['rgba(110, 184, 219, 0.7)'],
							borderColor: ['rgba(4,110,150,1)'],
							borderWidth: 1
						}]
					},
					options: {
						scales: {
							yAxes: [{
								ticks: {
									beginAtZero:true
								}
							}]
						}
					}
				});

				var ctx = document.getElementById("myChart4");
				var myChart4 = new Chart(ctx, {
					type: 'line',
					data: {
						labels: ["<?php echo implode('","', $labels); ?>"],
						datasets: [{
							label: '',
							data: [<?php echo implode(',', $blocks_data); ?>],
							backgroundColor: ['rgba(255, 172, 145, .6)'],
							borderColor: ['rgba(255, 87, 34, 1)'],
							borderWidth: 1
						}]
					},
					options: {
						scales: {
							yAxes: [{
								ticks: {
									beginAtZero:true
								}
							}]
						}
					}
				});
			</script>

			<?php
		} else {
			?>

			<table style="width: 940px;">
				<tr>
					<td style="width: 50%" align="center">

						<div class="userFormWrapper jsChartWrapper" style="width: 400px">
							<div class="chartJsHeadingIcon"><i class="fa fa-question-circle-o" aria-hidden="true"></i></div>
							<div class="chartJsHeadingTxt" style="text-align: left; padding-left: 10px">My Queries: Last 30 Days</div>
							<p class="chartJsNoData">Seeing no data here? It's likely because you haven't installed RIDEN Protection. Please go to the downloads page and install "Protection".</p>
							<p class="chartJsFooterTxt">Shows how many times your server has checked connection requests with RIDEN.</p>
						</div>

					</td><td style="width: 50%" align="center">

						<div class="userFormWrapper jsChartWrapper" style="width: 400px">
							<div class="chartJsHeadingIcon"><i class="fa fa-shield" aria-hidden="true"></i></i></div>
							<div class="chartJsHeadingTxt" style="text-align: left; padding-left: 10px">My Blocks: Last 30 Days</div>
							<p class="chartJsNoData">Seeing no data here? It's likely because you haven't installed RIDEN Protection. Please go to the downloads page and install "Protection".</p>
							<p class="chartJsFooterTxt">Shows how many IP connection requests to your server were blocked by RIDEN.</p>
						</div>

					</td>
				</tr>
			</table>

			<?php
		}

		/*
		if ( count($submissions) || count($assists) ) {
			?>

			<table style="width: 940px;">
				<tr>
					<td style="width: 50%" align="center">

						<div class="userFormWrapper jsChartWrapper" style="width: 400px">
							<div class="chartJsHeadingIcon"><i class="fa fa-university" aria-hidden="true"></i></i></div>
							<div class="chartJsHeadingTxt" style="text-align: left; padding-left: 10px">My Submissions: Last 30 Days</div>
							<canvas id="myChart1" width="250" class="jsChartType1" height="250"></canvas>
							<p class="chartJsFooterTxt">Shows how many IP addresses you reported to RIDEN as suspicious.</p>
						</div>

					</td><td style="width: 50%" align="center">

						<div class="userFormWrapper jsChartWrapper" style="width: 400px">
							<div class="chartJsHeadingIcon"><i class="fa fa-users" aria-hidden="true"></i></i></div>
							<div class="chartJsHeadingTxt" style="text-align: left; padding-left: 10px">My Assists: Last 30 Days</div>
							<canvas id="myChart3" width="250" class="jsChartType1" height="250"></canvas>
							<p class="chartJsFooterTxt">Shows how many server connection requests to RIDEN users were blocked due to suspicious activity that was reported by you.</p>
						</div>

					</td>
				</tr>
			</table>

			<script>

				Chart.defaults.global.legend.display = false;
				var ctx = document.getElementById("myChart1");
				var myChart1 = new Chart(ctx, {
					type: 'line',
					data: {
						labels: ["<?php echo implode('","', $labels); ?>"],
						datasets: [{
							label: '',
							data: [<?php echo implode(',', $submissions_data); ?>],
							backgroundColor: ['rgba(242, 188, 138, .6)'],
							borderColor: ['rgba(230, 126, 34, 1)'],
							borderWidth: 1
						}]
					},
					options: {
						scales: {
							yAxes: [{
								ticks: {
									beginAtZero:true
								}
							}]
						}
					}
				});

				var ctx = document.getElementById("myChart3");
				var myChart3 = new Chart(ctx, {
					type: 'line',
					data: {
						labels: ["<?php echo implode('","', $labels); ?>"],
						datasets: [{
							label: '',
							data: [<?php echo implode(',', $assists_data); ?>],
							backgroundColor: ['rgba(163, 209, 208, .6)'],
							borderColor: ['rgba(78, 159, 157, 1)'],
							borderWidth: 1
						}]
					},
					options: {
						scales: {
							yAxes: [{
								ticks: {
									beginAtZero:true
								}
							}]
						}
					}
				});

			</script>

			<?php

		} else {
			?>

			<table style="width: 940px;">
				<tr>
					<td style="width: 50%" align="center">

						<div class="userFormWrapper jsChartWrapper">
							<div class="chartJsHeadingIcon"><i class="fa fa-university" aria-hidden="true"></i></i></div>
							<div class="chartJsHeadingTxt" style="text-align: left; padding-left: 10px">My Submissions: Last 30 Days</div>
							<p class="chartJsNoData">Seeing no data here? It's likely because you haven't installed RIDEN Reporting. Please go to the downloads page and install "Reporting".</p>
							<p class="chartJsFooterTxt">Shows how many IP addresses you reported to RIDEN as suspicious.</p>
						</div>

					</td><td style="width: 50%" align="center">

						<div class="userFormWrapper jsChartWrapper">
							<div class="chartJsHeadingIcon"><i class="fa fa-users" aria-hidden="true"></i></i></div>
							<div class="chartJsHeadingTxt" style="text-align: left; padding-left: 10px">My Assists: Last 30 Days</div>
							<p class="chartJsNoData">Seeing no data here? It's likely because you haven't installed RIDEN Reporting. Please go to the downloads page and install "Reporting".</p>
							<p class="chartJsFooterTxt">Shows how many server connection requests to RIDEN users were blocked due to suspicious activity that was reported by you.</p>
						</div>

					</td>
				</tr>
			</table>

			<?php
		}

	*/	//	End commented-out block for printing reporting stats. (Not yet fully implemented.)

	}

	/**
	 * Get the settings option array and print one of its values
	 */
	public function whitelist_callback() {
		printf(
			'<input type="text" id="whitelist" name="rsc-riden-wp-options[whitelist]" value="%s" size="50" />',
			isset($this->options['whitelist'] ) ? esc_attr( $this->options['whitelist']) : ''
		);
	}

	/**
	 * Get the settings option array and print one of its values
	 */
	public function blacklist_callback() {
		printf(
			'<input type="text" id="blacklist" name="rsc-riden-wp-options[blacklist]" value="%s" size="50" />',
			isset($this->options['blacklist'] ) ? esc_attr( $this->options['blacklist']) : ''
		);
	}

	/**
	 * Get the settings option array and print one of its values
	 */
	public function threshold_callback() {
		printf(
			'<input type="text" id="threshold" name="rsc-riden-wp-options[threshold]" value="%s" maxlength="3" size="4" />',
			isset($this->options['threshold'] ) ? esc_attr( $this->options['threshold']) : ''
		);
	}

	/**
	 * Get the settings option array and print one of its values
	 */
	public function uuid_callback() {
		printf(
			'<input type="text" id="client_uuid" name="rsc-riden-wp-options[client_uuid]" value="%s" maxlength="32" size="33" />',
			isset( $this->options['uuid'] ) ? esc_attr( $this->options['uuid']) : ''
		);
	}

}

$rsc_riden_settings = new rsc_riden_settings();
