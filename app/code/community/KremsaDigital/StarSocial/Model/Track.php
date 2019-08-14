<?php
require_once(Mage::getBaseDir('lib') . '/KremsaDigital/StarSocial/starsmp.php');

class KremsaDigital_StarSocial_Model_Track {
    private $smpInstance = null;
    private $siteUrl = null;
    private $isModuleActive = null;
    const VIPFAN_KEY = 'starsocial_vipfan_id';

    public function __construct() {
        $this->isModuleActive = Mage::helper('starsocial')->isModuleActive();
        $this->siteUrl = $this->getProtocol() . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"];
    }

    private function isAuthorized() {
        return !($this->smpInstance == null);
    }

    private function getProtocol() {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        return $protocol;
    }

    private function authorize() {
        if (!$this->isModuleActive) {
            return;
        }
        $smpSettings = new StarSMPSettings();
        $smpSettings->loyaltyId = Mage::getStoreConfig('starsocial/conf/loyalty_id');
        $smpSettings->clientId = Mage::getStoreConfig('starsocial/conf/client_id');
        $smpSettings->clientSecret = Mage::getStoreConfig('starsocial/conf/client_secret');

        try {
            $smp = new StarSMP($smpSettings);
            if (Mage::getStoreConfig('starsocial/conf/is_beta') == '1') {
                $smp->setApiUrl('https://api.starsmp.com-beta.com/api/');
            }
            $smp->authorize();
        } catch (Exception $e) {
            throw $e;
        }
        $this->smpInstance = $smp;
        $smpToken = Mage::getSingleton('core/session')->getSmpToken();
        if (!empty($smpToken)) {
            $this->smpInstance->setAccessToken($smpToken);
        }
    }

    public function track($action, $value) {
        if (!$this->isModuleActive) {
            return;
        }

        if (!$this->isAuthorized()) {
            $this->authorize();
        }

        try {
            $vipfanId = Mage::getSingleton('core/session')->getVipfanId();
            if ($vipfanId) {
                $this->smpInstance->setVipfanID($vipfanId);
            }
            // var_dump($this->smpInstance);die();
            $this->smpInstance->track($action, $this->siteUrl, $value); // tracked under vipfan opted in above
        } catch (Exception $e) {
            Mage::log($e->getMessage(), null, 'starsocial.log');
        }
    }

    public function optin($data) {
        if (!$this->isModuleActive) {
            return;
        }
        if (!$this->isAuthorized()) {
            $this->authorize();
        }
        try {
        	$data['update_referrer'] = true;
            $vipfan = $this->smpInstance->optin($data, $this->siteUrl);
            if (Mage::registry($this::VIPFAN_KEY) != $vipfan['id']) {
            	Mage::unregister($this::VIPFAN_KEY);
            	Mage::register($this::VIPFAN_KEY, $vipfan['id']);
            }
            Mage::getSingleton('core/session')->setVipfanId($vipfan['id']);
        } catch (Exception $e) {
            Mage::log($e->getMessage(), null, 'starsocial.log');
        }
    }

    public function logout() {
        if (!$this->isModuleActive) {
            return;
        }
        if (!$this->isAuthorized()) {
            $this->authorize();
        }
        try {
            $this->smpInstance->logout();
        } catch (Exception $e) {
            Mage::log($e->getMessage(), null, 'starsocial.log');
        }
        Mage::getSingleton('core/session')->unsVipfanId();
        Mage::register($this::VIPFAN_KEY, null);
    }
}