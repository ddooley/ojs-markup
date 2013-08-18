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
	
class MarkupPluginUtilities {
		
	/**
	* Return server's folder path that points to an article's supplementary file folder.
	*
	* @param $articleId int
	*
	* @return string supplementary file folder path.
	*/
	function getSuppFolder($articleId) {
		import('classes.file.ArticleFileManager');	
		$articleFileManager = new ArticleFileManager($articleId);
		return ($articleFileManager->filesDir) . ($articleFileManager->fileStageToPath( ARTICLE_FILE_SUPP )) ;
	}

	/**
	* Return plugin root url that provides file access for a given article within context of current journal.  Uses gateway plugin access point.
	* e.g. ... /index.php/praxis/gateway/plugin/markup/1/refresh
	* or ... index.php?journal=praxis&page=gateway&op=plugin&path[]=markup&path[]=1&path[]=refresh
	* FIXME: possible that 'markup' name would change.
	*
	* @param $args Array [articleId, action, filepath]
	*
	* @return string URL
	*
	* @see MarkupPlugin _submitURL()
	* @see MarkupPluginFetch fetch()
	*/	
	function getMarkupURL($args) {

		$articleId = $args['articleId'];//intval
		if ($args['action']) {
			$path = array('markup', $articleId, $args['action']);
		}
		else if ($args['folder']) {
			$path = array('markup', $args['folder'], $args['filename']);
		}
		else {
			$path = array('markup', $articleId, 0, $args['filename']);
		}
		$params = null;
		return Request::url(null, 'gateway', 'plugin', $path, $params);
	}

	/**
	* Ensures no funny business with filenames usually coming in from Markup plugin-handled file requests
	*
	* @param $fileName string
	*/
	function cleanFileName($fileName) {
		return preg_replace('/[^[:alnum:]\._-]/', '', $fileName);
	}


	/**
	* Provide suffix for copy of uploaded file (sits in same folder as original upload).
	* The uploaded temp file doesn't have a file suffix.  We copy this file and add a suffix, in preperation for uploading it to document markup server.  Uploaded file hasn't been processed by OJS yet.
	*
	* @param: $articleFileManager object primed with article	
	* @param: $fieldName string upload form field name	
	*
	* @return false if no suffix; otherwise path to copied file
	*/
	function copyTempFilePlusExt($articleId, $fieldName) {
		import('classes.file.ArticleFileManager');
		$articleFileManager = new ArticleFileManager($articleId);	
		$articleFilePath = $articleFileManager->getUploadedFilePath($fieldName);
		$fileName =  $articleFileManager->getUploadedFileName($fieldName);
		if (!strpos($fileName,".")) return false; // Exit if no suffix.
		$suffix = $articleFileManager->getExtension($fileName);
		$newFilePath = $articleFilePath.".".$suffix;
		$articleFileManager->copyFile($articleFilePath, $newFilePath);
		return $newFilePath;
	}
	
	
	/**
	* Return requested markup file to user's browser	
	* Eg. /var/www_uploads/journals/1/articles/2/supp/markup/document.html : text/html
	* 
	* @param $folder string Server file path
	* @param $fileName string (must already be validated)
	* 
	* @see DocumentMarkupFetch
	*/
	function _downloadFile($folder, $fileName) {
		
		$filePath = $folder.$fileName;
		$fileManager = new FileManager();
		
		if (!$fileManager->fileExists($filePath,'file')) {
			return $this->_exitFetch( __('plugins.generic.markup.archive.no_file').' : '.$fileName);
		}
		/*
		$suffix = pathinfo($fileName, PATHINFO_EXTENSION);
			
		switch ($suffix) {

			case 'xml'  : $mimeType = 'application/xml'; break;
			case 'txt'  : $mimeType = 'text/plain'; break;
			case 'pdf'	: $mimeType = 'application/pdf'; break;
			case 'html' : $mimeType = 'text/html'; break;				
			case 'png' : $mimeType = 'image/png'; break;
			case 'jpg' : $mimeType = 'image/jpeg'; break;			
			case 'css' : $mimeType = 'text/css'; break;
			//case 'zip'	:  $mimeType = 'application/zip';break;
			default: 
				return false; //WARNING: File type not matched.
		}
		$fileManager->downloadFile($folder. $fileName, $mimeType, true);
		*/

		$fileManager->downloadFile($folder. $fileName, null, true);
	
		return true;
	}
	
	
	/**
	* Delete markup plugin media files related to an Article if no XML or HTML galley links are left (that would need media). 
	* Triggered when refresh() called and galley generation flag= false
	*
	* @param $articleId int
	*
	* @see _refresh()

	function _deleteGalleyMedia($articleId) {
		// Delete all markup files
		$suppFolder =  MarkupPluginUtilities::getSuppFolder($articleId).'/markup/*';
		$glob = glob($suppFolder);
		foreach ($glob as $g) {unlink($g);}
		
		// Delete galley links
		$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
		$gals =& $galleyDao->getGalleysByArticle($articleId);
		foreach ($gals as $galley) {
			//if trailing url is clearly made by this Plugin ...
			$url = $galley->getRemoteURL();
			$url = $galley->getRemoteURL();
			if (strlen($url)>0 && preg_match("#plugin/markup/$articleId/[0-9]+/#", $url) ) {
				$galleyDao->deleteGalley($galley);
			}
		}
	}
	*/
	
	
}
?>
