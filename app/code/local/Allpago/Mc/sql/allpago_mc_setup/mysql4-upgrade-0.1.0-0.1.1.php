<?php

$installer = $this;

$installer->startSetup();

$installer->getConnection()->exec('ALTER TABLE allpago_robots_logs MODIFY order_id  INT(10) UNSIGNED DEFAULT NULL');

$installer->endSetup();

?>
