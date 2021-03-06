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
class MageHost_Hosting_Helper_Data extends Mage_Core_Helper_Abstract {

    /**
     * Get local hostname of this server
     *
     * @return string - Local hostname
     */
    public function getLocalHostname() {
        if ( function_exists('shell_exec') ) {
            $hostname = shell_exec('hostname -f 2>/dev/null');
            if (empty($hostname)) {
                $hostname = shell_exec('hostname -s 2>/dev/null');
            }
        } else {
            $hostname = gethostname();
        }
        if ( empty($hostname) ) {
            $hostname = reset( $this->getLocalIPs() );
        }
        return trim($hostname);
    }

    /**
     * Get the local IPs of a Linux, FreeBSD or Mac server
     *
     * @return array - Local IPs
     */
    public function getLocalIPs() {
        $result = array();
        if ( function_exists('shell_exec') ) {
            $result = $this->readIPs('ip addr');
            if (empty($result)) {
                $result = $this->readIPs('ifconfig -a');
            }
        }
        if (!empty($_SERVER['SERVER_ADDR']) && !in_array($_SERVER['SERVER_ADDR'],$result)) {
            $result[] = $_SERVER['SERVER_ADDR'];
        }
        return $result;
    }

    /**
     * Execute shell command to receive IPs and parse the output
     *
     * @param $cmd   - can be 'ip addr' or 'ifconfig -a'
     * @return array - IP numbers
     */
    protected function readIPs( $cmd ) {
        $result = array();
        $lines = explode( "\n", trim(shell_exec($cmd.' 2>/dev/null')) );
        foreach( $lines as $line ) {
            $matches = array();
            if ( preg_match('|inet6?\s+(?:addr\:\s*)?([\:\.\w]+)|',$line,$matches) ) {
                $result[$matches[1]] = 1;
            }
        }
        unset( $result['127.0.0.1'] );
        unset( $result['::1'] );
        return array_keys($result);
    }

    /**
     * @param string $message
     */
    public function successMessage( $message ) {
        if ( null === Mage::app()->getRequest()->getControllerName() ) {
            // Shell script
            echo $message . "\n";
        } else {
            Mage::getSingleton( 'adminhtml/session' )->addSuccess( $message );
        }
    }

    /**
     * @param string $message
     */
    public function errorMessage( $message ) {
        if ( null === Mage::app()->getRequest()->getControllerName() ) {
            // Shell script
            printf( "ERROR: %s\n", $message );
        } else {
            Mage::getSingleton( 'adminhtml/session' )->addError( $message );
        }
    }

    /**
     * Function for writing messages to log-file, if debugging is enabled
     *
     * @param string $message
     * @param int $level
     */
    public function log($message, $level = Zend_Log::DEBUG)
    {
        if (Mage::getStoreConfig('magehost_hosting/general/debug_enabled') || $level <= Zend_Log::WARN) {
            Mage::log($message, $level, Mage::getStoreConfig('magehost_hosting/general/log_file'));
        }
    }
}
