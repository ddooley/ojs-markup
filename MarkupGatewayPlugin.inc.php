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
	 * Handles URL requests to trigger document markup processing for given
	 * article; also handles download requests for xml/pdf/html versions of an
	 * article as well as the html's css files
	 *
	 * Accepted parameters:
	 *   articleId/[int],
	 *   fileName/[string]
	 *   refresh/[bool]
	 *   refreshGalley/[bool]
	 *   css/[string]
	 *   userId/[int]
	 *
	 * @param $args Array of url arguments
	 *
	 * @return void
	 */
	function fetch($args) {
		// Parse keys and values from arguments
		$keys = array();
		$values = array();
		foreach ($args as $index => $arg) {
			if ($arg == 'true') $arg = true;
			if ($arg == 'false') $arg = false;

			if ($index % 2 == 0) {
				$keys[] = $arg;
			} else {
				$values[] = $arg;
			}
		}
		$args = array_combine($keys, $values);

		if (!$this->getEnabled()) {
			echo __('plugins.generic.markup.archive.enable');
			exit;
		}

		// Make sure we're within a Journal context
		$journal =& Request::getJournal();
		if (!$journal) {
			echo __('plugins.generic.markup.archive.no_journal');
			exit;
		}

		// Handles requests for css files
		if (isset($args['css'])) {
			$this->_downloadMarkupCSS($journal, $args['css']);
			exit;
		}

		// Load the article
		$articleId = isset($args['articleId']) ? (int) $args['articleId'] : false;
		if (!$articleId) {
			echo __('plugins.generic.markup.archive.no_articleID');
			exit;
		}

		$articleDao =& DAORegistry::getDAO('ArticleDAO');
		$article =& $articleDao->getArticle($articleId);
		if (empty($article)) {
			echo __('plugins.generic.markup.archive.no_article');
			exit;
		}

		// Replace supplementary document file with Document Markup Server
		// conversion archive file. With 'refreshgalley' option. User
		// permissions don't matter here.
		if (isset($args['refresh'])) {
			$this->_setUserId((int) $args['userId']);
			$this->_refreshArticleArchive($article, (isset($args['refreshGalley'])));
			exit;
		};

		// Here we deliver any markup file request if its article's publish
		// state allows it, or if user's credentials allow it.
		if (!isset($args['fileName'])) {
			echo __('plugins.generic.markup.archive.bad_filename');
			exit;
		}

		$this->import('MarkupPluginUtilities');
		$markupFolder = MarkupPluginUtilities::getSuppFolder($articleId) . '/markup/';
		if (!file_exists($markupFolder . $args['fileName'])) {
			echo __('plugins.generic.markup.archive.no_file', array('file' => $args['fileName']));
			exit;
		}

		// Check if user can view published article
		$user =& Request::getUser();
		if ($article->getStatus() == STATUS_PUBLISHED) {
			if ($this->_getUserPermViewPublished($user, $articleId, $journal, $args['fileName'])) {
				MarkupPluginUtilities::downloadFile($markupFolder, $args['fileName']);
				exit;
			}
		}

		// Article is not published check if user can view draft
		if ($this->_getUserPermViewDraft($user, $articleId, $journal, $args['fileName'])) {
			MarkupPluginUtilities::downloadFile($markupFolder, $args['fileName']);
			exit;
		}

		echo __('plugins.generic.markup.archive.no_access');
		exit;
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
	function _downloadMarkupCSS($journal, $fileName) {
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
	function _refreshArticleArchive($article, $galleyFlag) {
		$journal =& Request::getJournal();
		$journalId = $journal->getId();
		$articleId = $article->getId();

		$suppFileDao =& DAORegistry::getDAO('SuppFileDAO');
		$suppFiles =& $suppFileDao->getSuppFilesBySetting('title', MARKUP_SUPPLEMENTARY_FILE_TITLE, $articleId);
		$suppFile = $suppFiles[0];
		if (!$suppFile) {
			echo __('plugins.generic.markup.archive.supp_missing');
			exit;
		}

		// If supplementary file is already a zip, there's nothing to do. It's
		// been converted.
		$suppFileName = $suppFile->getFileName();
		if (preg_match('/\.zip$/', strtolower($suppFileName))) {
			echo __('plugins.generic.markup.archive.is_zip');
			exit;
		}

		// Submit the file to the markup server for conversion
		$suppFolder = MarkupPluginUtilities::getSuppFolder($articleId);
		$suppFilePath = $suppFolder . '/' . $suppFileName;

		$apiResponse = MarkupPluginUtilities::submitFile($this, $suppFileName, $suppFilePath);

		if ($apiResponse['status'] == 'error') {
			echo $apiResponse['error'];
			return;
		}

		$this->_retrieveJobArchive($articleId, $suppFile, $apiResponse['id']);

		// Unzip file and launch galleys only during layout upload
		if ($galleyFlag) {
			if (!$this->_unzipSuppFile($articleId, $suppFile)) {
				return;
			}
			$this->_setupGalleyForMarkup($articleId, $suppFolder . '/markup/html/document.html');
			$this->_setupGalleyForMarkup($articleId, $suppFolder . '/markup/document.pdf');
			$this->_setupGalleyForMarkup($articleId, $suppFolder . '/markup/document.xml');
		} else {
			// If we're not launching a galley, then if there are no galley
			// links left in place for XML or HTML content, then make sure
			// markup/images & media are deleted for given article.
			MarkupPluginUtilities::cleanGalleyMedia($articleId);
		}
	}

	/**
	 * Fetches processed job archive from Document Markup server and replaces
	 * existing supplementary file.
	 *
	 * @param $articleId int ArticleId
	 * @param $suppFile Supplementary file to update with the converted file
	 * @param $jobId string Conversion jobId
	 *
	 * @return void
	 */
	function _retrieveJobArchive($articleId, $suppFile, $jobId) {
		// Wait for max 5 minutes for the job to complete
		$i = 0;
		while($i++ < 60) {
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

		$mimeType = MarkupPluginUtilities::getMimeType($tmpZipFile);
		$suppFileId = $suppFile->getFileId();
		import('classes.file.ArticleFileManager');
		$articleFileManager = new ArticleFileManager($articleId);
		if ($suppFileId == 0) {
			$suppFileId = $articleFileManager->copySuppFile($tmpZipFile, $mimeType);
			$suppFile->setFileId($suppFileId);
		} else {
			$articleFileManager->copySuppFile($tmpZipFile, $mimeType, $suppFileId);
		}
		@unlink($tmpZipFile);

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
	function _unzipSuppFile($articleId, $suppFile) {
		// We need updated name. It was x.pdf or docx, now its y.zip:
		$suppFileDao =& DAORegistry::getDAO('SuppFileDAO');
		$suppFile =& $suppFileDao->getSuppFile($suppFile->getId());
		$suppFileName = $suppFile->getFileName();
		$suppFolder = MarkupPluginUtilities::getSuppFolder($articleId);
		$zipFile = $suppFolder . '/' . $suppFileName;

		$validFiles = array(
			'document.pdf',
			'document.xml',
			'html.zip',
		);

		// Extract the zip archive to a markup subdirectory
		$message = '';
		$destination = $suppFolder . '/markup';
		if (!MarkupPluginUtilities::zipArchiveExtract($zipFile, $destination, $message, $validFiles)) {
			echo __(
				'plugins.generic.markup.archive.bad_zip',
				array(
					'file' => $zipFile,
					'error' => $message
				)
			);
			exit;
		}

		// If we got a html.zip extract this to html subdirectory
		$htmlZipFile = $destination . '/html.zip';
		if (file_exists($htmlZipFile)) {
			if (!MarkupPluginUtilities::zipArchiveExtract($htmlZipFile, $destination . '/html', $message)) {
				echo __(
					'plugins.generic.markup.archive.bad_zip',
					array(
						'file' => $htmlZipFile,
						'error' => $message
					)
				);
				exit;
			}
			unlink($htmlZipFile);
		}

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
		import('classes.file.ArticleFileManager');

		$mimeType = MarkupPluginUtilities::getMimeType($fileName);
		$fileExtension = strtoupper(ArticleFileManager::parseFileExtension($fileName));

		$articleFileManager = new ArticleFileManager($articleId);
		$suppFileId = $articleFileManager->copySuppFile($fileName, $mimeType);

		$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
		$galleys =& $galleyDao->getGalleysByArticle($articleId);
		foreach ($galleys as $galley) {
			// Doing by suffix since usually no isXMLGalley() fn
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
	 * Checks if user is allowed to download this file
	 *
	 * @param $user mixed User object
	 * @param $articleId int ArticleId
 	 * @param $journal mixed Journal object
 	 * @param $fileName string File to download
 	 *
 	 * @return boolean Whether or not the user is permitted to download the file
	 */
	function _getUserPermViewPublished($user, $articleId, $journal, $fileName) {
		import('classes.issue.IssueAction');

		$journalId = $journal->getId();
		$articleId = $articleId;
		$userId = $user ? $user->getId() : 0;

		$publishedArticleDao =& DAORegistry::getDAO('PublishedArticleDAO');
		if ($journal->getSetting('enablePublicArticleId')) {
			$publishedArticle =& $publishedArticleDao->getPublishedArticleByBestArticleId($journalId, $articleId, true);
		} else {
			$publishedArticle =& $publishedArticleDao->getPublishedArticleByArticleId($articleId, $journalId, true);
		}

		$issue = null;
		$article = null;
		$issueDao =& DAORegistry::getDAO('IssueDAO');
		if (isset($publishedArticle)) {
			$issue =& $issueDao->getIssueById($publishedArticle->getIssueId(), $publishedArticle->getJournalId(), true);
		} else {
			$articleDao =& DAORegistry::getDAO('ArticleDAO');
			$article =& $articleDao->getArticle($articleId, $journalId, true);
		}

		// If this is an editorial user who can view unpublished/unscheduled
		// articles, bypass further validation. Likewise for its author.
		if (
			($article || $publishedArticle) &&
			(
				$article && IssueAction::allowedPrePublicationAccess($journal, $article) ||
				$publishedArticle && IssueAction::allowedPrePublicationAccess($journal, $publishedArticle)
			)
		) {
			return true;
		}

		// Make sure the reader has rights to view the article/issue.
		if (!($issue && $issue->getPublished() && $publishedArticle->getStatus() == STATUS_PUBLISHED)) {
			return false;
		}

		$subscriptionRequired = IssueAction::subscriptionRequired($issue);
		$isSubscribedDomain = IssueAction::subscribedDomain($journal, $issue->getId(), $publishedArticle->getId());

		// Check if login is required for viewing.
		// TODO: this never worked $galleyId hasn't been set at this point this also influences the sections below
		if (
			!$isSubscribedDomain &&
			!Validation::isLoggedIn() &&
			$journal->getSetting('restrictArticleAccess') &&
			isset($galleyId) && $galleyId
		) {
			return false;
		}

		// Bypass all validation if subscription based on domain or ip is valid
		// or if the user is just requesting the abstract
		if (
			(!$isSubscribedDomain && $subscriptionRequired) &&
			(isset($galleyId) && $galleyId)
		) {
			// Subscription Access
			$subscribedUser = IssueAction::subscribedUser($journal, $issue->getId(), $publishedArticle->getId());

			if (
				!(
					!$subscriptionRequired ||
					$publishedArticle->getAccessStatus() == ARTICLE_ACCESS_OPEN ||
					$subscribedUser
				)
			) {
				// If payment information is enabled
				import('classes.payment.ojs.OJSPaymentManager');
				$paymentManager = new OJSPaymentManager($request);

				if ($paymentManager->purchaseArticleEnabled() || $paymentManager->membershipEnabled()) {
					// If only pdf files are being restricted, then approve all
					// non-pdf galleys and continue checking if it is a pdf galley
					if ($paymentManager->onlyPdfEnabled()) {
						$fileManager = new FileManager();
						if (strtoupper($fileManager->parseFileExtension($fileName)) == 'PDF') return true;
					}

					if (!Validation::isLoggedIn()) {
						return false;
					}

					// If the article has been paid for then forget about everything else
					// and just let them access the article
					$completedPaymentDao =& DAORegistry::getDAO('OJSCompletedPaymentDAO');
					$dateEndMembership = $user->getSetting('dateEndMembership', 0);

					return (
						$completedPaymentDao->hasPaidPurchaseArticle($userId, $publishedArticle->getId()) ||
						$completedPaymentDao->hasPaidPurchaseIssue($userId, $issue->getId()) ||
						(!is_null($dateEndMembership) && $dateEndMembership > time())
					);
				}

				if (!isset($galleyId) || $galleyId) {
					return false;
				}
			}
		}

		return true;
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
	function _getUserPermViewDraft($userId, $articleId, $journal, $fileName) {
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
