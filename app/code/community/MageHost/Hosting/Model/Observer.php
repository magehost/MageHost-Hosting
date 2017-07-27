<?php
/**
 * MageHost_Hosting
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this Module to
 * newer versions in the future.
 *
 * @category     MageHost
 * @package      MageHost_Hosting
 * @copyright    Copyright (c) 2016 MageHost BVBA (http://www.magentohosting.pro)
 */

class MageHost_Hosting_Model_Observer
{
    const CONFIG_SECTION  = 'magehost_hosting';
    /** @var bool|string */
    protected $miniDir = false;

    public function __construct() {
        $this->miniDir = Mage::getBaseDir('base') . DIRECTORY_SEPARATOR . 'mini';
        if ( !is_dir($this->miniDir) ) {
            $this->miniDir = false;
        }
    }

    /**
     * Event listener to clean minified JS and CSS files in 'mini' directory.
     * This is only necessary for vhosts running on Nginx with automagic minify.
     *
     * @param Varien_Event_Observer $observer
     */
    public function cleanMediaCacheAfter( /** @noinspection PhpUnusedParameterInspection */ $observer ) {
        $this->cleanMiniDir();
    }

    /**
     * Event listener for cache backend cleans.
     * The event 'magehost_clean_backend_cache_after' is only triggered if cache backend used in local.xml:
     *    'MageHost_Cm_Cache_Backend_File'
     * or 'MageHost_Cm_Cache_Backend_Redis'
     *
     * @param Varien_Event_Observer $observer
     */
    public function magehostCleanBackendCacheAfter( $observer ) {
        // Check if we need to flush the 'mini' dir containing minified JavaScript and CSS
        if ($this->miniDir) {
            $prefix = Mage::app()->getCacheInstance()->getFrontend()->getOption('cache_id_prefix');
            if ( empty($observer->getTransport()->getTags()) ) {
                // no tags = clear everything
                $this->cleanMiniDir();
            } else {
                $cleanOnTags = array( strtoupper(Mage_Core_Block_Abstract::CACHE_GROUP),
                                      strtoupper(Mage_Core_Model_Layout_Update::LAYOUT_GENERAL_CACHE_TAG) );
                $prefixMatch = '/^' . preg_quote($prefix,'/') . '/i';
                foreach ($observer->getTransport()->getTags() as $tag) {
                    $tag = preg_replace( $prefixMatch, '', $tag );
                    if ( in_array($tag,$cleanOnTags) ) {
                        $this->cleanMiniDir();
                        break;
                    }
                }
            }
        }
        // Pass flush to other nodes in cluster if enabled
        if ( Mage::getStoreConfigFlag(self::CONFIG_SECTION.'/cluster/enable_pass_cache_clean') &&
            ! Mage::registry('MageHost_cacheClean_via_Api') ) {
            $localHostname = Mage::helper('magehost_hosting')->getLocalHostname();
            /** @noinspection PhpUndefinedMethodInspection */
            $transport = $observer->getTransport();
            $tags = $observer->getTransport()->getTags();
            $tags = array_unique($tags); // Remove duplicates
            /** @noinspection PhpUndefinedMethodInspection */
            Mage::helper('magehost_hosting')->log( sprintf( "Cache Clean Event. Mode '%s', tags '%s'.",
                $transport->getMode(), implode(',',$tags) ) );
            $nodes = Mage::getStoreConfig(self::CONFIG_SECTION.'/cluster/http_nodes');
            $url = '';
            // Protection against occasional crash while trying to get API url during n98-magerun usage
            if (  Mage::app()->getFrontController()->getRouter('admin') ) {
                $url = Mage::getUrl('api');
            }
            // Fix wrong URL generated via n98 or other CLI tools
            if ( false === strpos($url,'/index.php/api') ) {
                $url = preg_replace('#/[\w\-]+\.(php|phar)/api#', '/api', $url);
            }
            $url = str_replace('n98-magerun/', '', $url);
            if ( empty($url) ) {
                $url = Mage::getStoreConfig('web/unsecure/base_url') . 'api/';
            }
            $urlData = parse_url($url);
            $nodeList = explode("\n",$nodes);
            $localIPs = Mage::helper('magehost_hosting')->getLocalIPs();
            foreach ( $nodeList as $node ) {
                $node = trim($node);
                if ( empty($node) ) {
                    continue;
                }
                $nodeSplit = explode(':',$node);
                $nodeHost = $nodeSplit[0];
                $nodePort = (empty($nodeSplit[1])) ? 80 : intval($nodeSplit[1]);
                if ( preg_match('/^\d+\.\d+\.\d+\.\d+$/',$nodeHost) ) {
                    $nodeIP = $nodeHost;
                } else {
                    $nodeIP =  gethostbyname( $nodeHost );
                }
                if ( $nodeHost == $localHostname || in_array($nodeIP,$localIPs) ) {
                    continue;
                }
                $headers = array();
                $hostHeader = Mage::getStoreConfig(self::CONFIG_SECTION.'/cluster/host_header');
                if ( empty($hostHeader) ) {
                    $hostHeader = $urlData['host'];
                }
                $nodeSchemeConfig = Mage::getStoreConfig(self::CONFIG_SECTION.'/cluster/http_protocol');
                $headers[] = 'Host: ' . $hostHeader;
                if ( 'auto' == $nodeSchemeConfig ) {
                    $nodeScheme = $urlData['scheme'];
                    if ( 443 == $nodePort && 'https' != $nodeScheme ) {
                        // strange situation
                        $nodeScheme = 'https';
                        $headers[] = 'X-Forwarded-Proto: http';
                    }
                    elseif ( 80 == $nodePort && 'http' != $nodeScheme ) {
                        $nodeScheme = 'http';
                        $headers[] = 'X-Forwarded-Proto: https';
                        $headers[] = 'Ssl-Offloaded: 1';
                    }
                } else {
                    $nodeScheme = $nodeSchemeConfig;
                    if ( 'http_ssloffloaded' == $nodeScheme ) {
                        $nodeScheme = 'http';
                        $headers[] = 'X-Forwarded-Proto: https';
                        $headers[] = 'Ssl-Offloaded: 1';
                    }
                }
                $nodeLocation = $nodeScheme.'://'.$node.$urlData['path'];
                $apiUser = Mage::getStoreConfig(self::CONFIG_SECTION.'/cluster/api_user');
                $apiKey  = Mage::getStoreConfig(self::CONFIG_SECTION.'/cluster/api_key');
                $options = array( 'uri' => 'urn:Magento',
                                  'location' => $nodeLocation,
                                  'curl_headers' => $headers );
                Mage::helper('magehost_hosting')->log( sprintf("%s::%s: Passing flush to '%s' with headers '%s'",
                                   __CLASS__, __FUNCTION__, $nodeLocation, implode(' + ',$headers)), Zend_Log::INFO );
                try {
                    $client = new MageHost_Hosting_Model_SoapClientCurl(null,$options);
                    /** @noinspection PhpUndefinedMethodInspection */
                    $sessionId =  $client->login( $apiUser, $apiKey );
                    /** @noinspection PhpUndefinedMethodInspection */
                    $client->call( $sessionId, 'magehost_hosting.cacheClean',
                                   array( $transport->getMode(), $tags, $localHostname) );
                } catch ( Exception $e ) {
                    Mage::helper('magehost_hosting')->log( sprintf("%s::%s: ERROR during SOAP request: %s", __CLASS__, __FUNCTION__, $e->getMessage()) );
                }
            }
        }
    }

    /**
     * If you have a big site, Google crawler hits the site a lot of times in a short time period.
     * This causes lock problems with Cm_RedisSession, because all crawler hits are requesting the same session lock.
     * Cm_RedisSession provides the define CM_REDISSESSION_LOCKING_ENABLED to overrule if locking should be enabled.
     *
     * @param Varien_Event_Observer $observer
     */
    function controllerFrontInitBefore( /** @noinspection PhpUnusedParameterInspection */ Varien_Event_Observer $observer ) {
        if ( Mage::getStoreConfigFlag(self::CONFIG_SECTION.'/improvements/enable_cm_redissession_bot_locking_fix') ) {
            if ( Mage::helper('core')->isModuleEnabled('Cm_RedisSession')
                 && defined('Cm_RedisSession_Model_Session::BOT_REGEX') ) {
                $userAgent = empty($_SERVER['HTTP_USER_AGENT']) ? false : $_SERVER['HTTP_USER_AGENT'];
                $isBot = ( !$userAgent || preg_match(Cm_RedisSession_Model_Session::BOT_REGEX, $userAgent) );
                if ($isBot && !defined('CM_REDISSESSION_LOCKING_ENABLED')) {
                    define('CM_REDISSESSION_LOCKING_ENABLED', false);
                }
            }
        }
    }

    protected function cleanMiniDir() {
        if ( $this->miniDir ) {
            $success = $this->clean_dir_content( $this->miniDir );
            /** @var Mage_Adminhtml_Model_Session $adminSession */
            if ( $success ) {
                Mage::helper('magehost_hosting')->successMessage( sprintf("Directory '%s' has been cleaned.",$this->miniDir) );
            } else {
                Mage::helper('magehost_hosting')->errorMessage( sprintf("Error cleaning directory '%s'.",$this->miniDir) );
            }
        }
    }

    /**
     * Does not delete the dir itself, only its contents, recursive.
     *
     * @param string $dir
     * @return bool
     * @throws Exception
     */
    protected function clean_dir_content( $dir )
    {
        if (!is_dir( $dir )) {
            return false;
        }
        $result = true;
        foreach (scandir( $dir ) as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir( $path )) {
                $this->clean_dir_content( $path );
                $result = $result && rmdir( $path );
            } else {
                $result = $result && unlink( $path );
            }
        }
        return $result;
    }

}
