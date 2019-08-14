<?php
require_once(Mage::getBaseDir('lib') . '/KremsaDigital/StarSocial/starsmp.php');

class KremsaDigital_StarSocial_Model_Track {
    private $smpInstance = null;
    private $siteUrl = null;
    const VIPFAN_KEY = 'starsocial_vipfan_id';

    private function isAuthorized() {
        return !($this->smpInstance == null);
    }

    private function getProtocol() {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        return $protocol;
    }

    private function authorize() {
        if (Mage::getStoreConfig('starsocial/conf/active') == '0') {
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
        $this->siteUrl = $this->getProtocol() . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"];
    }

    public function track($action, $value) {
        if (Mage::getStoreConfig('starsocial/conf/active') == '0') {
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
            $this->smpInstance->track($action, $this->siteUrl, $value); // tracked under vipfan opted in above
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function optin($data) {
        if (Mage::getStoreConfig('starsocial/conf/active') == '0') {
            return;
        }
        if (!$this->isAuthorized()) {
            $this->authorize();
        }
        try {
            $vipfan = $this->smpInstance->optin($data, $this->siteUrl);
            Mage::register($this::VIPFAN_KEY, $vipfan['id']);
            Mage::getSingleton('core/session')->setVipfanId($vipfan['id']);
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function logout() {
        if (Mage::getStoreConfig('starsocial/conf/active') == '0') {
            return;
        }
        if (!$this->isAuthorized()) {
            $this->authorize();
        }
        try {
            $this->smpInstance->logout();
        } catch (Exception $e) {
            throw $e;
        }
        Mage::getSingleton('core/session')->unsVipfanId();
        Mage::register($this::VIPFAN_KEY, null);
    }
}