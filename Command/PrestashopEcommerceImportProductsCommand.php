<?php

namespace MauticPlugin\PrestashopEcommerceBundle\Command;

use Mautic\CampaignBundle\Command\WriteCountTrait;
use Mautic\CampaignBundle\Executioner\ScheduledExecutioner;
use Mautic\CoreBundle\Templating\Helper\FormatterHelper;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\EcommerceBundle\Model\ProductModel;
use MauticPlugin\PrestashopEcommerceBundle\Integration\PrestashopEcommerceIntegration;
use MauticPlugin\PrestashopEcommerceBundle\Services\PrestaShopWebserviceException;
use MauticPlugin\PrestashopEcommerceBundle\Services\PrestaShopWebservice;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Translation\TranslatorInterface;



class PrestashopEcommerceImportProductsCommand extends Command
{
    use WriteCountTrait;

    private $scheduledExecutioner;

    private $translator;

    private $formatterHelper;

    private $productModel;

    private $prestashopEcommerceIntegration;

    private $integrationHelper;

    public function __construct(ScheduledExecutioner $scheduledExecutioner, TranslatorInterface $translator, FormatterHelper $formatterHelper, ProductModel $productModel, PrestashopEcommerceIntegration $prestashopEcommerceIntegration, IntegrationHelper $integrationHelper)
    {
        parent::__construct();
        $this->scheduledExecutioner = $scheduledExecutioner;
        $this->translator           = $translator;
        $this->formatterHelper      = $formatterHelper;
        $this->productModel         = $productModel;
        $this->prestashopEcommerceIntegration = $prestashopEcommerceIntegration;
        $this->integrationHelper    = $integrationHelper;
    }

    protected function configure()
    {
        $this
            ->setName('mautic:prestashopecommerce:importproducts')
            ->setDescription('Import prodcutcs from prestashop API')
            ->addOption(
                '--key',
                null,
                InputOption::VALUE_REQUIRED,
                'key of webservice'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        date_default_timezone_set("America/Argentina/Buenos_Aires"); //TODO

        $output->writeln('');
        $output->writeln('Importing products');
        $output->writeln('whit key: ' . $input->getOption('key'));

        $api = $this->prestashopEcommerceIntegration->decryptApiKeys($this->integrationHelper->getIntegrationObject('PrestashopEcommerce')->getIntegrationSettings()->getApiKeys());

        try {
            $webService = new PrestaShopWebservice($api['apiUrl'], $api['apiKey'], false);

            $xml = $webService->get([   'resource'  =>  'shops',
                                        'display'   =>  '[id,name]',
                                        'filter[active]'  => '1']);
            $shops = $xml->shops;


            foreach ($shops->children() as $shop){
                $taxProduct = [];
                $shopId = (int)$shop->id;
                $xml = $webService->get([   'resource'          =>  'shop_urls',
                                            'display'           =>  '[domain,physical_uri,virtual_uri]',
                                            'filter[active]'    => '1',
                                            'filter[main]'      => '1',
                                            'filter[id_shop]'   => $shopId]);
                $shop_url = $xml->shop_urls->shop_url;
                $shop_url ='http://' . $shop_url->domain . $shop_url->physical_uri . $shop_url->virtual_uri;

                $webServiceShop = new PrestaShopWebservice($shop_url, $api['apiKey'], false);

                $xml = $webServiceShop->get([   'resource'  =>  'languages',
                                                'display'   =>  '[id,iso_code, active]']);
                $languages = $xml->languages;
                foreach ($languages->children() as $language){
                    $xml = $webServiceShop->get([   'resource'  =>  'products',
                                                    'display'   =>  '[id,active,reference,price,date_upd,name,description,description_short,id_tax_rules_group,id_default_image]',
                                                    'language'  =>  (int)$language->id]);
                    $products = $xml->products;
                    foreach ($products->children() as $product){
                        if ((int)$product->active == 1){
                            $xml = $webServiceShop->get([   'resource'              =>  'combinations',
                                                            'display'               =>  'full',
                                                            'filter[id_product]'    =>  (int)$product->id,
                                                            'language'  =>  (int)$language->id]);
                            $combinations = $xml->combinations->children();

                            if(count($combinations)>0){
                                foreach ($combinations as $combination){

                                    if (!isset($taxProduct[(int)$product->id_tax_rules_group] )){
                                        $xml = $webServiceShop->get([   'resource'                      =>  'tax_rules',
                                                                        'display'                       =>  '[id,id_tax]',
                                                                        'filter[id_tax_rules_group]'   =>  (int)$product->id_tax_rules_group]);
                                        $taxRules = $xml->tax_rules;
                                        $taxRule = $taxRules->children()[0]; //TODO IMPLEMENT MULTIPLE TAX OR DEFAULT

                                        $xml = $webServiceShop->get([   'resource'    =>  'taxes',
                                                                        'display'     =>  '[id,rate]',
                                                                        'filter[id]'  =>  (int)$taxRule->id_tax]);
                                        $taxes = $xml->taxes;
                                        $tax = $taxes->children()[0];
                                        $taxProduct[(int)$product->id_tax_rules_group] = $tax->rate;
                                    }

                                    $msg = ' id:' .$product->id . ' combID: ' . $combination->id . ' lang: '. $language->iso_code . ' Shop: ' . $shop->name;
                                    $output->writeln($product->name );
                                    $output->writeln($msg );
                                    $output->writeln(((float)$product->price +  (float)$combination->price));
                                    $output->writeln('');

                                    $entity = $this->productModel->getProductById((int)$product->id, (int)$shop->id, (int)$combination->id, $language->iso_code);
                                    if (empty($entity)){
                                        $entity = $this->productModel->getEntity();
                                    }
                                    else{
                                        $entity = $this->productModel->getEntity((int)$entity[0]['id']);
                                    }

                                    $url=(int)$combination->associations->images->image[0]->id != 0?
                                        (string)$combination->associations->images->image[0]->attributes('xlink', true)['href']:
                                        (string)$product->id_default_image->attributes('xlink', true)['href'];

                                    $url = str_replace('http://','http://FHKPQAGH8S8FRY9Q7GTDI8V21781QRED@',$url);

                                    $file_name =  __DIR__ .'/../Assets/img/products/' . $product->id . '-' . $combination->id . '-' . $shop->id . '-' . $language->iso_code . '.jpg';

                                    if(file_put_contents( $file_name , file_get_contents($url))) {
                                        $output->writeln("File downloaded successfully");
                                    }
                                    else {
                                        $output->writeln("File downloading failed");
                                        dump((int)$combination->associations->images->image[0]->id);
                                        dump($url);
                                        return 0;
                                    }

                                    $productAttributeUrl = (int)$combination->id > 0 ? '&id_product_attribute=' . $combination->id : '';
                                    $entity->setName((string)$product->name->language);
                                    $entity->setShortDescription((string)$product->description_short->language);
                                    $entity->setLongDescription((string)$product->description->language);
                                    $entity->setProductId((int)$product->id);
                                    $entity->setShopId((int)$shop->id);
                                    $entity->setProductAttributeId((int)$combination->id);
                                    $entity->setPrice((float)$product->price + (float)$combination->price);
                                    $entity->setLanguage($language->iso_code);
                                    $entity->setReference($combination->reference?$combination->reference : $product->reference);
                                    $entity->setTaxPercent($taxProduct[(int)$product->id_tax_rules_group]);
                                    $entity->setUrl($shop_url . 'index.php?controller=product&id_product=' . $product->id . $productAttributeUrl);
                                    $entity->setImageUrl($product->id . '-' . $combination->id . '-' . $shop->id . '-' . $language->iso_code . '.jpg');
                                    $this->productModel->saveEntity($entity);
                                }
                            }
                            else{
                                if (!isset($taxProduct[(int)$product->id_tax_rules_group] )){
                                    $xml = $webServiceShop->get([   'resource'                      =>  'tax_rules',
                                                                    'display'                       =>  '[id,id_tax]',
                                                                    'filter[id_tax_rules_group]'   =>  (int)$product->id_tax_rules_group]);
                                    $taxRules = $xml->tax_rules;
                                    $taxRule = $taxRules->children()[0]; //TODO IMPLEMENT MULTIPLE TAX OR DEFAULT

                                    $xml = $webServiceShop->get([   'resource'    =>  'taxes',
                                        'display'     =>  '[id,rate]',
                                        'filter[id]'  =>  (int)$taxRule->id_tax]);
                                    $taxes = $xml->taxes;
                                    $tax = $taxes->children()[0];
                                    $taxProduct[(int)$product->id_tax_rules_group] = $tax->rate;
                                }

                                $msg = ' id:' .$product->id . ' combID: ' . '0' . ' lang: '. $language->iso_code . ' Shop: ' . $shop->name;
                                $output->writeln($product->name );
                                $output->writeln($msg);
                                $output->writeln((float)$product->price);
                                $output->writeln('');

                                $entity = $this->productModel->getProductById((int)$product->id, (int)$shop->id, 0, $language->iso_code);
                                if (empty($entity)){
                                    $entity = $this->productModel->getEntity();
                                }
                                else{
                                    $entity = $this->productModel->getEntity((int)$entity[0]['id']);
                                }

                                $url=(string)$product->id_default_image->attributes('xlink', true)['href'];

                                $url = str_replace('http://','http://FHKPQAGH8S8FRY9Q7GTDI8V21781QRED@',$url);

                                $file_name =  __DIR__ .'/../Assets/img/products/' . $product->id . '-' . '0' . '-' . $shop->id . '-' . $language->iso_code . '.jpg';

                                if(file_put_contents( $file_name , file_get_contents($url))) {
                                    $output->writeln("File downloaded successfully");
                                }
                                else {
                                    $output->writeln("File downloading failed");
                                    dump((int)$product->id_default_image);
                                    dump($url);
                                    return 0;
                                }


                                $entity->setName((string)$product->name->language);
                                $entity->setShortDescription((string)$product->description_short->language);
                                $entity->setLongDescription((string)$product->description->language);
                                $entity->setProductId((int)$product->id);
                                $entity->setShopId((int)$shop->id);
                                $entity->setProductAttributeId(0);
                                $entity->setPrice((float)$product->price);
                                $entity->setLanguage($language->iso_code);
                                $entity->setReference($product->reference);
                                $entity->setTaxPercent($taxProduct[(int)$product->id_tax_rules_group]);
                                $entity->setUrl($shop_url . 'index.php?controller=product&id_product=' . $product->id);
                                $entity->setImageUrl($product->id . '-' . '0' . '-' . $shop->id . '-' . $language->iso_code . '.jpg');
                                $this->productModel->saveEntity($entity);
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

