<?php

class KremsaDigital_StarSocial_Helper_Data extends Mage_Core_Helper_Abstract
{

	private $moduleActive = null;

	public function isModuleActive() {
		if ($this->moduleActive == null) {
			$act = Mage::getStoreConfig('starsocial/conf/active');
			$lid = Mage::getStoreConfig('starsocial/conf/loyalty_id');
			$cid = Mage::getStoreConfig('starsocial/conf/client_id');
			$cs = Mage::getStoreConfig('starsocial/conf/client_secret');
			$ret = (isset($act) && ($act == '1') && !empty($lid) && !empty($cid) && !empty($cs));			
		}
		$this->moduleActive = $ret;
		return $ret;
	}    

	public function getLibUrl() {
		return '/js/KremsaDigital/StarSocial';
	}
}