<?php

namespace MauticPlugin\PrestashopEcommerceBundle\Command;

use Mautic\CampaignBundle\Command\WriteCountTrait;
use Mautic\CampaignBundle\Executioner\ScheduledExecutioner;
use Mautic\CoreBundle\Templating\Helper\FormatterHelper;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\EcommerceBundle\Entity\CartLine;
use MauticPlugin\EcommerceBundle\Model\CartModel;
use MauticPlugin\EcommerceBundle\Model\ProductModel;
use MauticPlugin\PrestashopEcommerceBundle\Integration\PrestashopEcommerceIntegration;
use MauticPlugin\PrestashopEcommerceBundle\Services\PrestaShopWebserviceException;
use MauticPlugin\PrestashopEcommerceBundle\Services\PrestaShopWebservice;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Translation\TranslatorInterface;



class PrestashopEcommerceImportCartsCommand extends Command
{
    use WriteCountTrait;

    private $scheduledExecutioner;

    private $translator;

    private $formatterHelper;

    private $cartModel;

    private $productModel;

    private $leadModel;

    private $prestashopEcommerceIntegration;

    private $integrationHelper;

    public function __construct(ScheduledExecutioner $scheduledExecutioner, TranslatorInterface $translator, FormatterHelper $formatterHelper, CartModel $cartModel, ProductModel $productModel, LeadModel $leadModel, PrestashopEcommerceIntegration $prestashopEcommerceIntegration, IntegrationHelper $integrationHelper)
    {
        parent::__construct();
        $this->scheduledExecutioner = $scheduledExecutioner;
        $this->translator           = $translator;
        $this->formatterHelper      = $formatterHelper;
        $this->cartModel            = $cartModel;
        $this->productModel         = $productModel;
        $this->leadModel            = $leadModel;
        $this->prestashopEcommerceIntegration = $prestashopEcommerceIntegration;
        $this->integrationHelper    = $integrationHelper;
    }

    protected function configure()
    {
        $this
            ->setName('mautic:prestashopecommerce:importcarts')
            ->setDescription('Import carts from prestashop API')->addOption(
                '--full',
                null,
                InputOption::VALUE_NONE,
                'Full Import'
            );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        date_default_timezone_set("America/Argentina/Buenos_Aires"); //TODO

        $output->writeln('');
        $output->writeln('Importing Carts');

        $api = $this->prestashopEcommerceIntegration->decryptApiKeys($this->integrationHelper->getIntegrationObject('PrestashopEcommerce')->getIntegrationSettings()->getApiKeys());


        $cartsCount = [];
        $cartsCountType = '';


        try {
            $webService = new PrestaShopWebservice($api['apiUrl'], $api['apiKey'], false);

            $xml = $webService->get([   'resource'  =>  'shops',
                                        'display'   =>  '[id,name]',
                                        'filter[active]'  => '1']);
            $shops = $xml->shops;


            foreach ($shops->children() as $shop){
                $cartsCount['Created'] = 0;
                $cartsCount['Updated'] = 0;
                $cartsCount['Deleted'] = 0;
                //$taxProduct = [];
                $xml = $webService->get([   'resource'          =>  'languages',
                    'display'           =>  '[id,iso_code]',]);
                $languages = [];
                foreach ($xml->languages->children() as $language){
                    $languages[(int)$language->id] =$language->iso_code;
                }

                $shopId = (int)$shop->id;
                $xml = $webService->get([   'resource'          =>  'shop_urls',
                                            'display'           =>  '[domain,physical_uri,virtual_uri]',
                                            'filter[active]'    => '1',
                                            'filter[main]'      => '1',
                                            'filter[id_shop]'   => $shopId]);
                $shop_url = $xml->shop_urls->shop_url;
                $shop_url ='http://' . $shop_url->domain . $shop_url->physical_uri . $shop_url->virtual_uri;

                $webServiceShop = new PrestaShopWebservice($shop_url, $api['apiKey'], false);

                $xml = $webServiceShop->get([   'resource'  =>  'carts',
                                                'display'   =>  'full',
                                                'filter[id_shop]'   => $shopId]);
                $carts = $xml->carts;
                $cartsToRemove = array_column($this->cartModel->getRepository()->getEntitiesIds($shopId), 'id');


                foreach ($carts->children() as $cart){
                    $entity = $this->cartModel->getCartById((int)$cart->id, (int)$cart->id_shop);

                    if (empty($entity)){
                        $entity = $this->cartModel->getEntity();
                        $cartsCountType = 'Created';
                    }
                    else{
                        $cartsToRemove = array_diff($cartsToRemove , [(int)$entity[0]['id']]);
                        $entity = $this->cartModel->getEntity((int)$entity[0]['id']);
                        $cartsCountType = 'Updated';
                    }

                    if ($input->getOption('full') or ($entity->getDateModified() < new \Datetime($cart->date_upd))){
                        if ((int)$cart->id_customer > 0){
                            $lead = $this->leadModel->getRepository()->getLeadsByFieldValue(
                                'customerid' , (int)$cart->id_customer
                            );
                        }
                        else{
                            $lead = $this->leadModel->getRepository()->getLeadsByFieldValue(
                                'guestid' , (int)$cart->id_guest
                            );
                        }

                        if ($lead){
                            $lead = array_values($lead)[0];
                            $entity->setLead($lead);
                        }

                        $entity->setShopId($cart->id_shop);
                        $entity->setCartId($cart->id);
                        $entity->setCarrierId($cart->id_carrier);
                        $entity->setAddressDeliveryId($cart->id_address_delivery);
                        $entity->setAddressInvoiceId($cart->id_address_invoice);
                        $entity->setDateModified(new \Datetime());
                        $cartsCount[$cartsCountType] = $cartsCount[$cartsCountType] + 1;
                        $this->cartModel->saveEntity($entity);

                        $cartLinesToRemove = array_column($this->cartModel->getCartLineRepository()->getEntitiesIds($entity), 'id');

                        foreach ($cart->associations->cart_rows->children() as $cart_row){
                            $product = $this->productModel->getRepository()->findOneBy([
                                'productId'             =>  (int)$cart_row->id_product,
                                'shopId'                =>  (int)$cart->id_shop,
                                'productAttributeId'    =>  (int)$cart_row->id_product_attribute,
                                'language'              =>  $languages[(int)$cart->id_lang]]);

                            if ($product){
                                $cartLineRepo = $this->cartModel->getCartLineRepository();
                                $cartLine = $cartLineRepo->findOneBy(
                                    [
                                        'cart'                => $entity,
                                        'product'             => $product,
                                    ]
                                );
                                if (!$cartLine){
                                    $cartLine = new CartLine();
                                    $cartLine->setDateAdd(new \Datetime());
                                }
                                $cartLine->setCart($entity);
                                $cartLine->setProduct($product);
                                $cartLine->setQuantity((int)$cart_row->quantity);
                                $cartLine->setDateUpd(new \Datetime());
                                $entity->addCartLine($cartLine);
                                $this->cartModel->saveEntity($cartLine);
                                $this->cartModel->saveEntity($entity);
                                $cartLinesToRemove = array_diff($cartLinesToRemove , [$cartLine->getId()]);
                            }
                            else{
                                dump('NO PRODUCT');
                            }
                        }

                        foreach ($cartLinesToRemove as $cartLineToRemove){
                            $entityRemove =  $this->cartModel->getCartLineRepository()->getEntity((int)$cartLineToRemove);
                            $this->cartModel->getCartLineRepository()->deleteEntity($entityRemove);
                        }
                    }
                }
                foreach ($cartsToRemove as $cartToRemove){
                    $entityRemove =  $this->cartModel->getRepository()->getEntity((int)$cartToRemove);
                    $this->cartModel->getCartLineRepository()->deleteEntity($entityRemove);
                }
                $cartsCount['Deleted'] = count($cartsToRemove);

                $output->writeln('');
                $output->writeln('Shop ' . $shop->name);

                foreach ($cartsCount as $key => $value){
                    $output->writeln('Carts ' . $key . ' = ' . $value);
                }
            }
        } catch (PrestaShopWebserviceException $ex) {
            // Shows a message related to the error
            $output->writeln($ex->getMessage());
        }
        return 0;
    }
}

