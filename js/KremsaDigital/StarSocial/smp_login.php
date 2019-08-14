<?php
if (!defined('MAGENTO_ROOT'))
    define('MAGENTO_ROOT', getcwd());

$compilerConfig = MAGENTO_ROOT . '/../../../includes/config.php';
if (file_exists($compilerConfig)) {
    include $compilerConfig;
}

$mageFilename = MAGENTO_ROOT . '/../../../app/Mage.php';

require_once $mageFilename;
umask(0);
Mage::app('default');

require_once(Mage::getBaseDir('lib') . '/KremsaDigital/StarSocial/starsmp.php');

define("SMP_CLIENT_ID", Mage::getStoreConfig('starsocial/conf/client_id'));
define("SMP_CLIENT_SECRET", Mage::getStoreConfig('starsocial/conf/client_secret'));

Mage::getSingleton('core/session', array('name' => 'frontend'));
$core_session = Mage::getSingleton('core/session', array('name' => 'frontend'));
$session = Mage::getSingleton('customer/session', array('name' => 'frontend'));

if (!empty($_POST['tkn'])) {
    $ret = $_POST['tkn'] != $core_session->getSmpToken() ? "updated" : "not_changed";
    $core_session->setSmpToken($_POST['tkn']);
    echo json_encode(array("result" => $ret));
} else {
    $customer = $session->getCustomer();
    $isLoggedIn = $session->isLoggedIn();
    if ($isLoggedIn) {
        $signed_request = StarSMP::create_signed_request(array(
            "email" => $customer->getEmail()
        ), SMP_CLIENT_SECRET);

        echo json_encode(array("signedRequest" => $signed_request));
    } else {
        echo json_encode(array("signedRequest" => ""));
    }

}