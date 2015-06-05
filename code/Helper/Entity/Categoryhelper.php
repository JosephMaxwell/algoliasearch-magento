<?php

class Algolia_Algoliasearch_Helper_Entity_Categoryhelper extends Algolia_Algoliasearch_Helper_Entity_Helper
{
    private static $_categoryAttributes;

    protected function getIndexNameSuffix()
    {
        return '_categories';
    }

    public function getIndexSettings($storeId)
    {
        $attributesToIndex          = array();
        $unretrievableAttributes    = array();

        foreach ($this->config->getCategoryAdditionalAttributes($storeId) as $attribute)
        {
            if ($attribute['searchable'] == '1')
            {
                if ($attribute['order'] == 'ordered')
                    $attributesToIndex[] = $attribute['attribute'];
                else
                    $attributesToIndex[] = 'unordered('.$attribute['attribute'].')';
            }

            if ($attribute['retrievable'] != '1')
                $unretrievableAttributes[] = $attribute['attribute'];
        }

        $customRankings = $this->config->getCategoryCustomRanking($storeId);

        $customRankingsArr = array();

        foreach ($customRankings as $ranking)
            $customRankingsArr[] =  $ranking['order'] . '(' . $ranking['attribute'] . ')';

        // Default index settings
        $indexSettings = array(
            'attributesToIndex'         => array_values(array_unique($attributesToIndex)),
            'customRanking'             => $customRankingsArr,
            'unretrievableAttributes'   => $unretrievableAttributes
        );

        // Additional index settings from event observer
        $transport = new Varien_Object($indexSettings);
        Mage::dispatchEvent('algolia_index_settings_prepare', array('store_id' => $storeId, 'index_settings' => $transport));
        $indexSettings = $transport->getData();

        $this->algolia_helper->mergeSettings($this->getIndexName($storeId), $indexSettings);

        return $indexSettings;
    }

    public function getAllAttributes()
    {
        if (is_null(self::$_categoryAttributes))
        {
            self::$_categoryAttributes = array();

            /** @var $config Mage_Eav_Model_Config */
            $config = Mage::getSingleton('eav/config');

            $allAttributes = $config->getEntityAttributeCodes('catalog_category');

            $categoryAttributes = array_merge($allAttributes, array('product_count'));

            $excludedAttributes = array(
                'all_children', 'available_sort_by', 'children', 'children_count', 'custom_apply_to_products',
                'custom_design', 'custom_design_from', 'custom_design_to', 'custom_layout_update', 'custom_use_parent_settings',
                'default_sort_by', 'display_mode', 'filter_price_range', 'global_position', 'image', 'include_in_menu', 'is_active',
                'is_always_include_in_menu', 'is_anchor', 'landing_page', 'level', 'lower_cms_block',
                'page_layout', 'path_in_store', 'position', 'small_image', 'thumbnail', 'url_key', 'url_path',
                'visible_in_menu');

            $categoryAttributes = array_diff($categoryAttributes, $excludedAttributes);

            foreach ($categoryAttributes as $attributeCode)
                self::$_categoryAttributes[$attributeCode] = $config->getAttribute('catalog_category', $attributeCode)->getFrontendLabel();
        }

        return self::$_categoryAttributes;
    }

    public function getObject(Mage_Catalog_Model_Category $category)
    {
        /** @var $productCollection Mage_Catalog_Model_Resource_Product_Collection */
        $productCollection = $category->getProductCollection();
        $category->setProductCount($productCollection->addMinimalPrice()->count());

        $transport = new Varien_Object();
        Mage::dispatchEvent('algolia_category_index_before', array('category' => $category, 'custom_data' => $transport));
        $customData = $transport->getData();

        $storeId = $category->getStoreId();
        $category->getUrlInstance()->setStore($storeId);
        $path = '';
        foreach ($category->getPathIds() as $categoryId) {
            if ($path != '') {
                $path .= ' / ';
            }
            $path .= $this->getCategoryName($categoryId, $storeId);
        }

        $image_url = NULL;
        try {
            $image_url = $category->getImageUrl();
        } catch (Exception $e) { /* no image, no default: not fatal */
        }
        $data = array(
            'objectID'      => $category->getId(),
            'name'          => $category->getName(),
            'path'          => $path,
            'level'         => $category->getLevel(),
            'url'           => $category->getUrl(),
            '_tags'         => array('category'),
            'popularity'    => 1,
            'product_count' => $category->getProductCount()
        );

        if ( ! empty($image_url)) {
            $data['image_url'] = $image_url;
        }
        foreach ($this->getCategoryAdditionalAttributes($storeId) as $attribute) {
            $value = $category->hasData($this->_dataPrefix.$attribute['attribute'])
                ? $category->getData($this->_dataPrefix.$attribute['attribute'])
                : $category->getData($attribute['attribute']);

            $value = Mage::getResourceSingleton('algoliasearch/fulltext')->getAttributeValue($attribute['attribute'], $value, $storeId, Mage_Catalog_Model_Category::ENTITY);

            if (isset($data[$attribute['attribute']]))
                $value = $data[$attribute['attribute']];

            if ($value)
                $data[$attribute['attribute']] = $value;
        }

        $data = array_merge($data, $customData);

        foreach ($data as &$data0)
            $data0 = $this->try_cast($data0);

        return $data;
    }
}