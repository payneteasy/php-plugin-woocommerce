($ => {
	'use strict'

	var { field_prefix = '', row_selector = 'tr', submitter, submit_hook } = window.pneAdminSettings

	$(() => {
		var fields = {}, rows = {}
		'URL END_POINT LOGIN CONTROL_KEY'.split(' ').forEach(k => {
			['LIVE','SANDBOX'].forEach(section => {
				var $field = $('#'+field_prefix+section+'_'+k);
				(fields[section] ??= []).push($field[0]);

				(rows[section] ??= []).push($field.closest(row_selector)[0])

				var $other = $('#'+field_prefix+(section == 'LIVE' ? 'SANDBOX' : 'LIVE')+'_'+k)
				$field.on('blur', () => { '' == $other.val() ? $other.val($field.val()) : 0 })
			})
		})

		var $fields = { LIVE:$(fields.LIVE), SANDBOX:$(fields.SANDBOX) }, $rows = { LIVE:$(rows.LIVE), SANDBOX:$(rows.SANDBOX) }

		var checks = {
			URL: /^https?:\/\/(?:\w+(?:-\w+)*\.)+\w+\/\w+$/,
			END_POINT: /^\d+$/,
			LOGIN: /^[a-z][\w-]*\w$/i,
			CONTROL_KEY: /^[\da-f]{8}(?:-[\da-f]{4}){3}-[\da-f]{12}$/i }

		function validateField($f) {
			var valid = checks[ $f.attr('id').substr(field_prefix.length).replace(/LIVE_|SANDBOX_/, '') ].test($f.val())

			$f.toggleClass('pne-invalid', !valid).css('background-color', valid ? '' : '#FDD')

			return valid
		}

		$rows.LIVE.add($rows.SANDBOX).css('display', 'none')

		function do_toggle($toggle, key) {
			var is_on = $toggle.is(':checked')

			$('#pne-'+key+'-desc-on').toggle(is_on)
			$('#pne-'+key+'-desc-off').toggle(!is_on)

			return is_on
		}

		var $isLive = $('#'+field_prefix+'IS_LIVE')
		function toggle_isLive(duration=0) {
			var [on, off] = do_toggle($isLive, 'IS_LIVE') ? ['LIVE','SANDBOX'] : ['SANDBOX','LIVE']
			$rows[off].stop(true, true).fadeOut(duration, () => $rows[on].stop(true, true).fadeIn(duration))
		}

		var $isMulticurr = $('#'+field_prefix+'IS_MULTICURR')
		function toggle_isMulticurr() {
			var is_multi = do_toggle($isMulticurr, 'IS_MULTICURR')

			var $blink = $('.pne-is-multi')
			$blink.stop(true, true).fadeOut(200, () => $blink.stop(true, true).fadeIn(200))

			var [from, to] = is_multi ? ['Endpoint ID','Endpoint Group ID'] : ['Endpoint Group ID','Endpoint ID']
			$('.pne-endpointid-label').text((i, s) => s.replace(from, to))
		}

		['IS_FORM','IS_CAPTURE_MANUAL','IS_PREAUTH','IS_SSN_REQUIRED'].forEach((key) => {
			var $toggle = $('#'+field_prefix+key)
			do_toggle($toggle, key)
			$toggle.on('change', () => do_toggle($toggle, key))
		})

		$('[data-toggle-dependant]').each((i, el) => {
			var $toggle = $(el), $row = $('#'+field_prefix+$toggle.data('toggleDependant')).closest(row_selector)

			$row.toggle($toggle.is(':checked'))

			$toggle.on('change', () => {
				var is_on = $toggle.is(':checked')
				$row.toggle(is_on)

				if (is_on)
					$row.stop(true, true).fadeOut(200, () => $row.stop(true, true).fadeIn(200))
			})
		})

		toggle_isLive()

		$isLive.on('change', () => toggle_isLive(200))
		$isMulticurr.on('change', toggle_isMulticurr)

		$fields.LIVE.add($fields.SANDBOX).on('input blur', e => validateField($(e.target).first()))

		function visible_section()
			{ return $fields[ $isLive.is(':checked') ? 'LIVE' : 'SANDBOX' ] }

		function on_submit(...args) {
			var $invalid = $($.grep(visible_section(), f => validateField($(f)), true)).first()

			if ($invalid.length) {
				$invalid.trigger('focus')
				$invalid.get(0).scrollIntoView({ behavior: 'smooth', block: 'center' })

				if (submitter)
					return
				else
					args[0].preventDefault()
			}

			if (submitter)
				submitter(...args)
		}

		submitter
			? submit_hook(on_submit)
			: visible_section().closest('form').on('submit', e => on_submit(e))

	})
})(jQuery)
