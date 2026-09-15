jQuery($ => {
	const orderID = payneteasy_ajax_var.order_id
	const $statusColumn = $('.order_data_column').eq(1).length ? $('.order_data_column').eq(1) : $('.order_data_column').eq(0)

	const showNotice = (message, isError) =>
		$(`<div class="notice ${isError ? 'notice-error' : 'notice-success'} is-dismissible"><p></p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button></div>`)
			.find('p').text(message).end()
			.prependTo($statusColumn)
			.find('.notice-dismiss').on('click', function() { $(this).closest('.notice').remove() })

	if ($statusColumn.length) {
		$statusColumn.append(`
			<p class="form-field form-field-wide">
				<label for="payneteasy-button-card-info">To check the saved card details in the PAYNET payment system, please click the button:</label>
				<button class="button custom-action" id="payneteasy-button-card-info">Show card info</button>
			</p>
		`)

		$('#payneteasy-button-card-info').on('click', (event) => {
			event.preventDefault()

			$.ajax({ url: payneteasy_ajax_var.api_url, method: 'POST', data: { action: 'card_info', nonce: payneteasy_ajax_var.nonce, order_id: orderID } })
			.done((data) => { showNotice(data.message, !data.success) })
			.fail((error) => {
				console.error('Request error:', error)
				showNotice('An error occurred while processing the request.', true) })
		})
	}
})
