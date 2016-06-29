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
	 * Overwrite the JS path with the parent's JS path
	 *
	 * @return string CSS path
	 */
	function getCssPath() {
		$plugin =& $this->getMarkupPlugin();
		return $plugin->getCssPath();
	}

	/**
	 * Overwrite the JS path with the parent's JS path
	 *
	 * @return string JS path
	 */
	function getJsPath() {
		$plugin =& $this->getMarkupPlugin();
		return $plugin->getJsPath();
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
	 *   js/[string]
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

		// Handles requests for js files
		if (isset($args['js'])) {
			$this->_downloadMarkupJS($args['js']);
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

		// Check if user can view the article
		if ($this->validate($articleId)) {
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
	 * Offers the plugins article JS file for download
	 *
	 * @param $fileName string File name of the JS file to fetch
	 *
	 * @return bool Whether or not the JS file exists
	 */
	function _downloadMarkupJS($fileName) {
		return MarkupPluginUtilities::downloadFile($this->getJsPath(), $fileName);
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
            
            // get journal id
            $request = Registry::get('request');
            $router =& $request->getRouter();
            $journal =& $router->getContext($request);
            $journalId = $journal->getId();
            
            $plugin =& $this->getMarkupPlugin();
            $wantedFormats = $plugin->getSetting($journalId, 'wantedFormats');
            $overrideGalley = (bool) intval($plugin->getSetting($journalId, 'overrideGalley'));
        
            if (in_array('html', $wantedFormats)) {
                $this->_setupGalleyForMarkup($articleId, $suppFolder . '/markup/html/document.html', $overrideGalley);
            }
            
            if (in_array('pdf', $wantedFormats)) {
                $this->_setupGalleyForMarkup($articleId, $suppFolder . '/markup/document.pdf', $overrideGalley);
            }
            
            if (in_array('xml', $wantedFormats)) {
                $this->_setupGalleyForMarkup($articleId, $suppFolder . '/markup/document.xml', $overrideGalley);
            }
            if (in_array('epub', $wantedFormats)) {
                $this->_setupGalleyForMarkup($articleId, $suppFolder . '/markup/document.epub', $overrideGalley);
            }
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
			if (($apiResponse['jobStatus'] != 0) && ($apiResponse['jobStatus'] != 1)) break; // Jobstatus 0 - pending ; Jobstatus 1 - Processing
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
			'document.epub',
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
	 * Populates an article with galley files for document.html, document.xml, document.epub
	 * and document.pdf
	 *
	 * @param $articleId int Article Id
	 * @param $fileName string File to process
	 * @param $overrideGalley boolean whether galley should be overriden
	 *
	 * @return int Id of new galley link
	 */
	function _setupGalleyForMarkup($articleId, $fileName, $overrideGalley) {
		import('classes.file.ArticleFileManager');

		$mimeType = MarkupPluginUtilities::getMimeType($fileName);
		$fileExtension = strtoupper(ArticleFileManager::parseFileExtension($fileName));
        
        $plugin =& $this->getMarkupPlugin();
        
		$articleFileManager = new ArticleFileManager($articleId);
		$suppFileId = $articleFileManager->copySuppFile($fileName, $mimeType);

		$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
        
        if ($overrideGalley) {
            $galleys =& $galleyDao->getGalleysByArticle($articleId);
            foreach ($galleys as $galley) {
                // Doing by suffix since usually no isXMLGalley() fn
                if ($galley->getLabel() == $fileExtension) {
                    $galley->setFileId($suppFileId);
                    $galleyDao->updateGalley($galley);
                    return true;
                }
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
	 * Validate if the user allowed to view the requested document. This method
	 * is a slightly modified copy of ArticleHandler::validate() and should be
	 * removed once a proper API is available
	 *
	 * @param $articleId string
	 *
	 * TODO: Replace this method with a proper API call
	 */
	function validate($articleId) {
		$request = Registry::get('request');

		import('classes.issue.IssueAction');

		$router =& $request->getRouter();
		$journal =& $router->getContext($request);
		$journalId = $journal->getId();
		$article = $publishedArticle = $issue = null;
		$user =& $request->getUser();
		$userId = $user?$user->getId() : 0;

		$publishedArticleDao =& DAORegistry::getDAO('PublishedArticleDAO');
		if ($journal->getSetting('enablePublicArticleId')) {
			$publishedArticle =& $publishedArticleDao->getPublishedArticleByBestArticleId((int) $journalId, $articleId, true);
		} else {
			$publishedArticle =& $publishedArticleDao->getPublishedArticleByArticleId((int) $articleId, (int) $journalId, true);
		}

		$issueDao =& DAORegistry::getDAO('IssueDAO');
		if (isset($publishedArticle)) {
			$issue =& $issueDao->getIssueById($publishedArticle->getIssueId(), $publishedArticle->getJournalId(), true);
		} else {
			$articleDao =& DAORegistry::getDAO('ArticleDAO');
			$article =& $articleDao->getArticle((int) $articleId, $journalId, true);
		}

		// If this is an editorial user who can view unpublished/unscheduled
		// articles, bypass further validation. Likewise for its author.
		if (($article || $publishedArticle) && (($article && IssueAction::allowedPrePublicationAccess($journal, $article) || ($publishedArticle && IssueAction::allowedPrePublicationAccess($journal, $publishedArticle))))) {
			return true;
		}

		// Make sure the reader has rights to view the article/issue.
		if ($issue && $issue->getPublished() && $publishedArticle->getStatus() == STATUS_PUBLISHED) {
			$subscriptionRequired = IssueAction::subscriptionRequired($issue);
			$isSubscribedDomain = IssueAction::subscribedDomain($journal, $issue->getId(), $publishedArticle->getId());

			// Check if login is required for viewing.
			if (!$isSubscribedDomain && !Validation::isLoggedIn() && $journal->getSetting('restrictArticleAccess') && isset($galleyId) && $galleyId) {
				Validation::redirectLogin();
			}

			// bypass all validation if subscription based on domain or ip is valid
			// or if the user is just requesting the abstract
			if ( (!$isSubscribedDomain && $subscriptionRequired) &&
			     (isset($galleyId) && $galleyId) ) {

				// Subscription Access
				$subscribedUser = IssueAction::subscribedUser($journal, $issue->getId(), $publishedArticle->getId());

				import('classes.payment.ojs.OJSPaymentManager');
				$paymentManager = new OJSPaymentManager($request);

				$purchasedIssue = false;
				if (!$subscribedUser && $paymentManager->purchaseIssueEnabled()) {
					$completedPaymentDao =& DAORegistry::getDAO('OJSCompletedPaymentDAO');
					$purchasedIssue = $completedPaymentDao->hasPaidPurchaseIssue($userId, $issue->getId());
				}

				if (!(!$subscriptionRequired || $publishedArticle->getAccessStatus() == ARTICLE_ACCESS_OPEN || $subscribedUser || $purchasedIssue)) {

					if ( $paymentManager->purchaseArticleEnabled() || $paymentManager->membershipEnabled() ) {
						/* if only pdf files are being restricted, then approve all non-pdf galleys
						 * and continue checking if it is a pdf galley */
						if ( $paymentManager->onlyPdfEnabled() ) {
							$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
							if ($journal->getSetting('enablePublicGalleyId')) {
								$galley =& $galleyDao->getGalleyByBestGalleyId($galleyId, $publishedArticle->getId());
							} else {
								$galley =& $galleyDao->getGalley($galleyId, $publishedArticle->getId());
							}
							if ( $galley && !$galley->isPdfGalley() ) {
								return true;
							}
						}

						if (!Validation::isLoggedIn()) {
							Validation::redirectLogin("payment.loginRequired.forArticle");
						}

						/* if the article has been paid for then forget about everything else
						 * and just let them access the article */
						$completedPaymentDao =& DAORegistry::getDAO('OJSCompletedPaymentDAO');
						$dateEndMembership = $user->getSetting('dateEndMembership', 0);
						if ($completedPaymentDao->hasPaidPurchaseArticle($userId, $publishedArticle->getId())
							|| (!is_null($dateEndMembership) && $dateEndMembership > time())) {
							return true;
						} else {
							$queuedPayment =& $paymentManager->createQueuedPayment($journalId, PAYMENT_TYPE_PURCHASE_ARTICLE, $user->getId(), $publishedArticle->getId(), $journal->getSetting('purchaseArticleFee'));
							$queuedPaymentId = $paymentManager->queuePayment($queuedPayment);

							$paymentManager->displayPaymentForm($queuedPaymentId, $queuedPayment);
							exit;
						}
					}

					if (!isset($galleyId) || $galleyId) {
						if (!Validation::isLoggedIn()) {
							Validation::redirectLogin("reader.subscriptionRequiredLoginText");
						}
						$request->redirect(null, 'about', 'subscriptions');
					}
				}
			}
		} else {
			$request->redirect(null, 'index');
		}
		return true;
	}
}
