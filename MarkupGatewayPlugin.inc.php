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
	* @brief Responds to requests for markup files; sends request to markup an article to Document Markup Server.
	*
	* Handles URL request to trigger document processing for given article; also handles URL requests for xml/pdf/html versions of an article as well as the html's image and css files.   
	* This is the gateway plugin component of this plugin.  It sets up a fetch() receiver for requests of form "http://[domain]/ojs/index.php/[journal name]/gateway/plugin/markup/...".  THIS IS NOT A HOOK.  Access is view-only for now.
	*
	* When disable_path_info is true URL is of the form:
	*
	* When false, URL is of form:
	* http://.../index.php/chaos/gateway/plugin/markup/[article id]
	*		- retrieves document.xml file manifest
	*		... /markup/1/0/document.html // retrieves document.html page and related images/media files.
	*		... /markup/1/
	*		... /markup/1/0/document.xml
	*		... /markup/1/0/document.pdf 		
	*		... /markup/1/0/document-review.pdf 	
	*		... /css/[filename] 		//stylesheets for given journal		
	*		... /[articleid]/refresh 	//generate zip file 
	*		... /[articleid]/refreshgalley 	//generate zip file and make galley links 
	*		... /[articleid]/0/[filename] // return galley pdf/xml/html
	*		... /[articleid]/[versionid]/[action] //FUTURE: access version.
	*
	* @param $args Array of relative url folders down from plugin if path info is enabled
	* @param $request Object contains _requestVars url parameters used when path info is disabled
	*/

	
import('classes.plugins.GatewayPlugin');

class MarkupGatewayPlugin extends GatewayPlugin {
	var $parentPluginName;

	/**
	 * Constructor
	 */
	function MarkupGatewayPlugin($parentPluginName) {
		$this->parentPluginName = $parentPluginName;
		
		die($parentPluginName);
	}
	
	/**
	 * Get the name of this plugin. The name must be unique within
	 * its category.
	 * @return String name of plugin
	 */
	function getName() {
		return 'markup';
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
		$plugin =& PluginRegistry::getPlugin('generic', $this->parentPluginName);
		return $plugin;
	}

	/**
	 * Override the builtin to get the correct plugin path.
	 */
	function getPluginPath() {
		$plugin =& $this->getMarkupPlugin();//plugins/generic/markup
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
	 * Get whether or not this plugin is enabled. (Should always return true, as the
	 * parent plugin will take care of loading this one when needed)
	 * @return boolean
	 */
	function getEnabled() {
		$plugin =& $this->getMarkupPlugin();
		die($plugin->getEnabled());
		return $plugin->getEnabled(); // Should always be true anyway if this is loaded
	}

	/**
	 * Get the management verbs for this plugin (override to none so that the parent
	 * plugin can handle this)
	 * @return array
	 */
	function getManagementVerbs() {
		return array();
	}

	function fetch($args) {
		die("here at fetch");
		if (! $this->getEnabled() ) 
			return $this->_exitFetch( __('plugins.generic.markup.archive.enable'));
	
		// Make sure we're within a Journal context
		$journal =& Request::getJournal();
		if (!$journal) 
			return $this->_exitFetch( __('plugins.generic.markup.archive.no_journal'));
		
		$journalId = $journal->getId();

		$this->import('MarkupPluginUtilities');
				
		//if (!Request::isPathInfoEnabled()) {	$requestVars = $request->_requestVars; die(var_dump($args));}
		
		$param_1 = strtolower(array_shift($args));
		$param_2 = strtolower(array_shift($args)); 
		$fileName = strtolower(array_shift($args));	
		
		/* STYLESHEET HANDLING
		* Handles relative urls like "../../css/styles.css"
		* Provide Journal specific stylesheet content.  CSS is public so no permission check. (This is the only public folder below /markup/ folder.)
		* Journal's [filesDir]/css is checked first for content
		* 
		*/
		if ($param_1 == "css") {
			$fileName = MarkupPluginUtilities::cleanFileName($param_2);
			import('classes.file.JournalFileManager');
			$journalFileManager = new JournalFileManager($journal);
			$folderCss = $journalFileManager->filesDir . 'css/';
			if (file_exists($folderCss.$fileName))
				$folderCss = dirname(__FILE__).'/css';
			return MarkupPluginUtilities::downloadFile($folderCss, $fileName);
		}
		
		
		/* Should be dealing with a particular article's files here. */
	
		$articleId = intval($param_1);
		if (!$articleId) 
			return $this->_exitFetch( __('plugins.generic.markup.archive.no_articleID'));
	
		$articleDao = &DAORegistry::getDAO('ArticleDAO');
		$article = &$articleDao->getArticle($articleId);
		if (!$article) 
			return $this->_exitFetch(  __('plugins.generic.markup.archive.no_article'));
	
		if ($param_2 == 'refresh' ) {
			$this->_refresh($article, false);
			return true; //Doesn't matter what is returned.  This is a separate curl() thread.
		};
		
		// As above, but galley links created too.
		if ($param_2 == 'refreshgalley') {
			$this->_refresh($article, true);
			return true; 
		};	

		/* 
		Now we deliver any markup file request if its article's publish state allow it, or if user's credentials allow it. 
		$param_2 is /0/ for version/revision; a constant for now. 
		$fileName should be a file name.
		*/

		$fileName = MarkupPluginUtilities::cleanFileName($fileName);

		if ($fileName == '')
			return $this->_exitFetch( __('plugins.generic.markup.archive.bad_filename')); 
			
		$markupFolder =  MarkupPluginUtilities::getSuppFolder($articleId).'/markup/';
		
		if (!file_exists($markupFolder.$fileName))
			return $this->_exitFetch( __('plugins.generic.markup.archive.no_file')); 
		
		$status = $article->getStatus();
	
		// Most requests come in when an article is in its published state, so check that first.
		if ($status == STATUS_PUBLISHED ) { 
			if ($this->_publishedDownloadCheck($articleId, $journal, $fileName)) {
				MarkupPluginUtilities::downloadFile($markupFolder, $fileName);
				return true;
			}
		}
	
		// Article not published, so access can only be granted if user is logged in and of the right type / connection to article
		$user =& Request::getUser();
		$userId = $user ? $user->getId() : 0;
	
		if (!$userId) 
			return $this->_exitFetch( __('plugins.generic.markup.archive.login')); 
		
		if ($this->_authorizedUser($userId, $articleId, $journalId, $fileName) ) {
			MarkupPluginUtilities::downloadFile($markupFolder, $fileName);
			return true;
		}

		return $this->_exitFetch( __('plugins.generic.markup.archive.no_access')); 

	}

	/**
	* Request is for a "refresh" of an article's markup archive.  
	* If article's "Document Markup Files" supplementary file is not a .zip (in other words it is an uploaded .doc or .pdf), then send the supplementary file to the PKP Document Markup Server for conversion.
	* Status of the supplementary file (Creator field) is updated to indicate progress in fetching and processing it.
	*
	* @param $article object
	* @param $galleyLinkFlag boolean
	*
	* @see fetch()
	*/
	function _refresh(&$article, $galleyLinkFlag) {
		$journal =& Request::getJournal(); 
		$journalId = $journal->getId();
		$articleId = $article->getId();

		// PRECONDITIONS
		$markupHostURL = $this->getSetting($journalId, 'markupHostURL');
		
		if (!strlen($markupHostURL)) return $this->_exitFetch( __('plugins.generic.markup.archive.no_url'),true);

		$suppFileDao =& DAORegistry::getDAO('SuppFileDAO');
		$suppFiles =& $suppFileDao->getSuppFilesBySetting("title", "Document Markup Files", $articleId);
		
		if (count($suppFiles) == 0) return $this->_exitFetch( __('plugins.generic.markup.archive.supp_missing'),true);

		$suppFile = $suppFiles[0];// There should only be one.

		$fileId = $suppFile->getFileId();
		if ($fileId == 0) return $this->_exitFetch( __('plugins.generic.markup.archive.supp_file_missing'),true);
		
		$suppFileName = $suppFile->getFileName();
		// If supplementary file is already a zip, there's nothing to do.  Its been converted.
		if (preg_match("/.*\.zip/", $suppFileName ) ) {
			return $this->_exitFetch( __('plugins.generic.markup.archive.is_zip'));
		}
				
		$args =& $this->_refreshPrepareData($article, $journal);
		import('lib.pkp.classes.core.JSONManager');
		$postFields = array(
			'jit_events' => JSONManager::encode(array($argsArray)),
			'userfile' => "@". $getSuppFolder($articleId). '/' . $suppFileName
		);
		
		//CURL sends article file to pdfx server for processing, and in 15-30+ seconds or so returns jobId which is folder where document.zip archive of converted documents sits.
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $markupHostURL."process.php");
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); //provides $contents
		curl_setopt($ch, CURLOPT_VERBOSE, 1);
		$contents = curl_exec ($ch);
		$errorMsg = curl_error($ch);
		curl_close ($ch);

		if ($contents === false) return $this->_exitFetch($errorMsg, true);
		
		$this->_notificationService(__('plugins.generic.markup.archive.processing'));				

		$events = JSONManager::decode($contents); // decode() returns object, not array.		
		$responses = $events->jit_events;
		$response = $responses[0]; //Should only be 1 element in array

		if ($response->error > 0) // Document markup server provides plain text error message details.
			return $this->_exitFetch($response['message'],true);
		
		$this->_refreshProcessResult($article, $response, $suppFile, $galleyLinkFlag);
		
	}
	
	/**
	* Produce an array of settings to be processed by the Document Markup Server.
	*
	* @param $article object
	* @param $journal object
	*
	* @return $args array
	*/
	function _refreshPrepareData(&$article, &$journal) {
		$journalId = $journal->getId();
		$articleId = $article->getId();
			
		// Construct the argument list and call the plug-in settings DAO
		$markupHostURL = $this->getSetting($journalId, 'markupHostURL');
		$hostUser = $this->getSetting($journalId, 'markupHostUser');
		$hostPass = $this->getSetting($journalId, 'markupHostPass');
		
		//In authors array we just want the _data object.
		$authors = $article->getAuthors(); 
		$authorsOut = array();
		foreach($authors as $author) {
			$author = ($author->_data);
			unset($author["sequence"], $author["biography"]);
			//Remaining fields: submissionId, firstName, middleName, lastName, country, email, url, primaryContact (boolean), affiliation:{"en_US":"..."}
			$authorsOut[] = $author;
		}

		$cslStyle = $this->getSetting($journalId, 'cslStyle');
		
		//Prepare request for Document Markup Server
		$args = array(
			'type' => 'PDFX.fileUpload',
			'data' => array(
				'user' => $hostUser, //login with these params or use guest if blank.
				'pass' => $hostPass,
				'cslStyle' => $cslStyle,
				'cssURL' => '',
				'title'	  => $article->getLocalizedTitle(),
				'authors' => $authorsOut,
				'journalId' => $journalId,
				'articleId' => $article->getId(),

				'publicationName' => $journal->getLocalizedTitle(),
				'copyright' =>  strip_tags($journal->getLocalizedSetting('copyrightNotice')),
				'publisher' => strip_tags($journal->getLocalizedSetting('publisherNote')),
				'rights' => strip_tags($journal->getLocalizedSetting('openAccessPolicy')),
				'eISSN' => $journal->getLocalizedSetting('onlineIssn'),
				'ISSN' => $journal->getLocalizedSetting('printIssn'), // http://www.issn.org/

				'DOI' => $article->getPubId('doi') //http://dx.doi.org

			)
		);

		// This field has content only if header image actually exists in the right folder.
		import('classes.file.JournalFileManager');
		$journalFileManager = new JournalFileManager($journal);
		$ImageFileGlob = $journalFileManager->filesDir . 'css/article_header.{jpg,png}';
		$g = glob($ImageFileGlob,GLOB_BRACE);
		$cssHeaderImageName = basename($g[0]);
		if (strlen($cssHeaderImageName) > 0) 
			$args['data']['cssHeaderImageURL'] = $cssURL.$cssHeaderImageName;
					
		// Provide some publication info
		$issueDao =& DAORegistry::getDAO('IssueDAO');
		$issue =& $issueDao->getIssueByArticleId($articleId, $journalId);
		if ($issue && $issue->getPublished()) { // At what point are articles shunted into issues?
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
	* Fetch document.zip file waiting in job folder at Document Markup Server
	* Unzips 
	* @param $article object
	* @param $response object conversion data from Document Markup Server
	* @param $journal object
	*
	*
	*
	*/
	function _refreshProcessResult(&$article, &$response, &$suppFile, $galleyLinkFlag) {

		import('classes.file.ArticleFileManager');
		$articleFileManager = new ArticleFileManager($articleId);
		$suppFileDao =& DAORegistry::getDAO('SuppFileDAO');
		
		// With a $jobId, we can fetch URL of zip file and enter into supplimentary file record.
		$responseData = $response->data;
		$jobId = $responseData->jobId;
		$jobId = preg_replace("/[^a-zA-Z0-9]+/", "", $jobId);
		if (strlen($jobId) == 0 || strlen($jobId) > 40) 
			return $this->_exitFetch( __('plugins.generic.markup.archive.no_job').$contents);
		
		$archiveURL = $markupHostURL . 'job/'.$jobId.'/document.zip';
			
		$suppFileId = $suppFile->getFileId();
		if ($suppFileId == 0) { // no current supplementary file on drive 
			$suppFileId = $articleFileManager->copySuppFile($archiveURL, 'application/zip');
			$suppFile->setFileId($suppFileId);
		}
		else {
			// copySuppFile($url, $mimeType, $fileId = null, $overwrite = true)
			// See classes/file/ArticleFileManager.inc.php. Null if unsuccessful.
			$articleFileManager->copySuppFile($archiveURL, 'application/zip', $suppFileId, true);
		}
		$suppFileDao->updateSuppFile($suppFile);
			
		// Unzip file and launch galleys ONLY during Layout upload
		if ($galleyLinkFlag) {
			if (! $this->_unzipSuppFile($articleId, $suppFile, $galleyLinkFlag) ) return true; // Any errors are reported within call. 
		}
		else {
			// If we're not launching a galley, then if there are no galley links left in place for XML or HTML content, then make sure markup/images & media are deleted for given article. 
			$this->_checkGalleyMedia($articleId);	
		}
	
		return $this->_exitFetch(__('plugins.generic.markup.completed') . "  $articleId (Job $jobId)",true);

	}

	/**
	* Do all necessary checks to see if user is allowed to download this file
	* Basically a variation on /ojs.pages/article/ArticleManager.inc.php validate() AND /ojs.pages/issue/IssueManager.inc.php validate() 
 	* FUTURE: provide access to a file only if galley exists for it?
	*
	* @param $articleId int
 	* @param $journal object
 	* @param $fileName string , included in case we have to filter about requested file type (pdf or other)
 	*
 	* @return boolean true iff user allowed to see given file
	* @see fetch()
	*/
	function _publishedDownloadCheck($articleId, &$journal, $fileName) {

		$journalId = $journal->getId();
		
		//Is Issue published?
		$issueDao =& DAORegistry::getDAO('IssueDAO');
		$issue =& $issueDao->getIssueByArticleId($articleId, $journalId);
		if (!$issue->getPublished()) {
			return false;
		}
				
		// Flag indicating user's computer domain is ok for viewing. $articleId just used to lookup published article id, which is then used to say OK if an expired subscription was valid when article published.!!!!?
		import('classes.issue.IssueAction');
		$isSubscribedDomain = IssueAction::subscribedDomain($journal, $issue->getId(), $articleId);
		if ($isSubscribedDomain) 
			return true;// Let em see it.	
		
		// Login required. Presumably flag for 'restrictSiteAccess' => 'Users must be registered and log in to view the journal site.'
		$subscriptionRequired = IssueAction::subscriptionRequired($issue);
		if (!$subscriptionRequired) 
			return true;// Let em see it.	
		
		// Now subscriptionRequired
		// Some journals allow access at the individual article level without login?
		if (!$journal->getSetting('restrictArticleAccess')) 
			return true;
		
		// 'Users must be registered and log in to view restricted article related content'
		if (!Validation::isLoggedIn()) 
			return false;
		
		// Subscription Access.  Calls getPublishedArticleByArticleId with $articleId
		$subscribedUser = IssueAction::subscribedUser($journal, $issue->getId(), $articleId);
		if ($subscribedUser) 
			return true;
		
		// A chunk of work below is done in above IssueAction::subscribedUser, but we don't have access to it.
		$publishedArticleDao =& DAORegistry::getDAO('PublishedArticleDAO');
		// Regular article doesn't have getAccessStatus()
		$publishedArticle =& $publishedArticleDao->getPublishedArticleByArticleId((int) $articleId, (int) $journalId, true);
		// Choice here: ARTICLE_ACCESS_OPEN or ARTICLE_ACCESS_ISSUE_DEFAULT
		if ($publishedArticle->getAccessStatus() == ARTICLE_ACCESS_OPEN) 
			return true;
	
		// At this point access requires fee-based service except possibly in non-pdf case.  The OJSPaymentManager($request) uses $request just to look up journal.
		import('classes.payment.ojs.OJSPaymentManager');
		$request =& Request::getRequest();
		$paymentManager = new OJSPaymentManager($request);
		
		// One of these fee methods must be active (with fees above $0), or else we quit.
		if ( !$paymentManager->purchaseArticleEnabled() &&
			!$paymentManager->purchaseIssueEnabled() &&
			!$paymentManager->membershipEnabled() ) {
			return false;
		}

		// If only pdf files are being restricted, then approve all non-pdf files; should this be moved above fee method?
		if ( $paymentManager->onlyPdfEnabled() && pathinfo($fileName, PATHINFO_EXTENSION) != 'pdf') {
			return true;
		}
		
		$completedPaymentDao =& DAORegistry::getDAO('OJSCompletedPaymentDAO');

		// If article has been paid for
		if ($completedPaymentDao->hasPaidPurchaseArticle($userId, $publishedArticle->getId()) )
			return true;
		
		// If issue has been paid for
		if ( $completedPaymentDao->hasPaidPurchaseIssue($userId, $issue->getId()) )
			return true;
		
		// If membership is still good; could move this up.
		$dateEndMembership = $user->getSetting('dateEndMembership', 0);
		if (!is_null($dateEndMembership) && $dateEndMembership > time() ) 
			return true;

		// In all other cases...
		return false;
	}
	
	
	/**
	* Calculate current user's read permission with respect to given article.
	* Handles case where article isn't published yet.
	* FUTURE: Return editing permission too (only if STATUS_QUEUED)
	*
	*   - user is SITE_ADMIN or JOURNAL_MANAGER ?: return true
	*	- user is Editor / Section Editor of given journal ?: return true
	*	- user is author / reader / reviewer of given article ?: return true.
	*
	* USERS TO CONSIDER: See ojs/classes/security/Validation.inc.php
	*
	*  ROLE_ID_SITE_ADMIN		isSiteAdmin() RARE
	*
	*  All isXYZ() functions below can take a journalId.
	*  ROLE_ID_JOURNAL_MANAGER isJournalManager()
	*  ROLE_ID_EDITOR 			isEditor()
	*  ROLE_ID_SECTION_EDITOR	isSectionEditor()
	*
	*  ROLE_ID_COPYEDITOR		isCopyeditor()
	*  ROLE_ID_LAYOUT_EDITOR 	isLayoutEditor()
	*  ROLE_ID_PROOFREADER		isProofreader()
	*
	*  ROLE_ID_AUTHOR			isAuthor()
	*  ROLE_ID_READER			isReader()
	*  ROLE_ID_REVIEWER		isReviewer()
	*
	* @return userType that matches user to article.
	**/
	function _authorizedUser($userId, $articleId, $journalId, $fileName) {

		$roleDao = &DAORegistry::getDAO('RoleDAO'); 
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
			
						if ($sectionEditorSubmission != null && $sectionEditorSubmission->getJournalId() == $journalId && $sectionEditorSubmission->getDateSubmitted() != null) {
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

					case ROLE_ID_COPYEDITOR : //'SIGNOFF_COPYEDITING'
						$SESDao =& DAORegistry::getDAO('SectionEditorSubmissionDAO');
						if ($SESDao->copyeditorExists($articleId, $userId) )
							return $roleType; 
						break;
					
					case ROLE_ID_AUTHOR : //Find out if article has this submitter.
						
						$articleDao =& DAORegistry::getDAO('ArticleDAO');
						$article =& $articleDao->getArticle($articleId, $journalId);
						if ($article && $article->getUserId() == $userId && ($article->getStatus() == STATUS_QUEUED || $article->getStatus() == STATUS_PUBLISHED)) {
							 return $roleType;
						}
						break;
						
					case ROLE_ID_REVIEWER :
						// Find out if article currently has this reviewer.
						$reviewAssignmentDao = DAORegistry::getDAO('ReviewAssignmentDAO');
						$reviewAssignments = $reviewAssignmentDao->getBySubmissionId($articleId);
						foreach ($reviewAssignments as $assignment) {
							if ($assignment->getReviewerId() == $userId) {
								//	REVIEWER ACCESS: If reviewers are not supposed to see list of authors, REVIEWER ONLY GETS TO SEE document-review.pdf version, which has all author information stripped.
								if ($this->getSetting($journalId, 'reviewVersion') != true || $fileName == 'document-review.pdf')
									return $roleType; 
								continue; // We've matched to user so no more tries.
							}
						}

						break;
				}
			}
		}

		return false;
		
	}
	
	
	/**
	* Atom XML template displayed in response to a direct plugin URL document fetch() or refresh() call .
	* Never seen by OJS end users unless accessing a document directly by URL and there is a problem.  Useful for programmers to debug fetch issue.
	*
	* @param $msg string status indicating job success or error
	* @param $notification boolean indicating if user should be notified.
	*/
	function _exitFetch($msg, $notification) {
		
		if ($notification == true) 
			$this->_notificationService(__('plugins.generic.markup.archive.status') . $msg);
		
		$templateMgr =& TemplateManager::getManager();
		$templateMgr->assign_by_ref('journal', $journal);
		$templateMgr->assign('selfUrl', Request::getCompleteUrl() ); 
		$templateMgr->assign('dateUpdated', Core::getCurrentDate() );
		$templateMgr->assign('description', $msg);

		$templateMgr->display($this->getTemplatePath()."/fetch.tpl", "application/atom+xml");

		return true;
	}
	
	/**
	* Uncompresses document.zip into an article's supplementary file markup folder.
	* notifications are sent here because this is in response to work done on an article.
	* Unzip is triggered by URL call to /refreshGalley ; in OJS this is triggered by editor's file upload to Layout files area.  Article has a freshly generated supplementary documents.zip file.  Now into the /markup folder, extract all graphics, and the following:
	*	manifest.xml
	*	document.xml
	*	document-new.pdf
	*	document.html
	*	document-review.pdf // doesn't have author list 
	* and make galley links for the xml, pdf, and html files.
	* WARNING: zip extractTo() function will fail completely if we include an entry in $extractFiles() that doesn't exist in zip manifest.
	*
	* @param $articleId int
	* @param $suppFile object
	* @param $galleyLinkFlag boolean signals creation of galley links
	*
	* @see _refresh()
	*/
	function _unzipSuppFile($articleId, &$suppFile, $galleyLinkFlag) {

		// We need updated name. It was x.pdf or docx, now its y.zip:
		$suppFileDao =& DAORegistry::getDAO('SuppFileDAO');
		$suppFile =& $suppFileDao->getSuppFile($suppFile->getId() );
		$suppFileName = $suppFile->getFileName();
		
		$suppFolder =  MarkupPluginUtilities::getSuppFolder($articleId);		
		$markupPath = $suppFolder.'/markup';

		$zip = new ZipArchive;
		$res = $zip->open($suppFolder.'/'.$suppFileName, ZIPARCHIVE::CHECKCONS);
		
		if ($res !== TRUE) {
			$errorMsg = $zip->getStatusString();
			$this->_exitFetch(__('plugins.generic.markup.archive.bad_zip') .":". $suppFileName .":". $errorMsg, true);
			return false;
		}

		// Ensure that we only extract "good" files.
		$candidates = array("manifest.xml","document.xml", "document-new.pdf", "document.html","document-review.pdf");
		//FIXME: try "media" folder.
		$extractFiles = array();
		for ($ptr = 0; $ptr < count($candidates); $ptr++) {
			$candidate = $candidates[$ptr];
			if ( ($zip->locateName($candidate)) !== false)
				$extractFiles[] = $candidate;
		};
		
		// Get all graphics
		$extractSuffixes = array("png","jpg");
		for ($i = 0; $i < $zip->numFiles; $i++) {
			 $fileName = $zip->getNameIndex($i);
			 
			 if (in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), $extractSuffixes)) 
			 	 $extractFiles[] = $fileName;
 
		}
	
		//* Write contents of $suppFileId document.zip to [/var/www_uploads]/journals/[x]/articles/[y]/supp/[z]/markup/
	
		// PHP docs say extractTo() returns false on failure, but its triggering this, and yet returning "No error" for $errorMsg below.
		if ($zip->extractTo($markupPath, $extractFiles) === FALSE) {
			$errorMsg = $zip->getStatusString();
			if ($errorMsg != 'No error') {
				$zip->close();
				$this->_exitFetch( __('plugins.generic.markup.archive.bad_zip').$errorMsg, true);
				return false;
			}
		}
		$zip->close();
		
		if ($galleyLinkFlag) {
			$this->_setupGalleyForMarkup($articleId, "document.html");
			$this->_setupGalleyForMarkup($articleId, "document-new.pdf");
			$this->_setupGalleyForMarkup($articleId, "document.xml");
		}
		
		return true;
	}
			
	/**
	* Populates an article's galley links with remote_urls.
	* CURRENTLY: no sensitivity to an article's revision/version. 
	* "/0/" is used as placeholder for future revision.
	*
	* @param $articleId int
	* @param $fileName string document.[xml | pdf | html] to link
	*
	* @return $galleyId int Id of new galley link created.
	*/
	function _setupGalleyForMarkup($articleId, $fileName) {
		
		$journal =& Request::getJournal();
		$journalId = $journal->getId();
		$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
				
		$args = array(
			'articleId' => $articleId,
			'filename' => $fileName
		);
		$remoteURL =  MarkupPluginUtilities::getMarkupURL($args);

		$gals =& $galleyDao->getGalleysByArticle($articleId);
		foreach ($gals as $gal) {
			//Currently there is no method for querying a galley entry's remote_url field.  It isn't a "setting" in article_galley_settings.  So doing a loop here.
			if ($gal->getRemoteURL() == $remoteURL) {
				return true; //no need to overwrite	
    		}
    	}
		
		$galley = new ArticleGalley();
		$galley->setArticleId($articleId);
		$galley->setFileId(null);
		$suffix = pathinfo($fileName, PATHINFO_EXTENSION);
		$galley->setLabel(strtoupper($suffix));
		$galley->setLocale(AppLocale::getLocale());

		//FIXME
		$galley->setRemoteURL( $remoteURL  );
	
		// Insert new galley link
		$galleyDao->insertGalley($galley);
		return $galley->getId();
		
	}
}
?>
