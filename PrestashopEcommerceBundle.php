<?php
namespace MauticPlugin\PrestashopEcommerceBundle;

use Mautic\CoreBundle\Factory\MauticFactory;
use Mautic\LeadBundle\Entity\LeadField;
use Mautic\PluginBundle\Bundle\PluginBundleBase;
use Mautic\PluginBundle\Entity\Plugin;


class PrestashopEcommerceBundle extends PluginBundleBase
{
    static public function onPluginInstall(Plugin $plugin, MauticFactory $factory, $metadata = null, $installedSchema = NULL)
    {
        if ($metadata !== null) {
            self::installPluginSchema($metadata, $factory);
        }
        $em = $factory->getEntityManager();

        $leadField = new LeadField();
        $leadField->setLabel('Customer Id');
        $leadField->setAlias('customerid');
        $leadField->setObject('lead');
        $leadField->setGroup('core');
        $leadField->setType('number');
        $leadField->setIsUniqueIdentifier(true);
        $leadField->setIsPubliclyUpdatable(true);
        $em->persist($leadField);
        $em->flush($leadField);

        $leadField = new LeadField();
        $leadField->setLabel('Guest Id');
        $leadField->setAlias('guestid');
        $leadField->setObject('lead');
        $leadField->setGroup('core');
        $leadField->setType('number');
        $leadField->setIsUniqueIdentifier(true);
        $leadField->setIsPubliclyUpdatable(true);
        $em->persist($leadField);
        $em->flush($leadField);

    }
}