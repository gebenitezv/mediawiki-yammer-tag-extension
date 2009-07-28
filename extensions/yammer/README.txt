=============================================
# Yammer Extension for MediaWiki
#
# Use <yammer tag="{yourtag}"> to include a 
# badge holding the latest messages tagged 
# with #{yourtag}
#
# Version: 1.0
# Date: July 2009
# License: The MIT License
# Copyright (c) 2009 Arnoud ten Hoedt, Netherlands
=============================================

The Yammer Extension uses the Yammer OAuth API and requires you to do a one
time OAuth authentication cycle. The extension will not require end users to
authenticate themselves, but uses a single account to do all reads

=============================================
REQUIREMENTS
- This extension is tested with Mediawiki 1.12.0
- This extension requires you to have an application key registered with Yammer
  You can register a new application here:
    https://www.yammer.com/client_applications/new
  Or look up your existing application keys here:
    https://www.yammer.com/client_applications/

  Write down the Consumer Key and Consumer Secret which will be requested in
  the installation sequence
- A writable directory used for caching purposes. This can be an only, or 
  offline directory, and needs to be specified as $wgYammerCacheDir in your
  LocalSettings.php. An absolute path is preferable, but relative paths should
  be resolved by the PHP include path.

=============================================
PACKAGE
The package contains a light weight file, that loads a Yammer Extension class when a
yammer tag is found in a page. This safes mediawiki to parse loads of php code which
might never be used.

The package needs to be deployed in your 'extensions' directory:
 {mediawiki}/extensions/yammer.php
 {mediawiki}/extensions/yammer/yammerextension.class.php
 {mediawiki}/extensions/yammer/yammer.css
 {mediawiki}/extensions/yammer/yammer-logo.png
 {mediawiki}/extensions/yammer/yammer-window.png
 {mediawiki}/extensions/yammer/install.txt

If you choose to not use /extensions/ but another folder, please update
function fnYammerExtensionCSS() at the bottom of yammer.php to reference
to the correct path.

=============================================
INSTALLATION
The Yammer Extension requires a one time OAuth authentication cycle. This
cycle will be run through automatically on the first use of the application.

1. Upload the Yammer files to your server as described in the 'PACKAGE'
   section, and include the extension/yammer.php from your LocalSettings.php:
   include_once 'extensions/yammer.php';

2. Create a new page in your Mediawiki using your browser and create or update one
   of the pages. Make sure to include <yammer tag="sometag" /> or <yammer>sometag</yammer>.
   At this stage the tag you choose does not matter, although a tag you have previously used
   on Yammer gives the nicest result.
  
3. Save the page and note that the a Yammer Badge is included in the page, mentioning you
   need to specify $wgYammerConsumerKey and $wgYammerConsumerSecret in your LocalSettings.php
   (As part of the cycle you need to add more variables to the LocalSettings.php, so you might
   want to keep the file opened in your editor)

4. Add the consumer key and consumer secret you have registered as described in the 'REQUIREMENTs'
   section, and reload the page.

5. After adding the consumer key and secret and reloading the page, you will see a notice that the server
   has retrieved a Request Token, and will ask you to reload the page once again.

6. Click the url which leads you to the Yammer authorization page. After granting access the Yammer window will
   show you a 4 or 5 digit code. Copy this code, and paste it in the form shown in your mediawiki badge and 
   click "Validate".

7. If validation is succesful, the system will tell you to copy $wgYammerAccessKey and $wgYammerAccessToken into
   your LocalSettings.php.
   If valdation failed, you need to reset your session, as the Yammer code matching your Request Token has been
   invalidated.

8. After udpating the LocalSettings.php, you can reload the page, and the system will load and show any available
   messages tagged with the designated tag.

8. You can include Yammer tags in any mediawiki page, using various tags.