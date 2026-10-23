(({ registerPaymentMethod }, { createElement: h, useState, useEffect }, { getSetting }) => {
	const settings = getSetting('paymentMethodData', {}).wc_payneteasy || getSetting('wc_payneteasy_data', {})
	const label = settings.title || 'Payneteasy'
	const icon = settings.icon && h('img', { src: settings.icon, alt: label, style: { maxHeight: '24px', marginRight: '4px' } })

	const isDark = !!document.querySelector('.wc-block-checkout.has-dark-controls')
	const colors = isDark
		? { bg: 'rgba(0, 0, 0, 0.1)', border: 'rgba(255, 255, 255, 0.4)', text: '#FFF', label: '#FFF', error: '#CC1818' }
		: { bg: '#FFF', border: '#50575E', text: '#2B2D2F', label: '#757575', error: '#CC1818' }

	function field(fieldLabel, value, onChange, width, inputProps = {}, isBad = false) {
		const id = 'pne-' + fieldLabel.toLowerCase().replace(/[^a-z0-9]+/g, '-')
		return h('div', { className: 'wc-block-components-text-input' + (value ? ' is-active' : '') + (isBad ? ' has-error' : ''), style: { width, marginTop: 0 } },
			h('input', Object.assign({ type: 'text', id, value, onChange: e => onChange(e.target.value) }, inputProps)),
			h('label', { htmlFor: id }, fieldLabel))
	}

	function selectField(fieldLabel, value, onChange, options, width, placeholder, isBad = false) {
		const id = 'pne-' + fieldLabel.toLowerCase().replace(/[^a-z0-9]+/g, '-')
		return h('div', { style: { width } },
			h('label', { htmlFor: id, style: { display: 'block', marginBottom: '4px', fontSize: '14px', color: isBad ? colors.error : colors.label } }, fieldLabel),
			h('select', {
					id, value, onChange: e => onChange(e.target.value),
					style: { width: '100%', boxSizing: 'border-box', height: '3em', padding: '0 8px', border: `1px solid ${isBad ? colors.error : colors.border}`, borderRadius: '4px',
						fontFamily: 'inherit', fontSize: '16px', backgroundColor: colors.bg, color: colors.text } },
				h('option', { value: '' }, placeholder), ...options.map(o => h('option', { key: o, value: o }, o))))
	}

	function row(...items)
		{ return h('div', { style: { display: 'flex', gap: '12px' } }, ...items) }

	function Content(props) {
		const { eventRegistration, emitResponse } = props
		const onPaymentSetup = eventRegistration.onPaymentSetup || eventRegistration.onPaymentProcessing

		const t = settings.testCard || {}
		const [ccn, setCcn] = useState(t.ccn || '')
		const [name, setName] = useState(t.name || '')
		const [month, setMonth] = useState(t.month ? String(t.month).padStart(2, '0') : '')
		const [year, setYear] = useState(t.year ? String(t.year) : '')
		const [cvv, setCvv] = useState('')
		const [ssn, setSsn] = useState('')
		const [bad, setBad] = useState([])

		const edit = (key, set) => value => {
			set(value)
			setBad(b => b.filter(k => k != key))
		}

		useEffect(() => {
			const unsubscribe = onPaymentSetup(async () => {
				const invalid = PneCheckout.invalid(Object.assign(
					settings.IS_FORM ? {} : { credit_card_number: ccn, card_printed_name: name, expire_month: month, expire_year: year, cvv2: cvv },
					settings.IS_SSN_REQUIRED ? { ssn } : {}))

				setBad(invalid)

				if (invalid.length)
					return { type: emitResponse.responseTypes.ERROR, message: 'Please check the highlighted fields' }

				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: { paymentMethodData: {
						credit_card_number: ccn,
						card_printed_name: name,
						expire_month: month,
						expire_year: year,
						cvv2: cvv,
						ssn } } }
			})

			return unsubscribe
		}, [ccn, name, month, year, cvv, ssn, onPaymentSetup, emitResponse.responseTypes])

		const description = settings.description && h('p', null, settings.description)

		if (settings.IS_FORM)
			return h('div', null, description, settings.IS_SSN_REQUIRED ? field('Document Number (CPF)', ssn, edit('ssn', setSsn), '200px', {}, bad.includes('ssn')) : null)

		const months = Array.from({ length: 12 }, (_, i) => String(i + 1).padStart(2, '0'))
		const thisYear = new Date().getFullYear()
		const years = Array.from({ length: 16 }, (_, i) => String(thisYear + i))

		return h('div', null, description,
			h('div', { style: { border: '1px solid #DDD', borderRadius: '4px', padding: '16px', display: 'flex', flexDirection: 'column', gap: '12px' } },
				row(field('Card Number', ccn, setCcn, '14em', { inputMode: 'numeric', onKeyDown: PneCheckout.onCcnKeyDown, onChange: e => edit('credit_card_number', setCcn)(PneCheckout.onCcnInput(e)) },
						bad.includes('credit_card_number')),
					field('CVC', cvv, edit('cvv2', setCvv), '5em', {
						type: 'password',
						style: { height: '3em', boxSizing: 'border-box', textIndent: '8px', border: `1px solid ${bad.includes('cvv2') ? colors.error : colors.border}`, borderRadius: '4px',
							fontFamily: 'inherit', fontSize: '16px', width: '100%', backgroundColor: colors.bg, color: colors.text } }, bad.includes('cvv2'))),
				row(selectField('Expiry month', month, edit('expire_month', setMonth), months, '6em', 'MM', bad.includes('expire_month')),
					selectField('Expiry year', year, edit('expire_year', setYear), years, '10em', 'YYYY', bad.includes('expire_year'))),
				field('Printed name', name, edit('card_printed_name', setName), '20em', {}, bad.includes('card_printed_name')),
				settings.IS_SSN_REQUIRED ? field('Document Number (CPF)', ssn, edit('ssn', setSsn), '20em', {}, bad.includes('ssn')) : null))
	}

	registerPaymentMethod({
		name: 'wc_payneteasy',
		label: icon ? h('span', null, icon, label) : label,
		content: h(Content),
		edit: h('div', null, settings.description || ''),
		canMakePayment: () => true,
		ariaLabel: label,
		supports: { features: settings.supports || ['products'] } })
})(window.wc.wcBlocksRegistry, window.wp.element, window.wc.wcSettings)
