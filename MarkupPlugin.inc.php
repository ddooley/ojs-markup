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
	* When an author, copyeditor or editor uploads a new version (odt, docx, doc, or pdf format) of an article, this module submits it to the pdfx server specified in the configuration file.  The following files are returned in gzip'ed archive file (X-Y-Z-AG.tar.gz) file which is added (or replaces a pre-existing version in) the Supplementary files section.
	*
	* manifest.xml
	* document.pdf (used for parsing; generated if input is not PDF)
	* document-new.pdf (layout version of PDF)
	* document.nlm.xml (NLM-XML3/JATS-compliant)
	* document.html (web-viewable article version)
	* document.bib (JSON-like format for structured citations)
	* document.refs (a text file of the article's citations and their bibliographic references, formatted according to selected CSL style. Also indicates when references were unused in body of article.)
	* 
	* If the article is being uploaded as a galley publish, this plugin will extract the html, xml and pdf versions when they are ready, and will place them in the supplementary file folder.
	*/
	
import('lib.pkp.classes.plugins.GenericPlugin');

class MarkupPlugin extends GenericPlugin {
	
	/**
	* URL for Markup server
	* @var string
	*/
	var $_host;

	/**
	* Set the host
	* @param $host string
	*/
	function setHost($host) {
	    $this->_host = $host;
	}

	/**
	* Get the host
	* @return string
	*/
	function getHost() {
	    return $this->_host;
	}
	
	/**
	* Get the system name of this plugin. 
	* The name must be unique within its category. This name is short since it enables a simple URL to an articles markup files, e.g. http://ubie/ojs/index.php/chaos/gateway/plugin/markup/1/0/document.html
	* 
	* @return string name of plugin
	*/
	//function getName() {
	//	return 'markup'; 
	//}
	// DEFAULT is markupplugin - i.e. name of class, lowercase
	
	function getDisplayName() {
		return __('plugins.generic.markup.displayName');
	}

	function getDescription() {
		return __('plugins.generic.markup.description');
	}
	
	/**
	* Get the management verbs for this plugin
	*/
	function getManagementVerbs() {
		$verbs = parent::getManagementVerbs();
		if (!$this->getEnabled()) return $verbs; // enable/upgrade/delete
		$verbs[] = array(
			'settings', __('plugins.generic.markup.settings')
		);
		return $verbs;
	}
	
	/**
	* Provides enable / disable / settings form options
	*
	* @param $verb string.
	* @param $args ? (unused)
	*/
	function manage($verb, $args, &$message, &$messageParams) {

		$messageParams = array('pluginName'=> $this->getDisplayName());
		/* If manage() returns true, parent's manage() handler is skipped.  Enable/disable still seem to need to be handled in parent as well as here.  Settings is handled here entirely.
		*/
		switch ($verb) {

			case 'enable':
				$this->setEnabled(true);
				$message=NOTIFICATION_TYPE_PLUGIN_ENABLED;
				return false;
				
			case 'disable':
				$this->setEnabled(false);
				$message=NOTIFICATION_TYPE_PLUGIN_DISABLED;
				return false;
			
			case 'settings':
				$journal =& Request::getJournal();
				
				$templateMgr =& TemplateManager::getManager();
				$templateMgr->register_function('plugin_url', array(&$this, 'smartyPluginUrl'));
				
				$this->import('SettingsForm');
				$form = new SettingsForm($this, $journal->getId());

				if (Request::getUserVar('save')) {
					$form->readInputData();
					// NOTIFICATION_TYPE_FORM_ERROR
					if ($form->validate()) {
						$form->execute();
						$this->_notificationService(__('plugins.generic.markup.settings.saved'));
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
				return false;
		}
		return true;
	}

	
	/**
	* Called as a plugin is registered to the registry.
	* We avoid reviewer upload hooks since user may be uploading commentary
	* Tech notes: Because EDITOR doesn't have a hook like LayoutEditorAction::deleteSuppFile we don't use it for tidying up.
	* Ignored AuthorAction::uploadRevisedVersion
	*
	* @param $category String Name of category plugin was registered to
	*
	* @return boolean True iff plugin initialized successfully; if false, the plugin will not be registered.
	*/
	function register($category, $path) { 
		// See lib/pkp/classes/plugins/ HookRegistry
		$success = parent::register($category, $path);
		$this->addLocaleData();

		if ($success && $this->getEnabled()) {

			// For User > Author > Submissions > New Submission: Any step in form.  Triggers at step 5, after entry of title and authors.			SEE line 155 of /current/html/SubmitHandler.inc.php: 
			HookRegistry::register('Author::SubmitHandler::saveSubmit', array(&$this, '_authorNewSubmissionConfirmation'));

			// The following hooks fire AFTER Apache upload of file, but before it is brought into OJS and assigned an id 
			
			// SEE classes/submission/author/authorAction.inc.php
			// For User > Author > Submissions > X > Review: Upload Author 
			HookRegistry::register('AuthorAction::uploadRevisedVersion', array(&$this, '_fileToMarkup'));	// Receives &$authorSubmission
			
			// For User > Author > Submissions > X > Editing: Author Copyedit: 
			HookRegistry::register('AuthorAction::uploadCopyeditVersion', array(&$this, '_fileToMarkup'));
	
			// Copyeditor user handled in future versions of OJS I believe.
			HookRegistry::register('CopyeditorAction::uploadCopyeditVersion', array(&$this, '_fileToMarkup')); // receives array(&$copyeditorSubmission));
			
			// SEE clases/submission/sectionEditor/SectionEditorAction.inc.php
			// For Submissions >x> Review: Submission: 
			HookRegistry::register('SectionEditorAction::uploadReviewVersion', array(&$this, '_fileToMarkup'));	
			// For Submissions >x> Review: Editor Decision: 
			HookRegistry::register('SectionEditorAction::uploadEditorVersion', array(&$this, '_fileToMarkup'));	
			
			// For Submissions >x> Editing: Copyediting: 
			HookRegistry::register('SectionEditorAction::uploadCopyeditVersion', array(&$this, '_fileToMarkup'));	
			
			// EDITOR ON BEHALF OF REVIEWER: AVOID THIS?
			// For User > Editor > Submissions > #4 > Review: Peer Review (reviewer) : Upload review: 
			// hook receives array(&$reviewAssignment, &$reviewer)
			HookRegistry::register('SectionEditorAction::uploadReviewForReviewer', array(&$this, '_fileToMarkup'));	

			// For Submissions >x> Editing: Layout: 
			HookRegistry::register('SectionEditorAction::uploadLayoutVersion', array(&$this, '_fileToMarkup'));	
			HookRegistry::register('LayoutEditorAction::uploadLayoutVersion', array(&$this, '_fileToMarkup'));	

			HookRegistry::register('ArticleGalleyDAO::deleteGalleyById', array(&$this, 'deleteGalley'));
			
			//Add gateway plugin class to handle markup content requests;
			HookRegistry::register('PluginRegistry::loadCategory', array(&$this, '_callbackLoadCategory'));
		}

		return $success;
	}

	
	/**
	* Register as a gateway plugin too.
	* This allows the fetch() function to respond to requests for article files.  
	*
	* @param $hookName string
	* @param $args array [category string, plugins array]
	*/
	function _callbackLoadCategory($hookName, $args) {
		$category =& $args[0];
		$plugins =& $args[1];
		switch ($category) {
			case 'gateways': // Piggyback gateway accesss to this plugin.
				 $this->import('MarkupGatewayPlugin');
				 $gatewayPlugin = new MarkupGatewayPlugin($this->getName());
				 $plugins[$gatewayPlugin->getSeq()][$gatewayPlugin->getPluginPath()] =& $gatewayPlugin;
				break;
		}
		return false;
	}
	
	
	/**
	* Trigger document conversion when an author confirms a new submission (step 5).
	* Triggered at User > Author > a > New Submission, any step in form.
	*
	* @param $hookName string 
	* @param $params array [&$step, &$article, &$submitForm]
	*/
	function _authorNewSubmissionConfirmation($hookName, $params) {
		$step =& $params[0];
		if($step == 5) { // Only Interested in final confirmation step
				
			$article =& $params[1];
			$articleId	 = $article->getId();
			$journal =& Request::getJournal();
			$journalId	 = $journal->getId();
			
			//Ensure a supplementary file record is in place. (not nec. file itself).
			$suppFile = $this->_supplementaryFile($articleId);
					
			$fileId	= $article->getSubmissionFileId(); //Id of doc just uploaded.
			
			$articleFileDao =& DAORegistry::getDAO('ArticleFileDAO'); 
			$articleFile =& $articleFileDao->getArticleFile($fileId);
	
			if (!isset($articleFile)) {
				return false;
			}
			
			//Need article file manager just to get the file path of article.
			import('classes.file.ArticleFileManager');
			$articleFileManager = new ArticleFileManager($articleId);
			$articleFileDir = $articleFileManager->filesDir;
			
			//fileStageToPath : see classes/file/ArticleFileManager.inc.php
			//REPLACE $articleFilePath WITH PATH TO UPLOADED FILE ABOVE
			$articleFilePath = $articleFileDir. $articleFileManager->fileStageToPath( $articleFile->getFileStage() ) . '/' . $articleFile->getFileName();
			
			$this->_setSuppFileId($suppFile, $articleFilePath, $articleFileManager); 
			
			// Submit the article to the pdfx server
			$this->_submitURL($articleId);

		}
		
		return false; // Or true if for some reason we'd want to cancel the import of the uploaded file into OJS.
	}

	/**
	* Trigger document conversion from various hooks for editor, section editor, layout editor etc. uploading of documents.
	* FUTURE: check valid file suffix before proceeding?
	*
	* @param $hookName string , eg. SectionEditorAction::uploadCopyeditVersion
	* @param $params array [article object , ...]
	*
	*/
	function _fileToMarkup($hookName, $params) {
		
		$article =& $params[0]; 
		$articleId = $article->getId();
		$journal =& Request::getJournal(); /* @var $journal Journal */
		$journalId	 = $journal->getId();

		// Ensure a supplementary file record is in place. (not nec. file itself).
		$suppFile = $this->_supplementaryFile($articleId);

		// The form $fieldname of the uploaded file differs in one case of the calling hooks.  For the "Submissions >x> Editing: Layout:" call (SectionEditorAction::uploadLayoutVersionFForm) the file is called 'layoutFile', while in all other cases it is called 'upload'. 

		$fieldName = 'upload'; 
		if ($hookName == "SectionEditorAction::uploadLayoutVersion") {
			$fieldName = 'layoutFile'; 
		}

		// Trigger only if file uploaded.
		import('classes.file.ArticleFileManager');
		$articleFileManager = new ArticleFileManager($articleId);		
		if ($articleFileManager->uploadedFileExists($fieldName) ) {
			
			// Uploaded temp file must have an extension to continue 
			$this->import('MarkupPluginUtilities');
			$newPath = MarkupPluginUtilities::copyTempFilePlusExt($articleId, $fieldName);
			if ($newPath !== false) { 
				$this->_setSuppFileId($suppFile, $newPath, $articleFileManager); 
				@unlink($newPath);// Delete our temp copy of uploaded file. 
	
				// If we have Layout upload then trigger galley link creation.
				if (strpos($hookName, 'uploadLayoutVersion') >0) 
					$galleyFlag = "galley";
				else
					$galleyFlag = "";
					
				// Submit the article to the pdfx server
				$this->_submitURL($articleId, $galleyFlag);
			}
		}

		return false; // Or true if we want to cancel the file upload.
	}
	
	/** 
	* Make a new supplementary file record or copy over an existing one.
	* Depends on mime_content_type() to get suffix of uploaded file.
	*
	* @param $suppFile object
	* @param $suppFilePath string file path		
	* @param $articleFileManager object, already initialized with an article id.
	*/
	function _setSuppFileId(&$suppFile, &$suppFilePath, &$articleFileManager) {
		$mimeType = String::mime_content_type($suppFilePath);	
		$suppFileId = $suppFile->getFileId();

		if ($suppFileId == 0) { // There is no current supplementary file
			$suppFileId = $articleFileManager->copySuppFile($suppFilePath, $mimeType);
			$suppFile->setFileId($suppFileId);
			
			$suppFileDao =& DAORegistry::getDAO('SuppFileDAO');
			$suppFileDao->updateSuppFile($suppFile);
		}
		else {
			// See copySuppFile() in classes/file/ArticleFileManager.inc.php
			$articleFileManager->copySuppFile($suppFilePath, $mimeType, $suppFileId, true);
		}
	}
	
	/**
	* Web URL call triggers separate thread to do document conversion on  uploaded document.
	* URL request goes out to this same plugin.  The url path is enabled by the  gateway fetch() part of this plugin.  Then php continues on in 1 second ...
	*
	* @param $articleId int 
	* @param $galleyFlag boolean communicates whether or not galley links should be created (i.e. article is being published) 
	*/
	function _submitURL($articleId, $galleyFlag) {
		
		$args = array(
			'articleId' => $articleId,
			'action' => 'refresh'.$galleyFlag
		);
		$this->import('MarkupPluginUtilities');
		$url = MarkupPluginUtilities::getMarkupURL($args);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); //sends output to $contents
		curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1000);
		curl_setopt($ch, CURLOPT_VERBOSE, 1);
		$contents = curl_exec ($ch);

		/* The right $curlError response is:
		'Operation timed out after XYZ milliseconds with 0 bytes received'
		Bad response is: 'Timeout was reached'
		Occurs when not enough time alloted to get process going. Seems like 1000 ms is minimum.  Otherwise URL fetch not even triggered.
		*/
		$curlError = curl_error($ch);
		
		curl_close ($ch);
		return false;
	}

	/**
	* Ensures that a single "Document Markup Files" supplementary file record exists for given article.
	* The title name of this file must be unique so it can be found.
	*
	* @param: $articleId int
	* @var $locale string controlled by current locale, eg. en_US
	*/
	function _supplementaryFile($articleId) {

		//SEE: classes/article/SuppFileDAO.inc.php
		$suppFileDao =& DAORegistry::getDAO('SuppFileDAO');
		$suppFiles =& $suppFileDao->getSuppFilesBySetting('title','Document Markup Files', $articleId);
		$locale = AppLocale::getLocale();// eg. 'en_US'); 
			
		if (count($suppFiles) == 0) {

			// Insert article_supp_file
			// Adapted from classes/submission/form/supFileForm.inc.php
			//SubmissionFile->classes.article.ArticleFile
			import('classes.article.SuppFile');
			$suppFile = new SuppFile(); //See classes/article/SuppFile.inc.php
			$suppFile->setArticleId($articleId);

			// DO NOT CHANGE (LOCALIZE) THIS NAME - IT IS MATCHED LATER TO OVERWRITE INITIAL PDF/DOCX WIH ADJUSTED zip contents.
			$suppFile->setTitle('Document Markup Files', $locale );
			$suppFile->setType(''); //possibly 'common.other'
			$suppFile->setTypeOther("zip", $locale);
			$suppFile->setDescription(__('plugins.generic.markup.archive.description'), $locale);
			$suppFile->setDateCreated( Core::getCurrentDate() );
			$suppFile->setShowReviewers(0);
			$suppFile->setFileId(0); //has to be set (zero) for new create.
			// Unused: subject, source, language
			$suppId = $suppFileDao->insertSuppFile($suppFile);
			$suppFile->setId($suppId);
		}
				
		else {//Supplementary file exists, so just overwrite its file.
			$suppFile = $suppFiles[0];
		}
		// Skipping search index since this content is a repetition of article	
		$this->_notificationService(__('plugins.generic.markup.archive.processing'));
		$suppFileDao->updateSuppFile($suppFile);
				
		return $suppFile;
	}
	
	
	/**
	 * Provide notification messages for Document Markup Server job status
	 * $message is already translated, i.e. caller takes responsibility for setting up the text correctly.  $typeFlag for now signals just success or failure style of message.
	 *
	 * @param $message string translated text to display
	 * @param $typeFlag bool 
	 */
	function _notificationService($message, $typeFlag = true) {
		import('classes.notification.NotificationManager');
		$notificationManager = new NotificationManager();

		$notificationType = NOTIFICATION_TYPE_SUCCESS;		
		if ($typeFlag == false){
			$notificationType = NOTIFICATION_TYPE_ERROR;
		}
		$user = Request::getUser();
		$params= array('itemTitle' => $this->getDisplayName() ); 
		$notificationManager->createTrivialNotification(
             $user->getId(), 
             $notificationType,
             array('contents' => $message)
         );
	}


	/**
	* HOOK Sees if there are any HTML or XML galleys left if galley item is deleted.  If not, delete all markup related file(s).
	*
	* @param $hookName string
	* @param $params array [$galleyId]
	*
	* @see register()
	**/
	function deleteGalley($hookName, $params) {
		$galleyId=$params[0];
		$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
		$galley =& $galleyDao->getGalley($galleyId);
		$articleId = $galley->getSubmissionId();
		//FIXME: New approach deletes all media files if no HTML or XML galley exists anymore for this $articleId
		//$this->_deleteGalleyMedia($articleId);
		/*
		$remoteURL = $galley->getRemoteURL();
		//if trailing url is clearly made by this Plugin ...
		if (preg_match("#plugin/markup/$articleId/[0-9]+/document(\.html|-new\.pdf|\.xml)$#", $remoteURL, $matches)) {
			switch ($matches[1]) {
				case ".xml": $suffix = '.xml'; break;
				case "-new.pdf": $suffix = '.pdf'; break;
				case ".html": $suffix = '.html,.jpg,.png'; break;
				default: return false; //shouldn't occur
			}
			$suppFolder =  MarkupPluginUtilities::getSuppFolder($articleId).'/markup/document*{'.$suffix.'}';
			$glob = glob($suppFolder,GLOB_BRACE);
			foreach ($glob as $g) {unlink($g);}
		
		}
		*/
		
		//Issue of removing associated media....
		return false;	
	}

}
?>
