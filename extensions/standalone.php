<?php
	/**
	* Wrapper file for standalone usage of the MediaWiki Yammer Extension.
	*
	* @author Arnoud ten Hoedt
	* @date   July 15, 2010
	*
	* This wrapper allows you to use the mediawiki yammer extension standalone
	* like a widget or include in other files.
	*
	* 1. Start by registering your application with yammer:
	*      https://www.yammer.com/capgemini.com/client_applications/new
	*    or using an existing application found at
	*      https://www.yammer.com/capgemini.com/client_applications/
	*
	* 2. Configuring the consumer key and consumer secret in the Yammer
	*     configuration section below in the $wgYammerConsumerKey and
	*     $wgYammerConsumerSecret variables.
	*
	* 3. Startup the application through your browser and follow the
	*    instructions. Note that some references to mediawiki specific
	*    files such as LocalSettings.php do not apply. Please make
	*    configurational changes in the Yammer Configuration section
	*    instead.
	*/
	
	// Configuration
	$wgYammerCacheDir = dirname(__FILE__). DIRECTORY_SEPARATOR . 'cache';
	
	// Yammer configuration
	$wgYammerConsumerKey = '7ulsqyx4oiCRPiby5wdjoA';
	$wgYammerConsumerSecret = 'QF4yA4DTVYgcHvUoxVm8XT9PoX0G93o5uTEzPecnY'; 
	$wgYammerAccessKey = '';
	$wgYammerAccessSecret = '';

	// ==================================================================
	// No need to change anything below this line
	// ==================================================================
	
	// Validate cache dir
	if(!is_dir($wgYammerCacheDir) && !mkdir($wgYammerCacheDir, 0777)) {
		exit('Please make sure that the cache directory is created and accessible: '.$wgYammerCacheDir);
	}
	
	// Constants
	define('YAMMER_MODE_TAG', 'tag');
	define('YAMMER_MODE_COMPANY', 'company');
	define('YAMMER_MODE_GROUP', 'group');

	// Load the MediaWiki Yammer extension
	require_once dirname(__FILE__). DIRECTORY_SEPARATOR . 'yammer' .  DIRECTORY_SEPARATOR . 'yammerextension.class.php';
	$yammer = YammerExtension::getInstance();
	
	// The MediaWiki Yammer extension will return a bunch of HTML
	$data = '';
	
	// Determine which content we need to retrieve
	$mode = YAMMER_MODE_COMPANY;
	
	if(!empty($_GET['tag'])) {
		$mode = YAMMER_MODE_TAG;
	} else if(!empty($_GET['group'])) {
		$mode = YAMMER_MODE_GROUP;
	}
	
	// Content retrieval
	switch($mode) {
		case YAMMER_MODE_TAG:
			$data = $yammer->fetch(
				sprintf(YammerExtension::YAMMER_URI_MESSAGES_BY_TAG, urlencode(strtolower($_GET['tag'])))
				,
				'Messages tagged with "'.htmlspecialchars($tag).'"'
			);
			break;
			
		case YAMMER_MODE_GROUP:
		
			$group = $_GET['group'];
		
			$groupId = $yammer->findGroupId($group);
		
			$data = $yammer->fetch(
				sprintf(YammerExtension::YAMMER_URI_MESSAGES_BY_GROUP, urlencode($groupId))
				,
				'Messages in the "'.htmlspecialchars($group).'"-group'
				,
				'in this group'
			);
			break;
		case YAMMER_MODE_COMPANY:
			$data = $yammer->fetch(YammerExtension::YAMMER_URI_MESSAGES, 'Recent messages');
			break;
	}
	
	// Just output the data
	echo $data;