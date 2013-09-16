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
	 * @return String name of plugin
	 */
	function getName() {
		$plugin =& $this->getMarkupPlugin();
		return $plugin->getName();
	}

	/**
	 * Hide this plugin from the management interface (it's subsidiary)
	 */
	function getHideManagement() {
		return true;
	}

	function getDisplayName() {
		return __('plugins.generic.markup.displayName');
	}

	function getDescription() {
		return __('plugins.generic.markup.description');
	}

	/**
	 * Get the web feed plugin
	 * @return object
	 */
	function &getMarkupPlugin() {
		$plugin =& PluginRegistry::getPlugin('generic', MARKUP_PLUGIN_NAME);
		return $plugin;
	}

	/**
	 * Override the built-in to get the correct plugin path.
	 */
	function getPluginPath() {
		$plugin =& $this->getMarkupPlugin();
		return $plugin->getPluginPath();
	}

	/**
	 * Override the builtin to get the correct template path.
	 * @return string
	 */
	function getTemplatePath() {
		$plugin =& $this->getMarkupPlugin();
		return $plugin->getTemplatePath() . 'templates/';
	}

	/**
	 * Get whether or not this plugin is enabled. (Should always return true, as
	 * the parent plugin will take care of loading this one when needed)
	 * @return boolean
	 */
	function getEnabled() {
		$plugin =& $this->getMarkupPlugin();
		return $plugin->getEnabled();
	}

	/**
	 * Get the management verbs for this plugin (override to none so that the
	 * parent plugin can handle this)
	 * @return array
	 */
	function getManagementVerbs() {
		return array();
	}

	//
	// Public plugin methods
	//
	/**
	 * Gateway single point of entry.
	 * All other methods below spun off of fetch().
	 * Handles URL request to trigger document markup processing for given
	 * article; also handles URL requests for xml/pdf/html versions of an
	 * article as well as the xml/html's image and css files.
	 * URL is usually of form:
	 * http://.../index.php/chaos/gateway/plugin/markup/...
	 *     .../0/[articleId]/[fileName]  // eg. document.html/xml/pdf
	 *     .../css/[fileName]            // get stylesheets
	 *     .../refresh/[articleid]       // generate zip file
	 *     .../refreshgalley/[articleid] // updates zip file
	 * When disable_path_info is true URL conveys the above in path[] parameter
	 * array.
	 *
	 * @param $args Array of relative url folders down from plugin
	 *
	 * @see MarkupPluginUtilities::getMarkupURL() - the url generator.
	 */
	function fetch($args) {
		$this->import('MarkupPluginUtilities');
		foreach ($args as &$arg) { $arg = strtolower($arg); }

		if (!$this->getEnabled()) {
			return $this->_printXMLMessage(__('plugins.generic.markup.archive.enable'));
		}

		// Make sure we're within a Journal context
		$journal =& Request::getJournal();
		if (!$journal) {
			return $this->_printXMLMessage(__('plugins.generic.markup.archive.no_journal'));
		}

		// Handles relative urls like "../../css/styles.css"
		if ($args[0] == 'css') {
			return $this->_downloadMarkupCSS($journal, $args[1]);
		}

		// Load the article
		$articleId = (int) $args[1];
		if (!$articleId) {
			return $this->_printXMLMessage(__('plugins.generic.markup.archive.no_articleID'));
		}
		$articleDao =& DAORegistry::getDAO('ArticleDAO');
		$article =& $articleDao->getArticle($articleId);
		if (!$article) {
			return $this->_printXMLMessage(__('plugins.generic.markup.archive.no_article'));
		}

		// Replace supplementary document file with Document Markup Server
		// conversion archive file. With 'refreshgalley' option. User
		// permissions don't matter here.
		if (substr($args[0], 0, 7) == 'refresh') {
			$this->_setUserId((int) $args[2]);
			$this->_refreshArticleArchive($article, ($args[0] == 'refreshgalley'));
			return true;
		};

		// Here we deliver any markup file request if its article's publish
		// state allows it, or if user's credentials allow it. $args[0] is /0/, a
		// constant for now. $fileName should be a file name.
		if ((int) $args[0] != 0) {
			return $this->_printXMLMessage(__('plugins.generic.markup.archive.no_article'));
		}

		if (!$fileName = MarkupPluginUtilities::cleanFileName($args[2])) {
			return $this->_printXMLMessage(__('plugins.generic.markup.archive.bad_filename'));
		}

		$markupFolder = MarkupPluginUtilities::getSuppFolder($articleId) . '/markup/';
		if (!file_exists($markupFolder . $fileName)) {
			return $this->_printXMLMessage(__('plugins.generic.markup.archive.no_file'));
		}


		// Most requests come in when an article is in its published state, so
		// check that first.
		if ($article->getStatus() == STATUS_PUBLISHED) {
			if (MarkupPluginUtilities::getUserPermViewPublished($user, $articleId, $journal, $fileName)) {
				MarkupPluginUtilities::downloadFile($markupFolder, $fileName);
				return true;
			}
		}

		// Article is not published, so access can only be granted if user is
		// logged in and of the right type / connection to article
		if (!$user = Request::getUser()) {
			return $this->_printXMLMessage(__('plugins.generic.markup.archive.login'));
		}

		if (MarkupPluginUtilities::getUserPermViewDraft($user, $articleId, $journal, $fileName)) {
			MarkupPluginUtilities::downloadFile($markupFolder, $fileName);
			return true;
		}

		return $this->_printXMLMessage(__('plugins.generic.markup.archive.no_access'));
	}

	//
	// Protected helper methods
	//
	/**
	 * Get userId of user interacting with this plugin
	 * (used by notification system only).
	 * @return String user Id (Request::getUser()->getID is string)
	 */
	function _getUserId() {
		if (empty($this->_userId)) {
			$user =& Request::getUser();
			$this->_setUserId($user->getId());
		}
		return $this->_userId;
	}

	/**
	 * Set userId of plugin user.
	 * Passed with curl requests for actions on behalf of an editor etc.
	 * @param String user Id
	 */
	function _setUserId($id) {
		$this->_userId = strval($id);
	}

	/**
	 * Provide Journal specific css stylesheets
	 * CSS is public so no permission check. Returns css files for relative
	 * URLs like "../../css/styles.css" content. Journal's [filesDir]/css is
	 * checked first for content to return. If nothing there, then check the
	 * markup plugin's own css folder. This is the only folder below /markup's
	 * folder.
	 *
	 * @param $fileName;
	 */
	function _downloadMarkupCSS(&$journal, $fileName) {
		$fileName = MarkupPluginUtilities::cleanFileName($fileName);
		import('classes.file.JournalFileManager');
		$journalFileManager = new JournalFileManager($journal);
		$folderCss = $journalFileManager->filesDir . 'css/';
		if (!file_exists($folderCss . $fileName)) {
			// Default to this plugin dir
			$folderCss = dirname(__FILE__) . '/css/';
		}
		return MarkupPluginUtilities::downloadFile($folderCss, $fileName);
	}

	/**
	 * Request is for a "refresh" of an article's markup archive.
	 * If article's supplementary file is not a .zip (in other words it is an
	 * uploaded .doc or .pdf), then send the supplementary file to the
	 * PKP Document Markup Server for conversion.
	 * Then retrieve archive file and place it in supplementary file.
	 * Optionally create galley xml, html and pdf links.
	 *
	 * @param $article object
	 * @param $galleyFlag boolean
	 *
	 * @see fetch()
	 * @see technicalNotes.md file for details on the interface between this
	 * plugin and the Document Markup Server.
	 */
	function _refreshArticleArchive(&$article, $galleyFlag) {
		$journal =& Request::getJournal();
		$journalId = $journal->getId();
		$articleId = $article->getId();

		// Conditions
		$suppFileDao =& DAORegistry::getDAO('SuppFileDAO');
		$suppFiles =& $suppFileDao->getSuppFilesBySetting('title', MARKUP_SUPPLEMENTARY_FILE_TITLE, $articleId);
		if (count($suppFiles) == 0) {
			return $this->_printXMLMessage(__('plugins.generic.markup.archive.supp_missing'), true);
		}

		$suppFile = $suppFiles[0]; // There should only be one.

		$fileId = $suppFile->getFileId();
		if ($fileId == 0) {
			return $this->_printXMLMessage(__('plugins.generic.markup.archive.supp_file_missing'), true);
		}

		$suppFileName = $suppFile->getFileName();

		// If supplementary file is already a zip, there's nothing to do. Its
		// been converted.
		if (preg_match('/.*\.zip/', strtolower($suppFileName))) {
			return $this->_printXMLMessage(__('plugins.generic.markup.archive.is_zip'));
		}

		$args =& $this->_jobMetaData($article, $journal);
		$argsArray = array($args);
		$uploadFile = MarkupPluginUtilities::getSuppFolder($articleId) . '/' . $suppFileName;

		import('lib.pkp.classes.core.JSONManager');
		$postFields = array(
			'jit_events' => JSONManager::encode($argsArray),
			'userfile' => '@' . $uploadFile
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
			return $this->_printXMLMessage($errorMsg, true);
		}

		$events = JSONManager::decode($content);
		$responses = $events->jit_events;
		$response = $responses[0];

		if ($response->error > 0) {
			return $this->_printXMLMessage($response->message . ':' . $content, true);
		}

		// With a $jobId, we can fetch URL of zip file and enter into
		// supplimentary file record.
		$jobId = $this->_getResponseJobId($response);
		if (strlen($jobId) == 0 || strlen($jobId) > 32) {
			return $this->_printXMLMessage(__('plugins.generic.markup.archive.no_job') . $jobId, true);
		}

		$this->_retrieveJobArchive($articleId, $journalId, $jobId, $suppFile);

		// Unzip file and launch galleys only during layout upload
		if ($galleyFlag) {
			if (!$this->_unzipSuppFile($articleId, $suppFile, $galleyFlag)) {
				return true;
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

		$this->_printXMLMessage(__('plugins.generic.markup.completed') . " $articleId (Job $jobId)", true);

		return true;
	}

	/**
	 * Get jobId from document markup server response
	 * This should be a 32 character long alphanumeric string.
	 *
	 * @param $response object
	 */
	function _getResponseJobId(&$response) {
		$responseData =& $response->data;
		$jobId = $responseData->jobId;
		return preg_replace('/[^a-zA-Z0-9]/', '', $jobId);
	}

	/**
	 * Produce an array of settings to be processed by the Document Markup Server.
	 * Note: we include these getAuthors() fields: submissionId, firstName,
	 * middleName, lastName, country, email, url, primaryContact (boolean),
	 * affiliation: {"en_US":"..."}
	 *
	 * @param $article object
	 * @param $journal object
	 *
	 * @return $args array
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
				'articleId' => $article->getId(),

				'publicationName' => $journal->getLocalizedTitle(),
				'copyright' => strip_tags($journal->getLocalizedSetting('copyrightNotice')),
				'publisher' => strip_tags($journal->getLocalizedSetting('publisherNote')),
				'rights' => strip_tags($journal->getLocalizedSetting('openAccessPolicy')),
				'eISSN' => $journal->getLocalizedSetting('onlineIssn'),
				'ISSN' => $journal->getLocalizedSetting('printIssn'),

				'DOI' => $article->getPubId('doi'),
			)
		);

		// This field has content only if header image actually exists in the right folder.
		import('classes.file.JournalFileManager');
		$journalFileManager = new JournalFileManager($journal);
		$imageFileGlob = $journalFileManager->filesDir . 'css/article_header.{jpg,png}';
		$g = glob($imageFileGlob, GLOB_BRACE);
		$cssHeaderImageName = basename($g[0]);
		if (strlen($cssHeaderImageName) > 0) {
			$args['data']['cssHeaderImageURL'] = '../../css/' . $cssHeaderImageName;
		}

		// Provide some publication info
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
	 * Get article author info without sequence or biography info
	 *
	 * @param $authors array
	 */
	function _getAuthorMetaData($authors) {
		$authorsArray = array();
		foreach ($authors as $author) {
			$author = ($author->_data);
			unset($author['sequence'], $author['biography']);
			$authorsArray[] = $author;
		}

		return $authorsArray;
	}

	/**
	 * Fetch document.zip file waiting in job folder at Document Markup Server
	 * Document.zip replaces existing supplementary file.
	 *
	 * @param $articleId int
	 * @param $jobId string jobId from Document Markup Server
	 * @param $suppfile object
	 */
	function _retrieveJobArchive($articleId, $journalId, $jobId, &$suppFile) {
		import('classes.file.ArticleFileManager');
		$articleFileManager = new ArticleFileManager($articleId);

		$jobURL = $this->getSetting($journalId, 'markupHostURL') . 'job/' . $jobId . '/document.zip';

		$suppFileId = $suppFile->getFileId();
		if ($suppFileId == 0) {
			$suppFileId = $articleFileManager->copySuppFile($jobURL, 'application/zip');
			$suppFile->setFileId($suppFileId);
		} else {
			$articleFileManager->copySuppFile($jobURL, 'application/zip', $suppFileId, true);
		}
		$suppFileDao =& DAORegistry::getDAO('SuppFileDAO');
		$suppFileDao->updateSuppFile($suppFile);
	}

	/**
	 * Unzip document.zip into an article's supplementary file markup folder.
	 * Unzip is triggered by URL call to /refreshGalley ; in OJS this is
	 * triggered by editor's file upload to layout files area. Article has a
	 * freshly generated supplementary documents.zip file. Now into the /markup
	 * folder, extract all graphics, and the converted documents, and then make
	 * galley links for the xml, pdf, and html files.
	 * Notifications are triggered because this is in response to work done on
	 * an article.
	 * WARNING: zip extractTo() function will fail completely if we include an
	 * entry in $extractFiles[] that doesn't exist in zip manifest.
	 *
	 * @param $articleId int
	 * @param $suppFile object
	 * @param $galleyFlag boolean signals creation of galley links
	 *
	 * @see _retrieveJobArchive()
	 */
	function _unzipSuppFile($articleId, &$suppFile, $galleyFlag) {
		// We need updated name. It was x.pdf or docx, now its y.zip:
		$suppFileDao =& DAORegistry::getDAO('SuppFileDAO');
		$suppFile =& $suppFileDao->getSuppFile($suppFile->getId());
		$suppFileName = $suppFile->getFileName();

		$suppFolder = MarkupPluginUtilities::getSuppFolder($articleId);

		$zip = new ZipArchive;
		if (!$zip->open($suppFolder . '/' . $suppFileName, ZIPARCHIVE::CHECKCONS)) {
			$errorMsg = $zip->getStatusString();
			$this->_printXMLMessage(
				__('plugins.generic.markup.archive.bad_zip') . ':' . $suppFileName . ':' . $errorMsg, true
			);
			return false;
		}

		// Ensure that we only extract "good" files.
		$candidates = array('manifest.xml','document.xml', 'document-new.pdf', 'document.html','document-review.pdf');
		// FIXME: try "media" folder.
		$extractFiles = array();
		for ($i = 0; $i < count($candidates); $i++) {
			$candidate = $candidates[$i];
			if ($zip->locateName($candidate) !== false)
				$extractFiles[] = $candidate;
		};

		// Get all graphics
		$extractSuffixes = array('png','jpg');
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$fileName = $zip->getNameIndex($i);
			if (in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), $extractSuffixes)) {
				$extractFiles[] = $fileName;
			}
		}

		// PHP docs say extractTo() returns false on failure, but its triggering
		// this, and yet returning "No error" for $errorMsg below.
		if ($zip->extractTo($suppFolder . '/markup', $extractFiles) === false) {
			$errorMsg = $zip->getStatusString();
			if ($errorMsg != 'No error') {
				$zip->close();
				$this->_printXMLMessage(__('plugins.generic.markup.archive.bad_zip') . $errorMsg, true);
				return false;
			}
		}
		$zip->close();

		return true;
	}

	/**
	 * Populates an article with galley files for document.html, document.xml
	 * and document.pdf
	 * Note: Currently we avoid creating XML and HTML galleys using
	 * classes.article.ArticleHTMLGalley since we are privately handling all
	 * image and css files through other avenues. When HTML galleys are
	 * displayed to users (MarkupPlugin::displayGalley), they are dynamically
	 * rewritten to display css and media correctly.
	 *
	 * @param $articleId int
	 * @param $fileName string document.[xml | pdf | html] to link
	 *
	 * @return $galleyId int Id of new galley link created.
	 */
	function _setupGalleyForMarkup($articleId, $fileName) {
		$journal =& Request::getJournal();

		$mimeType = String::mime_content_type($fileName);

		import('classes.file.ArticleFileManager');
		$articleFileManager = new ArticleFileManager($articleId);
		$fileExt = strtoupper(ArticleFileManager::parseFileExtension($fileName));
		$archiveFile = MarkupPluginUtilities::getSuppFolder($articleId) . '/markup/' . $fileName;
		$suppFileId = $articleFileManager->copySuppFile($archiveFile, $mimeType);

		$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
		$gals =& $galleyDao->getGalleysByArticle($articleId);
		foreach ($gals as $galley) {
			// Doing by suffix since usually no isXMLGalley() fn
			if ($galley->getLabel() == $fileExt) {
				$galley->setFileId($suppFileId);
				$galleyDao->updateGalley($galley);
				return true;
			}
		}

		$galley = new ArticleGalley();
		$galley->setArticleId($articleId);
		$galley->setFileId($suppFileId);
		$galley->setLabel($fileExt);
		$galley->setLocale(AppLocale::getLocale());
		$galleyDao->insertGalley($galley);
		return $galley->getId();
	}

	/**
	 * Atom XML template displayed in response to a plugin gateway fetch() where
	 * a message or error condition is reported (instead of returning a file).
	 * Never seen by OJS end users unless accessing a document directly by URL
	 * and there is a problem. Useful for programmers to debug fetch issue.
	 *
	 * @param $msg string status indicating job success or error
	 * @param $notification boolean indicating if user should be notified.
	 */
	function _printXMLMessage($msg, $notification) {
		if ($notification == true) {
			$this->import('MarkupPluginUtilities');
			// FIXME: for now all notifications are success types
			MarkupPluginUtilities::notificationService(
				__('plugins.generic.markup.archive.status') . ' ' . $msg, true, $this->_getUserId()
			);
		}
		$templateMgr =& TemplateManager::getManager();
		$templateMgr->assign_by_ref('journal', $journal);
		$templateMgr->assign('selfUrl', Request::getCompleteUrl());
		$templateMgr->assign('dateUpdated', Core::getCurrentDate());
		$templateMgr->assign('description', $msg);

		$templateMgr->display($this->getTemplatePath() . '/fetch.tpl', 'application/atom+xml');

		return true;
	}
}
