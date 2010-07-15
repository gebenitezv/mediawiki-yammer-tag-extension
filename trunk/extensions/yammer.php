<?php
/**
 * Yammer extension for mediawiki
 *
 * @version 1.0
 * @author Arnoud ten Hoedt
 *
 * @todo: Allow for other feeds than the 'Tag' feed only
 * @todo: Show the yammer public feed
 *
 * May 2010: Remove pass-by-reference bug.
 */
$wgExtensionFunctions[] = "wfYammerInit";

function wfYammerInit() {
	GLOBAL $wgParser;
	$wgParser->setHook('yammertag','wfYammer_Tag_Hook');
	$wgParser->setHook('yammergroup', 'wfYammer_Group_Hook');
	$wgParser->setHook('yammer', 'wfYammer_Broker_Hook');
}

function wfYammer_Tag_Hook($input, $argv, &$parser) {
	# Let the yammer class show the login screen and/or
	# load the tag feed
	require_once dirname(__FILE__). DIRECTORY_SEPARATOR . 'yammer' .  DIRECTORY_SEPARATOR . 'yammerextension.class.php';
	
	return YammerExtension::tag($input, $parser);
}

function wfYammer_Group_Hook($input, $argv, &$parser) {
	# Let the yammer class show the login screen and/or
	# load the tag feed
	require_once dirname(__FILE__). DIRECTORY_SEPARATOR . 'yammer' .  DIRECTORY_SEPARATOR . 'yammerextension.class.php';
	
	return YammerExtension::group($input, $parser);
}

function wfYammer_Broker_Hook($input, $argv, &$parser) {
	# Early broking of dedicated functions
	if(!empty($argv['tag'])) {
		return wfYammer_Tag_Hook($argv['tag'], $argv, $parser);
	} elseif (!empty($argv['group'])) {
		return wfYammer_Group_Hook($argv['group'], $argv, $parser);
	}

	# Let the yammer class do some handling otherwise	
	require_once dirname(__FILE__). DIRECTORY_SEPARATOR . 'yammer' .  DIRECTORY_SEPARATOR . 'yammerextension.class.php';
	
	return YammerExtension::broker($input, $argv, $parser);
}

function fnYammerExtensionCSS(&$out, &$sk = null) {
	GLOBAL $wgScriptPath;
	$out->addScript('<link rel="stylesheet" href="'.$wgScriptPath.'/extensions/yammer/yammer.css" type="text/css" />');
	return true;
}

function fnYammerAuthenticationNotCachable($article) {
	return false;
}
