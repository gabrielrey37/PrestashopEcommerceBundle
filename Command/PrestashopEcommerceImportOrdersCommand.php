<?php

namespace MauticPlugin\PrestashopEcommerceBundle\Command;

use Mautic\CampaignBundle\Command\WriteCountTrait;
use Mautic\CampaignBundle\Executioner\ScheduledExecutioner;
use Mautic\CoreBundle\Templating\Helper\FormatterHelper;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\EcommerceBundle\Entity\CartLine;
use MauticPlugin\EcommerceBundle\Entity\OrderRow;
use MauticPlugin\EcommerceBundle\Model\CartModel;
use MauticPlugin\EcommerceBundle\Model\OrderModel;
use MauticPlugin\EcommerceBundle\Model\ProductModel;
use MauticPlugin\PrestashopEcommerceBundle\Integration\PrestashopEcommerceIntegration;
use MauticPlugin\PrestashopEcommerceBundle\Services\PrestaShopWebserviceException;
use MauticPlugin\PrestashopEcommerceBundle\Services\PrestaShopWebservice;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Translation\TranslatorInterface;



class PrestashopEcommerceImportOrdersCommand extends Command
{
    use WriteCountTrait;

    private $scheduledExecutioner;

    private $translator;

    private $formatterHelper;

    private $orderModel;

    private $productModel;

    private $leadModel;

    private $cartModel;

    private $prestashopEcommerceIntegration;

    private $integrationHelper;

    public function __construct(ScheduledExecutioner $scheduledExecutioner, TranslatorInterface $translator, FormatterHelper $formatterHelper, CartModel $cartModel,OrderModel $orderModel, ProductModel $productModel, LeadModel $leadModel, PrestashopEcommerceIntegration $prestashopEcommerceIntegration, IntegrationHelper $integrationHelper)
    {
        parent::__construct();
        $this->scheduledExecutioner = $scheduledExecutioner;
        $this->translator           = $translator;
        $this->formatterHelper      = $formatterHelper;
        $this->cartModel            = $cartModel;
        $this->orderModel           = $orderModel;
        $this->productModel         = $productModel;
        $this->leadModel            = $leadModel;
        $this->prestashopEcommerceIntegration = $prestashopEcommerceIntegration;
        $this->integrationHelper    = $integrationHelper;
    }

    protected function configure()
    {
        $this
            ->setName('mautic:prestashopecommerce:importOrders')
            ->setDescription('Import orders from prestashop API')->addOption(
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
        $output->writeln('Importing Orders');

        $api = $this->prestashopEcommerceIntegration->decryptApiKeys($this->integrationHelper->getIntegrationObject('PrestashopEcommerce')->getIntegrationSettings()->getApiKeys());


        $ordersCount = [];
        $ordersCountType = '';


        try {
            $webService = new PrestaShopWebservice($api['apiUrl'], $api['apiKey'], false);

            $xml = $webService->get([   'resource'  =>  'shops',
                                        'display'   =>  '[id,name]',
                                        'filter[active]'  => '1']);
            $shops = $xml->shops;


            foreach ($shops->children() as $shop){
                $ordersCount['Created'] = 0;
                $ordersCount['Updated'] = 0;
                $ordersCount['Deleted'] = 0;
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

                $xml = $webServiceShop->get([   'resource'  =>  'orders',
                                                'display'   =>  'full',
                                                'filter[id_shop]'   => $shopId]);
                $orders = $xml->orders;

                $ordersToRemove = array_column($this->orderModel->getRepository()->getEntitiesIds($shopId), 'id');

                foreach ($orders->children() as $order){

                    $entity = $this->orderModel->getOrderById((int)$order->id, (int)$order->id_shop);

                    if (empty($entity)){
                        $entity = $this->orderModel->getEntity();
                        $ordersCountType = 'Created';
                    }
                    else{
                        $ordersToRemove = array_diff($ordersToRemove , [(int)$entity[0]['id']]);
                        $entity = $this->orderModel->getEntity((int)$entity[0]['id']);
                        $ordersCountType = 'Updated';
                    }

                    if ($input->getOption('full') or ($entity->getDateModified() < new \Datetime($order->date_upd))){
                        if ((int)$order->id_customer > 0){
                            $lead = $this->leadModel->getRepository()->getLeadsByFieldValue(
                                'customerid' , (int)$order->id_customer
                            );
                        }
                        else{
                            $lead = $this->leadModel->getRepository()->getLeadsByFieldValue(
                                'guestid' , (int)$order->id_guest
                            );
                        }

                        if ($lead){
                            $lead = array_values($lead)[0];
                            $entity->setLead($lead);
                        }

                        $cart = $this->cartModel->getRepository()->findOneBy([
                            'shopId'                =>  (int)$order->id_shop,
                            'cartId'                =>  (int)$order->id_cart,
                        ]);
                        if ($cart){
                            $entity->setCartId($cart);
                        }

                        $entity->setShopId($order->id_shop);
                        $entity->setOrderId($order->id);
                        $entity->setCarrierId($order->id_carrier);
                        $entity->setAddressDeliveryId($order->id_address_delivery);
                        $entity->setAddressInvoiceId($order->id_address_invoice);
                        $entity->setCurrentState($order->current_state);
                        $entity->setPayment($order->payment);
                        $entity->setReference($order->reference);
                        $entity->settotalDiscounts($order->total_discounts);
                        $entity->settotalDiscountsTaxIncl($order->total_discounts_tax_incl);
                        $entity->settotalDiscountsTaxExcl($order->total_discounts_tax_excl);
                        $entity->settotalPaid($order->total_paid);
                        $entity->settotalPaidTaxIncl($order->total_paid_tax_incl);
                        $entity->settotalPaidTaxExcl($order->total_paid_tax_excl);
                        $entity->settotalPaidReal($order->total_paid_real);
                        $entity->settotalProducts($order->total_products);
                        $entity->settotalProductsWt($order->total_products_wt);
                        $entity->settotalShipping($order->total_shipping);
                        $entity->settotalShippingTaxIncl($order->total_shipping_tax_incl);
                        $entity->setTotalShippingTaxExcl($order->total_shipping_tax_excl);
                        $entity->setGift($order->gift);
                        $entity->setDateModified(new \Datetime());
                        $ordersCount[$ordersCountType] = $ordersCount[$ordersCountType] + 1;
                        $this->orderModel->saveEntity($entity);

                        $orderLinesToRemove = array_column($this->orderModel->getOrderRowRepository()->getEntitiesIds($entity), 'id');

                        foreach ($order->associations->order_rows->children() as $order_row){
                            $product = $this->productModel->getRepository()->findOneBy([
                                'productId'             =>  (int)$order_row->product_id,
                                'shopId'                =>  (int)$order->id_shop,
                                'productAttributeId'    =>  (int)$order_row->product_attribute_id,
                                'language'              =>  $languages[(int)$order->id_lang]]);

                            if ($product){
                                $orderLineRepo = $this->orderModel->getOrderRowRepository();
                                $orderLine = $orderLineRepo->findOneBy(
                                    [
                                        'order'               => $entity,
                                        'product'             => $product,
                                    ]
                                );
                                if (!$orderLine){
                                    $orderLine = new OrderRow();
                                    $orderLine->setDateAdd(new \Datetime());
                                }
                                $orderLine->setOrder($entity);
                                $orderLine->setProduct($product);
                                $orderLine->setProductQuantity((int)$order_row->product_quantity);
                                $orderLine->setProductPrice($order_row->product_price);
                                $orderLine->setUnitPriceTaxExcl($order_row->unit_price_tax_excl);
                                $orderLine->setUnitPriceTaxIncl($order_row->unit_price_tax_incl);

                                $orderLine->setDateUpd(new \Datetime());
                                $entity->addOrderRow($orderLine);
                                $this->orderModel->saveEntity($orderLine);
                                $this->orderModel->saveEntity($entity);
                                $orderLinesToRemove = array_diff($orderLinesToRemove , [$orderLine->getId()]);
                            }
                            else{
                                dump('NO PRODUCT');
                            }
                        }

                        foreach ($orderLinesToRemove as $orderLineToRemove){
                            $entityRemove =  $this->orderModel->getOrderRowRepository()->getEntity((int)$orderLineToRemove);
                            $this->orderModel->getOrderRowRepository()->deleteEntity($entityRemove);
                        }
                    }
                }
                foreach ($ordersToRemove as $orderToRemove){
                    $entityRemove =  $this->orderModel->getRepository()->getEntity((int)$orderToRemove);
                    $this->orderModel->getOrderRowRepository()->deleteEntity($entityRemove);
                }
                $ordersCount['Deleted'] = count($ordersToRemove);

                $output->writeln('');
                $output->writeln('Shop ' . $shop->name);

                foreach ($ordersCount as $key => $value){
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

