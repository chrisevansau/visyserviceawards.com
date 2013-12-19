<?php
$installer = $this;
/* @var $installer Mage_Core_Model_Resource_Setup */

$installer->startSetup();

$fieldsSql = 'SHOW COLUMNS FROM ' . $this->getTable('eav/attribute');
$cols = $installer->getConnection()->fetchCol($fieldsSql);

if (!in_array('customer_groups', $cols))
{
    $installer->run("ALTER TABLE `{$this->getTable('eav/attribute')}` ADD `customer_groups` VARCHAR( 128 ) NOT NULL");
}

$installer->endSetup();
