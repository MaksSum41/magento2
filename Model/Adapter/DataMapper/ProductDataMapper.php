<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Elasticsearch\Model\Adapter\DataMapper;

use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Elasticsearch\Model\Adapter\Container\Attribute as AttributeContainer;
use Magento\Elasticsearch\Model\Adapter\Document\Builder;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Elasticsearch\Model\ResourceModel\Index;
use Magento\Elasticsearch\Model\Adapter\FieldMapperInterface;
use Magento\Elasticsearch\Model\Adapter\DataMapperInterface;
use Magento\Elasticsearch\Model\Adapter\FieldType\Date as DateFieldType;

/**
 * @deprecated 2.2.0
 * @see \Magento\Elasticsearch\Model\Adapter\BatchDataMapperInterface
 * @since 2.1.0
 */
class ProductDataMapper implements DataMapperInterface
{
    /**
     * Attribute code for image
     */
    const MEDIA_ROLE_IMAGE = 'image';

    /**
     * Attribute code for small image
     */
    const MEDIA_ROLE_SMALL_IMAGE = 'small_image';

    /**
     * Attribute code for thumbnail
     */
    const MEDIA_ROLE_THUMBNAIL = 'thumbnail';

    /**
     * Attribute code for swatches
     */
    const MEDIA_ROLE_SWATCH_IMAGE = 'swatch_image';

    /**
     * @var Builder
     * @since 2.1.0
     */
    private $builder;

    /**
     * @var AttributeContainer
     * @since 2.1.0
     */
    private $attributeContainer;

    /**
     * @var Index
     * @since 2.1.0
     */
    private $resourceIndex;

    /**
     * @var FieldMapperInterface
     * @since 2.1.0
     */
    private $fieldMapper;

    /**
     * @var StoreManagerInterface
     * @since 2.1.0
     */
    private $storeManager;

    /**
     * @var DateFieldType
     * @since 2.1.0
     */
    private $dateFieldType;

    /**
     * Media gallery roles
     *
     * @var array
     * @since 2.1.0
     */
    protected $mediaGalleryRoles;

    /**
     * Construction for DocumentDataMapper
     *
     * @param Builder $builder
     * @param AttributeContainer $attributeContainer
     * @param Index $resourceIndex
     * @param FieldMapperInterface $fieldMapper
     * @param StoreManagerInterface $storeManager
     * @param DateFieldType $dateFieldType
     * @since 2.1.0
     */
    public function __construct(
        Builder $builder,
        AttributeContainer $attributeContainer,
        Index $resourceIndex,
        FieldMapperInterface $fieldMapper,
        StoreManagerInterface $storeManager,
        DateFieldType $dateFieldType
    ) {
        $this->builder = $builder;
        $this->attributeContainer = $attributeContainer;
        $this->resourceIndex = $resourceIndex;
        $this->fieldMapper = $fieldMapper;
        $this->storeManager = $storeManager;
        $this->dateFieldType = $dateFieldType;

        $this->mediaGalleryRoles = [
            self::MEDIA_ROLE_IMAGE,
            self::MEDIA_ROLE_SMALL_IMAGE,
            self::MEDIA_ROLE_THUMBNAIL,
            self::MEDIA_ROLE_SWATCH_IMAGE
        ];
    }

    /**
     * Prepare index data for using in search engine metadata.
     *
     * @param int $productId
     * @param array $indexData
     * @param int $storeId
     * @param array $context
     * @return array|false
     * @since 2.1.0
     */
    public function map($productId, array $indexData, $storeId, $context = [])
    {
        $this->builder->addField('store_id', $storeId);
        if (count($indexData)) {
            $productIndexData = $this->resourceIndex->getFullProductIndexData($productId, $indexData);
        }

        foreach ($productIndexData as $attributeCode => $value) {
            // Prepare processing attribute info
            if (strpos($attributeCode, '_value') !== false) {
                $this->builder->addField($attributeCode, $value);
                continue;
            }
            $attribute = $this->attributeContainer->getAttribute($attributeCode);
            if (!$attribute ||
                in_array(
                    $attributeCode,
                    [
                        'price',
                        'media_gallery',
                        'tier_price',
                        'quantity_and_stock_status',
                        'media_gallery',
                        'giftcard_amounts'
                    ]
                )
            ) {
                continue;
            }
            $attribute->setStoreId($storeId);
            $value = $this->checkValue($value, $attribute, $storeId);
            $this->builder->addField(
                $this->fieldMapper->getFieldName(
                    $attributeCode,
                    $context
                ),
                $value
            );
        }
        $this->processAdvancedAttributes($productId, $productIndexData, $storeId);

        return $this->builder->build();
    }

    /**
     * Process advanced attribute values
     *
     * @param int $productId
     * @param array $productIndexData
     * @param int $storeId
     * @return void
     * @since 2.1.0
     */
    protected function processAdvancedAttributes($productId, array $productIndexData, $storeId)
    {
        $mediaGalleryRoles = array_fill_keys($this->mediaGalleryRoles, '');
        $productPriceIndexData = $this->attributeContainer->getAttribute('price')
            ? $this->resourceIndex->getPriceIndexData([$productId], $storeId)
            : [];
        $productCategoryIndexData = $this->resourceIndex->getFullCategoryProductIndexData(
            $storeId,
            [$productId => $productId]
        );
        foreach ($productIndexData as $attributeCode => $value) {
            if (in_array($attributeCode, $this->mediaGalleryRoles)) {
                $mediaGalleryRoles[$attributeCode] = $value;
            } elseif ($attributeCode == 'tier_price') {
                $this->builder->addFields($this->getProductTierPriceData($value));
            } elseif ($attributeCode == 'quantity_and_stock_status') {
                $this->builder->addFields($this->getQtyAndStatus($value));
            } elseif ($attributeCode == 'media_gallery') {
                $this->builder->addFields(
                    $this->getProductMediaGalleryData(
                        $value,
                        $mediaGalleryRoles
                    )
                );
            }
        }
        $this->builder->addFields($this->getProductPriceData($productId, $storeId, $productPriceIndexData));
        $this->builder->addFields($this->getProductCategoryData($productId, $productCategoryIndexData));
    }

    /**
     * @param mixed $value
     * @param Attribute $attribute
     * @param string $storeId
     * @return array|mixed|null|string
     * @since 2.1.0
     */
    protected function checkValue($value, $attribute, $storeId)
    {
        if (in_array($attribute->getBackendType(), ['datetime', 'timestamp'])
            || $attribute->getFrontendInput() === 'date') {
            return $this->dateFieldType->formatDate($storeId, $value);
        } elseif ($attribute->getFrontendInput() === 'multiselect') {
            return str_replace(',', ' ', $value);
        } else {
            return $value;
        }
    }

    /**
     * Prepare tier price data for product
     *
     * @param array $data
     * @return array
     * @since 2.1.0
     */
    protected function getProductTierPriceData($data)
    {
        $result = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $tierPrice) {
                $result['tier_price_id_'.$i] = $tierPrice['price_id'];
                $result['tier_website_id_'.$i] = $tierPrice['website_id'];
                $result['tier_all_groups_'.$i] = $tierPrice['all_groups'];
                $result['tier_cust_group_'.$i] = $tierPrice['cust_group'] == GroupInterface::CUST_GROUP_ALL
                    ? '' : $tierPrice['cust_group'];
                $result['tier_price_qty_'.$i] = $tierPrice['price_qty'];
                $result['tier_website_price_'.$i] = $tierPrice['website_price'];
                $result['tier_price_'.$i] = $tierPrice['price'];
                $i++;
            }
        }

        return $result;
    }

    /**
     * Prepare media gallery data for product
     *
     * @param array $media
     * @param array $roles
     * @return array
     * @since 2.1.0
     */
    protected function getProductMediaGalleryData($media, $roles)
    {
        $result = [];

        if (!empty($media['images'])) {
            $i = 0;
            foreach ($media['images'] as $data) {
                if ($data['media_type'] === 'image') {
                    $result['image_file_' . $i] = $data['file'];
                    $result['image_position_' . $i] = $data['position'];
                    $result['image_disabled_' . $i] = $data['disabled'];
                    $result['image_label_' . $i] = $data['label'];
                    $result['image_title_' . $i] = $data['label'];
                    $result['image_base_image_' . $i] = $this->getMediaRoleImage($data['file'], $roles);
                    $result['image_small_image_' . $i] = $this->getMediaRoleSmallImage($data['file'], $roles);
                    $result['image_thumbnail_' . $i] = $this->getMediaRoleThumbnail($data['file'], $roles);
                    $result['image_swatch_image_' . $i] = $this->getMediaRoleSwatchImage($data['file'], $roles);
                } else {
                    $result['video_file_' . $i] = $data['file'];
                    $result['video_position_' . $i] = $data['position'];
                    $result['video_disabled_' . $i] = $data['disabled'];
                    $result['video_label_' . $i] = $data['label'];
                    $result['video_title_' . $i] = $data['video_title'];
                    $result['video_base_image_' . $i] = $this->getMediaRoleImage($data['file'], $roles);
                    $result['video_small_image_' . $i] = $this->getMediaRoleSmallImage($data['file'], $roles);
                    $result['video_thumbnail_' . $i] = $this->getMediaRoleThumbnail($data['file'], $roles);
                    $result['video_swatch_image_' . $i] = $this->getMediaRoleSwatchImage($data['file'], $roles);
                    $result['video_url_' . $i] = $data['video_url'];
                    $result['video_description_' . $i] = $data['video_description'];
                    $result['video_metadata_' . $i] = $data['video_metadata'];
                    $result['video_provider_' . $i] = $data['video_provider'];
                }
                $i++;
            }
        }
        return $result;
    }

    /**
     * @param string $file
     * @param array $roles
     * @return string
     * @since 2.1.0
     */
    protected function getMediaRoleImage($file, $roles)
    {
        return $file == $roles[self::MEDIA_ROLE_IMAGE] ? '1' : '0';
    }

    /**
     * @param string $file
     * @param array $roles
     * @return string
     * @since 2.1.0
     */
    protected function getMediaRoleSmallImage($file, $roles)
    {
        return $file == $roles[self::MEDIA_ROLE_SMALL_IMAGE] ? '1' : '0';
    }

    /**
     * @param string $file
     * @param array $roles
     * @return string
     * @since 2.1.0
     */
    protected function getMediaRoleThumbnail($file, $roles)
    {
        return $file == $roles[self::MEDIA_ROLE_THUMBNAIL] ? '1' : '0';
    }

    /**
     * @param string $file
     * @param array $roles
     * @return string
     * @since 2.1.0
     */
    protected function getMediaRoleSwatchImage($file, $roles)
    {
        return $file == $roles[self::MEDIA_ROLE_SWATCH_IMAGE] ? '1' : '0';
    }

    /**
     * Prepare quantity and stock status for product
     *
     * @param array $data
     * @return array
     * @since 2.1.0
     */
    protected function getQtyAndStatus($data)
    {
        $result = [];
        if (!is_array($data)) {
            $result['is_in_stock'] = $data ? 1 : 0;
            $result['qty'] = $data;
        } else {
            $result['is_in_stock'] = $data['is_in_stock'] ? 1 : 0;
            $result['qty'] = $data['qty'];
        }
        return $result;
    }

    /**
     * Prepare price index for product
     *
     * @param int $productId
     * @param int $storeId
     * @param array $priceIndexData
     * @return array
     * @since 2.1.0
     */
    protected function getProductPriceData($productId, $storeId, array $priceIndexData)
    {
        $result = [];
        if (array_key_exists($productId, $priceIndexData)) {
            $productPriceIndexData = $priceIndexData[$productId];
            $websiteId = $this->storeManager->getStore($storeId)->getWebsiteId();
            foreach ($productPriceIndexData as $customerGroupId => $price) {
                $fieldName = 'price_' . $customerGroupId . '_' . $websiteId;
                $result[$fieldName] = sprintf('%F', $price);
            }
        }
        return $result;
    }

    /**
     * Prepare category index data for product
     *
     * @param int $productId
     * @param array $categoryIndexData
     * @return array
     * @since 2.1.0
     */
    protected function getProductCategoryData($productId, array $categoryIndexData)
    {
        $result = [];
        $categoryIds = [];

        if (array_key_exists($productId, $categoryIndexData)) {
            $indexData = $categoryIndexData[$productId];
            $result = $indexData;
        }

        if (array_key_exists($productId, $categoryIndexData)) {
            $indexData = $categoryIndexData[$productId];
            foreach ($indexData as $categoryData) {
                $categoryIds[] = $categoryData['id'];
            }
            if (count($categoryIds)) {
                $result = ['category_ids' => implode(' ', $categoryIds)];
                foreach ($indexData as $data) {
                    $result['position_category_' . $data['id']] = $data['position'];
                    $result['name_category_' . $data['id']] = $data['name'];
                }
            }
        }
        return $result;
    }
}
