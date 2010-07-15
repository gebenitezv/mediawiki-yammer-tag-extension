<?php
if(empty($wgYammerAccessKey) || empty($wgYammerAccessSecret)) {
	$wgHooks['IsFileCacheable'][] = 'fnYammerAuthenticationNotCachable';
	@session_start();
}

//@Todo: Use mediawiki session system instead
/**
* PoC Yammer extension for mediawiki.
*
* It allows for read-only request on a Yammer network
* Accessing discussions by Tag, User, Group or date
*
* @author Arnoud ten Hoedt (webmaster@roonaan.nl)
* @version 0.9
*
*/

class YammerExtension {
	/**
	 * @const string Url for the Yammer API's to retrieve request token
	 */
	const YAMMER_URI_REQUESTTOKEN = 'https://www.yammer.com/oauth/request_token';
	
	/**
	 * @const string Url for the Yammer API's page to send visitors to, to have then authorize API usage
	 */
	const YAMMER_URI_AUTHORIZE    = 'https://www.yammer.com/oauth/authorize';
	
	/**
	 * @const string Url for the Yammer API's to exchange the request token with the access token
	 */
	const YAMMER_URI_ACCESSTOKEN  = 'https://www.yammer.com/oauth/access_token';
	
	/**
	 * @const string Url template to retrieve messages holding a certain tag
	 */
	const YAMMER_URI_MESSAGES_BY_TAG = 'https://www.yammer.com/api/v1/messages/tagged_with/%s.xml?threaded=true';
	
	/**
	 * @const string Url template to retrieve messages in a specific group
	 */
	const YAMMER_URI_MESSAGES_BY_GROUP = 'https://www.yammer.com/api/v1/messages/in_group/%s.xml?threaded=true';
	
	/**
	 * @const string Url template to retrieve all messages
	 */
	const YAMMER_URI_MESSAGES = 'https://www.yammer.com/api/v1/messages';

	/**
	 * @const string Url template to retrieve messages in a specific group
	 */
	const YAMMER_URI_GROUPS_BY_LETTER = 'https://www.yammer.com/api/v1/groups.xml?letter=%s&page=%d';
	
	/**
	 * The Yammer Extension requires the default php session to maintain its state.
	 * To prevent intersection with other extensions and mediawiki itself, all
	 * YammerExtension related variables are nested in $_SESSION[SESSION_NAMESPACE]
	 */
  const SESSION_NAMESPACE = 'NS:YammerExtension';
	
	/**
	 * @var YammerExtension Singleton implementation
	 */
	private static $_instance = null;
	
	/**
	 * @var boolean Toggle caching
	 */
	private $_cacheEnabled = true;
	
	/**
	 * Generic handler that tries to do a best guess at which
	 * kind of Yammer content the mediawiki users was trying
	 * to reach
	 */
	public static function broker($input, $argv, &$parser) {
		if(!empty($input) && count($argv) == 0) {
			return self::tag($input, $parser);
		}
		return self::help();
	}
	
	/**
	 * Returns a list of mediawiki tags end users can use,
	 * accompanied with a description of their functionality
	 * and expected output
	 */
	public static function help() {
		return self::getInstance()->createResponse(
			'<h2>How to use the Yammer extension</h2>'
			.'You can use the Yammer functionality in several ways:'
			.'<ul>'
			.'<li>Show messages with a specific tag #YourTag:'
			.'   <ul>'
			.'     <li><code>&lt;yammer&gt;<b><i>YourTag</i></b>&lt;/yammer&gt;</code></li>'
			.'     <li><code>&lt;yammertag&gt;<b><i>YourTag</i></b>&lt;/yammertag&gt;</code></li>'
			.'     <li><code>&lt;yammer tag="<b><i>YourTag</i></b>" /&gt;</code></li>'
			.'   </ul>'
			.'</li>'
			.'<li>Show message from a specific group:'
			.'  <ul>'
			.'    <li><code>&lt;yammergroup&gt;<b><i>YourGroup</i></b>&lt;/yammergroup&gt;</code>'
			.'    <li><code>&lt;yammer group="<b><i>YourGroup</i></b>" /&gt;</code>'
			.'  </ul>'
			.'</li>'
			.'</ul>'
			.'In later versions you might be able to use alternative construct to get other types of content from Yammer'
		);
	}
	
	/**
	 * Returns the most recent messages in the
	 * Yammer network
	 *
	 * @param String $tag
	 */
	public static function all(&$parser) {
		$parser->disableCache();
		
		return self::getInstance()->fetch(
			self::YAMMER_URI_MESSAGES
			,
			'Recent messages'
		);
	}	
	
	/**
	 * Returns the 10 latest uses of a certain #tag, in the
	 * Yammer network
	 *
	 * @param String $tag
	 */
	public static function tag($tag, &$parser) {
		$parser->disableCache();
		
		return self::getInstance()->fetch(
			sprintf(self::YAMMER_URI_MESSAGES_BY_TAG, urlencode(strtolower($tag)))
			,
			'Messages tagged with "'.htmlspecialchars($tag).'"'
		);
	}
	
	/**
	 * Returns the 10 latest uses of a certain #tag, in the
	 * Yammer network
	 *
	 * @param String $tag
	 */
	public static function group($group, &$parser) {
		//return 'Group';
		$parser->disableCache();

		$inst = self::getInstance();
		
		$groupId = $inst->findGroupId($group);

		if(!is_numeric($groupId)) {
			return $groupId;
		}
		
		return $inst->fetch(
			sprintf(self::YAMMER_URI_MESSAGES_BY_GROUP, urlencode($groupId))
			,
			'Messages in the "'.htmlspecialchars($group).'"-group'
			,
			'in this group'
		);
	}
	
	/**
	 * Generic constructor with singleton exception
	 * @throws Exception when an instance of this class was already loaded
	 */
	public function __construct() {
		if(is_object(self::$_instance)) {
			throw new Exception('Please use '.__CLASS__.'::getInstance()');
		}
		//echo '<pre>Session: '.var_export($this->getNamespace(), true).'</pre>';
		GLOBAL $wgHooks;
		$wgHooks['BeforePageDisplay'][] = 'fnYammerExtensionCSS';
	}
	
	/**
	 * Get the singleton instance of the YammerExtension class
	 * @return YammerExtension
	 */
	public static function &getInstance() {
		if(!is_object(self::$_instance)) {
			$inst = new self();
			self::$_instance = $inst;
		}
		return self::$_instance;
	}

	/**
	 * Try and perform a signed request to one of the Yammer API
	 * urls.
	 * Note: When OAuth authentication has not yet been completed,
	 * the request will enter the authentication cycle.
	 */
	public function findGroupId($group) {
		
		$group = strtolower(preg_replace('/[^\w\-]/','', $group));
		
		GLOBAL $wgYammerCacheDir;
		
		# Authentication tests
		if(!$this->isOAuthAuthenticated()) {
			return $this->performAuth();
		}
		if(empty($wgYammerCacheDir)) {
			return $this->createErrorResponse('Please specify <code>$wgYammerCacheDir</code> in your LocalSettings.php. Then <a href="'.$_SERVER['REQUEST_URI'].'">reload the page</a>.');
		}
		if(!is_dir($wgYammerCacheDir)) {
			return $this->createErrorResponse('Please make sure that <code>$wgYammerCacheDir</code> as defined in your LocalSettings.php exists and is a directory. Then <a href="'.$_SERVER['REQUEST_URI'].'">reload the page</a>.');
		}
		
		# Try and see if the id is available in cache
		$file = $wgYammerCacheDir . DIRECTORY_SEPARATOR . 'group-'.strtolower($group). '.txt';
		if(is_file($file)) {
			return file_get_contents($file);
		}
		
		# Cache miss, we are going to process the groups list until the group is found
		
		$letter = strtolower(substr($group, 0, 1));
		$page = 0;
		
		while(true) {
			$url = sprintf(self::YAMMER_URI_GROUPS_BY_LETTER, $letter, $page);
			$body = $this->signedRequest($url);
			
			# Make sure there are groups in the response
			if(false === stripos($body, '<id>')) {
				break;
			}
			# Try and parse the groups. 
			if(!preg_match_all('#<response>(.*?)</response>#ims', $body, $m)) {
				break;
			}
			
			# Process each group
			foreach($m[1] as $responseBody) {
				# Fetch group name and group id
				$name = preg_match('#<name>(.*?)</name>#i', $responseBody, $m) ? $m[1] : '';
				$id   = preg_match('#<id>(.*?)</id>#i', $responseBody, $m) ? $m[1] : '';
				
				# Skip this response if either name or id is empty
				if(empty($name) || empty($id)) {
					continue;
				}
				
				# Create a cache file with the group id
				$file = $wgYammerCacheDir . DIRECTORY_SEPARATOR . 'group-'.strtolower($name).'.txt';
				if(!is_file($file)) {
					$f = fopen($file, 'w');
					fwrite($f, $id);
					fclose($f);
				}
				
				# We found the correct group
				if($name == strtolower($group)) {
					return $id;
				}
			}
			
			//exit($body);
			
			# Increase page number
			$page++;
		}
		
		return $this->createErrorResponse('Group with name "'.$group.'" was not found');
	}	
		
	/**
	 * Try and perform a signed request to one of the Yammer API
	 * urls.
	 * Note: When OAuth authentication has not yet been completed,
	 * the request will enter the authentication cycle.
	 */
	public function fetch($url, $label='', $type='with this tag') {
		GLOBAL $wgYammerCacheDir;
		
		if(!$this->isOAuthAuthenticated()) {
			return $this->performAuth();
		}
		if(empty($wgYammerCacheDir)) {
			return $this->createErrorResponse('Please specify <code>$wgYammerCacheDir</code> in your LocalSettings.php. Then <a href="'.$_SERVER['REQUEST_URI'].'">reload the page</a>.');
		}
		if(!is_dir($wgYammerCacheDir)) {
			return $this->createErrorResponse('Please make sure that <code>$wgYammerCacheDir</code> as defined in your LocalSettings.php exists and is a directory. Then <a href="'.$_SERVER['REQUEST_URI'].'">reload the page</a>.');
		}
		
		$cacheFile = $wgYammerCacheDir . DIRECTORY_SEPARATOR . md5($url) . '.txt';
		
		if(!$this->_cacheEnabled || !is_file($cacheFile) || filemtime($cacheFile) < @strtotime('-30 minutes')) {
			# Cache miss or timeout. First start of by getting the latest data
			$body = $this->signedRequest($url);
			
			if(substr($body, 0, 5) !== ('<'.'?xml')) {
				$body = '<div class="yammer-error-body">No threads found '.$type.'</div>';
			} else {
	
				# Prepare temporary data arrays			
				$messages = array();
				$threads = array();
				$users = array();
				
				$stream = simplexml_load_string($body);
				
				foreach($stream->references->reference as $ref) {
					if($ref->type == 'thread') {
						$threads[(string) $ref->id] = array(
							'url' => (string) $ref->url
							, 'updates' => intval($ref->stats->updates)
							, 'latest-reply' => (string) $ref->stats->{'latest-reply-at'}
						);
					} else if($ref->type == 'user') {
						$users[(string) $ref->id] = array(
							'name' => (string) $ref->name,
							'icon' => (string) $ref->{'mugshot-url'},
							'url'  => (string) $ref->{'web-url'},
							'role' => (string) $ref->{'job-title'}
						);
					}
				}
				
				$body = '<div class="yammer-message-list">';
				foreach($stream->messages->message as $msg) {
					$user = $users[(string) $msg->{'sender-id'}];
					$body .= '<div class="yammer-message">';
					$body .= '<div class="yammer-icon"><img src="'.$user['icon'].'" /></div>';
					$body .= '<div class="yammer-message-content">';
					$body .= '<div class="yammer-message-head">';
					$body .= '<a class="yammer-message-sender" href="'.$user['url'].'">'.htmlspecialchars($user['name']).'</a> @ '.((string) $msg->{'created-at'});
					$body .= '</div>';
					$body .= '<div class="yammer-message-body">';
					$body .= htmlspecialchars($msg->body->plain);
					$body .= '</div>';
					$body .= '<div style="clear:both">#<a href="'.$msg->{'web-url'}.'" target="_blank" onclick="window.open(this.href);return false;">'.$msg->id.'</a></div>';
					$body .= '</div>';
					$body .= '</div>';
				}
				$body .= '</div>';
			}
			$body = (empty($label) ? '' : '<h2>'.$label.'</h2>') . $body . '<div class="yammer-last-update">Last update: '.@date('d/m/Y H:i:s').'</div>';

			# Save to cache
			$f = fopen($cacheFile, 'w');
			if(is_resource($f)) {
				fwrite($f, $body);
				fclose($f);
			}
						
		} else {
			# Read from cache
			$body = file_get_contents($cacheFile);
		}
		
//		$body .= "\n".var_export($threads, true)."\n";
		//$body .= "\n".var_export($users, true)."\n";
		
		return $this->createResponse($body);
	}
	
	/**
	 * Load and validate the session namespace
	 * @see YammerExtension::SESSION_NAMESPACE
	 * @return array
	 */
	private function &getNamespace() {
		$ns = self::SESSION_NAMESPACE;
		if(!isset($_SESSION[$ns])) {
			$_SESSION[$ns] = array();
		}
		return $_SESSION[$ns];
	}
	
	/**
	 * Read a specific entry from the session namespace.
	 * @param string $var Entry Key to be retrieved
	 * @param mixed  $default Default Key value
	 * @param boolean $writeDefaultToSession Record the default value into the session when no value was found
	 * @return mixed
	 */
	private function namespaceGet($var, $default ='', $writeDefaultToSession = false) {
		$ns = $this->getNamespace();
		if(isset($_SESSION[self::SESSION_NAMESPACE][$var])) {
			return $_SESSION[self::SESSION_NAMESPACE][$var];
		}
		if($writeDefaultToSession) {
			$this->namespaceSet($var, $default);
		}
		return $default;
	}
	
	/**
	 * Write an entry into the session namespace
	 * @param string $var Entry Key to be written to
	 * @param mixed $value Data to be stored
	 */
	private function namespaceSet($var, $value) {
		$_SESSION[self::SESSION_NAMESPACE][$var] = $value;
	}
	
	/**
	 * Generic handler for the OAuth authorisation cycle
	 */
	private function performAuth() {
		
		GLOBAL $wgYammerConsumerKey, $wgYammerConsumerSecret, $wgEnableParserCache;
		
		$wgEnableParserCache = false;
		
		$status = $this->namespaceGet('status','init',true);
		
		# Consumer key is required
		if(empty($wgYammerConsumerKey) || empty($wgYammerConsumerSecret)) {
			return $this->createErrorResponse('Please configure <code>$wgYammerConsumerKey</code> and <code>$wgYammerConsumerSecret</code> in your LocalSettings.php. Then <a href="'.$_SERVER['REQUEST_URI'].'">reload the page</a>.');
		}
		
		# If the accesstoken/accesstokensecret are available, display those
		if(($atk =$this->namespaceGet('accesstoken',false)) !== false && ($ats = $this->namespaceGet('accesstokensecret',false)) !== false) {
			return $this->createErrorResponse('Please configure <code>$wgYammerAccessKey="'.$atk.'";</code> and <code>$wgYammerAccessSecret="'.$ats.'";</code> in your LocalSettings.php. Then <a href="'.$_SERVER['REQUEST_URI'].'">reload the page</a>.');
		}
		
		# If the request token/request token secret are not available, request one
		$rtk = $this->namespaceGet('requesttoken', false);
		$rts = $this->namespaceGet('requesttokensecret', false);
		if(false === ($rtk) && false === ($rts)) {
			$resp = $this->oauth_get(self::YAMMER_URI_REQUESTTOKEN, $wgYammerConsumerKey, $wgYammerConsumerSecret,'','',false,'PLAINTEXT');//$this->http(self::YAMMER_URI_REQUESTTOKEN, 'get');
			parse_str($resp[1], $arr);
			
			if(!empty($arr['oauth_token']) && !empty($arr['oauth_token_secret'])) {
				$this->namespaceSet('requesttoken', $arr['oauth_token']);
				$this->namespaceSet('requesttokensecret', $arr['oauth_token_secret']);
				return $this->createErrorResponse('A new request token was retrieved from the Yammer server. Please <a href="'.$_SERVER['REQUEST_URI'].'">reload the page</a> for further instructions');
			}
			
			return $this->createErrorResponse('<pre>Invalid response: '.htmlspecialchars($resp[1]).'</pre>');
		} else if(!empty($_POST['oauth_verifier'])) {
			$verifier = $_POST['oauth_verifier'];
			$resp = $this->oauth_get(self::YAMMER_URI_ACCESSTOKEN.'?callback_token='.urlencode($verifier), $wgYammerConsumerKey, $wgYammerConsumerSecret, $rtk, $rts, false, 'PLAINTEXT', 'POST');
			parse_str($resp[1], $arr);
			if(!empty($arr['oauth_token']) && !empty($arr['oauth_token_secret'])) {
				$this->namespaceSet('accesstoken', $arr['oauth_token']);
				$this->namespaceSet('accesstokensecret', $arr['oauth_token_secret']);
				return $this->createErrorResponse('Request token was exchanged for an access token. Please <a href="'.$_SERVER['REQUEST_URI'].'">reload the page</a>.');
			}
			
			return $this->createErrorResponse('Failed to verify using code '.$verifier. '<br />The Yammer server sent the following response:<code>'.htmlspecialchars($resp[1]).'</code>');
		} else {
			return $this->createVerifyFormResponse($rtk);//'rtk='.var_export($rtk, true).', rts='.var_export($rts, true));
		}
		
		# If the request token/request token secret are available
		# a) If the $_POST[verifier] is available, try and exchange the request token with an access token
		# b) Else show the authorisation url + the form where the member can input the Yammer oauth token
		if($rtk = $this->namespaceGet('requesttoken') && $rts = $this->namespaceGet('requesttokensecret', false)) {
		}
		
		return $this->createErrorResponse('Unexpected error in '.__CLASS__.'::'.__FUNCTION__);
	}
	
	private function createVerifyFormResponse($token) {
		ob_start();
		
		$url = self::YAMMER_URI_AUTHORIZE.'?oauth_token='.urlencode($token);
		
		echo '<div class="yammer-verify-form">';
		echo '<p>Please visit the below URL and allow mediawiki to read from Yammer on our behalf: <br /><br /><a target="_blank" onclick="window.open(this.href);return false;" href="'.$url.'">'.$url.'</a></p>';
		echo '<p>When done, copy the code and paste it down here:</p>';
		echo '<form method="post">';
			echo 'Authorization Code: <input type="text" name="oauth_verifier" /> <input type="submit" value="validate" />';
		echo '</form>';
		echo '</div>';
		
		return $this->createResponse(ob_get_clean());
	}
	
	/**
	 * Perform a signed request, and return the response as $xml
	 * @param string $url Location of the Yammer API call
	 */
	private function signedRequest($url) {
		GLOBAL $wgYammerConsumerKey, $wgYammerConsumerSecret, $wgYammerAccessKey, $wgYammerAccessSecret;
		$resp = $this->oauth_get($url, $wgYammerConsumerKey, $wgYammerConsumerSecret, $wgYammerAccessKey, $wgYammerAccessSecret, false, 'PLAINTEXT', 'GET');
		return $resp[1];
	}
	
	private function toQueryString($array) {
		$parts = array();
		foreach($array as $key => $value) {
			$parts[] = urlencode($key).'='.urlencode($value);
		}
		return implode('&', $parts);
	}
	
	private function http($url, $method = 'get', $getParams=array(), $postParams = array(), $realm=null) {
		$params = $this->assembleParams();
		$query  = $this->toQueryString($params);
		if(!empty($getParams)) {
			$params = array_merge($getParams, $params);
			$query  = $this->toQueryString($params);
		}
		if(!empty($postParams)) {
			$params = array_merge($getParams, $params);
			$query  = $this->toQueryString($params);
		}
		$httpHeaders = array();
		$httpBody = '';
		
		$headerValue = array();
    $headerValue[] = 'OAuth realm="' . $realm . '"';
    foreach ($params as $key => $value) {
       if ($excludeCustomParams=false) {
            if (!preg_match("/^oauth_/", $key)) {
                continue;
            }
        }
        $headerValue[] = urlEncode($key) . '="' . urlEncode($value) . '"';
    }		
		
		$httpHeaders[] = 'Authorization: '.implode(',', $headerValue);
		if(strtolower($method) == 'get') {
			if(!empty($query)) {
				$url .= ((false === strpos($url, '?')) ? '?' : '&') . $query;
			}
		} else {
			//post not yet supported
		}
		
		return $url;
	}
	
	/**
	 * Test if the OAuth authorisation cycle has been completed
	 * @return boolean
	 */
	private function isOAuthAuthenticated() {
		GLOBAL $wgYammerAccessKey, $wgYammerAccessSecret;
		return !empty($wgYammerAccessKey) && !empty($wgYammerAccessSecret);
	}
	
	/**
	 * Wrap a response into an error layout
	 * @param string $body Response XHtml Body
	 * @return string XHtml response
	 */
	private function createErrorResponse($body) {
		return $this->createResponse('<div class="yammer-error-body"><h2>Error:</h2>'.$body.'</div>');
	}
	
	/**
	 * Wrap a response into a Yammer layout
	 * @param string $body Response XHtml Body
	 * @return String XHtml respons
	 */
	private function createResponse($body) {
			ob_start();
			echo '<div class="yammer-window"><div class="yammer-header"><h1><span>Yammer</span></h1></div><div>'.$body.'</div><div class="yammer-footer"><a href="http://www.yammer.com">Yammer</a></div></div>';
			/*echo '<div>';
			print_r($_SESSION);
			echo '</div>';*/
			return ob_get_clean();
	}
	
    /*
    * Custom OAuth function 
    * @author Arnoud ten Hoedt
    */
    function oauth_get($url, $consumerKey, $consumerSecret, $accessKey, $accessSecret, $tokenInHeader = false, $method='HMAC-SHA1', $requestMethod='GET') {
			# Parse the url to be used in fsockopen
			$parts = parse_url($url);
			
			# Get and validate the ports
			$port = $parts['scheme'] == 'http' ? '80' : '443';
			/*if(!$port) {
				trigger_error('Only HTTP is supported at this stage');
				return false;
			}*/
			
			# Prepare construction of http header, and query string
			$query = array_filter(explode('&', @$parts['query']));
			$oauthParts[] = 'OAuth_Realm=""';
			
			$nonce = md5(time());
			$time  = time();
			//$nonce = 'kllo9940pd9333jh';
			//$time  = '1191242096';//time();
			
			# Start OAuth
			if($tokenInHeader) {
				$oauthParts[] = 'oauth_consumer_key="'.urlencode($consumerKey).'"';
				$oauthParts[] = 'oauth_token="'.urlencode($accessKey).'"';
				$oauthParts[] = 'oauth_nonce="'.urlencode($nonce).'"';
				$oauthParts[] = 'oauth_timestamp="'.urlencode($time).'"';
				$oauthParts[] = 'oauth_signature_method="'.urlencode($method).'"';
				$oauthParts[] = 'oauth_version="'.urlencode('1.0').'"';
			} else {
				array_unshift($query, 'oauth_version='.urlencode('1.0'));
				array_unshift($query, 'oauth_token='.urlencode($accessKey));
				array_unshift($query, 'oauth_timestamp='.urlencode($time));
				array_unshift($query, 'oauth_signature_method='.urlencode($method));
				array_unshift($query, 'oauth_nonce='.urlencode($nonce));
				array_unshift($query, 'oauth_consumer_key='.urlencode($consumerKey));
			}
			
			# Add the signature into the HTTP HEader
			switch($method) {
				case 'HMAC-SHA1':
					$signatureParts = array($requestMethod);
					$signatureParts[] = urlencode($parts['scheme'].'://'.$parts['host'].$parts['path']);
					$signatureParts[] = urlencode(implode('&', $query));
					$signatureBase = implode('&', $signatureParts);
					$signatureKey  = urlencode($consumerSecret).(empty($accessSecret) ? '' : '&'.urlencode($accessSecret));
					echo '<code>signature base string = '.htmlspecialchars($signatureBase).'</code><br />';
					$oauthParts[] = 'oauth_signature="'.base64_encode(hash_hmac("sha1", $signatureBase, $signatureKey, true)).'"';
					$query[] = 'oauth_signature="'.base64_encode(hash_hmac("sha1", $signatureBase, $signatureKey, true)).'"';
					break;
				case 'PLAINTEXT':
				default:
					$oauthParts[] = 'oauth_signature="'.urlencode($consumerSecret).'&'.urlencode($accessSecret).'"';
					$query[] = 'oauth_signature='.urlencode($consumerSecret.'&'.$accessSecret);
					break;
			}
			
			# End OAuth
			
			# Prepare for the http request
			# Construct the quest string
			$query = implode('&', $query); 
			# Construct the Authorization header
			$oauth = implode(",", $oauthParts);
			
			# Get the hostname and request URL 
			$host = $_SERVER['SERVER_NAME'];
			$uri  = $parts['path'];
			
			# Compose the http request
				$contentLength = 0;
		    $reqheader = (empty($query) ? "$requestMethod $uri" : "$requestMethod $uri?$query") . " HTTP/1.1\r\n".
		     "Host: $host\n". "User-Agent: OAuth\r\n".
		     "Authorization: $oauth\r\n".
		     //"Content-Type: application/x-www-form-urlencoded\r\n".
		     "Accept: */*\r\n".
				 "Accept-Language: en-us\r\n".
		     "Connection: close\r\n".
		     "Content-Length: $contentLength\r\n\r\n".
		     "\r\n"; 
			
		    # Debug
		    //echo '<pre>'.htmlspecialchars('OPEN '.$parts['host'].':'.$port."\n".$reqheader).'</pre>';
			
		    # Prepare fsockopen
			$host = $parts['host'];
		
			# Open connection
			$socket = fsockopen($host, 80, $errno, $errstr);
			
			# Validate connect
			if (!$socket) {
			   $result["errno"] = $errno;
			   $result["errstr"] = $errstr;
			   return $result;
			}
			
			# Send request
			fputs($socket, $reqheader);
			
			# Fetch server response
			$result = '';
			while (!feof($socket)) {
			   $result .= fgets($socket, 4096);
			}
			fclose($socket);
			
			# Split http header from body
			return preg_split("/(\r\n?){2}/", $result, 2);
		}
}