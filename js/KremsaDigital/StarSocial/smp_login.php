<?php
if(!empty($_POST['tkn']))
{
if (!defined('MAGENTO_ROOT'))
    define('MAGENTO_ROOT', getcwd());

$compilerConfig = MAGENTO_ROOT . '/../../../includes/config.php';
if (file_exists($compilerConfig)) {
    include $compilerConfig;
}

$mageFilename = MAGENTO_ROOT . '/../../../app/Mage.php';

require_once $mageFilename;
umask(0);
Mage::app();

require_once(Mage::getBaseDir('lib') . '/KremsaDigital/StarSocial/starsmp.php');

define("SMP_CLIENT_ID", Mage::getStoreConfig('starsocial/conf/client_id'));
define("SMP_CLIENT_SECRET", Mage::getStoreConfig('starsocial/conf/client_secret'));

$ret = $_POST['tkn'] != Mage::getSingleton('core/session', array('name'=>'frontend'))->getSmpToken() ? "updated" : "not_changed";
Mage::getSingleton('core/session', array('name'=>'frontend'))->setSmpToken($_POST['tkn']);
echo json_encode(array("result"=>$ret));
}
else
{
// TODO this e-shop doesn't have login possibility
if (isset($_SESSION["logged"]) && $_SESSION["logged"] == true) {
}
else {
echo json_encode(array("signedRequest" => ""));
}
 
}