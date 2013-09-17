<?php

/**
 * @file plugins/generic/markup/MarkupPlugin.inc.php
 *
 * Copyright (c) 2003-2013 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class MarkupPlugin
 * @ingroup plugins_generic_markup
 *
 * @brief NLM XML and HTML transform plugin class
 *
 * Specification:
 *
 * When an author, copyeditor or editor uploads a new version (odt, docx, doc,
 * or pdf format) of an article, this module submits it to the pdfx server
 * specified in the configuration file. The following files are returned in
 * gzip'ed archive file (X-Y-Z-AG.tar.gz) file. An article supplementary
 * file is created/updated to hold the archive.
 *
 * manifest.xml
 * document-new.pdf (layout version of PDF)
 * document-review.pdf (review version of PDF, header author info stripped)
 * document.xml (NLM-XML3/JATS-compliant)
 * document.html (web-viewable article version)
 *
 * If the article is being uploaded as a galley publish, this plugin extracts
 * the html, xml and pdf versions and places them in the galley; and image
 * or other media files go into a special supplementary file subfolder called
 * "markup".
 *
 * @see technicalNotes.md file for details on the interface between this plugin
 * and the Document Markup Server.
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class MarkupPlugin extends GenericPlugin {
	/** Callback to hook mappings for this plugin */
	var $_callbackMap = array(
		'_loadCategoryCallback' => 'PluginRegistry::loadCategory',
		'_authorNewSubmissionConfirmationCallBack' => 'Author::SubmitHandler::saveSubmit',
		'_fileToMarkupCallback' => array(
			'AuthorAction::uploadRevisedVersion',
			'AuthorAction::uploadCopyeditVersion',
			'CopyeditorAction::uploadCopyeditVersion',
			'SectionEditorAction::uploadReviewVersion',
			'SectionEditorAction::uploadEditorVersion',
			'SectionEditorAction::uploadCopyeditVersion',
			'SectionEditorAction::uploadReviewForReviewer',
			'SectionEditorAction::uploadLayoutVersion',
			'LayoutEditorAction::uploadLayoutVersion',
		),
		'_deleteGalleyMediaCallback' => 'ArticleGalleyDAO::deleteGalleyById',
		'_displayGalleyCallback' => 'TemplateManager::display',
		'_downloadArticleCallback' => 'ArticleHandler::downloadFile',
	);

	//
	// Plugin Setup
	//
	/**
	 * Get the system name of this plugin.
	 * The name must be unique within its category. It enables a simple URL to
	 * an articles markup files, e.g.:
	 *
	 * index.php/chaos/gateway/plugin/markup/1/0/document.html
	 *
	 * @return string name of plugin
	 */
	function getName() {
		return 'markup';
	}

	function getDisplayName() {
		return __('plugins.generic.markup.displayName');
	}

	function getDescription() {
		return __('plugins.generic.markup.description');
	}

	/**
	 * Override the builtin to get the correct template path.
	 * @return string
	 */
	function getTemplatePath() {
		return parent::getTemplatePath() . 'templates/';
	}

	/**
	 * Display verbs for the management interface.
	 */
	function getManagementVerbs() {
		$verbs = array();
		if ($this->getEnabled()) {
			$verbs[] = array('settings', __('plugins.generic.markup.settings'));
		}
		return parent::getManagementVerbs($verbs);
	}

	/**
	 * Provides enable / disable / settings form options
	 *
	 * @param $verb string.
	 * @param $args ? (unused)
	 */
	function manage($verb, $args, &$message, &$messageParams) {
		if (!parent::manage($verb, $args, $message, $messageParams)) {
			return false;
		}

		$messageParams = array('pluginName' => $this->getDisplayName());

		switch ($verb) {
			case 'enable':
				$this->setEnabled(true);
				$message = NOTIFICATION_TYPE_PLUGIN_ENABLED;
				return false;

			case 'disable':
				$this->setEnabled(false);
				$message = NOTIFICATION_TYPE_PLUGIN_DISABLED;
				return false;

			case 'settings':
				$journal =& Request::getJournal();

				$templateMgr =& TemplateManager::getManager();
				$templateMgr->register_function('plugin_url', array($this, 'smartyPluginUrl'));

				$this->import('SettingsForm');
				$form = new SettingsForm($this, $journal->getId());

				if (Request::getUserVar('save')) {
					$form->readInputData();
					if ($form->validate()) {
						$form->execute();
						MarkupPluginUtilities::notificationService(__('plugins.generic.markup.settings.saved'));
						return false;
					} else {
						$form->display();
					}
				} else {
					$form->initData();
					$form->display();
				}
				break;

			default:
				assert(false);
				return false;
		}

		return true;
	}

	/**
	 * Register the plugin
	 *
	 * @param $category string Name of category plugin was registered to
	 *
	 * @return boolean Whether or not the plugin initialized sucessfully
	 */
	function register($category, $path) {
		$success = parent::register($category, $path);
		$this->addLocaleData();

		if ($success && $this->getEnabled()) {
			$this->import('MarkupPluginUtilities');
			$this->registerCallbacks();
		}
		return $success;
	}

	/**
	 * Register plugin callbacks
	 */
	function registerCallbacks(){
		foreach ($this->_callbackMap as $callback => $hooks) {
			if (!is_array($hooks)) $hooks = array($hooks);
			foreach ($hooks as $hook) {
				HookRegistry::register($hook, array($this, $callback));
			}
		}
	}

	//
	// Callbacks
	//
	/**
	 * Register the gateway plugin
     *
	 * @param $hookName string
	 * @param $params array [category string, plugins array]
	 */
	function _loadCategoryCallback($hookName, $params) {
		$category = $params[0];
		$plugins =& $params[1];

		if ($category == 'gateways') {
			$this->import('MarkupGatewayPlugin');
			$gatewayPlugin = new MarkupGatewayPlugin();
			$plugins[$gatewayPlugin->getSeq()][$gatewayPlugin->getPluginPath()] =& $gatewayPlugin;
		}

		return false;
	}


	/**
	 * Trigger document conversion when an author confirms a new
	 * submission (step 5).
	 * Triggered at User > Author > a > New Submission, any step in form.
	 *
	 * @param $hookName string
	 * @param $params array [&$step, &$article, &$submitForm]
	 */
	function _authorNewSubmissionConfirmationCallback($hookName, $params) {
		$step = $params[0];

		// Only interested in the final confirmation step
		if ($step != 5) return false;

		$article =& $params[1];
		$articleId = $article->getId();
		$journal =& Request::getJournal();
		$journalId = $journal->getId();

		// Check if upload of user's doc succeeded
		$fileId = $article->getSubmissionFileId();
		$articleFileDao =& DAORegistry::getDAO('ArticleFileDAO');
		$articleFile =& $articleFileDao->getArticleFile($fileId);
		if (!isset($articleFile)) return false;

		// Ensure a supplementary file record titled
		// MARKUP_SUPPLEMENTARY_FILE_TITLE is in place.
		$suppFile = $this->_suppFile($articleId);

		// Set supplementary file record's file id and folder location of
		// uploaded article file
		import('classes.file.ArticleFileManager');
		$articleFileManager = new ArticleFileManager($articleId);
		$articleFileDir = $articleFileManager->filesDir;
		$articleFilePath = $articleFileDir
			. $articleFileManager->fileStageToPath($articleFile->getFileStage())
			. '/' . $articleFile->getFileName();
		$this->_setSuppFileId($suppFile, $articleFilePath, $articleFileManager);

		// Submit the article to the pdfx server
		$this->_submitURL($articleId);
	}

	/**
	 * Trigger document conversion from various hooks for editor,
	 * section editor, layout editor etc. uploading of documents.
	 *
	 * @param $hookName string
	 * @param $params array [article object , ...]
	 */
	function _fileToMarkupCallback($hookName, $params) {
		$article =& $params[0];
		$articleId = $article->getId();
		$journal =& Request::getJournal();
		$journalId = $journal->getId();

		// Ensure a supplementary file record is in place.
		$suppFile = $this->_suppFile($articleId);

		// The file name of the uploaded file differs in one case of the
		// calling hooks. For the "Submissions > X > Editing: Layout:" call
		// (SectionEditorAction::uploadLayoutVersionFForm) the file is called
		// 'layoutFile', while in all other cases it is called 'upload'.
		$fileName = 'upload';
		if ($hookName == 'SectionEditorAction::uploadLayoutVersion') {
			$fileName = 'layoutFile';
		}

		// Trigger only if file uploaded.
		import('classes.file.ArticleFileManager');
		$articleFileManager = new ArticleFileManager($articleId);
		if ($articleFileManager->uploadedFileExists($fileName)) { return false; }

		// Copy the temporary file
		$newPath = MarkupPluginUtilities::copyTempFile($articleId, $fileName);
		if (!$newPath) { return false; }

		$this->_setSuppFileId($suppFile, $newPath, $articleFileManager);
		@unlink($newPath);

		// If we have a layout upload then trigger galley link creation.
		$galleyFlag = (strpos($hookName, 'uploadLayoutVersion') !== false);

		// Submit the article to the pdfx server
		$this->_submitURL($articleId, $galleyFlag);

		return false;
	}

	/**
	 * Checks if there are any HTML or XML galleys left when galley item is
	 * deleted. If not, delete all markup related file(s).
	 *
	 * @param $hookName string
	 * @param $params array [$galleyId]
	 */
	function _deleteGalleyMediaCallback($hookName, $params) {
		$galleyId = $params[0];
		$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
		$galley =& $galleyDao->getGalley($galleyId);
		$articleId = $galley->getSubmissionId();
		$type = $galley->getLabel();
		MarkupPluginUtilities::checkGalleyMedia($articleId, $type);

		return false;
	}

	/**
	 * This hook handles display of any HTML & XML ProofGalley links that were
	 * generated by this plugin. PDFs are not handled here.
	 * Note: permissions for 5 user types (author, copyeditor, layouteditor,
	 * proofreader, sectioneditor) have already been decided in caller's
	 * proofGalley() methods.
	 * NOTE: Do NOT pass hook $params by reference, or this hook will
	 * mysteriously never fire!
	 * TODO: verify that
	 *
	 * @param $hookName string
	 * @param $params array [$galleyId]
	 */
	function _displayGalleyCallback($hookName, $params) {
		if ($params[1] != 'submission/layout/proofGalley.tpl') return false;

		$templateMgr = $params[0];
		$galleyId = $templateMgr->get_template_vars('galleyId');
		$articleId = $templateMgr->get_template_vars('articleId');
		if (!$articleId) return false;

		$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
		$galley =& $galleyDao->getGalley($galleyId, $articleId);
		if (!$galley) return false;

		return $this->_rewriteArticleHTML($articleId, $galley, true);
	}

	/**
	 * This hook intercepts user request on public site to download/view an
	 * article galley HTML / XML / PDF link.
	 *
	 * @param $hookName string
	 * @param $params array [$article, $galley]
	 */
	function _downloadArticleCallback($hookName, $params) {
		$article =& $params[0];
		$galley =& $params[1];
		$articleId = $article->getId();
		$this->_rewriteArticleHTML($articleId, $galley, false);

		exit; // Otherwise journal page tacked on end
	}

	//
	// Private helper methods
	//
	/**
	 * Make a new supplementary file record or copy over an existing one.
	 *
	 * @param $suppFile object
	 * @param $suppFilePath string file path
	 * @param $articleFileManager object, already initialized with an article id.
	 */
	function _setSuppFileId($suppFile, $suppFilePath, $articleFileManager) {
		$mimeType = MarkupPluginUtilities::getMimeType($suppFilePath);
		$suppFileId = $suppFile->getFileId();

		if ($suppFileId == 0) {
			$suppFileId = $articleFileManager->copySuppFile($suppFilePath, $mimeType);
			$suppFile->setFileId($suppFileId);

			$suppFileDao =& DAORegistry::getDAO('SuppFileDAO');
			$suppFileDao->updateSuppFile($suppFile);
		} else {
			$articleFileManager->copySuppFile($suppFilePath, $mimeType, $suppFileId, true);
		}
	}

	/**
	 * Web URL call triggers separate thread to do document conversion on
	 * uploaded document. URL request goes out to the same plugin. The URL path
	 * is enabled by the gateway fetch() part of this plugin. Then php continues
	 * on in 1 second ...
	 *
	 * Note: A bad $curlError response is: 'Timeout was reached', which occurs
	 * when not enough time alloted to get request going. Seems like 1000 ms is
	 * the minimum. Otherwise URL fetch not even triggered. The right $curlError
	 * response which allows thread to continue is: 'Operation timed out after
	 * XYZ milliseconds with 0 bytes received'
	 *
	 * @param $articleId int
	 * @param $galleyFlag boolean communicates whether or not galley links
	 * should be created (i.e. article is being published)
	 * TODO: check if this is the best solution
	 */
	function _submitURL($articleId, $galleyFlag = false) {
		$args = array(
			'articleId' => $articleId,
			'action' => $galleyFlag ? 'refreshgalley' : 'refresh'
		);
		$url = MarkupPluginUtilities::getMarkupURL($args);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1000);
		curl_setopt($ch, CURLOPT_VERBOSE, 1);
		curl_exec($ch);
		curl_close($ch);

		return false;
	}

	/**
	 * Ensures that a single supplementary file record exists for given article.
	 * The title name of this file must be unique so it can be found again by
	 * this plugin (MARKUP_SUPPLEMENTARY_FILE_TITLE).
	 * Skipping search indexing since this content is a repetition of the article.
	 *
	 * @param: $articleId int
	 * @var $locale string controlled by current locale, eg. en_US
	 */
	function _suppFile($articleId) {
		$suppFileDao =& DAORegistry::getDAO('SuppFileDAO');
		$suppFiles =& $suppFileDao->getSuppFilesBySetting('title', MARKUP_SUPPLEMENTARY_FILE_TITLE, $articleId);
		$locale = AppLocale::getLocale();

		if (!$suppFiles) {
			import('classes.article.SuppFile');
			$suppFile = new SuppFile();
			$suppFile->setArticleId($articleId);
			$suppFile->setTitle(MARKUP_SUPPLEMENTARY_FILE_TITLE, $locale);
			$suppFile->setType('');
			$suppFile->setTypeOther('zip', $locale);
			$suppFile->setDescription(__('plugins.generic.markup.archive.description'), $locale);
			$suppFile->setDateCreated(Core::getCurrentDate());
			$suppFile->setShowReviewers(0);
			$suppFile->setFileId(0); // Ensures new record is created
			$suppId = $suppFileDao->insertSuppFile($suppFile);
			$suppFile->setId($suppId);
		} else {
			// Supplementary file exists, so just overwrite its file.
			$suppFile = $suppFiles[0];
		}

		MarkupPluginUtilities::notificationService(__('plugins.generic.markup.archive.processing'));
		$suppFileDao->updateSuppFile($suppFile);

		return $suppFile;
	}

	/**
	 * This method skips handling xml and pdf docs. It displays HTML file with
	 * relative urls modified to reference plugin location for article's media.
	 *
	 * @param $hookName string
	 * @param $params array [$galleyId]
	 * @param $backLinkFlag boolean If true, iframe injected with link back to
	 * referring page.
	 * TODO: URL regex replacement and iframe injection might not be optimal
	 */
	function _rewriteArticleHTML($articleId, $galley, $backLinkFlag) {
		// TODO: https://github.com/pkp/ojs/pull/98#discussion_r5986311
		if (strtoupper($galley->getLabel()) != 'HTML') return false;

		$filePath = $galley->getFilePath();
		$mimeType = MarkupPluginUtilities::getMimeType($filePath);

		header('Content-Type: ' . $mimeType . '; charset=UTF-8');
		header('Cache-Control: ' . $templateMgr->cacheability);
		header('Content-Length: ' . filesize($filePath));
		ob_clean();
		flush();

		// Get article's plugin file folder
		$args = array(
			'articleId' => $articleId,
			'fileName' => ''
		);

		$articleURL = MarkupPluginUtilities::getMarkupURL($args);
		$markupURL = Request::url(null, 'gateway', 'plugin', array(MARKUP_GATEWAY_FOLDER, null), null);

		$html = file_get_contents($filePath);

		// Get rid of relative path to markup root
		// TODO use DOM/XPATH for that
		$html = preg_replace("#((\shref|src)\s*=\s*[\"'])(\.\./\.\./)([^\"'>]+)([\"'>]+)#", '$1' . $markupURL . '$4$5', $html);

		// Insert document base url into all relative urls except anchorlinks
		$html = preg_replace("#((\shref|src)\s*=\s*[\"'])(?!\#|http|mailto)([^\"'>]+)([\"'>]+)#", '$1' . $articleURL . '$3$4', $html);

		// Converts remaining file name to path[] param.
		// Will need 1 more call if media subfolders exist.
		// TODO: Use Request::url() function to generate urls
		if (Request::isPathInfoEnabled() == false) {
			$html = preg_replace("#((\shref|src)\s*=\s*[\"'])(?!\#)([^\"'>]+index\.php[^\"'>]+)/([^\"\?'>]+)#", '$1$3&path[]=$4', $html);
		}

		if ($backLinkFlag == true) {
			// Inject iframe at top of page that enables return to previous page.
			$backURL = Request::url(null, null, 'proofGalleyTop', $articleId, null);
			// TODO: DOM/XPATH
			$iframe = '<iframe src="' . $backURL . '" noresize="noresize" frameborder="0" scrolling="no" height="40" width="100%"></iframe>';
			$insertPtr = strpos($html, '<body>') + 6;
			$html = substr($html, 0, $insertPtr)
				. "\n\t"
				. $iframe
				. substr($html, $insertPtr);
		}

		echo $html;

		return true;
	}
}
