{**
* plugins/generic/markup/settingsForm.tpl
*
* Copyright (c) 2003-2013 John Willinsky
* Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
*
* Document Markup gateway plugin settings
*}
{strip}
{assign var="pageTitle" value="plugins.generic.markup.displayName"}
{include file="common/header.tpl"}
{/strip}

{url|assign:"urlFileManager" page="manager" op="files" path="css"}

<div id="markupSettings">
	<h3>{translate key="plugins.generic.markup.settings"}</h3>

	<form method="post" action="{plugin_url path="settings"}"  enctype="multipart/form-data" autocomplete="off">
	{include file="common/formErrors.tpl"}

		<table class="data">

			<tr valign="top">
				<td class="label">
					{fieldLabel name="cslStyle" key="plugins.generic.markup.settings.cslStyle"}
				</td>
				<td class="value">
					<input type="text" name="cslStyleName" id="cslStyleName" value="{$cslStyleName|escape}" class="textField" size="40" />
					<input name="cslStyle" type="hidden" id="cslStyle" value="{$cslStyle|escape}" />
					<p>{fieldLabel key="plugins.generic.markup.settings.cslStyleFieldHelp"}</p>
				</td>
			</tr>

			<tr valign="top">
				<td class="label">
					{fieldLabel name="cssStyles" key="plugins.generic.markup.settings.cssStyles"}
				</td>
				<td class="value">
					<div>
						{translate key="plugins.generic.markup.settings.cssStylesHelp"  url=$urlFileManager}
					</div>
					<br />
					<a href="{$urlFileManager}/article.css" target="_blank">article.css</a><br/>
					<a href="{$urlFileManager}/article_font.css" target="_blank">article_font.css</a><br/>
					<a href="{$urlFileManager}/article_print.css" target="_blank">article_print.css</a><br/>
					<a href="{$urlFileManager}/article_small.css" target="_blank">article_small.css</a><br/>
					<a href="{$urlFileManager}/article_wide.css" target="_blank">article_wide.css</a><br/>
				</td>
			</tr>

			<tr valign="top">
				<td class="label">
					{fieldLabel name="cssHeaderImage" id="cssHeaderImage"  key="plugins.generic.markup.settings.cssHeaderImageURL"}
				</td>
				<td class="value">
					<a href="{$urlFileManager}/{$cssHeaderImageName}" target="_blank">{$cssHeaderImageName}</a>
					<input type="file" name="cssHeaderImage" />
					<p>{translate key="plugins.generic.markup.settings.cssHeaderImageURLHelp"  url=$urlFileManager}</p>
				</td>
			</tr>

			<tr>
				<td class="label">
					{fieldLabel key="plugins.generic.markup.settings.reviewVersion"}
				</td>
				<td class="value">
					<input type="checkbox" name="reviewVersion" id="reviewVersion" value="yes" {if $reviewVersion == "yes"}checked="checked"{/if} />
					{translate key="plugins.generic.markup.settings.reviewVersionHelp"}
				</td>
			</tr>

			<tr><td colspan="2"><div class="separator">&nbsp;</div></td></tr>

			<tr>
				<td colspan="2"><h3>{fieldLabel key="plugins.generic.markup.settings.requirements"}</h3></td>
			</tr>

			<tr>
				<td class="label"></td>
				<td class="value">
					{fieldLabel key="plugins.generic.markup.settings.markupHostAccountHelp"}
				</td>
			</tr>

			<tr>
				<td class="label">
					{fieldLabel key="plugins.generic.markup.settings.markupHostUser"}
				</td>
				<td class="value">
					<input type="text" name="markupHostUser" id="markupHostUser" value="{$markupHostUser|escape}" class="textField" />
				</td>
			</tr>

			<tr>
				<td class="label">
					{fieldLabel key="plugins.generic.markup.settings.markupHostPass"}
				</td>
				<td class="value">
					<input type="password" name="markupHostPass" id="markupHostPass" value="{$markupHostPass|escape}" class="textField" />
				</td>
			</tr>

			<tr>
				<td class="label" valign="top">
					{fieldLabel key="plugins.generic.markup.settings.markupHostURL"}
				</td>
				<td class="value">
					<input type="text" name="markupHostURL" id="markupHostURL" value="{$markupHostURL|escape}" class="textField" size="40" />
					<p>{translate key="plugins.generic.markup.settings.markupHostURLHelp"}</p>
				</td>
			</tr>

			<tr>
				<td class="label">
					{fieldLabel key="plugins.generic.markup.settings.php5Support"}
				</td>
				<td class="value">
					<strong>{$php5Support|escape}</strong>
					<p>{translate key="plugins.generic.markup.settings.php5SupportHelp"}</p>
				</td>
			</tr>

			<tr>
				<td class="label">
					{translate key="plugins.generic.markup.settings.curlSupport"}
				</td>
				<td class="value">
					<strong>{$curlSupport|escape}</strong>
					<p>{fieldLabel key="plugins.generic.markup.settings.curlSupportHelp"}</p>
				</td>
			</tr>

			<tr>
				<td class="label">
					{fieldLabel key="plugins.generic.markup.settings.zipSupport"}
				</td>
				<td class="value">
					<strong>{$zipSupport|escape}</strong>
					<p>{translate key="plugins.generic.markup.settings.zipSupportHelp"}</p>
				</td>
			</tr>

			<tr>
				<td class="label">
					{fieldLabel key="plugins.generic.markup.settings.pathInfo"}
				</td>
				<td class="value">
					<strong>{$pathInfo|escape}</strong>
					<p>{translate key="plugins.generic.markup.settings.pathInfoHelp"}</p>
				</td>
			</tr>
		</table>

		<input type="submit" name="save" class="button defaultButton" value="{translate key="common.save"}"/>
		<input type="button" class="button" value="{translate key="common.cancel"}" onclick="document.location.href='{url|escape:"quotes" page="manager" op="plugins" escape="false"}'" />
	</form>
</div>

<script>
{*
	csl.json is an array of csl values obtained from the document markup server directly:

		jsonCallback(
			{"styles": [
				{"label":"...","value":"..."},
				{...}
			] }
		)
// TODO: inline js???
*}
{literal}
$(document).ready(function() {
	jQuery.support.cors = true;
	jQuery.ajaxSettings.cache = false;

	jQuery.ajax({
		url: '{/literal}{$markupHostURL|escape}static/csl-style-index.json?callback=?{literal}',
		dataType: 'jsonp',
		jsonpCallback: 'jsonCallback',
		contentType: 'application/json',
		error: function(e) { console.log(e.message); },
		success: function(cslData) {
			$("#cslStyleName").autocomplete({
				source: cslData.styles,
				minLength: 2,
				html: true,
				position: { my : 'left bottom', at: 'left top' },
				select: function( event, ui ) {
					// Yields ui.item.value & ui.item.label
					$(this).val(ui.item.label); // Was set to value
					$("#cslStyle").val(ui.item.value);
					return false; // Cancels normal setting of value
				}
			});
		}
	})
});
{/literal}
</script>

{include file="common/footer.tpl"}
