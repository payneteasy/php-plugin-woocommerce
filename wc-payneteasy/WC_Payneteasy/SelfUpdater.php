<?php

namespace WC_Payneteasy;

if (!defined('ABSPATH')) exit;

use Payneteasy\PneApi;

add_filter('plugins_api', ['WC_Payneteasy\SelfUpdater', 'hook_plugin_update_info'], 20, 3);
add_filter('pre_set_site_transient_update_plugins', ['WC_Payneteasy\SelfUpdater', 'hook_plugin_check_version']);
if ($host = PneApi::debug_host())
	add_filter('http_request_args', function($parsed_args, $url) use ($host) {
		if (parse_url($url, PHP_URL_HOST) === $host)
			$parsed_args['reject_unsafe_urls'] = false;
		return $parsed_args;
	}, 10, 2);

class SelfUpdater {
	private const GITHUB_REPO = 'payneteasy/php-plugin-woocommerce';

	public static function hook_plugin_update_info($rv, $action, $args) {
		if ('plugin_information' != $action || PNE_PLUGIN_SLUG != $args->slug)
			return $rv;

		if (empty($json = self::fetch_update_json($rv)))
			return $rv;

		return (object)($json += [ 'download_link' => self::update_url($json['version']) ]);
	}

	public static function hook_plugin_check_version(\stdClass $C): \stdClass {
		if (empty($C->checked))
			return $C;

		if (empty($json = self::fetch_update_json()))
			return $C;

		$Remote = (object)$json;

		if (version_compare($Remote->version, $C->checked[$entry = PNE_PLUGIN_BASENAME], '>')) {
			$is_pkg_avail = wp_remote_head($pkg_url = self::update_url($Remote->version));

			$C->response[$entry] = (object)[
				'url' => 'https://github.com/payneteasy/php-plugin-woocommerce/blob/main/README.md',
				'icons' => [ 'default' => PNE_PLUGIN_URL.'assets/img/payneteasy.png' ],
				'slug' => $Remote->slug,
				'plugin' => $entry,
				'tested' => $Remote->tested,
				'requires' => $Remote->requires,
				'new_version' => $Remote->version,
				'requires_php' => $Remote->requires_php,
				'package' => (is_wp_error($is_pkg_avail) || !in_array(wp_remote_retrieve_response_code($is_pkg_avail), [ 200, 302 ])) ? '' : $pkg_url ];
		}
		else
			$C->no_update[$entry] = (object)[ 'slug' => $Remote->slug, 'plugin' => $entry, 'new_version' => $Remote->version ];

		return $C;
	}

	private static function update_url(string $plg_ver=null): string {
		if ($host = PneApi::debug_host())
			return $plg_ver
				? "http://$host/".PNE_PLUGIN_SLUG.'.zip' # "-v$plg_ver.zip"
				: "http://$host/update.json";

		return sprintf($plg_ver
				? 'https://github.com/%s/releases/download/v%s/%s.zip' # -v%2$s.zip'
				: 'https://raw.githubusercontent.com/%s/refs/heads/main/installation/update.json',
			self::GITHUB_REPO, $plg_ver, PNE_PLUGIN_SLUG);
	}

	private static function fetch_update_json($failret = null): ?array {
		if (!($json_str = get_transient(self::GITHUB_REPO))) {
			if (!empty($json_str = wp_remote_retrieve_body( wp_remote_get(self::update_url()) )))
				set_transient(self::GITHUB_REPO, $json_str, HOUR_IN_SECONDS);
			else
				return $failret;
		}

		return json_decode($json_str, true);
	}
}
