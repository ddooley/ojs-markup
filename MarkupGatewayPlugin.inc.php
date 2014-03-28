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
		// Parse keys and values from arguments
		$keys = array();
		$values = array();
		foreach ($args as $index => $arg) {
			if ($index % 2 == 0) {
				$keys[] = $arg;
			} else {
				$values[] = $arg;
			}
		}
		$args = array_combine($keys, $values);

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
		// TODO: Research that
		if (isset($args['css'])) {
			$this->_downloadMarkupCSS($journal, $args[1]);
			return;
		}

		// Load the article
		$articleId = isset($args['articleId']) ? (int) $args['articleId'] : false;
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
		if (isset($args['refresh']) or isset($args['refreshgalley'])) {
			$this->_setUserId((int) $args['userId']);
			$this->_refreshArticleArchive($article, (isset($args['refreshgalley'])));
			return;
		};

		// Here we deliver any markup file request if its article's publish
		// state allows it, or if user's credentials allow it. $args[0] is /0/, a
		// constant for now. $fileName should be a file name.
		// TODO: check that
		if (false and (int) $args[0] != 0) {
			$this->_printXMLMessage(__('plugins.generic.markup.archive.no_article'));
			return;
		}

		$this->import('MarkupPluginUtilities');

		if (!$fileName = $args['fileName']) {
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

		if ($this->getUserPermViewDraft($user, $articleId, $journal, $fileName)) {
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


		// If supplementary file is already a zip, there's nothing to do. It's
		// been converted.
		$suppFileName = $suppFile->getFileName();
		if (preg_match('/\.zip$/', strtolower($suppFileName))) {
			$this->_printXMLMessage(__('plugins.generic.markup.archive.is_zip'));
			return;
		}

		// Submit the file to the markup server for conversion
		$suppFilePath = MarkupPluginUtilities::getSuppFolder($articleId) . '/' . $suppFileName;
		$apiResponse = MarkupPluginUtilities::submitFile($this, $suppFileName, $suppFilePath);

		if ($apiResponse['status'] == 'error') {
			$this->_printXMLMessage($apiResponse['error'], true);
			return;
		}

		MarkupPluginUtilities::saveJobIdSuppFile($suppFile, $apiResponse['id']);

		$this->_retrieveJobArchive($articleId, $suppFile);

		/*
		 * TODO: Disabled for now. Check implementation
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
		*/
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
	function _retrieveJobArchive($articleId, $suppFile) {
		$jobId = MarkupPluginUtilities::getJobIdSuppFile($suppFile);

		// Wait for max 2 minutes for the job to complete
		$i = 0;
		while($i++ < 40) {
			$apiResponse = MarkupPluginUtilities::getJobStatus($this, $jobId);
			if ($apiResponse['jobStatus'] != 0) break; // Jobstatus 0 - pending
			sleep(5);
		}

		// Return if the job didn't complete
		if ($apiResponse['jobStatus'] != 2) return;

		// Download the Zip archive. This is a workaround because the file name
		// detection fails for API URLs if we use copySuppFile() with the API
		// URL
		$url = MarkupPluginUtilities::getZipFileUrl($this, $jobId);
		$tmpZipFile = sys_get_temp_dir() . '/documents.zip';
		@unlink($tmpZipFile);
		@copy($url, $tmpZipFile);

		if (!file_exists($tmpZipFile)) return;

		$mimeType = 'application/zip';
		$suppFileId = $suppFile->getFileId();
		MarkupPluginUtilities::removeJobIdSuppFile($suppFile);
		import('classes.file.ArticleFileManager');
		$articleFileManager = new ArticleFileManager($articleId);
		if ($suppFileId == 0) {
			$suppFileId = $articleFileManager->copySuppFile($tmpZipFile, $mimeType);
			$suppFile->setFileId($suppFileId);
		} else {
			$articleFileManager->copySuppFile($tmpZipFile, $mimeType, $suppFileId);
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
	 * TODO: Check if we can remove this.
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

	/**
	 * Get a users role for a journal and article
	 *
	 * @param $userId int UserId
	 * @param $articleId int ArticleId to check roles for
	 * @param $journal mixed Journal to check roles for
	 * @param $fileName string File name for reviewer access
	 *
	 * @return int RoleId of user in journal and article
	 **/
	function getUserPermViewDraft($userId, $articleId, &$journal, $fileName) {
		$journalId = $journal->getId();

		$roleDao =& DAORegistry::getDAO('RoleDAO');
		$roles =& $roleDao->getRolesByUserId($userId);
		foreach ($roles as $role) {
			$roleType = $role->getRoleId();
			if ($roleType == ROLE_ID_SITE_ADMIN) return ROLE_ID_SITE_ADMIN;

			if ($role->getJournalId() == $journalId) {
				switch ($roleType) {
					// These users get global access
					case ROLE_ID_JOURNAL_MANAGER :
					case ROLE_ID_EDITOR :
						return $roleType;
						break;

					case ROLE_ID_SECTION_EDITOR :
						$sectionEditorSubmissionDao =& DAORegistry::getDAO('SectionEditorSubmissionDAO');
						$sectionEditorSubmission =& $sectionEditorSubmissionDao->getSectionEditorSubmission($articleId);

						if (
							$sectionEditorSubmission != null &&
							$sectionEditorSubmission->getJournalId() == $journalId &&
							$sectionEditorSubmission->getDateSubmitted() != null
						) {
							// If this user isn't the submission's editor, they don't have access.
							$editAssignments =& $sectionEditorSubmission->getEditAssignments();

							foreach ($editAssignments as $editAssignment) {
								if ($editAssignment->getEditorId() == $userId) {
									return $roleType;
								}
							}
						};
						break;

					case ROLE_ID_LAYOUT_EDITOR :
						$signoffDao =& DAORegistry::getDAO('SignoffDAO');
						if ($signoffDao->signoffExists('SIGNOFF_LAYOUT', ASSOC_TYPE_ARTICLE, $articleId, $userId)) {
							return $roleType;
						}
						break;

					case ROLE_ID_PROOFREADER :
						$signoffDao =& DAORegistry::getDAO('SignoffDAO');
						if ($signoffDao->signoffExists('SIGNOFF_PROOFING', ASSOC_TYPE_ARTICLE, $articleId, $userId)) {
							return $roleType;
						}
						break;

					case ROLE_ID_COPYEDITOR:
						$sesDao =& DAORegistry::getDAO('SectionEditorSubmissionDAO');
						if ($sesDao->copyeditorExists($articleId, $userId))
							return $roleType;
						break;

					case ROLE_ID_AUTHOR:
						$articleDao =& DAORegistry::getDAO('ArticleDAO');
						$article =& $articleDao->getArticle($articleId, $journalId);
						if (
							$article &&
							$article->getUserId() == $userId &&
							(
								$article->getStatus() == STATUS_QUEUED ||
								$article->getStatus() == STATUS_PUBLISHED
							)
						) {
							return $roleType;
						}
						break;

					case ROLE_ID_REVIEWER:
						// Find out if article currently has this reviewer.
						$reviewAssignmentDao = DAORegistry::getDAO('ReviewAssignmentDAO');
						$reviewAssignments = $reviewAssignmentDao->getBySubmissionId($articleId);
						foreach ($reviewAssignments as $assignment) {
							if ($assignment->getReviewerId() == $userId) {
								// REVIEWER ACCESS: If reviewers are not supposed
								// to see list of authors, REVIEWER ONLY GETS TO
								// SEE document-review.pdf version, which has
								// all author information stripped.
								if (
									$this->getSetting($journalId, 'reviewVersion') != true ||
									$fileName == 'document-review.pdf'
								) {
									return $roleType;
								}
								break;
							}
						}
						break;
				}
			}
		}

		return false;
	}
}
