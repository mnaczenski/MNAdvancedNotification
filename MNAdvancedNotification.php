<?php

namespace MNAdvancedNotification;

use Shopware\Components\Plugin\Context\ActivateContext;
use Shopware\Components\Plugin\Context\DeactivateContext;
use Shopware\Components\Plugin\Context\InstallContext;
use Shopware\Components\Plugin\Context\UpdateContext;
use Shopware\Components\Plugin\Context\UninstallContext;

class MNAdvancedNotification extends \Shopware\Components\Plugin
{
    public function install(InstallContext $context) {
        //register Service
        $service = $this->container->get('shopware_attribute.crud_service');

        //create attributes
        $service->update('s_core_countries_attributes','disableForShipping','boolean', [
            'label' => 'Als Lieferadresse ausblenden',
            'displayInBackend' => true,
        ]);

        $metaDataCache = Shopware()->Models()->getConfiguration()->getMetadataCacheImpl();
        $metaDataCache->deleteAll();
        Shopware()->Models()->generateAttributeModels(['s_core_countries_attributes']);
        $this->createAttributes();

    }

    public function activate(ActivateContext $context)
    {
        $context->scheduleClearCache(InstallContext::CACHE_LIST_ALL);
    }

    public function createAttributes()
    {
        $sql = 
            "INSERT INTO `s_core_countries_attributes`(`countryID`)
            SELECT d.id
            FROM `s_core_countries` d
            LEFT JOIN `s_core_countries_attributes` at
            ON at.countryID = d.id
            WHERE at.id IS NULL;";

        $result = Shopware()->Db()->query($sql);
    }

}