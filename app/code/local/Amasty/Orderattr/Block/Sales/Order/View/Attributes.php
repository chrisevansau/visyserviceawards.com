<?php
/**
* @author Amasty Team
* @copyright Amasty
* @package Amasty_Orderattr
*/
class Amasty_Orderattr_Block_Sales_Order_View_Attributes extends Mage_Core_Block_Template
{
    public function __construct()
    {
        parent::__construct();
        $this->setTemplate('amorderattr/attributes.phtml');
    }
    
    private function getStoreValues($attribute) {
        $values = array();
        $valuesCollection = Mage::getResourceModel('eav/entity_attribute_option_collection')
             ->setAttributeFilter($attribute->getId())
             ->setStoreFilter(Mage::app()->getStore()->getId(), false)
             ->load();
        foreach ($valuesCollection as $item) {
            $values[$item->getId()] = $item->getValue();
        }
        // fix for `No default store view`
        $valuesCollection = Mage::getResourceModel('eav/entity_attribute_option_collection')
             ->setAttributeFilter($attribute->getId())
             ->setStoreFilter(0, false)
             ->load();
        foreach ($valuesCollection as $item) {
            if (isset($values[$item->getId()]) && ($values[$item->getId()] !== '')) {
                continue;
            } else {
                $values[$item->getId()] = $item->getValue();
            }
        }
        return $values;
    }
    
    public function getAttributes()
    {
        $list = array();
        
        $collection = Mage::getModel('eav/entity_attribute')->getCollection();
        $collection->addFieldToFilter('entity_type_id', Mage::getModel('eav/entity')->setType('order')->getTypeId());
        $collection->getSelect()->order('checkout_step');
        $collection->getSelect()->order('sorting_order');
        $attributes = $collection->load();
        
        $order = Mage::registry('current_order');
        
        $orderAttributes = Mage::getModel('amorderattr/attribute')->load($order->getId(), 'order_id');
        
        if ($attributes->getSize())
        {
            foreach ($attributes as $attribute)
            {
                $currentStore = $order->getStoreId();
                $storeIds = explode(',', $attribute->getData('store_ids'));
                if (!$attribute->getIsVisibleOnFront()) {
                    continue;
                }
                if (!in_array($currentStore, $storeIds) && !in_array(0, $storeIds))
                {
                    continue;
                }
                
                $value = '';
                switch ($attribute->getFrontendInput())
                {
                    case 'select':
                    case 'boolean':
                        $values = $this->getStoreValues($attribute);
                        $options = $attribute->getSource()->getAllOptions(true, true);
                        foreach ($options as $option)
                        {
                            if ((isset($values[$option['value']]))&&($option['value'] == $orderAttributes[$attribute->getData('attribute_code')]))                            
                            {
                                $value = $values[$option['value']];
                            } elseif ($option['value'] == $orderAttributes->getData($attribute->getAttributeCode()))
                            {
                               $value = $option['label'];
                            }
                        }
                        break;
                     case 'checkboxes':
                        $values = $this->getStoreValues($attribute);
                        $options = $attribute->getSource()->getAllOptions(true, true);
                        $checkboxValues = explode(',', $orderAttributes->getData($attribute->getAttributeCode()));
                        foreach ($options as $i => $option)
                        {
                           if (($option['value']!='')&&(in_array($option['value'], $checkboxValues) ))
                            {
                                $value[] = $values[$option['value']];
                            }
                        }
                        if (!empty($value)){
                            $value = implode(', ',$value);
                        }
                        break;
                    case 'date':
                        $value = $orderAttributes->getData($attribute->getAttributeCode());
                        $format = Mage::app()->getLocale()->getDateTimeFormat(
                            Mage_Core_Model_Locale::FORMAT_TYPE_MEDIUM
                        );
                        if (!$value)
                        {
                            break;
                        }
                        if ('time' == $attribute->getNote())
                        {
                            $value = Mage::app()->getLocale()->date($value, Varien_Date::DATETIME_INTERNAL_FORMAT, null, false)->toString($format);
                        } else 
                        {
                            $format = trim(str_replace(array('m', 'a', 'H', ':', 'h', 's'), '', $format));
                            $value = Mage::app()->getLocale()->date($value, Varien_Date::DATE_INTERNAL_FORMAT, null, false)->toString($format);
                        }
                        break;
                    case 'textarea':
                        $value = nl2br($orderAttributes->getData($attribute->getAttributeCode()));
                        break;
                    default:
                        $value = $orderAttributes->getData($attribute->getAttributeCode());
                        break;
                }
                if ($attribute->getFrontendLabel() && $value){
                    $list[$attribute->getFrontendLabel()] = str_replace('$', '\$', $value);
                }
            }
        }
        return $list;
    }
}