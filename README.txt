Welcome to the Document Markup Plugin
--------------------------------------
Copyright (c) 2003-2013 John Willinsky
Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.

This README file contains a general description of this module's functionality (also available at http://http://pkp.sfu.ca/wiki/index.php/XML_Publishing).  All settings required for its functionality are managed on its plugin settings page (after installation).

This project implements an OJS plugin for producing NLM standard article XML, as well as pdf and HTML document versions for any article uploaded to an OJS journal.

This plugin has a few settings fields. One field enables selecting a CSL style (that pertains to the OJS journal) from a dynamic list. This controls the format of citations and bibliography entries. Another setting controls whether a pdf version of the article intended only for reviewers is produced. As well, an upload field is provided to send a header image for inclusion in pdf and html versions. Finally, using the OJS file manager, Journal Managers can change the styling of displayed html documents.

When an author, copyeditor or editor uploads a new version (odt, docx, doc, or pdf format) of an article, this module (using a separate thread) submits it to the pdfx server specified in the configuration file. The following files are returned in a gzip'ed archive file (X-Y-Z-AG.tar.gz) which is added (or replaces a pre-existing version in) the Supplementary files section.

		document-new.pdf (new version of original pdf or other file format)
		document-review.pdf (included for reviewers only, it has 1st page author information stripped out)
		document.xml (NLM National Library of Medicine standard xml)
		document.html and related graphics
		document.bib (a bibtex text file of reference data)
		document.refs.txt (a text file of the article's citations and their bibliographic references, formatted according to selected CSL style. It provides an indication of which references were unused in body of article.)

If the article is being uploaded as a galley publish, this plugin will extract the xml, html and pdf versions when they are ready, and will place them in the supplementary file folder so that web options can be provided for viewing.
This process is triggered each time an article is submitted to enable the bibliographic reference work to be available at early stages of review and during copyedit.
