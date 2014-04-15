/**
 * Populates the citation style dropdown with valid citation style options
 *
 * @param $cslStyle
 *
 * @return void
 */
var cslStylePopulate = function($cslStyle) {
	$cslStyle.append($('<option></option>').text('---'));

	// Disable the select if we got no markup server url
	if (markupHostUrl === '') {
		$cslStyle.attr('disabled', 'disabled');
		return;
	}

	// Build the citation style API url
	markupHostUrl = $.trim(markupHostUrl).replace('#/$#', '');
	markupHostUrl += 'api/job/citationStyleList';

	// Load the citation style list from the markup server
	$.ajax(
		{
			url: markupHostUrl,
			dataType: 'json',
			complete: function(data, textStatus) {
				if (textStatus !== 'success') { return; }

				var response = $.parseJSON(data.response);

				if (response.status !== 'success') { return; }

				var citationStyles = response.citationStyles;
				for (var hash in citationStyles) {
					var $option = $('<option></option>')
						.attr('value', hash)
						.text(citationStyles[hash]);

					if (cslStyleSelection == hash) {
						$option.attr('selected', 'selected');
					}

					$cslStyle.append($option);
				}
			}
		}
	);
}

$(function() {
	cslStylePopulate($('#cslStyle'))
});
