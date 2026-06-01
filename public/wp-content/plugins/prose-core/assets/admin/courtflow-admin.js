/* CourtFlow admin scripts */
(function () {
	'use strict';
	document.addEventListener('DOMContentLoaded', function () {
		var textareas = document.querySelectorAll('.courtflow-admin textarea.code');
		textareas.forEach(function (el) {
			el.addEventListener('blur', function () {
				try {
					JSON.parse(el.value);
					el.style.borderColor = '';
				} catch (e) {
					el.style.borderColor = '#d63638';
				}
			});
		});
	});
})();
