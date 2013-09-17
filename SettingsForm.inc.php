<?php

/**
* @file plugins/generic/markup/SettingsForm.inc.php
*
* Copyright (c) 2003-2013 John Willinsky
* Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
*
* @class SettingsForm
* @ingroup plugins_generic_markup
*
* @brief Form for Document Markup gateway plugin settings
*/

import('lib.pkp.classes.form.Form');

define('MARKUP_CSL_STYLE_DEFAULT', 'chicago-author-date.csl');
define('MARKUP_CSL_STYLE_NAME_DEFAULT', 'Chicago Manual of Style (author-date)');
define('MARKUP_DOCUMENT_SERVER_URL_DEFAULT', 'http://pkp-udev.lib.sfu.ca/');

class SettingsForm extends Form {

	/** @var $journalId int */
	var $journalId;

	/** @var $plugin object */
	var $plugin;

	/** @var $settings array */
	var $settings;

	/**
	 * Constructor
	 * @param $plugin object
	 * @param $journalId int
	 */
	function SettingsForm(&$plugin, $journalId) {
		$this->journalId = $journalId;
		$this->plugin =& $plugin;
		$journal =& Request::getJournal();

		parent::Form($plugin->getTemplatePath() . 'settingsForm.tpl');

		// Validation checks for this form
		$this->settings = array(
			'cslStyle' => 'string',
			'cslStyleName' => 'string',
			'cssHeaderImageName' => 'string',
			'markupHostPass' => 'string',
			'markupHostURL' => 'string',
			'markupHostUser' => 'string',
			'reviewVersion' => 'bool',
		);
	}

	/**
	 * Validate the form
	 */
	function validate() {
		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidator($this, 'cslStyle', 'required', 'plugins.generic.markup.required.cslStyle'));
		$this->addCheck(new FormValidator($this, 'cslStyleName', 'optional', 'plugins.generic.markup.optional.cslStyleName'));
		$this->addCheck(new FormValidator($this, 'markupHostPass', 'optional', 'plugins.generic.markup.optional.markupHostPass'));
		$this->addCheck(new FormValidator($this, 'markupHostURL', 'required', 'plugins.generic.markup.required.markupHostURL'));
		$this->addCheck(new FormValidator($this, 'markupHostUser', 'optional', 'plugins.generic.markup.optional.markupHostUrl'));
		$this->addCheck(new FormValidator($this, 'reviewVersion', 'optional', 'plugins.generic.markup.optional.reviewVersion'));

		$this->addCheck(new FormValidatorCustom($this, 'cssHeaderImageName', 'optional', 'plugins.generic.markup.error', $this->_validateImage('cssHeaderImage'), true));

		return parent::validate();
	}

	/**
	 * Ensure attached header image is a .jpg or .png
	 *
	 * @param $imageName string form upload fieldname
	 */
	function _validateImage($imageName) {
		// TODO remove $_FILES reference
		if (isset($_FILES[$imageName])) {
			$journal =& Request::getJournal();
			import('classes.file.JournalFileManager');
			$journalFileManager = new JournalFileManager($journal);
			if ($journalFileManager->uploadedFileExists($imageName)) {
				$type = $journalFileManager->getUploadedFileType($imageName);
				$extension = $journalFileManager->getImageExtension($type);
				if (!$extension || ($extension != '.png' && $extension != '.jpg')) {
					$this->addError('coverPage', __('plugins.generic.markup.optional.cssHeaderImage'));
					return false;
				}
			}
		}

		return true;
	}


	/**
	 * Initialize plugin settings form data.
	 */
	function initData() {
		$journal =& Request::getJournal();
		$journalId = $this->journalId;
		$plugin =& $this->plugin;

		// User must at least load settings page for plugin to work with defaults.
		if ($plugin->getSetting($journalId, 'cslStyle') == '') {
			$plugin->updateSetting($journalId, 'cslStyle', MARKUP_CSL_STYLE_DEFAULT);
			$plugin->updateSetting($journalId, 'cslStyleName', MARKUP_CSL_STYLE_NAME_DEFAULT);
		}
		if ($plugin->getSetting($journalId, 'markupHostURL') == '') {
			$plugin->updateSetting($journalId, 'markupHostURL', MARKUP_DOCUMENT_SERVER_URL_DEFAULT);
		}

		$this->setData('cslStyle', $plugin->getSetting($journalId, 'cslStyle'));
		$this->setData('cslStyleName', $plugin->getSetting($journalId, 'cslStyleName'));

		// This field has content only if header image actually exists in the right folder.
		import('classes.file.JournalFileManager');
		$journalFileManager = new JournalFileManager($journal);
		$folderCssImage = glob($journalFileManager->filesDir . 'css/article_header.{jpg,png}', GLOB_BRACE);
		if (count($folderCssImage)) {
			$this->setData('cssHeaderImageName', basename($folderCssImage[0]));
		}

		$this->setData('markupHostUser', $plugin->getSetting($journalId, 'markupHostUser'));

		$this->setData('reviewVersion', $plugin->getSetting($journalId, 'reviewVersion'));

		// User assigned but should never change (view only).
		$this->setData('markupHostURL', $plugin->getSetting($journalId, 'markupHostURL'));
	}

	/**
	 * Populate and display settings form.
	 */
	function display() {
		$templateMgr =& TemplateManager::getManager();

		// Signals indicating plugin compatibility
		$templateMgr->assign('curlSupport', function_exists('curl_init') ? __('plugins.generic.markup.settings.installed') : __('plugins.generic.markup.settings.notInstalled'));
		$templateMgr->assign('zipSupport', extension_loaded('zlib') ? __('plugins.generic.markup.settings.installed') : __('plugins.generic.markup.settings.notInstalled'));
		$templateMgr->assign('php5Support', checkPhpVersion('5.0.0') ? __('plugins.generic.markup.settings.installed') : __('plugins.generic.markup.settings.notInstalled'));
		$templateMgr->assign('pathInfo', Request::isPathInfoEnabled() ? __('plugins.generic.markup.settings.enabled') : __('plugins.generic.markup.settings.disabled'));
		parent::display();
	}

	/**
	 * Assign form data to user-submitted data.
	 */
	function readInputData() {
		$this->readUserVars(
			array(
				'cslStyle',
				'cslStyleName',
				'cssHeaderImage',
				'markupHostPass',
				'markupHostURL',
				'markupHostUser',
				'reviewVersion',
			)
		);
	}

	/**
	 * Save settings.
	 */
	function execute() {
		$plugin =& $this->plugin;
		$journalId = $this->journalId;

		$plugin->updateSetting($journalId, 'cslStyle', $this->getData('cslStyle'));
		$plugin->updateSetting($journalId, 'cslStyleName', $this->getData('cslStyleName'));

		// Ensure document server url has http:// ... / in it.
		$markupHostURL = $this->getData('markupHostURL');
		if (strlen($markupHostURL) > 0) {
			if (substr($markupHostURL, 0, 4) != 'http') {
				$markupHostURL = 'http://' . $markupHostURL;
			}
			if (substr($markupHostURL, -1) != '/') {
				$markupHostURL .= '/';
			}
		}
		$plugin->updateSetting($journalId, 'markupHostURL', $markupHostURL);

		$markupHostUser = $this->getData('markupHostUser');
		$plugin->updateSetting($journalId, 'markupHostUser', $markupHostUser);

		if (strlen($markupHostUser) > 0) {
			$markupHostPass = $this->getData('markupHostPass');
			// Only update password if account exists and password exists.
			if (strlen($markupHostPass) > 0) {
				$plugin->updateSetting($journalId, 'markupHostPass', $markupHostPass);
			}
		} else {
			$plugin->updateSetting($journalId, 'markupHostPass', '');
		}

		$plugin->updateSetting($journalId, 'reviewVersion', $this->getData('reviewVersion'));

		// Upload article header image if given. Image suffix already validated above.
		if (isset($_FILES['cssHeaderImage'])) {
			import('classes.file.JournalFileManager');
			$journal =& Request::getJournal();
			$journalFileManager = new JournalFileManager($journal);
			$imageName = 'cssHeaderImage';
			if ($journalFileManager->uploadedFileExists($imageName)) {
				$type = $journalFileManager->getUploadedFileType($imageName);
				$extension = $journalFileManager->getImageExtension($type);
				$journalFileManager->uploadFile('cssHeaderImage', '/css/article_header.' . $extension);
			}
		}
	}
}
