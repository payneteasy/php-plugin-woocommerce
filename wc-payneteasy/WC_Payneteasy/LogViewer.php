<?php

namespace WC_Payneteasy;

if (!defined('ABSPATH')) exit;

class LogViewer { use \PneString;

	private const TAB = 'pne-log';
	private const ENTRY_START = '/^(\S+) (\w+) (.*)$/';
	private const PER_PAGE_OPTIONS = [ 10, 25, 50, 100 ];
	private const OPTION_PER_PAGE = 'pne_log_viewer_per_page';

	public static function hook_status_tabs(array $tabs): array
		{ return $tabs + [ self::TAB => self::_T('Payneteasy Log') ]; }

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
		<style>
			.pne-log-saa{color:#0A0;font-weight:bold}
			.pne-log-sad,.pne-log-prd{color:#F00}
			.pne-log-sap,.pne-log-sau,.pne-log-sac,.pne-log-prp,.pne-log-pru,.pne-log-prc{color:#09F}
			.pne-log-rea,.pne-log-cha,.pne-log-voa{background:#000;color:#F80;padding:0 8px;border-radius:6px}
			.pne-log-cha{color:#F00}
			.pne-log-voa{color:#FF0}
			.pne-log-sae,.pne-log-pre{color:#F00;text-decoration:line-through}

			.pne-log-toggle{display:inline-block;width:14px}
			#pne-log-toggle-all{width:110px;white-space:nowrap}

			.pne-log-type-filter-label{cursor:pointer;padding:2px 6px;border-radius:3px;transition:background-color .15s;color:#0073AA}
			.pne-log-type-filter-label.pne-log-is-error{color:#C00}
			.pne-log-type-filter-label:hover{background-color:rgba(0,0,0,.06)}

			.pne-log-entry-header{cursor:pointer;user-select:none;padding:2px 6px;margin-left:-6px;border-radius:3px;transition:background-color .15s;color:#0073AA}
			.pne-log-entry-header.pne-log-is-error{color:#C00}
			.pne-log-entry-header:hover{background-color:rgba(0,0,0,.06)}
			.pne-log-entry-header-static{color:#0073AA;padding-left:14px}
			.pne-log-entry-header-static.pne-log-is-error{color:#C00}

			.pne-log-type-count-wrap{display:inline-flex;flex-direction:column;margin-left:8px;vertical-align:middle;line-height:1.15}
			.pne-log-type-count{font-weight:normal;color:initial;font-family:monospace}
			.pne-log-type-delta{font-size:10px;color:#0A0;font-family:monospace}

			.pne-log-entry{border-left:4px solid #0073AA;padding:8px 12px;margin-bottom:8px;background:#FFF}
			.pne-log-entry.pne-log-is-error{border-left-color:#C00}
			.pne-log-entry-body{display:none;white-space:pre-wrap;margin:4px 0 0;font-family:monospace}
			.pne-log-entry-lead{font-family:monospace;color:initial;font-weight:normal}
			.pne-log-entry-tail{font-family:monospace;margin-left:8px}

			.pne-log-json-string{color:#183691}
			.pne-log-json-value{color:#0086B3}

			.pne-log-error-table{border-collapse:collapse;margin:0 0 8px}
			.pne-log-error-table:last-child{margin-bottom:0}
			.pne-log-error-table tr:nth-child(even){background:rgba(0,0,0,.04)}
			.pne-log-error-table td{padding:2px 8px;vertical-align:top}
			.pne-log-error-table td:first-child{color:#666;white-space:nowrap}
		</style>
		<select id="pne-log-date-select">
			<?= implode('', array_map(fn($d) => '<option value="'.esc_attr($d).'"'.($d == $date ? ' selected' : '').'>'.esc_html($d).'</option>', $dates)) ?>
		</select>
		<button type="button" class="button" id="pne-log-toggle-all"><?= self::_T('Expand all') ?></button>
		<span id="pne-log-type-filters"><?= self::render_type_filters($types) ?></span>
		<span id="pne-log-page-select-wrap"><?= self::render_page_select($page, $per_page, $total) ?></span>
		<?= self::render_per_page_select($per_page) ?>
		<br><br>
		<div id="pne-log-entries"><?= $html ?></div>
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

	public static function ajax_counts(): void {
		check_ajax_referer('pne_log_viewer', 'nonce');

		if (!current_user_can('manage_woocommerce'))
			wp_die('', '', 403);

		$date = $_POST['date'] ?? '';
		if (!in_array($date, self::get_dates(), true))
			wp_die('', '', 400);

		wp_send_json(self::get_counts($date));
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
		$html = '';
		foreach ($counts as $type => $count) {
			$is_checked = null === $checked || in_array($type, $checked, true);
			$html .= '<label class="pne-log-type-filter-label'.('ERROR' == $type ? ' pne-log-is-error' : '').'">'
				.'<input type="checkbox" class="pne-log-type-filter" value="'.esc_attr($type).'"'.($is_checked ? ' checked' : '').'> '.esc_html($type)
				.'<span class="pne-log-type-count-wrap">'
					.'<span class="pne-log-type-count" data-type="'.esc_attr($type).'">'.$count.'</span>'
					.'<span class="pne-log-type-delta" data-type="'.esc_attr($type).'"></span>'
				.'</span></label>';
		}

		return $html;
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
		$decoded = json_decode($entry['text'], true);
		$error_class = 'ERROR' == strtoupper($entry['level']) ? ' pne-log-is-error' : '';
		$time = explode('T', $entry['time'], 2)[1] ?? $entry['time'];
		$header_prefix = esc_html(preg_replace('/[+-]\d{2}:\d{2}$/', '', $time));

		$lead = (is_array($decoded) && isset($decoded[0]) && is_string($decoded[0])) ? $decoded[0] : null;

		if (null !== $lead) {
			$rest = array_slice($decoded, 1);
			$collapsible = (bool)$rest;
			$body = $collapsible ? implode('', array_map([self::class, 'render_error_table'], $rest)) : '';
		}
		elseif (is_array($decoded)) {
			$body = self::highlight_json(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			$collapsible = false !== strpos($body, "\n");
		}
		else {
			$body = self::highlight_webhook(esc_html($entry['text']));
			$collapsible = false;
		}
		$lead_html = null !== $lead
			? ' <span class="pne-log-entry-lead">'.esc_html($lead).'</span>'
			: '';

		if (!$collapsible) {
			$tail = '' !== $body
				? ' <span class="pne-log-entry-tail">'.$body.'</span>'
				: '';

			return '<div class="pne-log-entry'.$error_class.'" data-level="'.esc_attr(strtoupper($entry['level'])).'">'
				.'<strong class="pne-log-entry-header-static'.$error_class.'">'.$header_prefix.'</strong>'.$lead_html.$tail.'</div>';
		}

		return '<div class="pne-log-entry'.$error_class.'" data-level="'.esc_attr(strtoupper($entry['level'])).'">'
			.'<strong class="pne-log-entry-header'.$error_class.'"><span class="pne-log-toggle">&#9656;</span>'.$header_prefix.'</strong>'.$lead_html
			.'<pre class="pne-log-entry-body">'.$body.'</pre></div>';
	}

	private static function highlight_json(string $pretty): string {
		return preg_replace_callback('/"(?:\\\\.|[^\\\\"])*"|\b(?:true|false|null)\b|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/',
			fn($m) => '<span class="'.(substr($m[0], 0, 1) === '"' ? 'pne-log-json-string' : 'pne-log-json-value').'">'.$m[0].'</span>',
			htmlspecialchars($pretty, ENT_NOQUOTES, 'UTF-8'));
	}

	private static function highlight_webhook(string $webhook): string {
		return preg_replace_callback('!^(..)(\\w+)/(.)(\\w+) !',
			fn($m) => "<span class='pne-log-{$m[1]}{$m[3]}'>{$m[1]}{$m[2]}/{$m[3]}{$m[4]}</span> ",
			$webhook);
	}

	private static function render_error_table(array $hash): string {
		$rows = '';
		foreach ($hash as $key => $value)
			$rows .= '<tr><td>'.esc_html($key).'</td><td>'.esc_html((string)$value).'</td></tr>';

		return '<table class="pne-log-error-table">'.$rows.'</table>';
	}
}
