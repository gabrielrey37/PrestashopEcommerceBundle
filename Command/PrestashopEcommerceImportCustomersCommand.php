<?php

namespace MauticPlugin\PrestashopEcommerceBundle\Command;

use Mautic\CampaignBundle\Command\WriteCountTrait;
use Mautic\CampaignBundle\Executioner\ScheduledExecutioner;
use Mautic\CoreBundle\Templating\Helper\FormatterHelper;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\PrestashopEcommerceBundle\Integration\PrestashopEcommerceIntegration;
use MauticPlugin\PrestashopEcommerceBundle\Services\PrestaShopWebserviceException;
use MauticPlugin\PrestashopEcommerceBundle\Services\PrestaShopWebservice;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Translation\TranslatorInterface;



class PrestashopEcommerceImportCustomersCommand extends Command
{
    use WriteCountTrait;

    private $scheduledExecutioner;

    private $translator;

    private $formatterHelper;

    private $cartModel;

    private $leadModel;

    private $prestashopEcommerceIntegration;

    private $integrationHelper;

    public function __construct(ScheduledExecutioner $scheduledExecutioner, TranslatorInterface $translator, FormatterHelper $formatterHelper, LeadModel $leadModel, PrestashopEcommerceIntegration $prestashopEcommerceIntegration, IntegrationHelper $integrationHelper)
    {
        parent::__construct();
        $this->scheduledExecutioner = $scheduledExecutioner;
        $this->translator           = $translator;
        $this->formatterHelper      = $formatterHelper;
        $this->leadModel            = $leadModel;
        $this->prestashopEcommerceIntegration = $prestashopEcommerceIntegration;
        $this->integrationHelper    = $integrationHelper;
    }

    protected function configure()
    {
        $this
            ->setName('mautic:prestashopecommerce:importcustomers')
            ->setDescription('Import carts from prestashop API');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('');
        $output->writeln('Importing Customers');

        $api = $this->prestashopEcommerceIntegration->decryptApiKeys($this->integrationHelper->getIntegrationObject('PrestashopEcommerce')->getIntegrationSettings()->getApiKeys());

        try {
            $webService = new PrestaShopWebservice($api['apiUrl'], $api['apiKey'], false);

            $xml = $webService->get([   'resource'  =>  'shops',
                                        'display'   =>  '[id,name]',
                                        'filter[active]'  => '1']);
            $shops = $xml->shops;


            foreach ($shops->children() as $shop){
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

                dump($shop_url);
                $webServiceShop = new PrestaShopWebservice($shop_url, $api['apiKey'], false);

                $xml = $webServiceShop->get([   'resource'  =>  'customers',
                                                'display'   =>  'full']);
                $customers = $xml->customers;

                foreach ($customers->children() as $customer){
                    //dump($customer);
                    $lead = $this->leadModel->getRepository()->getLeadsByFieldValue(
                        'customerid' , (int)$customer->id
                    );

                    if ($lead){
                        $lead = array_values($lead)[0];
                    }
                    else
                        {
                        $lead = $this->leadModel->getEntity();
                        $lead->__set('customerid',(int)$customer->id);
                        $lead->setEmail($customer->email);
                    }
                    $lead->setFirstname($customer->firstname);
                    $lead->setLastname($customer->lastname);

                    $this->leadModel->saveEntity($lead);
                }

                $xml = $webServiceShop->get([   'resource'  =>  'guests',
                                                'display'   =>  'full']);
                $guests = $xml->guests;

                foreach ($guests->children() as $guest){
                    //dump($customer);
                    $lead = $this->leadModel->getRepository()->getLeadsByFieldValue(
                        'guestid' , (int)$guest->id
                    );

                    if ($lead){
                        $lead = array_values($lead)[0];
                    }
                    else
                    {
                        $lead = $this->leadModel->getEntity();
                        $lead->__set('guestid',(int)$guest->id);
                    }
                    $lead->setFirstname('Guest');
                    $this->leadModel->saveEntity($lead);
                }
            }
        } catch (PrestaShopWebserviceException $ex) {
            // Shows a message related to the error
            $output->writeln($ex->getMessage());
        }
        return 0;
    }
}

