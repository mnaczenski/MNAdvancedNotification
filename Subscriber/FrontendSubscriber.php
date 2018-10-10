<?php

namespace MNAdvancedNotification\Subscriber;

use Doctrine\Common\Collections\ArrayCollection;
use Enlight\Event\SubscriberInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;



class FrontendSubscriber implements SubscriberInterface
{
     /**
     * @var ContainerInterface
     */
    private $container;
    private $pluginDirectory;


    public function __construct(ContainerInterface $container, $pluginDirectory)
    {
        $this->container = $container;
        $this->pluginDirectory = $pluginDirectory;
    }


    public static function getSubscribedEvents()
    {
        return [
            'Enlight_Controller_Action_PostDispatch_Frontend_Detail' => 'onPostDispatch',
            'Enlight_Controller_Action_Frontend_Detail_Notify' => 'onNotifyAction',
            'Enlight_Controller_Action_Frontend_Detail_NotifyConfirm' => 'onNotifyConfirmAction'
        ];
    }


    public static function onPostDispatch(\Enlight_Controller_ActionEventArgs $args)
    {
        $request = $args->getSubject()->Request();
        $response = $args->getSubject()->Response();

        if (!$request->isDispatched() || $response->isException() || $request->getModuleName() != 'frontend') {
            return;
        }

        $id = (int) $args->getSubject()->Request()->sArticle;
        $view = $args->getSubject()->View();
        $notificationVariants = [];

        if (!empty(Shopware()->Session()->sNotificatedArticles)) {

            $sql = 'SELECT `ordernumber` FROM `s_articles_details` WHERE `articleID`=?';
            $ordernumbers = Shopware()->Db()->fetchCol($sql, $id);

            if (!empty($ordernumbers)) {

                foreach ($ordernumbers as $ordernumber) {

                    if (in_array($ordernumber, Shopware()->Session()->sNotificatedArticles)) {

                        $notificationVariants[] = $ordernumber;

                        if ($ordernumber === $view->sArticle['ordernumber']) {

                            $view->NotifyAlreadyRegistered = true;
                        }
                    }
                }
            }
        }

        $view->NotifyHideBasket = Shopware()->Config()->sDEACTIVATEBASKETONNOTIFICATION;
        $view->NotificationVariants = $notificationVariants;
        $view->ShowNotification = true;
        $view->WaitingForOptInApprovement = Shopware()->Session()->sNotifcationArticleWaitingForOptInApprovement[$view->sArticle['ordernumber']];
    }

    public static function onNotifyAction(\Enlight_Event_EventArgs $args)
    {
        $args->setProcessed(true);
        $action = $args->getSubject();
        $id = (int) $action->Request()->sArticle;
        $email = $action->Request()->sNotificationEmail;

        $sError = false;
        $action->View()->NotifyEmailError = false;
        $notifyOrderNumber = $action->Request()->notifyOrdernumber;

        if (!empty($notifyOrderNumber)) {

            $validator = Shopware()->Container()->get('validator.email');

            if (empty($email) || !$validator->isValid($email)) {

                $sError = true;
                $action->View()->NotifyEmailError = true;

            } elseif (!empty($notifyOrderNumber)) {

                if (!empty(Shopware()->Session()->sNotificatedArticles)) {

                    if (in_array($notifyOrderNumber, Shopware()->Session()->sNotificatedArticles)) {

                        $sError = true;
                        $action->View()->ShowNotification = false;
                        $action->View()->NotifyAlreadyRegistered = true;

                    } else {

                        Shopware()->Session()->sNotificatedArticles[] = $notifyOrderNumber;

                    }
                } else {

                    Shopware()->Session()->sNotificatedArticles = [$notifyOrderNumber];

                }

            } else {

                $sError = true;

            }
            if (!$sError) {

                $AlreadyNotified = Shopware()->Db()->fetchRow('
                    SELECT *  FROM `s_articles_notification`
                    WHERE `ordernumber`=?
                    AND `mail` = ?
                    AND send = 0
                ', [$notifyOrderNumber, $email]);

                if (empty($AlreadyNotified)) {

                    $action->View()->NotifyAlreadyRegistered = false;
                    $hash = \Shopware\Components\Random::getAlphanumericString(32);
                    $link = $action->Front()->Router()->assemble([
                        'sViewport' => 'detail',
                        'sArticle' => $id,
                        'sNotificationConfirmation' => $hash,
                        'sNotify' => '1',
                        'action' => 'notifyConfirm',
                        'number' => $notifyOrderNumber,
                    ]);

                    $name = Shopware()->Modules()->Articles()->sGetArticleNameByOrderNumber($notifyOrderNumber);
                    $basePath = $action->Front()->Router()->assemble(['sViewport' => 'index']);
                    Shopware()->System()->_POST['sLanguage'] = Shopware()->Shop()->getId();
                    Shopware()->System()->_POST['sShopPath'] = $basePath . Shopware()->Config()->sBASEFILE;

                    $sql = '
                        INSERT INTO s_core_optin (datum, hash, data, type)
                        VALUES (NOW(), ?, ?, "swNotification")
                    ';

                    Shopware()->Db()->query($sql, [$hash, serialize(Shopware()->System()->_POST->toArray())]);

                    $context = [
                        'sConfirmLink' => $link,
                        'sArticleName' => $name,
                    ];

                    $mail = Shopware()->TemplateMail()->createMail('sACCEPTNOTIFICATION', $context);
                    $mail->addTo($email);
                    $mail->send();

                    Shopware()->Session()->sNotifcationArticleWaitingForOptInApprovement[$notifyOrderNumber] = true;
                } else {

                    $action->View()->NotifyAlreadyRegistered = true;

                }
            }
        }
        return $action->forward('index');
    }
 
    public static function onNotifyConfirmAction(\Enlight_Event_EventArgs $args)
    {
        $args->setProcessed(true);
        $action = $args->getSubject();
        $action->View()->NotifyValid = false;
        $action->View()->NotifyInvalid = false;

        if (!empty($action->Request()->sNotificationConfirmation) && !empty($action->Request()->sNotify)) {

            $getConfirmation = Shopware()->Db()->fetchRow('
            SELECT * FROM s_core_optin WHERE hash = ?
            ', [$action->Request()->sNotificationConfirmation]);

            $notificationConfirmed = false;

            if (!empty($getConfirmation['hash'])) {

                $notificationConfirmed = true;
                $json_data = unserialize($getConfirmation['data']);
                Shopware()->Db()->query('DELETE FROM s_core_optin WHERE hash=?', [$action->Request()->sNotificationConfirmation]);

            }
            if ($notificationConfirmed) {

                $sql = '
                    INSERT INTO `s_articles_notification` (
                        `ordernumber` ,
                        `date` ,
                        `mail` ,
                        `language` ,
                        `shopLink` ,
                        `send`
                    )
                    VALUES (
                        ?, NOW(), ?, ?, ?, 0
                    );
                ';

                Shopware()->Db()->query($sql, [
                    $json_data['notifyOrdernumber'],
                    $json_data['sNotificationEmail'],
                    $json_data['sLanguage'],
                    $json_data['sShopPath'],
                ]);

                $action->View()->NotifyValid = true;
                Shopware()->Session()->sNotifcationArticleWaitingForOptInApprovement[$json_data['notifyOrdernumber']] = false;

            } else {

                $action->View()->NotifyInvalid = true;
            }
        }

        return $action->forward('index');
    }
}