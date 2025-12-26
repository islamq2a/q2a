<?php
/*
	Plugin Name: IslamQ2A Stats Dashboard
	Plugin URI:
	Plugin Description: Lightweight on-demand admin stats dashboard for Question2Answer.
	Plugin Version: 1.0.0
	Plugin Date: 2024-01-01
	Plugin Author: IslamQ2A
	Plugin Author URI:
	Plugin License: MIT
	Plugin Minimum Question2Answer Version: 1.8
	Plugin Update Check URI:
*/

if (!defined('QA_VERSION')) {
	header('Location: ../../');
	exit;
}

qa_register_plugin_module(
	'page',
	'islamq2a-stats-dashboard.php',
	'qa_islamq2a_stats_dashboard',
	'IslamQ2A Stats Dashboard'
);
