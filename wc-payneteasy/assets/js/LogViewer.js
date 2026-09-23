jQuery($ => {
	function anyExpanded()
		{ return 0 < $('#pne-log details[open]').length }

	function updateButtonText()
		{ $('#pne-log-toggle-all').text(anyExpanded() ? 'Collapse all' : 'Expand all') }

	function applyTypeFilters() {
		const checked = $('#pne-log-type-filters .pne-log-type-filter:checked').map((_, that) => that.value).get()
		$('#pne-log div').each((_, that) => $(that).toggle(checked.indexOf(that.dataset.level) !== -1))
	}

	function fetchLog(page, resetTypes = false) {
		const payload = {
			action: 'pne_log_viewer_fetch',
			nonce: pneLogViewer.nonce,
			date: $('#pne-log-date-select').val(),
			per_page: $('#pne-log-per-page-select').val(),
			page
		}

		if (!resetTypes)
			payload.types = $('#pne-log-type-filters .pne-log-type-filter:checked').map((_, that) => that.value).get().join(',')

		$.post(pneLogViewer.ajaxUrl, payload)
			.done(resp => {
				$('#pne-log').html(resp.html)
				$('#pne-log-type-filters').html(resp.filters)
				$('#pne-log-page-select-wrap').html(resp.pageSelect)
				updateButtonText()
				applyTypeFilters() })
			.fail(() => alert('Failed to load log entries.'))
	}

	$(document).on('heartbeat-send.pneLog', (event, data) => {
		if ($('#pne-log-date-select').val() === $('#pne-log-date-select option:first').val())
			data.pne_log_date = $('#pne-log-date-select').val() })

	$(document).on('heartbeat-tick.pneLog', (event, data) => {
		if (!data.pne_log_counts)
			return

		$.each(data.pne_log_counts, (type, count) => {
			const $count = $('.pne-log-type-count[data-type="'+type+'"]')
			if (!$count.length)
				return

			const delta = count - parseInt($count.text(), 10)
			$('.pne-log-type-delta[data-type="'+type+'"]').text(delta > 0 ? '+'+delta : '')
		}) })

	$('#pne-log-date-select').on('change', () => fetchLog(1, true))
	$('#pne-log-per-page-select').on('change', () => fetchLog(1))
	$(document).on('change', '#pne-log-page-select', e => fetchLog(e.target.value))

	$('#pne-log-toggle-all').on('click', () => {
		$('#pne-log details').prop('open', !anyExpanded())
		updateButtonText() })

	$(document).on('change', '.pne-log-type-filter', applyTypeFilters)

	$(document).on('click', '#pne-log summary', e => {
		e.preventDefault()
		$(e.target).closest('details').prop('open', (_, state) => !state)
		updateButtonText() })

	updateButtonText()
})
