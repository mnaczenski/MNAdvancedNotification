<?php

namespace MNAdvancedNotification\Subscriber;

use Doctrine\Common\Collections\ArrayCollection;
use Enlight\Event\SubscriberInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Shopware\Components\Routing\Context;



class CronSubscriber implements SubscriberInterface
{
     /**
     * @var ContainerInterface
     */
    private $container;


    public function __construct(ContainerInterface $container, $pluginDirectory)
    {
        $this->container = $container;
    }


    public static function getSubscribedEvents()
    {
        return [
            'Shopware_CronJob_Notification' => 'onRunCronJob',
        ];
    }

    public static function onRunCronJob(\Shopware_Components_Cron_CronJob $job)
    {
        $modelManager = Shopware()->Container()->get('models');
        $conn = Shopware()->Container()->get('dbal_connection');

        $notifications = $conn->createQueryBuilder()
            ->select(
                'n.ordernumber',
                'n.mail',
                'n.language'
            )
            ->from('s_articles_notification n')
            ->where('send=:send')
            ->setParameter('send', 0)
            ->execute()
            ->fetchAll();

        foreach ($notifications as $notify) {
            /* @var $shop \Shopware\Models\Shop\Shop */
            $shop = $modelManager->getRepository(\Shopware\Models\Shop\Shop::class)->getActiveById($notify['language']);
            $shop->registerResources();
            $shopContext = Context::createFromShop($shop, Shopware()->Container()->get('config'));
            $sContext = Shopware()->Container()->get('shopware_storefront.context_service')->createShopContext($notify['language']);
            Shopware()->Container()->get('router')->setContext($shopContext);


            $product_information = Shopware()->Container()->get('shopware_storefront.list_product_service')->get($notify['ordernumber'], $sContext);
            $product_information = Shopware()->Container()->get('legacy_struct_converter')->convertListProductStruct($product_information);

            $product = $conn->createQueryBuilder()
                ->select(
                    'a.id AS articleID',
                    'a.active',
                    'a.notification',
                    'd.ordernumber',
                    'd.minpurchase',
                    'd.instock',
                    'd.laststock'
                )
                ->from('s_articles_details', 'd')
                ->innerJoin('d', 's_articles', 'a', 'd.articleID = a.id')
                ->where('d.ordernumber = :number')
                ->andWhere('d.instock > 0')
                ->andWhere('d.minpurchase <= d.instock')
                ->setParameter('number', $notify['ordernumber'])
                ->execute()
                ->fetch(\PDO::FETCH_ASSOC);
            if (
                empty($product) || //No product associated with the specified order number (empty result set)
                empty($product['articleID']) || // or empty articleID
                empty($product['notification']) || // or notification disabled on product
                empty($product['active']) // or product is not active
            ) {
                continue;
            }

            $link = Shopware()->Front()->Router()->assemble([
                'sViewport' => 'detail',
                'sArticle' => $product['articleID'],
                'number' => $product['ordernumber'],
            ]);

            $context = [
                'sArticleLink' => $link,
                'sOrdernumber' => $notify['ordernumber'],
                'sData' => $job['data'],
                'product' => $product_information
            ];

            $mail = Shopware()->TemplateMail()->createMail('sARTICLEAVAILABLE', $context);
            $mail->addTo($notify['mail']);
            $mail->send();

            //Set notification to already sent
            $conn->update(
                's_articles_notification',
                ['send' => 1],
                ['orderNumber' => $notify['ordernumber']]
            );
        }
    }
}