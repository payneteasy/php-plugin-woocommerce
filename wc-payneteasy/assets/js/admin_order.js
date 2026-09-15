jQuery($ => {
	const orderID = payneteasy_ajax_var.order_id
	const $statusColumn = $('.order_data_column').eq(1).length
		? $('.order_data_column').eq(1)
		: $('.order_data_column').eq(0)

	const showNotice = (message) =>
		$('<div class="notice notice-error is-dismissible"><p></p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button></div>')
			.find('p').text(message).end()
			.prependTo($statusColumn)
			.find('.notice-dismiss').on('click', function() { $(this).closest('.notice').remove() })

	if ($statusColumn.length) {
		$statusColumn.append(`
			<p class="form-field form-field-wide">
				<span class="pne-check-status-wrap">
					<button class="button custom-action" id="payneteasy-button-check-status">Check status</button>
					<span class="order_number pne-transaction-id">ID ${payneteasy_ajax_var.paynet_order_id}</span>
				</span>
			</p>
		`)

		$('#payneteasy-button-check-status').on('click', (event) => {
			event.preventDefault()

			$.ajax({ url: payneteasy_ajax_var.api_url, method: 'POST', data: { action: 'check_status', nonce: payneteasy_ajax_var.nonce, order_id: orderID } })
			.done((data) => {
				if (data.success)
					location.reload()
				else
					showNotice(data.message) })
			.fail((error) => {
				console.error('Request error:', error)
				showNotice('An error occurred while processing the request.') })
		})

		if (payneteasy_ajax_var.show_capture) {
			$statusColumn.append(`
				<p class="form-field form-field-wide">
					<label for="payneteasy-capture-amount">Capture the authorized payment (full amount by default; enter less for a partial capture):</label>
					<input type="number" step="0.01" min="0" id="payneteasy-capture-amount" value="${payneteasy_ajax_var.capture_amount}">
					<button class="button custom-action" id="payneteasy-button-capture">Capture payment</button>
				</p>
			`)

			$('#payneteasy-button-capture').on('click', (event) => {
				event.preventDefault()

				$.ajax({ url: payneteasy_ajax_var.api_url, method: 'POST',
					data: { action: 'capture', nonce: payneteasy_ajax_var.nonce, order_id: orderID, amount: $('#payneteasy-capture-amount').val() } })
				.done((data) => {
					if (data.success)
						location.reload()
					else
						showNotice(data.message) })
				.fail((error) => {
					console.error('Request error:', error)
					showNotice('An error occurred while processing the request.') })
			})
		}
	}
})
