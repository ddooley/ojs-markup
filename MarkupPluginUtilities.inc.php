<?php

/**
 * @file plugins/generic/markup/MarkupPluginUtilities.inc.php
 *
 * Copyright (c) 2003-2013 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class MarkupPluginUtilities
 * @ingroup plugins_generic_markup
 *
 * @brief helper functions
 *
 */

// Plugin gateway path folder.
define('MARKUP_GATEWAY_FOLDER', 'markupplugin');
// Title of suplementary files on markup server
define('MARKUP_SUPPLEMENTARY_FILE_TITLE', 'Document Markup Files');

class MarkupPluginUtilities {

	/**
	 * Show a notification to the user
	 *
	 * @param $message string Translated text to display
	 * @param $typeFlag bool Success/Error message
	 * @param $userId int UserId of user to notify
	 */
	function showNotification($message, $typeFlag = true, $userId = null) {

		import('classes.notification.NotificationManager');
		$notificationManager = new NotificationManager();

		$notificationType = NOTIFICATION_TYPE_SUCCESS;
		if ($typeFlag == false) {
			$notificationType = NOTIFICATION_TYPE_ERROR;
		}

		// If user not specified explicitly, then include current user.
		if (!isset($userId)) {
			$user =& Request::getUser();
			$userId = $user->getId();
		}
		if (isset($userId)) {
			$notificationManager->createTrivialNotification(
				$userId,
				$notificationType,
				array('contents' => $message)
			);
		}
	}

	/**
	 * Return article's supplementary files directory.
	 *
	 * @param $articleId int ArticleId
	 *
	 * @return string Supplementary file folder path
	 */
	function getSuppFolder($articleId) {
		import('classes.file.ArticleFileManager');
		$articleFileManager = new ArticleFileManager((int) $articleId);
		return $articleFileManager->filesDir . $articleFileManager->fileStageToPath(ARTICLE_FILE_SUPP);
	}

	/**
	 * Returns URL that provides file access for a given article and context
	 *
	 * e.g. ... /index.php/praxis/gateway/plugin/markup/1/refresh
	 * or ... index.php?journal=praxis&page=gateway&op=plugin&path[]=markup&path[]=1&path[]=refresh
	 *
	 * $arg reflects 3 use cases
	 *   [action, articleId, userId] or
	 *   [folder, fileName] or
	 *   [0, articleId, fileName]
	 *
	 * @param $args mixed Specifying the URL components
	 *
	 * @return string URL
	 * TODO: Break this up for individual use cases
	 */
	function getMarkupURL($args) {
		$path = array(MARKUP_GATEWAY_FOLDER);
		$args['articleId'] = (int) $args['articleId'];

		if ($args['action']) {
			$user =& Request::getUser();
			// Actions need a userId for notifications.
			array_push($path, $args['action'], $args['articleId'], $user->getId());
		} elseif ($args['folder']) {
			array_push($path, $args['folder'], $args['fileName']);
		} else {
			array_push($path, 0, $args['articleId'], $args['fileName']);
		}

		return Request::url(null, 'gateway', 'plugin', $path);
	}

	/**
	 * Cleans file names
	 *
	 * @param $fileName string File name to clean
	 *
	 * @return Cleaned file name
	 */
	 // TODO: check why this is required
	function cleanFileName($fileName) {
		return preg_replace('/[^[:alnum:]\._-]/', '', $fileName);
	}

	/**
	 * Copy tempory uploaded file into new location before uploading it to the
	 * Document Markup server
	 *
	 * @param $articleId int ArticleId
	 * @param $fileName int File name of uploaded file
	 *
	 * @return string Path to the copied file
	 */
	function copyTempFile($articleId, $fileName) {
		import('classes.file.ArticleFileManager');
		$articleFileManager = new ArticleFileManager($articleId);
		$articleFilePath = $articleFileManager->getUploadedFilePath($fileName);
		$fileName = $articleFileManager->getUploadedFileName($fileName);

 		// Exit if no suffix.
		if (!strpos($fileName, '.')) return false;

		$suffix = $articleFileManager->getExtension($fileName);
		$newFilePath = $articleFilePath . '.' . $suffix;
		$articleFileManager->copyFile($articleFilePath, $newFilePath);

		return $newFilePath;
	}

	/**
	 * Return requested markup file to user's browser.
	 *
	 * @param $folder string Server file path
	 * @param $fileName string Name of file to download
	 *
	 * @return void
	 */
	function downloadFile($folder, $fileName) {
		$filePath = $folder . $fileName;
		$fileManager = new FileManager();

		if (!$fileManager->fileExists($filePath)) {
			return self::showNotification(
				__(
					'plugins.generic.markup.archive.no_file',
					array('file' => $fileName)
				)
			);
		}

		$mimeType = self::getMimeType($fileName);
		$fileManager->downloadFile($folder . $fileName, $mimeType, true);
	}

	/**
	 * Delete markup plugin media files related to an article if no XML or HTML
	 * galley links are left.
	 *
	 * @param $articleId int ArticleID
	 * @param $type string What document type to discard
	 *
	 * @return void
	 */
	function checkGalleyMedia($articleId, $type) {
		$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
		$galleys =& $galleyDao->getGalleysByArticle($articleId);

		$keep = array();
		foreach ($galleys as $galley) {
			$label = $galley->getLabel();
			if ($label == 'XML' && $type != 'XML') $keep['xml'] = true;
			if ($label == 'HTML' && $type != 'HTML') $keep['html'] = true;
			if ($label == 'PDF' && $type != 'PDF') $keep['pdf'] = true;
		};

		$suppFolder = self::getSuppFolder($articleId) . '/markup/';

		$delete = array();
		if ($keep) {
			if (!isset($keep['xml'])) {
				$delete[] = $suppFolder . 'document.xml';
			}
			if (!isset($keep['html'])) {
				$delete[] = $suppFolder . 'document.html';
			}
			if (!isset($keep['pdf'])) {
				$delete[] = $suppFolder . 'document-new.pdf';
				$delete[] = $suppFolder . 'document-review.pdf';
			}
		} else {
			// No markup galley files found so delete all markup media.
			$delete = glob($suppFolder . '*');
		}

		foreach ($delete as $file) { unlink($file); }
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
	function getUserPermViewPublished($user, $articleId, $journal, $fileName) {
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
	 * Return mime type of a file
	 *
	 * @param $file string File to get mimetype for
	 *
	 * @return string Mime type of the file
	 */
	function getMimeType($file) {
		$finfo = finfo_open(FILEINFO_MIME_TYPE);
		return finfo_file($finfo, $file);
	}

	/**
	 * Save the job id to the supplementary file source field
	 *
	 * @param $suppFile mixed Supplementary file to save the job id to
	 * @param $jobId int Job id to save
	 *
	 * @return void
	 */
	function saveJobIdSuppFile($suppFile, $jobId) {
		$locale = AppLocale::getLocale();
		if ($jobId) $suppFile->setSource('jobId::' . $jobId, $locale);
	}

	/**
	 * Retrieve the job id from the supplementary file source field
	 *
	 * @param $suppFile mixed Supplementary file to get the job id for
	 *
	 * @return int Job id
	 */
	function getJobIdSuppFile($suppFile) {
		$locale = AppLocale::getLocale();
		$source = $suppFile->getSource($locale);

		$matches = array();
		if (!preg_match('/^jobId\:\:([0-9]+)$/', $source, $matches)) return;

		return $matches[1];
	}

	/**
	 * Remove the job id from the supplementary file source field
	 *
	 * @param $suppFile mixed Supplementary file to remove the job id from
	 *
	 * @return void
	 */
	function removeJobIdSuppFile($suppFile) {
		$locale = AppLocale::getLocale();
		$suppFile->setSource('', $locale);
	}

	/**
	 *
	 * Build the URL to query the markup server
	 *
	 * @param $plugin mixed Plugin to process the Request for
	 * @param $action string API action
	 * @param $params array Query parameters
	 *
	 * @return string Markup server query URL
	 */
	function apiUrl($plugin, $action, $params = array()) {
		$journal = Request::getJournal();
		$journalId = $journal->getId();

		$apiUrl = $plugin->getSetting($journalId, 'markupHostURL');
		$apiUrl = rtrim($apiUrl, '/');

		$apiUrl = $apiUrl . '/api/job/' .  $action;

		if ($params) {
			$apiUrl .= '?' . http_build_query($params);
		}

		return $apiUrl;
	}

	/**
	 * Call the markup server API
 	 *
	 * @param $plugin mixed Plugin to process the Request for
	 * @param $action string API action
	 * @param $params array Query/POST parameters
	 * @param $method Whether to use a GET/POST request
	 * @param $method Whether to execute the curl request or to just return the cannel and url
	 *
	 * @return mixed API response
	 */
	function apiRequest($plugin, $action, $params = array(), $isPost = false, $execute = true) {
		$journal = Request::getJournal();
		$journalId = $journal->getId();

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

		$params['email'] = $plugin->getSetting($journalId, 'markupHostUser');
		$params['password'] = $plugin->getSetting($journalId, 'markupHostPass');

		if ($isPost) {
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
			$params = array();
		}

		$apiUrl = self::apiUrl($plugin, $action, $params);

		if (!$execute) {
			return array('channel' => $ch, 'apiUrl' => $apiUrl);
		}

		curl_setopt($ch, CURLOPT_URL, $apiUrl);

		$response = curl_exec($ch);

		$response = json_decode($response, true);
		if (!$response) {
			$error = curl_error($ch);
			if (empty($error)) {
				$error = 'HTTP status: ' . curl_getinfo($ch, CURLINFO_HTTP_CODE);
			}

			$response = array(
				'status' => 'error',
				'error' => $error,
			);
		}

		curl_close($ch);

		return $response;
	}


	/**
	 * Submit a file to the markup server for conversion
	 *
	 * @param $plugin mixed Plugin to submit the file for
	 * @param $fileName string File name
	 * @param $filePath string File path
	 *
	 * @return mixed API respnse
	 * TODO: Implement the correct citationStyleHash handling
	 */
	function submitFile($plugin, $fileName, $filePath) {
		$params = array(
			'fileName' => $fileName,
			'fileContent' => file_get_contents($filePath),
			'citationStyleHash' => 'c6de5efe3294b26391ea343053c19a84',
		);

		return self::apiRequest($plugin, 'submit', $params, true);
	}

	/**
	 * Get the converted file URL
	 *
	 * @param $plugin mixed Plugin to retrieve the file for
	 * @param $jobId Job Id
	 * @param $conversionStage What conversion stage to retrieve
	 *
	 * @return mixed API response
	 */
	function getFileUrl($plugin, $jobId, $conversionStage) {
		$params = array(
			'id' => $jobId,
			'conversionStage' => $conversionStage,
		);

	 	$data = self::apiRequest($plugin, 'retrieve', $params, false, false);

		return $data['apiUrl'];
	}

	/**
	 * Get the converted ZIP file URL
	 *
	 * @param $plugin mixed Plugin to retrieve the file for
	 * @param $jobId Job Id
	 *
	 * @return mixed API response
	 */
	function getZipFileUrl($plugin, $jobId) {

		return self::getFileUrl($plugin, $jobId, 10);
	}

	/**
	 * Retrieve a job status from markup server
	 *
	 * @param $plugin mixed Plugin to retrieve the job status for
	 * @param $jobId Job Id
	 *
	 * @return mixed API response
	 */
	function getJobStatus($plugin, $jobId) {
		$params = array(
			'id' => $jobId,
		);

		return self::apiRequest($plugin, 'status', $params);
	}
}
