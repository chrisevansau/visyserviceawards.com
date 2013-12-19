<?php
/**
* @author Amasty Team
* @copyright Copyright (c) 2008-2012 Amasty (http://www.amasty.com)
* @package Amasty_Orderattr
*/
class Amasty_Orderattr_Model_Observer
{
    protected $_attributes = null;
    protected $_permissibleActions = array('index', 'grid', 'exportCsv', 'exportExcel');
    protected $_exportActions = array('exportCsv', 'exportExcel');
    protected $_controllerNames = array('sales_', 'orderspro_');
    protected $_otherClasses = array('Mage_Adminhtml_Block_Sales_Order_Grid',
                                     'EM_DeleteOrder_Block_Adminhtml_Sales_Order_Grid',
                                     'MageWorx_Adminhtml_Block_Orderspro_Sales_Order_Grid',
                                     'Excellence_Salesgrid_Block_Adminhtml_Sales_Order_Grid',
                                     'AW_Ordertags_Block_Adminhtml_Sales_Order_Grid');
    
    protected function _prepareOrderAttributes()
    {
        if (Mage::app()->getRequest()->getPost('amorderattr'))
        {
            $session = Mage::getSingleton('checkout/type_onepage')->getCheckout();
            $orderAttributes = $session->getAmastyOrderAttributes();
            if (!$orderAttributes)
            {
                $orderAttributes = array();
            }
            if (!Mage::registry('attributeClear')){
                $orderAttributes = array_merge($orderAttributes, Mage::app()->getRequest()->getPost('amorderattr'));
            }
            $session->setAmastyOrderAttributes($orderAttributes);
        }
    }
    
    public function onSalesQuoteSaveAfter($observer)
    {
       $this->_prepareOrderAttributes();
    }
    
    public function onCheckoutTypeOnepageSaveOrderAfter($observer)
    {
        if(!Mage::registry('amorderattr_saved')){
            $this->_prepareOrderAttributes();
            $order = $observer->getOrder();
            $session = Mage::getSingleton('checkout/type_onepage')->getCheckout();
            $orderAttributes = $session->getAmastyOrderAttributes();
            $attributes = Mage::getModel('amorderattr/attribute');
            $attributes->load($order->getId(), 'order_id');
            if ($attributes->getId())
            {
                return false;
            }
           
            if (is_array($orderAttributes) && !empty($orderAttributes))
            {
                $collection = Mage::getModel('eav/entity_attribute')->getCollection();
                $collection->addFieldToFilter('is_visible_on_front', 1);
                $collection->addFieldToFilter('entity_type_id',Mage::getModel('eav/entity')->setType('order')->getTypeId());
                $attributesList = $collection->load();
                
                foreach ($attributesList as $attribute)
                {
                   
                    if ('checkboxes' == $attribute->getFrontend()->getInputType())
                    {
                       if (array_key_exists($attribute->getAttributeCode(), $orderAttributes)) {
                           $orderAttributes[$attribute->getAttributeCode()] = implode(',', $orderAttributes[$attribute->getAttributeCode()]);
                       }
                       
                    }   
                }
                $attributes->addData($orderAttributes);  
            }
            
            $attributes->setData('order_id', $order->getId());
            $this->_applyDefaultValues($order, $attributes);
            $attributes->save();
            Mage::register('amorderattr_saved', true);
            $session->setAmastyOrderAttributes(array());
            Mage::register('attributeClear',true);    
        }
    }
    
    // this will be used when creating/editing order in the backend
    public function onSalesOrderSaveAfter($observer)
    {
        if (false !== strpos(Mage::app()->getRequest()->getControllerName(), 'sales_order') && 'save' == Mage::app()->getRequest()->getActionName() && !Mage::registry('amorderattr_saved'))
        {
            $order = $observer->getOrder();
            $orderAttributes = Mage::app()->getRequest()->getPost('amorderattr');
            
            $attributes = Mage::getModel('amorderattr/attribute');
            $attributes->load($order->getId(), 'order_id');
            if ($attributes->getId())
            {
                return false;
            }
            
            if (is_array($orderAttributes) && !empty($orderAttributes))
            {
                foreach ($orderAttributes as $key => $val)
                {
                    if ($val)
                    {
                        if (is_array($val)){
                           $val=implode(', ',$val);
                        }
                        $attributes->setData($key, $val);
                    }
                }
            }
           
            $attributes->setData('order_id', $order->getId());
            $this->_applyDefaultValues($order, $attributes); // $attributes might be modified in that function
            $attributes->save();
            Mage::register('amorderattr_saved', true);
        }
    }
    
    protected function _applyDefaultValues($order, $attributes)
    {
        $collection = Mage::getResourceModel('eav/entity_attribute_collection')
                        ->setEntityTypeFilter(Mage::getModel('eav/entity')->setType('order')->getTypeId());
                             
        $collection->getSelect()
            ->where('main_table.is_user_defined = ?', 1)
            ->where('main_table.apply_default = ?', 1);
            
        if ($collection->getSize() > 0)
        {
            foreach ($collection as $attributeToApply)
            {
                if (!$attributes->getData($attributeToApply->getAttributeCode()) && $attributeToApply->getDefaultValue())
                {
                   $attributes->setData($attributeToApply->getAttributeCode(), $attributeToApply->getDefaultValue());
                }
            }
        }
    }
    
    protected function _getAttributes()
    {
        if (is_null($this->_attributes)) {
            $attributes = Mage::getModel('eav/entity_attribute')->getCollection();
            $attributes->addFieldToFilter('entity_type_id', Mage::getModel('eav/entity')->setType('order')->getTypeId());
            $attributes->addFieldToFilter('show_on_grid', 1);
            $this->_attributes = $attributes;
        }
        return $this->_attributes;
    }
    
    protected function _prepareCollection($collection, $place = 'order', $column = 'entity_id')
    {
        if ($this->_isJoined($collection->getSelect()->getPart('from')))
            return $collection;
            
        if (!$this->_isControllerName($place))
            return $collection;
        
        $attributes = $this->_getAttributes();
        if ($attributes->getSize()) {
            $fields = array();
            foreach ($attributes as $attribute) {
                $fields[] = $attribute->getAttributeCode();
            }
            
            $isVersion14 = ! Mage::helper('ambase')->isVersionLessThan(1,4);
            
            $alias = $isVersion14 ? 'main_table' : 'e';
            $collection->getSelect()
                       ->joinLeft(
                            array('custom_attributes' => Mage::getModel('amorderattr/attribute')->getResource()->getTable('amorderattr/order_attribute')),
                            "$alias.$column = custom_attributes.order_id",
                            $fields
                       );
        }
        return $collection;
    }
    
    protected function _isControllerName($place)
    {
        $found = false;
        foreach ($this->_controllerNames as $controllerName) {
            if (false !== strpos(Mage::app()->getRequest()->getControllerName(), $controllerName . $place)) {
                $found = true;
            }
        }
        return $found;
    }
    
    protected function _prepareColumns(&$grid, $export = false, $place = 'order', $after = 'grand_total')
    {
        if (!$this->_isControllerName($place) || 
            !in_array(Mage::app()->getRequest()->getActionName(), $this->_permissibleActions) )
            return $grid;
        
        $attributes = $this->_getAttributes();
        if ($attributes->getSize() > 0) {
            foreach ($attributes as $attribute) {
                $column = array();
                switch ($attribute->getFrontendInput())
                {
                    case 'date':
                            if ('time' == $attribute->getNote())
                            {
                                $column = array(
                                    'header'       => Mage::helper('amorderattr')->__($attribute->getFrontend()->getLabel()),
                                    'type'         => 'datetime',
                                    'align'        => 'center',
                                    'index'        => $attribute->getAttributeCode(),
                                    'filter_index' => 'custom_attributes.'.$attribute->getAttributeCode(),
                                    'gmtoffset'    => false,
                                    'renderer'     => 'amorderattr/adminhtml_order_grid_renderer_datetime',
                                );
                            } else 
                            {
                                $column = array(
                                    'header'       => Mage::helper('amorderattr')->__($attribute->getFrontend()->getLabel()),
                                    'type'         => 'date',
                                    'align'        => 'center',
                                    'index'        => $attribute->getAttributeCode(),
                                    'filter_index' => 'custom_attributes.'.$attribute->getAttributeCode(),
                                    'gmtoffset'    => false,
                                    'renderer'     => 'amorderattr/adminhtml_order_grid_renderer_datetime',
                                );
                            }
                            
                            break;
                        case 'text':
                        case 'textarea':
                            $column = array(
                                'header'       => Mage::helper('amorderattr')->__($attribute->getFrontend()->getLabel()),
                                'index'        => $attribute->getAttributeCode(),
                                'filter_index' => 'custom_attributes.'.$attribute->getAttributeCode(),
                                'filter'       => 'adminhtml/widget_grid_column_filter_text',
                                'sortable'     => true,
                                'renderer'     => 'amorderattr/adminhtml_order_grid_renderer_text' . ($export ? '_export' : ''),
                            );
                            break;
                        case 'boolean':
                            $options = array();
                            foreach ($attribute->getSource()->getAllOptions(false, true) as $option)
                            {
                                $options[$option['value']] = $option['label'];
                            }
                            $column = array(
                                'header'       =>  Mage::helper('amorderattr')->__($attribute->getFrontend()->getLabel()),
                                'index'        =>  $attribute->getAttributeCode(),
                                'align'        => 'center',
                                'filter_index' => 'custom_attributes.'.$attribute->getAttributeCode(),
                                'type'         => 'options',
                                'options'      =>  $options,
                                'filter'       => 'adminhtml/widget_grid_column_filter_select',                                
                            );                         
                            break;                                                    
                        case 'select':
                            $options = array();
                            foreach ($attribute->getSource()->getAllOptions(false, true) as $option) {
                                $options[$option['value']] = $option['label'];
                            }
                            $column = array(
                                'header'       =>  Mage::helper('amorderattr')->__($attribute->getFrontend()->getLabel()),
                                'index'        =>  $attribute->getAttributeCode(),
                                'filter_index' => 'custom_attributes.'.$attribute->getAttributeCode(),
                                'align'        => 'center',
                                'type'         => 'options',
                                'options'      =>  $options,
                            );
                            break;
                        case 'multiselect':
                            $options = array();
                            foreach ($attribute->getSource()->getAllOptions(false, true) as $option) {
                                $options[$option['value']] = $option['label'];
                            }
                            $column = array(
                                'header'       =>  Mage::helper('amorderattr')->__($attribute->getFrontend()->getLabel()),
                                'index'        =>  $attribute->getAttributeCode(),
                                'align'        => 'center',
                                'filter_index' => 'custom_attributes.'.$attribute->getAttributeCode(),
                                'type'         => 'options',
                                'options'      =>  $options,
                            );
                            break;
                         case 'checkboxes':
                            $options = array();
                            foreach ($attribute->getSource()->getAllOptions(false, true) as $option)
                            {
                                $options[$option['value']] = $option['label'];
                            }
                            $column = array(
                                'header'       =>  Mage::helper('amorderattr')->__($attribute->getFrontend()->getLabel()),
                                'type'         => 'options',
                                'align'        => 'center',
                                'options'      =>  $options,
                                'index'        =>  $attribute->getAttributeCode(),
                                'filter_index' => 'custom_attributes.'.$attribute->getAttributeCode(),
                                'filter'       => 'amorderattr/adminhtml_order_grid_filter_checkboxes',
                                'renderer'     => 'amorderattr/adminhtml_order_grid_renderer_checkboxes',
                            );
                            break;
                }
                $grid->addColumnAfter($column['index'], $column, $after);
                $after = $column['index'];
            }
        }
        return $grid;
    }
    
    public function onSalesOrderGridCollectionLoadBefore($observer)
    {
        $collection = $this->_prepareCollection($observer->getOrderGridCollection());
    }
    
    public function onSalesOrderInvoiceGridCollectionLoadBefore($observer)
    {
        if (!Mage::getStoreConfig('amorderattr/invoices_shipments/invoice_grid'))
            return;
        
        $collection = $this->_prepareCollection($observer->getOrderInvoiceGridCollection(), 'invoice', 'order_id');
    }
    
    public function onSalesOrderShipmentGridCollectionLoadBefore($observer)
    {
        if (!Mage::getStoreConfig('amorderattr/invoices_shipments/shipment_grid')) 
            return;
            
        $collection = $this->_prepareCollection($observer->getOrderShipmentGridCollection(), 'shipment', 'order_id');
    }
    
    protected function _isInstanceOf($block)
    {
        $found = false;
        foreach ($this->_otherClasses as $className) {
            if ($block instanceof $className) {
                $found = true;
                break;
            }
        }
        return $found;
    }
    
    public function onCoreLayoutBlockCreateAfter($observer)
    {
        $block = $observer->getBlock();
        // Order Grid
        if ($this->_isInstanceOf($block)) {
            $this->_prepareColumns($block, in_array(Mage::app()->getRequest()->getActionName(), $this->_exportActions));
        }
        /*if ($block instanceof Mage_Adminhtml_Block_Sales_Order_Grid || $block instanceof EM_DeleteOrder_Block_Adminhtml_Sales_Order_Grid) {
            $this->_prepareColumns($block, in_array(Mage::app()->getRequest()->getActionName(), $this->_exportActions));
        }*/
        // Invoice Grid
        if ($block instanceof Mage_Adminhtml_Block_Sales_Invoice_Grid && Mage::getStoreConfig('amorderattr/invoices_shipments/invoice_grid')) {
            $this->_prepareColumns($block, in_array(Mage::app()->getRequest()->getActionName(), $this->_exportActions), 'invoice');
        }
        // Shipment Grid
        if ($block instanceof Mage_Adminhtml_Block_Sales_Shipment_Grid && Mage::getStoreConfig('amorderattr/invoices_shipments/shipment_grid')) {
            $this->_prepareColumns($block, in_array(Mage::app()->getRequest()->getActionName(), $this->_exportActions), 'shipment', 'total_qty');
        }
    }
    
    protected function _isJoined($from)
    {
        $found = false;
        foreach ($from as $alias => $data) {
            if ('custom_attributes' === $alias) {
                $found = true;
                break;
            }
        }
        return $found;
    }

    protected function _prepareBackendHtml($html)
    {
        if (false === strpos($html, 'BEGIN `Amasty: Order Attributes`')) {
            $list = Mage::app()->getLayout()->createBlock('amorderattr/adminhtml_order_attribute_view_list');
            if (false === strpos($html, 'BEGIN `Amasty: Delivery Date`')) {
                $html = preg_replace('@<div class="entry-edit">(\s*)<div class="entry-edit-head">(\s*)(.*?)head-products@', 
                                 $list->toHtml() .'<div class="entry-edit"><div class="entry-edit-head">$3head-products', $html, 1);
            } else {
                $pos = strpos($html, '<!-- BEGIN `Amasty: Delivery Date` -->');
                $html = substr_replace($html, $list->toHtml(), $pos-1, 0);
            }
        }
        return $html;
    }
    
    protected function _prepareFrontendHtml($transport, $fields, $where = '<div class="buttons-set"')
    {
        $html = $transport->getHtml();
        if (false === strpos($html, 'amorderattr')) {
            $pos = strpos($html, $where);
            $insert = Mage::helper('amorderattr')->fields($fields);
            $html = substr_replace($html, $insert, $pos - 1, 0);
            $transport->setHtml($html);
        }
        return $html;
    }
    
    public function handleBlockOutput($observer)
    {
        /* @var $block Mage_Core_Block_Abstract */
        $block = $observer->getBlock();
        
        $transport = $observer->getTransport();
        $html = $transport->getHtml();
        
        if ($block instanceof Mage_Adminhtml_Block_Sales_Order_View_Tab_Info) {
            $html = $this->_prepareBackendHtml($html);
        }
        
        if ($block instanceof Mage_Adminhtml_Block_Sales_Order_Invoice_View) {
            if (Mage::getStoreConfig('amorderattr/invoices_shipments/invoice_view')) {
                $html = $this->_prepareBackendHtml($html);
            }
        }
        
        if ($block instanceof Mage_Adminhtml_Block_Sales_Order_Shipment_View) {
            if (Mage::getStoreConfig('amorderattr/invoices_shipments/shipment_view')) {
                $html = $this->_prepareBackendHtml($html);
            }
        }

        if ($block instanceof Mage_Checkout_Block_Onepage_Billing) {
            $html = $this->_prepareFrontendHtml($transport, 'billing');
        }
        
        if ($block instanceof Mage_Checkout_Block_Onepage_Shipping) {
            $html = $this->_prepareFrontendHtml($transport, 'shipping');
        }
        
        if ($block instanceof Mage_Checkout_Block_Onepage_Shipping_Method) { 
            $html = $this->_prepareFrontendHtml($transport, 'shipping_method');
        }
        
        if ($block instanceof Mage_Checkout_Block_Onepage_Payment) {
            $html = $this->_prepareFrontendHtml($transport, 'payment', '</form>');
        }
        
        if ($block instanceof Mage_Checkout_Block_Onepage_Review_Info) {
            $html = $this->_prepareFrontendHtml($transport, 'review', '</tfoot>');
        }
        
        if ($block instanceof Mage_Checkout_Block_Onepage_Shipping_Method_Available){
            $html .= '<script>if (typeof(amOrderattrConditionObj) != "undefined"){amOrderattrConditionObj.check();}</script>';
        }
        
        $transport->setHtml($html);
    }
}
