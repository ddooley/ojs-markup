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
{url|assign:"gatewayPath" page="gateway" op="plugin" path="markupplugin"}

<div id="markupSettings">
	<form method="post" action="{plugin_url path="settings"}"  enctype="multipart/form-data" autocomplete="off">
	{include file="common/formErrors.tpl"}

		<table class="data">
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

			<tr valign="top" id="cslStyleRow">
				<td class="label">
					{fieldLabel name="cslStyle" key="plugins.generic.markup.settings.cslStyle"}
				</td>
				<td class="value">
					<select name="cslStyle" id="cslStyle"></select>
					<p>{fieldLabel key="plugins.generic.markup.settings.cslStyleFieldHelp"}</p>
				</td>
			</tr>
            
            <tr>
                <td class="label">
                    {fieldLabel key="plugins.generic.markup.settings.overrideGalley"}
                </td>
                <td class="value">
                    <input type="radio" name="overrideGalley" id="overrideGalleyNo" value="0" {if $overrideGalley == 0}checked="checked"{/if} /> 
                    {fieldLabel name="overrideGalleyNo" key="plugins.generic.markup.settings.overrideGalleyNo"}
                    <br />
                    <input type="radio" name="overrideGalley" id="overrideGalleyYes" value="1" {if $overrideGalley == 1}checked="checked"{/if} />
                    {fieldLabel name="overrideGalleyYes" key="plugins.generic.markup.settings.overrideGalleyYes"}
					<p>{fieldLabel key="plugins.generic.markup.settings.overrideGalleyFieldHelp"}</p>
				</td>
			</tr>
            
            <tr>
                <td class="label">
                    {fieldLabel key="plugins.generic.markup.settings.wantedFormats"}
                </td>
                <td class="value">
                    <label>
                        <input type="checkbox" name="wantedFormats[]" id="markupDocFormatXml" value="xml" {if 'xml'|in_array:$wantedFormats} checked="checked" {/if} /> XML
                    </label>
                    <br />
                    
                    <label>
                        <input type="checkbox" name="wantedFormats[]" id="markupDocFormatHtml" value="html" {if 'html'|in_array:$wantedFormats} checked="checked" {/if} /> HTML
                    </label>
                    <br />
                    
                    <label>
                        <input type="checkbox" name="wantedFormats[]" id="markupDocFormatPdf" value="pdf" {if 'pdf'|in_array:$wantedFormats} checked="checked" {/if} /> PDF
                    </label>
                    <br />
                    
                    <label>
                        <input type="checkbox" name="wantedFormats[]" id="markupDocFormatEpub" value="epub" {if 'epub'|in_array:$wantedFormats} checked="checked" {/if} /> EPUB
                    </label>
                    <br />
                    
					<p>{fieldLabel key="plugins.generic.markup.settings.wantedFormatsHelp"}</p>
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

			<tr valign="top">
				<td class="label">
					{fieldLabel name="cssStyles" key="plugins.generic.markup.settings.cssStyles"}
				</td>
				<td class="value">
					<div>
						{translate key="plugins.generic.markup.settings.cssStylesHelp"  url=$urlFileManager}
					</div>
					<br />
					<a href="{$gatewayPath}/css/article.css" target="_blank">article.css</a><br/>
				</td>
			</tr>

			<tr><td colspan="2"><div class="separator">&nbsp;</div></td></tr>

		</table>

		<input type="submit" name="save" class="button defaultButton" value="{translate key="common.save"}"/>
		<input type="button" class="button" value="{translate key="common.cancel"}" onclick="document.location.href='{url|escape:"quotes" page="manager" op="plugins" escape="false"}'" />
	</form>
</div>

<script>
{* Populate required variables for the citation style select *}
{literal}var cslStyleSelection = '{/literal}{$cslStyle|escape}{literal}';{/literal}
{literal}var submitErrorMessage = '{/literal}{translate key="plugins.generic.markup.settings.cslStyleSubmitErrorMessage"}{literal}';{/literal}
</script>

{include file="common/footer.tpl"}
