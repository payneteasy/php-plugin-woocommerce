<?php

namespace WC_Payneteasy;

if (!defined('ABSPATH')) exit;

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
				'plugin' => $entry,
				'slug' => $Remote->slug,
				'tested' => $Remote->tested,
				'requires' => $Remote->requires,
				'new_version' => $Remote->version,
				'requires_php' => $Remote->requires_php,
				'url' => 'https://github.com/payneteasy/php-plugin-woocommerce/blob/main/README.md',
				'icons' => [ 'default' => PNE_PLUGIN_URL.'payneteasy.png' ],
				'package' => (is_wp_error($is_pkg_avail) || !in_array(wp_remote_retrieve_response_code($is_pkg_avail), [ 200, 302 ])) ? '' : $pkg_url ];
		}
		else
			$C->no_update[$entry] = (object)[ 'slug' => $Remote->slug, 'plugin' => $entry, 'new_version' => $Remote->version ];

		return $C;
	}

	private static function update_url(string $plg_ver=null): string {
		return sprintf($plg_ver
			? 'https://github.com/%s/releases/download/v%s/%s.zip'
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
