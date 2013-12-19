<?php
$installer = $this;
/* @var $installer Mage_Core_Model_Resource_Setup */

$installer->startSetup();

$fieldsSql = 'SHOW COLUMNS FROM ' . $this->getTable('eav/attribute');
$cols = $installer->getConnection()->fetchCol($fieldsSql);

if (!in_array('size_text', $cols))
{
    $installer->run("ALTER TABLE `{$this->getTable('eav/attribute')}` ADD `size_text` INT NULL ");
}

$installer->endSetup(); 