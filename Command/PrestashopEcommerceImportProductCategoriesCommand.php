<?php

namespace MauticPlugin\PrestashopEcommerceBundle\Command;

use Mautic\CampaignBundle\Command\WriteCountTrait;
use Mautic\CampaignBundle\Executioner\ScheduledExecutioner;
use Mautic\CoreBundle\Templating\Helper\FormatterHelper;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\EcommerceBundle\Model\ProductCategoryModel;
use MauticPlugin\EcommerceBundle\Model\ProductModel;
use MauticPlugin\PrestashopEcommerceBundle\Integration\PrestashopEcommerceIntegration;
use MauticPlugin\PrestashopEcommerceBundle\Services\PrestaShopWebserviceException;
use MauticPlugin\PrestashopEcommerceBundle\Services\PrestaShopWebservice;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Translation\TranslatorInterface;
use function _HumbugBox50e4a802564d\Levels\CallableVariance\d;


class PrestashopEcommerceImportProductCategoriesCommand extends Command
{
    use WriteCountTrait;

    private $scheduledExecutioner;

    private $translator;

    private $formatterHelper;

    private $cartModel;

    private $productCategoryModel;

    private $productModel;

    private $prestashopEcommerceIntegration;

    private $integrationHelper;

    public function __construct(ScheduledExecutioner $scheduledExecutioner, TranslatorInterface $translator, FormatterHelper $formatterHelper, ProductCategoryModel $productCategoryModel, ProductModel $producModel, PrestashopEcommerceIntegration $prestashopEcommerceIntegration, IntegrationHelper $integrationHelper)
    {
        parent::__construct();
        $this->scheduledExecutioner = $scheduledExecutioner;
        $this->translator           = $translator;
        $this->formatterHelper      = $formatterHelper;
        $this->productCategoryModel = $productCategoryModel;
        $this->productModel         = $producModel;
        $this->prestashopEcommerceIntegration = $prestashopEcommerceIntegration;
        $this->integrationHelper    = $integrationHelper;
    }

    protected function configure()
    {
        $this
            ->setName('mautic:prestashopecommerce:importproductcategories')
            ->setDescription('Import Categories from prestashop API');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('');
        $output->writeln('Importing Categories');

        $api = $this->prestashopEcommerceIntegration->decryptApiKeys($this->integrationHelper->getIntegrationObject('PrestashopEcommerce')->getIntegrationSettings()->getApiKeys());

        try {
            $webService = new PrestaShopWebservice($api['apiUrl'], $api['apiKey'], false);

            $xml = $webService->get([   'resource'  =>  'shops',
                                        'display'   =>  '[id,name]',
                                        'filter[active]'  => '1']);
            $shops = $xml->shops;


            foreach ($shops->children() as $shop){

                $xml = $webService->get([   'resource'          =>  'languages',
                                            'display'           =>  '[id,iso_code]',]);
                $languages = [];

                foreach ($xml->languages->children() as $language){
                    $languages[(int)$language->id] =$language->iso_code;
                }

                $shopId = (int)$shop->id;
                $xml = $webService->get([   'resource'          =>  'shop_urls',
                                            'display'           =>  'full',
                                            'filter[active]'    => '1',
                                            'filter[main]'      => '1',
                                            'filter[id_shop]'   => (int)$shopId]);

                $shop_url = $xml->shop_urls->shop_url;
                $shop_url ='http://' . $shop_url->domain . $shop_url->physical_uri . $shop_url->virtual_uri;

                dump($shop_url);
                $webServiceShop = new PrestaShopWebservice($shop_url, $api['apiKey'], false);
                $xml = $webServiceShop->get([   'resource'  =>  'languages',
                                                'display'   =>  '[id,iso_code, active]']);
                $languages = $xml->languages;
                foreach ($languages->children() as $language){
                    $xml = $webServiceShop->get([   'resource'  =>  'categories',
                                                    'display'   =>  'full',
                                                    'language'  =>  (int)$language->id]);
                    $categories=$xml->categories->children();

                    foreach ($categories as $category){
                            $output->writeln((string)$category->name->language);
                            $entity = $this->productCategoryModel->getCategoryById($category->id, $shopId,  $language->iso_code);
                            if (empty($entity)){
                                $entity = $this->productCategoryModel->getEntity();
                            }
                            else{
                                $entity = $this->productCategoryModel->getEntity((int)$entity[0]['id']);
                            }
                            $entity->setName((string)$category->name->language);
                            if ($category->level_depth == 0){
                                $entity->setIsRoot(true);
                            }
                            else{
                                $entity->setIsRoot(false);
                            }
                            $entity->setDepth($category->level_depth);
                            $entity->setShopId($shopId);
                            $entity->setLanguage($language->iso_code);
                            $entity->setCategoryId($category->id);
                            $this->productCategoryModel->saveEntity($entity);
                    }
                    foreach ($categories as $category){
                        $output->writeln("");
                        $output->writeln((string)$category->name->language);
                        $entity = $this->productCategoryModel->getCategoryById($category->id, $shopId,  $language->iso_code);

                        if (!empty($entity)){

                            $entity = $this->productCategoryModel->getEntity($entity[0]['id']);

                            foreach ($category->associations->categories->children() as $categoryChildren){
                                $categoryChildren = $this->productCategoryModel->getCategoryById($categoryChildren->id, $shopId,  $language->iso_code);
                                if (!empty($categoryChildren)){
                                    $categoryChildren = $this->productCategoryModel->getEntity((int)$categoryChildren[0]['id']);
                                    $entity->setChildren($categoryChildren);
                                    $this->productCategoryModel->saveEntity($entity);
                                    $output->writeln("|");
                                    $output->writeln("|->".$categoryChildren->getName());
                                }
                            }
                        }
                    }

                }

            }
        } catch (PrestaShopWebserviceException $ex) {
            // Shows a message related to the error
            $output->writeln($ex->getMessage());
        }
        return 0;
    }
}

