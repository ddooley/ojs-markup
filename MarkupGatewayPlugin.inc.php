<?php

/**
 * @file plugins/generic/markup/MarkupGatewayPlugin.inc.php
 *
 * Copyright (c) 2003-2013 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class MarkupGatewayPlugin
 * @ingroup plugins_generic_markup
 *
 * @brief Responds to requests for markup files for particular journal article;
 * sends request to markup an article to Document Markup Server.
 */

import('classes.plugins.GatewayPlugin');

class MarkupGatewayPlugin extends GatewayPlugin {
	var $_userId;

	//
	// Plugin Setup
	//
	/**
	 * Get the name of this plugin. The name must be unique within
	 * its category.
	 *
	 * @return string Name of plugin
	 */
	function getName() {
		$plugin =& $this->getMarkupPlugin();
		return $plugin->getName();
	}

	/**
	 * Hide this plugin from the management interface
	 *
	 * @return bool true
	 */
	function getHideManagement() {
		return true;
	}

	/**
	 * Get plugin display name
	 *
	 * @return string Plugin display name
	 */
	function getDisplayName() {
		return __('plugins.generic.markup.displayName');
	}

	/**
	 * Get plugin description
	 *
	 * @return string Plugin description
	 */
	function getDescription() {
		return __('plugins.generic.markup.description');
	}

	/**
	 * Get the parent plugin
	 *
	 * @return MarkupPlugin Markup plugin object
	 */
	function &getMarkupPlugin() {
		$plugin =& PluginRegistry::getPlugin('generic', 'markupplugin');
		return $plugin;
	}

	/**
	 * Overwrite plugin path with parent's plugin path
	 *
	 * @return string Plugin path
	 */
	function getPluginPath() {
		$plugin =& $this->getMarkupPlugin();
		return $plugin->getPluginPath();
	}

	/**
	 * Overwrite the template path with the parent's template path
	 *
	 * @return string Template path
	 */
	function getTemplatePath() {
		$plugin =& $this->getMarkupPlugin();
		return $plugin->getTemplatePath();
	}

	/**
	 * Overwrite the css path with the parent's css path
	 *
	 * @return string CSS path
	 */
	function getCssPath() {
		$plugin =& $this->getMarkupPlugin();
		return $plugin->getCssPath();
	}

	/**
	 * Enable/Disable status
	 *
	 * @return bool Whether or not the plugin is enabled
	 */
	function getEnabled() {
		$plugin =& $this->getMarkupPlugin();
		return $plugin->getEnabled();
	}

	/**
	 * Get the management verbs for this plugin (override to none so that the
	 * parent plugin can handle this)
	 *
	 * @return array
	 */
	function getManagementVerbs() {
		return array();
	}

	//
	// Public plugin methods
	//
	/**
	 * Handles URL request to trigger document markup processing for given
	 * article; also handles URL requests for xml/pdf/html versions of an
	 * article as well as the xml/html's image and css files.
	 *
	 * URL is usually of form:
	 * http://.../index.php/chaos/gateway/plugin/markup/...
	 *     .../0/[articleId]/[fileName]  // eg. document.html/xml/pdf
	 *     .../css/[fileName]            // get stylesheets
	 *     .../refresh/[articleid]       // generate zip file
	 *     .../refreshgalley/[articleid] // updates zip file
	 *
	 * When disable_path_info is true URL conveys the above in path[] parameter
	 * array.
	 *
	 * @param $args Array of relative url folders down from plugin
	 *
	 * @return bool Success status
	 */
	function fetch($args) {
		$this->import('MarkupPluginUtilities');
		foreach ($args as &$arg) { $arg = strtolower($arg); }

		if (!$this->getEnabled()) {
			$this->_printXMLMessage(__('plugins.generic.markup.archive.enable'));
			return;
		}

		// Make sure we're within a Journal context
		$journal =& Request::getJournal();
		if (!$journal) {
			$this->_printXMLMessage(__('plugins.generic.markup.archive.no_journal'));
			return;
		}

		// Handles relative urls like "../../css/styles.css"
		if ($args[0] == 'css') {
			$this->_downloadMarkupCSS($journal, $args[1]);
			return;
		}

		// Load the article
		$articleId = (int) $args[1];
		if (!$articleId) {
			$this->_printXMLMessage(__('plugins.generic.markup.archive.no_articleID'));
			return;
		}
		$articleDao =& DAORegistry::getDAO('ArticleDAO');
		$article =& $articleDao->getArticle($articleId);
		if (!$article) {
			$this->_printXMLMessage(__('plugins.generic.markup.archive.no_article'));
			return;
		}

		// Replace supplementary document file with Document Markup Server
		// conversion archive file. With 'refreshgalley' option. User
		// permissions don't matter here.
		if (substr($args[0], 0, 7) == 'refresh') {
			$this->_setUserId((int) $args[2]);
			$this->_refreshArticleArchive($article, ($args[0] == 'refreshgalley'));
			return;
		};

		// Here we deliver any markup file request if its article's publish
		// state allows it, or if user's credentials allow it. $args[0] is /0/, a
		// constant for now. $fileName should be a file name.
		if ((int) $args[0] != 0) {
			$this->_printXMLMessage(__('plugins.generic.markup.archive.no_article'));
			return;
		}

		if (!$fileName = MarkupPluginUtilities::cleanFileName($args[2])) {
			$this->_printXMLMessage(__('plugins.generic.markup.archive.bad_filename'));
			return;
		}

		$markupFolder = MarkupPluginUtilities::getSuppFolder($articleId) . '/markup/';
		if (!file_exists($markupFolder . $fileName)) {
			$this->_printXMLMessage(__('plugins.generic.markup.archive.no_file'));
			return;
		}

		// Most requests come in when an article is in its published state, so
		// check that first.
		if ($article->getStatus() == STATUS_PUBLISHED) {
			if (MarkupPluginUtilities::getUserPermViewPublished($user, $articleId, $journal, $fileName)) {
				MarkupPluginUtilities::downloadFile($markupFolder, $fileName);
				return;
			}
		}

		// Article is not published, so access can only be granted if user is
		// logged in and of the right type / connection to article
		if (!$user = Request::getUser()) {
			$this->_printXMLMessage(__('plugins.generic.markup.archive.login'));
			return;
		}

		if (MarkupPluginUtilities::getUserPermViewDraft($user, $articleId, $journal, $fileName)) {
			MarkupPluginUtilities::downloadFile($markupFolder, $fileName);
			return;
		}

		$this->_printXMLMessage(__('plugins.generic.markup.archive.no_access'));
		return;
	}

	//
	// Protected helper methods
	//
	/**
	 * Get userId of user interacting with this plugin
	 *
	 * @return int UserId
	 */
	function _getUserId() {
		return $this->_userId;
	}

	/**
	 * Set userId of plugin user.
	 *
	 * @param $userId int UserId
	 */
	function _setUserId($userId) {
		$this->_userId = strval($userId);
	}

	/**
	 * Returns a journal's CSS file to the browser. If the journal doesn't have
	 * one fall back to the one provided by the plugin
	 *
	 * @param $journal mixed Journal to fetch CSS for
	 * @param $fileName string File name of the CSS file to fetch
	 *
	 * @return bool Whether or not the CSS file exists
	 */
	function _downloadMarkupCSS(&$journal, $fileName) {
		$fileName = MarkupPluginUtilities::cleanFileName($fileName);
		import('classes.file.JournalFileManager');

		// Load the journals CSS path
		$journalFileManager = new JournalFileManager($journal);
		$cssFolder = $journalFileManager->filesDir . 'css/';

		// If journal CSS path doesn't exist fall back to plugin's CSS path
		if (!file_exists($cssFolder . $fileName)) {
			$cssFolder = $this->getCssPath();
		}

		return MarkupPluginUtilities::downloadFile($cssFolder, $fileName);
	}

	/**
	 * Refresh article's markup archive.
	 * If article's supplementary file is not a .zip then send the file to the
	 * Doucument Markup server for conversion. Once the document has been
	 * converted add the .zip as supplementary file.
	 *
	 * Optionally create galley xml, html and pdf links.
	 *
	 * @param $article mixed Article to refresh
	 * @param $galleyFlag bool Whether or not to update the galley
	 *
	 * @return void
	 */
	function _refreshArticleArchive(&$article, $galleyFlag) {
		$journal =& Request::getJournal();
		$journalId = $journal->getId();
		$articleId = $article->getId();

		$suppFileDao =& DAORegistry::getDAO('SuppFileDAO');
		$suppFiles =& $suppFileDao->getSuppFilesBySetting('title', MARKUP_SUPPLEMENTARY_FILE_TITLE, $articleId);
		$suppFile = $suppFiles[0];
		if (!$suppFile) {
			$this->_printXMLMessage(__('plugins.generic.markup.archive.supp_missing'), true);
			return;
		}

		$fileId = $suppFile->getFileId();
		if ($fileId == 0) {
			$this->_printXMLMessage(__('plugins.generic.markup.archive.supp_file_missing'), true);
			return;
		}

		$suppFileName = $suppFile->getFileName();

		// If supplementary file is already a zip, there's nothing to do. It's
		// been converted.
		if (preg_match('/\.zip$/', strtolower($suppFileName))) {
			$this->_printXMLMessage(__('plugins.generic.markup.archive.is_zip'));
			return;
		}

		$jobMetaData =& $this->_jobMetaData($article, $journal);
		$userFile = MarkupPluginUtilities::getSuppFolder($articleId) . '/' . $suppFileName;

		import('lib.pkp.classes.core.JSONManager');
		$postFields = array(
			'jit_events' => JSONManager::encode(array($jobMetaData)),
			'userfile' => '@' . $userFile
		);

		// CURL sends article file to pdfx server for processing, and (since no
		// timeout given) in 15-30+ seconds or so returns jobId which is folder
		// where document.zip archive of converted documents sits.
		$ch = curl_init();
		$url = $this->getSetting($journalId, 'markupHostURL') . 'process.php';
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_VERBOSE, 1);
		$content = curl_exec($ch);
		$errorMsg = curl_error($ch);
		curl_close($ch);

		if ($content === false) {
			$this->_printXMLMessage($errorMsg, true);
			return;
		}

		$events = JSONManager::decode($content);
		$response = array_pop($events->jit_events);

		if ($response && $response->error > 0) {
			$this->_printXMLMessage($response->message . ':' . $content, true);
			return;
		}

		if (!($jobId = $this->_getResponseJobId($response))) {
			$this->_printXMLMessage(
				__('plugins.generic.markup.archive.no_job', array('jobId' => $jobId)), true
			);
			return;
		}

		$this->_retrieveJobArchive($articleId, $journalId, $jobId, $suppFile);

		// Unzip file and launch galleys only during layout upload
		if ($galleyFlag) {
			if (!$this->_unzipSuppFile($articleId, $suppFile)) {
				return;
			}
			$this->_setupGalleyForMarkup($articleId, 'document.html');
			$this->_setupGalleyForMarkup($articleId, 'document-new.pdf');
			$this->_setupGalleyForMarkup($articleId, 'document.xml');
		} else {
			// If we're not launching a galley, then if there are no galley
			// links left in place for XML or HTML content, then make sure
			// markup/images & media are deleted for given article.
			MarkupPluginUtilities::checkGalleyMedia($articleId);
		}

		$this->_printXMLMessage(
			__('plugins.generic.markup.completed', array('articleId' => $articleId, 'jobId' => $jobId)),
			true
		);
	}

	/**
	 * Get jobId from document markup server response
	 *
	 * @param $response mixed Response object
	 *
	 * @return string JobId
	 */
	function _getResponseJobId(&$response) {
		if (isset($response->data)) {
			$responseData =& $response->data;
		} else {
			$responseData = null;
		}
		$jobId = isset($responseData->jobId) ? $responseData->jobId : null;
		if (!$jobId or strlen($jobId) != 32) { return false; }

		// TODO: Why is that? Does the job server return inconsistent jobId's?
		return preg_replace('/[^a-zA-Z0-9]/', '', $jobId);
	}

	/**
	 * Produce an array of article metadata for the Document Markup Server
	 * article conversion
	 *
	 * @param $article mixed Article
	 * @param $journal mixed Journal
	 *
	 * @return array Article metadata
	 */
	function _jobMetaData(&$article, &$journal) {
		$articleId = $article->getId();
		$journalId = $journal->getId();

		// Prepare request for Document Markup Server
		$args = array(
			'type' => 'PDFX.fileUpload',
			'data' => array(
				'user' => $this->getSetting($journalId, 'markupHostUser'),
				'pass' => $this->getSetting($journalId, 'markupHostPass'),
				'cslStyle' => $this->getSetting($journalId, 'cslStyle'),
				'cssURL' => '',
				'title' => $article->getLocalizedTitle(),
				'authors' => $this->_getAuthorMetaData($article->getAuthors()),
				'journalId' => $journalId,
				'articleId' => $articleId,
				'publicationName' => $journal->getLocalizedTitle(),
				'copyright' => strip_tags($journal->getLocalizedSetting('copyrightNotice')),
				'publisher' => strip_tags($journal->getLocalizedSetting('publisherNote')),
				'rights' => strip_tags($journal->getLocalizedSetting('openAccessPolicy')),
				'eISSN' => $journal->getLocalizedSetting('onlineIssn'),
				'ISSN' => $journal->getLocalizedSetting('printIssn'),
				'DOI' => $article->getPubId('doi'),
			)
		);

		// Add the header image if it exists
		import('classes.file.JournalFileManager');
		$journalFileManager = new JournalFileManager($journal);
		$imageFileGlob = $journalFileManager->filesDir . 'css/article_header.{jpg,png}';
		$files = glob($imageFileGlob, GLOB_BRACE);
		$cssHeaderImageName = basename($files[0]);
		if ($cssHeaderImageName) {
			$args['data']['cssHeaderImageURL'] = '../../css/' . $cssHeaderImageName;
		}

		// Issue specific information
		$issueDao =& DAORegistry::getDAO('IssueDAO');
		$issue =& $issueDao->getIssueByArticleId($articleId, $journalId);
		if ($issue && $issue->getPublished()) {
			$args['data']['number'] = $issue->getNumber();
			$args['data']['volume'] = $issue->getVolume();
			$args['data']['year'] = $issue->getYear();
			$args['data']['publicationDate'] = $issue->getDatePublished();
		};

		$reviewVersion = $this->getSetting($journalId, 'reviewVersion');
		if ($reviewVersion == true) {
			$args['data']['reviewVersion'] = true;
		};

		return $args;
	}

	/**
	 * Returns an array with author metadata. Strips sequence and biography
	 * information.
	 *
	 * @param $authors mixed Array with author meta information
	 *
	 * @return mixed Processed author metadata
	 */
	function _getAuthorMetaData($authors) {
		$processed = array();
		foreach ($authors as $author) {
			$author = ($author->_data);
			unset($author['sequence'], $author['biography']);
			$processed[] = $author;
		}

		return $processed;
	}

	/**
	 * Fetches processed job archive from Document Markup server and replaces
	 * existing supplementary file.
	 *
	 * @param $articleId int ArticleId
	 * @param $journalId int JournalId
	 * @param $jobId string Conversion jobId
	 * @param $suppFile Supplementary file to update with the converted file
	 *
	 * @return void
	 */
	function _retrieveJobArchive($articleId, $journalId, $jobId, &$suppFile) {
		import('classes.file.ArticleFileManager');
		$articleFileManager = new ArticleFileManager($articleId);

		$jobURL = $this->getSetting($journalId, 'markupHostURL') . 'job/' . $jobId . '/document.zip';
		$suppFileId = $suppFile->getFileId();
		$mimeType = 'application/zip';
		if ($suppFileId == 0) {
			$suppFileId = $articleFileManager->copySuppFile($jobURL, $mimeType);
			$suppFile->setFileId($suppFileId);
		} else {
			$articleFileManager->copySuppFile($jobURL, $mimeType, $suppFileId);
		}
		$suppFileDao =& DAORegistry::getDAO('SuppFileDAO');
		$suppFileDao->updateSuppFile($suppFile);
	}

	/**
	 * Extract valid document files from supplementary file.
	 *
	 * @param $articleId int ArticleId
	 * @param $suppFile mixed Supplementary file to extract documents from
	 *
	 * @return bool Wheter or not the extraction was successful
	 */
	function _unzipSuppFile($articleId, &$suppFile) {
		// We need updated name. It was x.pdf or docx, now its y.zip:
		$suppFileDao =& DAORegistry::getDAO('SuppFileDAO');
		$suppFile =& $suppFileDao->getSuppFile($suppFile->getId());
		$suppFileName = $suppFile->getFileName();
		$suppFolder = MarkupPluginUtilities::getSuppFolder($articleId);
		$zipFile = $suppFolder . '/' . $suppFileName;

		$zip = new ZipArchive;
		if (!$zip->open($zipFile, ZIPARCHIVE::CHECKCONS)) {
			$this->_printXMLMessage(
				__(
					'plugins.generic.markup.archive.bad_zip',
					array(
						'file' => $zipFile,
						'error' => $zip->getStatusString()
					)
				),
				true
			);
			return false;
		}

		$validFiles = array(
			'document-new.pdf',
			'document-review.pdf',
			'document.html',
			'document.xml',
			'manifest.xml',
		);
		// TODO: 'try "media" folder.'; check what dev meant with that
		$extractFiles = array();
		foreach ($validFiles as $validFile) {
			if ($zip->locateName($validFile) !== false) {
				$extractFiles[] = $validFile;
			}
		}

		// Get all graphics
		// TODO: do we only support jpg and png?
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$fileName = $zip->getNameIndex($i);
			if (preg_match('/\.(png|jpg)$/i', $fileName)) {
				$extractFiles[] = $fileName;
			}
		}

		// TODO: check what dev meant with this: "PHP docs say extractTo()
		// returns false on failure, but its triggering this, and yet returning
		// "No error" for $errorMsg below."
		if (
			$zip->extractTo($suppFolder . '/markup', $extractFiles) === false &&
			$zip->getStatusString() != 'No error'
		) {
			$zip->close();
			$this->_printXMLMessage(
				__(
					'plugins.generic.markup.archive.bad_zip',
					array(
						'file' => $zipFile,
						'error' => $zip->getStatusString()
					)
				),
				true
			);
			return false;
		}

		$zip->close();

		return true;
	}

	/**
	 * Populates an article with galley files for document.html, document.xml
	 * and document.pdf
	 *
	 * @param $articleId int Article Id
	 * @param $fileName string File to process
	 *
	 * @return int Id of new galley link
	 */
	function _setupGalleyForMarkup($articleId, $fileName) {
		$journal =& Request::getJournal();
		$mimeType = MarkupPluginUtilities::getMimeType($fileName);

		import('classes.file.ArticleFileManager');
		$articleFileManager = new ArticleFileManager($articleId);
		$fileExtension = strtoupper(ArticleFileManager::parseFileExtension($fileName));
		$archiveFile = MarkupPluginUtilities::getSuppFolder($articleId) . '/markup/' . $fileName;
		$suppFileId = $articleFileManager->copySuppFile($archiveFile, $mimeType);

		$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
		$galleys =& $galleyDao->getGalleysByArticle($articleId);
		foreach ($galleys as $galley) {
			// Doing by suffix since usually no isXMLGalley() fn
			// TODO: check if this is legit
			if ($galley->getLabel() == $fileExtension) {
				$galley->setFileId($suppFileId);
				$galleyDao->updateGalley($galley);
				return true;
			}
		}

		$galley = new ArticleGalley();
		$galley->setArticleId($articleId);
		$galley->setFileId($suppFileId);
		$galley->setLabel($fileExtension);
		$galley->setLocale(AppLocale::getLocale());
		$galleyDao->insertGalley($galley);
		return $galley->getId();
	}

	/**
	 * Atom XML template displayed in response to a plugin gateway fetch() where
	 * a message or error condition is reported (instead of returning a file).
	 *
	 * @param $message string Status message
	 * @param $notification boolean Whether or not a notification should be
	 * shown to the user
	 *
	 * @return void
	 */
	function _printXMLMessage($message, $notification = false) {
		if ($notification == true) {
			$this->import('MarkupPluginUtilities');
			// TODO: for now all notifications are success types
			MarkupPluginUtilities::showNotification(
				__('plugins.generic.markup.archive.status', array('message' => $message)),
				true,
				$this->_getUserId()
			);
		}
		$templateMgr =& TemplateManager::getManager();
		$templateMgr->assign_by_ref('journal', $journal);
		$templateMgr->assign('selfUrl', Request::getCompleteUrl());
		$templateMgr->assign('dateUpdated', Core::getCurrentDate());
		$templateMgr->assign('description', $message);

		$templateMgr->display($this->getTemplatePath() . '/fetch.tpl', 'application/atom+xml');
	}
}
