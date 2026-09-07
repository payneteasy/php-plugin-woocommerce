(function($) {
	'use strict'

	var fPrefix = window.pneAdminSettings.field_prefix

	$(function() {
		var fields = [], rows = { LIVE:[], SANDBOX:[] }
		'URL END_POINT LOGIN CONTROL_KEY'.split(' ').forEach((k) => {
			['LIVE','SANDBOX'].forEach((section) => {
				var $field = $('#'+fPrefix+section+'_'+k)
				fields.push($field[0])

				rows[section].push($field.closest('tr')[0])

				var $other = $('#'+fPrefix+(section == 'LIVE' ? 'SANDBOX' : 'LIVE')+'_'+k)
				$field.on('blur', function(){ if ('' === $other.val()) { $other.val($field.val()) } })
			})
		})

		var $fields = $(fields), $rows = { LIVE:$(rows.LIVE), SANDBOX:$(rows.SANDBOX) }
	
		var checks = {
			URL: /^https?:\/\/(?:\w+(?:-\w+)*\.)+\w+\/\w+$/,
			END_POINT: /^\d+$/,
			LOGIN: /^[a-z][\w-]*\w$/i,
			CONTROL_KEY: /^[\da-f]{8}(?:-[\da-f]{4}){3}-[\da-f]{12}$/i }

		function validateField($f) {
			var valid = checks[ $f.attr('id').substr(fPrefix.length).replace(/LIVE_|SANDBOX_/, '') ].test($f.val())

			valid
				? $f.removeClass('pne-invalid').css('background-color', '')
				: $f.addClass('pne-invalid').css('background-color', '#FDD')

			return valid
		}

		$rows.LIVE.add($rows.SANDBOX).css('display', 'none')

		var $isLive = $('#'+fPrefix+'IS_LIVE'), $isMulticurr = $('#'+fPrefix+'IS_MULTICURR')
		function toggle_isLive(duration=0) {
			var is_live = $isLive.is(':checked')
			$('#pne_is_live_desc_on').toggle(is_live)
			$('#pne_is_live_desc_off').toggle(!is_live)

			var [on, off] = is_live ? ['LIVE','SANDBOX'] : ['SANDBOX','LIVE']
			$rows[off].stop(true, true).fadeOut(duration, () => $rows[on].stop(true, true).fadeIn(duration))
		}

		function toggle_isMulticurr() {
			var is_multi = $isMulticurr.is(':checked')
			var $off = $('.pne_is_multi_off'), $on = $('.pne_is_multi_on'), $blink = $('.pne_is_multi')

			$off.toggle(!is_multi)
			$on.toggle(is_multi)

			$blink.stop(true, true).fadeOut(200, () => $blink.stop(true, true).fadeIn(200))

			var [from,to] = is_multi ? ['Endpoint ID','Endpoint Group ID'] : ['Endpoint Group ID','Endpoint ID']
			$('.pne_endpointid_label').text((i, s) => s.replace(from, to))
		}

		toggle_isLive()

		$isLive.on('change', () => toggle_isLive(200))
		$isMulticurr.on('change', toggle_isMulticurr)

		$fields.on('input blur', (e) => validateField($(e.target).first()))

		$fields.closest('form').on('submit', function(event){
			var $invalid = $($.grep($fields, (f, i) => validateField($(f)), true)).first()

			if ($invalid.length) {
				event.preventDefault()
				$invalid.trigger('focus')
				$invalid.get(0).scrollIntoView({ behavior: 'smooth', block: 'center' })
			}
		})
	})
})(jQuery)
