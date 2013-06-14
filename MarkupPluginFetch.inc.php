<?php

/**
 * @file plugins/generic/markup/MarkupPluginFetch.inc.php
 * @called from fetch() in MarkupPlugin.inc.php
 *
 * Copyright (c) 2003-2010 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 *
 * @brief Handle document xml, pdf, html, image and css fetch requests for this plugin.
 * ALL ACCESS IS VIEW ACCESS FOR NOW; 
 *
 * URL is generally of the form:
 * 		http://ubie/ojs/index.php/chaos/gateway/plugin/markup/[article id]
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
 */

	if (! $this->getEnabled() ) 
		return $this -> _exitFetch("Document Markup Plugin needs to be enabled!");

	// Make sure we're within a Journal context
	$journal =& Request::getJournal();
	if (!$journal) 
		return $this ->_exitFetch("Request needs a Journal.");
	
	$journalId = $journal->getId();

	/* See what kind of request this is: 
		...plugin/markup/$param_1/$param_2/$fileName
	*/
	$param_1 = strtolower(array_shift($args));
	$param_2 = strtolower(array_shift($args)); 
	
	$fileName = strtolower(array_shift($args)); 
	// Clean filename, if any:
	$fileName = preg_replace('/[^[:alnum:]\._-]/', '', $fileName );
		
	/* STYLESHEET HANDLING
	* Recognizes any relative urls like "../../css/styles.css"
	* Provide Journal specific stylesheet content.
	* No need to check user permissions here
	*/
	if ($param_1 == "css") {
		$folder =  Config::getVar('files', 'files_dir') . '/journals/' . $journalId . '/css/';
		return $this -> _downloadFile($folder, $param_2);
	}
	
	/* DEALING WITH A PARTICULAR ARTICLE HERE */

	$articleId = intval($param_1);
	if (!$articleId) 
		return $this -> _exitFetch("Article Id parameter is invalid or missing.");

	$articleDao = &DAORegistry::getDAO('ArticleDAO');
	$article = &$articleDao->getArticle($articleId);
	if (!$article) 
		return $this -> _exitFetch("No such article!");

	if ($param_2 == "refresh" ) {
		$this -> _refresh($article, false);
		return true; //Doesn't matter what is returned.  This is a separate curl() thread.
	};
	// As above, but galley links created too.
	if ($param_2 == "refreshgalley" ) {
		$this -> _refresh($article, true);
		return true; 
	};	
	
	if (trim($fileName) == '')
		return $this -> _exitFetch("File name is missing or misformatted.  Should be: .../markup/[article Id]/0/[file name]"); 

	/* Now we deliver any markup file request if its article's publish state allow it, or if user's credentials allow it. 
	
		$param_2 is /0/ for version/revision; a constant for now. 
		$filename should be a file name.
	
	*/
	
	$markupFolder = $this -> _getSuppFolder($articleId)."/markup/";
	
	if (!file_exists($markupFolder.$fileName))
		return $this -> _exitFetch("That file does not exist."); 
	
	$status = $article->getStatus();

	// Most requests come in when an article is in its published state, so check that first.
	if ($status == STATUS_PUBLISHED ) { 
	
		if ($this -> _publishedDownloadCheck($articleId, $journal, $fileName)) {
			$this -> _downloadFile($markupFolder, $fileName);
			return true;
		}
	}

	// Article not published, so access can only be granted if user is logged in and of the right type / connection to article
	$user =& Request::getUser();	  //$request->getUser();	
	$userId = $user?$user->getId():0;

	if (!$userId) return $this -> _exitFetch("You need to login to get access to this file!"); 
	
	if ($this -> _authorizedUser($userId, $articleId, $journalId, $fileName) )
		$this -> _downloadFile($markupFolder, $fileName);

	return true;
	
?>
