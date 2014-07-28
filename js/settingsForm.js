/**
 * Populates the citation style dropdown with valid citation style options
 *
 * @param $cslStyle
 *
 * @return void
 */
function CslStyle() {
	this.$markupHostInput = $('#markupHostURL');
	this.$cslStyleSelector = $('#cslStyle');
	this.$cslStyleRow = $('#cslStyleRow');
	this.cslStyleSelectorPopulated = false;

	if (this.$markupHostInput.val() != '') {
		this.populateDropdown();
	}

	// Prevent form submission if citation style list has not been populated
	$(this.$cslStyleSelector.parents('form')[0]).submit($.proxy(function() {
		if (!this.cslStyleSelectorPopulated) {
			alert(submitErrorMessage);
			return false;
		}
	}, this));

	this.$markupHostInput.keyup($.proxy(function(e) { this.populateDropdown(); }, this));
}

/**
* Set the CSL style API URL based on the value of the markupHostURL input
*/
CslStyle.prototype.setCslStyleUrl = function() {
	this.cslStyleUrl = this.$markupHostInput.val();

	this.cslStyleUrl = $.trim(this.cslStyleUrl).replace(/\/$/, '');
	this.cslStyleUrl += '/api/job/citationStyleList';
}

/**
* Populate the citation style dropdown with a list of valid ciation styles
* fetched from the markup server
*/
CslStyle.prototype.populateDropdown = function() {
	// Build the API URL
	this.setCslStyleUrl();

	// Remove all options from the dropbdown
	this.$cslStyleSelector.children('option').each(function() { this.remove(); });


	// Fetch a list of citation styles from the markup server and populate the
	// dropdown
	$.ajax(
		{
			url: this.cslStyleUrl,
			dataType: 'json',
			complete: $.proxy(function(data, textStatus) {
				if (textStatus !== 'success') {
					this.cslStyleSelectorPopulated = false;
				} else {
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

						this.$cslStyleSelector.append($option);
					}

					this.cslStyleSelectorPopulated = true;
				}

				this.cslUpdateHandler();
			}, this)
		}
	);
}

/**
* Hides/shows the citation style dropdown after an update
*/
CslStyle.prototype.cslUpdateHandler = function(e) {
	if (this.cslStyleSelectorPopulated) {
		this.$cslStyleRow.fadeIn();
	} else {
		this.$cslStyleRow.fadeOut();
	}
}

// Initalize submission style form handling
$(function() { var cslStyle = new CslStyle(); });
