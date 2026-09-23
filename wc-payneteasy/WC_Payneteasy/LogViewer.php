<?php

namespace WC_Payneteasy;

if (!defined('ABSPATH')) exit;

add_filter('heartbeat_received', ['WC_Payneteasy\LogViewer', 'hook_heartbeat_received'], 10, 2);
if (('wc-status' == ($_GET['page'] ?? '')) || (wp_doing_ajax() && 'pne_log_viewer_fetch' == ($_REQUEST['action'] ?? ''))) {
	add_filter('woocommerce_admin_status_tabs', ['WC_Payneteasy\LogViewer', 'hook_status_tabs']);
	add_action('woocommerce_admin_status_content_pne-log', ['WC_Payneteasy\LogViewer', 'show']);
	add_action('wp_ajax_pne_log_viewer_fetch', ['WC_Payneteasy\LogViewer', 'ajax_fetch']);
	add_action('admin_enqueue_scripts', ['WC_Payneteasy\LogViewer', 'hook_enqueue_admin_scripts']);
}

use \Automattic\WooCommerce\Utilities\OrderUtil;
class LogViewer { use \PneString;

	private const TAB = 'pne-log';
	private const ENTRY_START = '/^(\S+) (\w+) (.*)$/';
	private const PER_PAGE_OPTIONS = [ 10, 25, 50, 100 ];
	private const OPTION_PER_PAGE = 'pne_log_viewer_per_page';

	public static function hook_heartbeat_received(array $response, array $data): array {
		if (!isset($data['pne_log_date']) || !current_user_can('manage_woocommerce'))
			return $response;

		if (in_array($data['pne_log_date'], self::get_dates(), true))
			$response['pne_log_counts'] = self::get_counts($data['pne_log_date']);

		return $response;
	}

	public static function hook_status_tabs(array $tabs): array
		{ return $tabs + [ self::TAB => self::_T('Payneteasy Log') ]; }

	public static function hook_enqueue_admin_scripts(string $hook): void {
		if ('woocommerce_page_wc-status' != $hook || ($_GET['tab'] ?? '') != self::TAB)
			return;

		wp_enqueue_script('payneteasy-log-viewer', PNE_PLUGIN_URL.'assets/js/LogViewer.js', ['jquery', 'heartbeat'], PNE_PLUGIN_VERSION, true);
		wp_localize_script('payneteasy-log-viewer', 'pneLogViewer', [ 'ajaxUrl' => admin_url('admin-ajax.php?payneteasy_logviewer_poll'), 'nonce' => wp_create_nonce('pne_log_viewer') ]);
		wp_enqueue_style('payneteasy-log-viewer', PNE_PLUGIN_URL.'assets/css/LogViewer.css', [], PNE_PLUGIN_VERSION);
	}

	public static function show(): void {
		$dates = self::get_dates();

		if (!$dates) {
			echo '<p>'.self::_T('No log entries yet.').'</p>';
			return;
		}

		$date = in_array($_GET['pne_log_date'] ?? '', $dates)
			? $_GET['pne_log_date']
			: $dates[0];

		$per_page = in_array((int)($_GET['pne_log_per_page'] ?? 0), self::PER_PAGE_OPTIONS, true)
			? (int)$_GET['pne_log_per_page']
			: self::default_per_page();

		$page = max(1, (int)($_GET['pne_log_page'] ?? 1));

		[ $html, $types, $total ] = self::build_entries($date, $page, $per_page);

		?>
		<select id="pne-log-date-select">
			<?= implode('', array_map(fn($d) => '<option value="'.esc_attr($d).'"'.($d == $date ? ' selected' : '').'>'.esc_html($d).'</option>', $dates)) ?>
		</select>
		<button type="button" class="button" id="pne-log-toggle-all"><?= self::_T('Expand all') ?></button>
		<span id="pne-log-type-filters"><?= self::render_type_filters($types) ?></span>
		<span id="pne-log-page-select-wrap"><?= self::render_page_select($page, $per_page, $total) ?></span>
		<?= self::render_per_page_select($per_page) ?>
		<br><br>
		<div id="pne-log"><?= $html ?></div>
		<?php
	}

	public static function ajax_fetch(): void {
		check_ajax_referer('pne_log_viewer', 'nonce');

		if (!current_user_can('manage_woocommerce'))
			wp_die('', '', 403);

		if (!in_array($date = $_POST['date'] ?? '', self::get_dates(), true))
			wp_die('', '', 400);

		$per_page = in_array((int)($_POST['per_page'] ?? 0), self::PER_PAGE_OPTIONS, true) ? (int)$_POST['per_page'] : self::default_per_page();
		$page = max(1, (int)($_POST['page'] ?? 1));
		$checked = isset($_POST['types']) ? array_filter(explode(',', $_POST['types'])) : null;

		update_option(self::OPTION_PER_PAGE, $per_page);

		[ $html, $types, $total ] = self::build_entries($date, $page, $per_page);

		wp_send_json([
			'html' => $html,
			'filters' => self::render_type_filters($types, $checked),
			'pageSelect' => self::render_page_select($page, $per_page, $total) ]);
	}

	private static function log_file_pattern(string $date = '(\d{4}-\d{2}-\d{2})'): string
		{ return '/^'.preg_quote(PNE_PLUGIN_ID, '/').'-'.$date.'-[0-9a-f]+\.log$/'; }

	private static function get_dates(): array {
		$dates = [];

		foreach (scandir(WC_LOG_DIR) ?: [] as $f)
			if (preg_match(self::log_file_pattern(), $f, $m))
				$dates[] = $m[1];

		rsort($dates);

		return $dates;
	}

	private static function default_per_page(): int {
		$saved = (int)get_option(self::OPTION_PER_PAGE, 0);

		return in_array($saved, self::PER_PAGE_OPTIONS, true)
			? $saved
			: self::PER_PAGE_OPTIONS[0];
	}

	private static function read_entries(string $date): array {
		foreach (scandir(WC_LOG_DIR) ?: [] as $f)
			if (preg_match(self::log_file_pattern(preg_quote($date, '/')), $f))
				return array_reverse(self::parse_entries(@file_get_contents(WC_LOG_DIR.$f) ?: ''));

		return [];
	}

	private static function count_by_type(array $entries): array {
		$counts = [];
		foreach ($entries as $entry) {
			$level = strtoupper($entry['level']);
			$counts[$level] = ($counts[$level] ?? 0) + 1;
		}

		ksort($counts);
		return $counts;
	}

	private static function get_counts(string $date): array
		{ return self::count_by_type(self::read_entries($date)); }

	private static function build_entries(string $date, int $page, int $per_page): array {
		$entries = self::read_entries($date);

		$html = '';
		foreach (array_slice($entries, ($page - 1) * $per_page, $per_page) as $entry)
			$html .= self::render_entry($entry);

		return [ $html ?: '<p>'.self::_T('No log entries yet.').'</p>', self::count_by_type($entries), count($entries) ];
	}

	private static function render_per_page_select(int $per_page): string {
		return '<select id="pne-log-per-page-select">'
			.implode('', array_map(fn($n) => '<option value="'.$n.'"'.($n == $per_page ? ' selected' : '').'>'.$n.'</option>', self::PER_PAGE_OPTIONS))
			.'</select>';
	}

	private static function render_page_select(int $page, int $per_page, int $total): string {
		$total_pages = max(1, (int)ceil($total / $per_page));
		$page = min(max($page, 1), $total_pages);

		return '<select id="pne-log-page-select">'
			.implode('', array_map(fn($p) => '<option value="'.$p.'"'.($p == $page ? ' selected' : '').'>'.$p.'</option>', range(1, $total_pages)))
			.'</select>';
	}

	private static function render_type_filters(array $counts, ?array $checked = null): string {
		ob_start();

		foreach ($counts as $type => $count):
			$is_checked = null === $checked || in_array($type, $checked, true);
			?><label class="pne-log-type-filter-label<?= 'ERROR' == $type ? ' pne-log-is-error' : '' ?>">
					<input type="checkbox" class="pne-log-type-filter" value="<?= esc_attr($type) ?>"<?= $is_checked ? ' checked' : '' ?>>
						<?= esc_html($type) ?>
						<span class="pne-log-type-count-wrap">
							<span class="pne-log-type-count" data-type="<?= esc_attr($type) ?>"><?= $count ?></span>
							<span class="pne-log-type-delta" data-type="<?= esc_attr($type) ?>"></span>
						</span>
				</label><?php
		endforeach;

		return ob_get_clean();
	}

	private static function parse_entries(string $content): array {
		foreach (explode("\n", $content) as $line)
			if (preg_match(self::ENTRY_START, $line, $m))
				$entries[] = [ 'time' => $m[1], 'level' => $m[2], 'text' => $m[3] ];
			elseif (isset($entries) && $line !== '')
				$entries[count($entries)-1]['text'] .= "\n$line";

		return $entries ?? [];
	}

	private static function render_entry(array $entry): string {
		$time = substr($entry['time'], 11, 8);

		if ('[' == substr($entry['text'], 0, 1))
			return self::render_struct($entry['level'], $time, json_decode($entry['text'], true));
		else
			return self::render_plain($entry['level'], $time, $entry['text']);
	}

	private static function render_struct(string $level, string $time, array $json): string {
		$html = "<div data-level='$level'><details><summary><time>$time</time>".esc_html(array_shift($json)).'</summary>';

		foreach ($json as $hash)
			$html .= '<table>'
				.implode('', array_map(fn($k) => '<tr><th>'.esc_html($k).'</th><td>'.esc_html((string)$hash[$k]).'</td></tr>', array_keys($hash)))
				.'</table>';

		return $html.'</details></div>';
	}

	private static function render_plain(string $level, string $time, string $text): string {
		$no_link = preg_match('/\\bOrder #\\d+ not found\\b/', $text);
		return "<div data-level='$level'><time>$time</time>"
			.preg_replace_callback('!^(..)(\\w+)/(.)(\\w+) !',
					fn($m) => "<code class='{$m[1]}{$m[3]}'>{$m[1]}{$m[2]}/{$m[3]}{$m[4]}</code> ",
				$no_link
					? $text
					: preg_replace_callback('!\\b(client_orderid=)(\d+)\\b!',
						fn($m) => "<a href='".OrderUtil::get_order_admin_edit_url($m[2])."'>{$m[1]}{$m[2]}</a>", $text))
			.'</div>';
	}
}
