<?php

return array(
    'name'          => 'PrestashopEcommerce',
    'description'   => 'Prestashop Ecommerce for Mautic',
    'version'       => '0.1.0',
    'services' => [
        'integrations' => [
            'mautic.integration.PrestashopEcommerce' => [
                'class'     => \MauticPlugin\PrestashopEcommerceBundle\Integration\PrestashopEcommerceIntegration::class,
                'arguments' => [
                    'event_dispatcher',
                    'mautic.helper.cache_storage',
                    'doctrine.orm.entity_manager',
                    'session',
                    'request_stack',
                    'router',
                    'translator',
                    'logger',
                    'mautic.helper.encryption',
                    'mautic.lead.model.lead',
                    'mautic.lead.model.company',
                    'mautic.helper.paths',
                    'mautic.core.model.notification',
                    'mautic.lead.model.field',
                    'mautic.plugin.model.integration_entity',
                    'mautic.lead.model.dnc',
                ],
            ],
        ],
        'commands' => [
            'mautic.prestashopecommerce.command.importproducts' => [
                'class'     => \MauticPlugin\PrestashopEcommerceBundle\Command\PrestashopEcommerceImportProductsCommand::class,
                'arguments' => [
                    'mautic.campaign.executioner.scheduled',
                    'translator',
                    'mautic.helper.template.formatter',
                    'mautic.product.model.product',
                    'mautic.integration.PrestashopEcommerce',
                    'mautic.helper.integration',
                ],
                'tag' => 'console.command',
            ],
            'mautic.prestashopecommerce.command.importcarts' => [
                'class'     => \MauticPlugin\PrestashopEcommerceBundle\Command\PrestashopEcommerceImportCartsCommand::class,
                'arguments' => [
                    'mautic.campaign.executioner.scheduled',
                    'translator',
                    'mautic.helper.template.formatter',
                    'mautic.cart.model.cart',
                    'mautic.product.model.product',
                    'mautic.lead.model.lead',
                    'mautic.integration.PrestashopEcommerce',
                    'mautic.helper.integration',
                ],
                'tag' => 'console.command',
            ],
            'mautic.prestashopecommerce.command.importcustomers' => [
                'class'     => \MauticPlugin\PrestashopEcommerceBundle\Command\PrestashopEcommerceImportCustomersCommand::class,
                'arguments' => [
                    'mautic.campaign.executioner.scheduled',
                    'translator',
                    'mautic.helper.template.formatter',
                    'mautic.lead.model.lead',
                    'mautic.integration.PrestashopEcommerce',
                    'mautic.helper.integration',
                ],
                'tag' => 'console.command',
            ],
            'mautic.prestashopecommerce.command.importProductCategories' => [
                'class'     => \MauticPlugin\PrestashopEcommerceBundle\Command\PrestashopEcommerceImportProductCategoriesCommand::class,
                'arguments' => [
                    'mautic.campaign.executioner.scheduled',
                    'translator',
                    'mautic.helper.template.formatter',
                    'mautic.productcategory.model.productcategory',
                    'mautic.product.model.product',
                    'mautic.integration.PrestashopEcommerce',
                    'mautic.helper.integration',
                ],
                'tag' => 'console.command',
            ],
        ],
    ],
);
