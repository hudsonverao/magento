<?php

$installer = $this;

$installer->startSetup();

$installer->getConnection()->exec('ALTER TABLE allpago_payment_orders ADD registration_cc text');

$installer->endSetup();