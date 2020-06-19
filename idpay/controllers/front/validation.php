<?php
/**
 * IDPay - A Sample Payment Module for PrestaShop 1.7
 *
 * Order Validation Controller
 *
 * @author Andresa Martins <contact@andresa.dev>
 * @license https://opensource.org/licenses/afl-3.0.php
 */

class IDPayValidationModuleFrontController extends ModuleFrontController
{
    /** @var array Controller errors */
    public $errors = [];

    /** @var array Controller warning notifications */
    public $warning = [];

    /** @var array Controller success notifications */
    public $success = [];

    /** @var array Controller info notifications */
    public $info = [];


    /**
     * set notifications on SESSION
     */
    public function notification()
    {

        $notifications = json_encode([
            'error' => $this->errors,
            'warning' => $this->warning,
            'success' => $this->success,
            'info' => $this->info,
        ]);

        if (session_status() == PHP_SESSION_ACTIVE) {
            $_SESSION['notifications'] = $notifications;
        } elseif (session_status() == PHP_SESSION_NONE) {
            session_start();
            $_SESSION['notifications'] = $notifications;
        } else {
            setcookie('notifications', $notifications);
        }


    }


    /**
     * Processa os dados enviados pelo formulário de pagamento
     */
    public function postProcess()
    {

        /**
         * Get current cart object from session
         */
        $cart = $this->context->cart;


        /** @var CustomerCore $customer */
        $customer = new Customer($cart->id_customer);


        /**
         * Verify if this module is enabled and if the cart has
         * a valid customer, delivery address and invoice address
         */
        if (!$this->module->active || $cart->id_customer == 0 || $cart->id_address_delivery == 0
            || $cart->id_address_invoice == 0) {
            Tools::redirect('index.php?controller=order&step=1');
        }


        /**
         * Verify if this payment module is authorized
         */
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'idpay') {
                $authorized = true;
                break;
            }
        }


        if (!$authorized) {

            $this->errors[] = 'This payment method is not available.';
            $this->notification();
            /**
             * Redirect the customer to the order confirmation page
             */
            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . (int)$cart->id . '&id_module=' . (int)$this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);

        }


        /**
         * Check if this is a vlaid customer account
         */
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }


        $authorized = false;


        if (isset($_GET['do'])) {

            $this->callBack($cart, $customer);

        }


        $api_key = Configuration::get('idpay_api_key');
        $sandbox = Configuration::get('idpay_sandbox') == 'yes' ? 'true' : 'false';
        $amount = $cart->getOrderTotal();

        if (Configuration::get('idpay_currency') == "toman") {
            $amount *= 10;
        }

        // Customer information
        $details = $cart->getSummaryDetails();
        $delivery = $details['delivery'];
        $name = $delivery->firstname . ' ' . $delivery->lastname;
        $phone = $delivery->phone_mobile;

        if (empty($phone_mobile)) {
            $phone = $delivery->phone;
        }
        // There is not any email field in the cart details.
        // So we gather the customer email from this line of code:
        $mail = Context::getContext()->customer->email;
        $desc = $Description = 'پرداخت سفارش شماره: ' . $cart->id;


        $url = $this->context->link->getModuleLink('idpay', 'validation', array(), true);
        $callback = $url . '?do=callback&hash=' . md5($amount . $cart->id . Configuration::get('idpay_HASH_KEY'));


        if (empty($amount)) {

            $this->errors[] = $this->otherStatusMessages(404);
            $this->notification();
            /**
             * Redirect the customer to the order confirmation page
             */
            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . (int)$cart->id . '&id_module=' . (int)$this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);

        }


        $data = array(
            'order_id' => $cart->id,
            'amount' => $amount,
            'name' => $name,
            'phone' => $phone,
            'mail' => $mail,
            'desc' => $desc,
            'callback' => $callback,
        );



        $ch = curl_init('https://api.idpay.ir/v1.1/payment');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'X-API-KEY:' . $api_key,
            'X-SANDBOX:' . $sandbox,
        ));

        $result = curl_exec($ch);
        $result = json_decode($result);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);



        $sql='UPDATE `'._DB_PREFIX_.'cart`
        SET idpay_id = "'."$result->id".
        '" WHERE id_cart = "'. $cart->id .'"'
         ;
        Db::getInstance()->Execute($sql);


        if ($http_status != 201 || empty($result) || empty($result->id) || empty($result->link)) {
            $this->errors[] = sprintf('خطا هنگام ایجاد تراکنش. وضعیت خطا: %s - کد خطا: %s - پیام خطا: %s', $http_status, $result->error_code, $result->error_message);
            $this->notification();
            /**
             * Redirect the customer to the order confirmation page
             */
            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . (int)$cart->id . '&id_module=' . (int)$this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);

        } else {
            Tools::redirect($result->link);
            exit;
        }

    }


    public function callBack($cart, $customer)
    {


        if (!empty($_POST['id']) && !empty($_POST['order_id']) && !empty($_POST['amount']) && !empty($_POST['status'])) {


            $pid = $_POST['id'];
            $orderid = $_POST['order_id'];
            $status = $_POST['status'];
            $track_id = $_POST['track_id'];
            $order_id = $_POST['order_id'];
            $amount = $cart->getOrderTotal();

            if (Configuration::get('idpay_currency') == "toman") {
                $amount *= 10;
            }
            if (!empty($pid) && !empty($orderid) && md5($amount . $orderid . Configuration::get('idpay_HASH_KEY')) == $_GET['hash']) {
                if ($_POST['status'] == 10) {

                    $api_key = Configuration::get('idpay_api_key');
                    $sandbox = Configuration::get('idpay_sandbox') == 'yes' ? 'true' : 'false';

                    $data = array(
                        'id' => $pid,
                        'order_id' => $orderid,
                    );


                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, 'https://api.idpay.ir/v1.1/payment/verify');
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                        'Content-Type: application/json',
                        'X-API-KEY:' . $api_key,
                        'X-SANDBOX:' . $sandbox,
                    ));

                    $result = curl_exec($ch);
                    $result = json_decode($result);
                    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);


                    if ($http_status != 200) {
                        $msg = sprintf('خطا هنگام ایجاد تراکنش. وضعیت خطا: %s - کد خطا: %s - پیام خطا: %s', $http_status, $result->error_code, $result->error_message);
                        $this->errors[] = $msg;
                        $this->notification();
                        $this->saveOrder($customer, $msg, 8);
                        /**
                         * Redirect the customer to the order confirmation page
                         */
                        Tools::redirect('index.php?controller=order-confirmation&id_cart=' . (int)$cart->id . '&id_module=' . (int)$this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);


                    } else {
                        $verify_status = empty($result->status) ? NULL : $result->status;
                        $verify_track_id = empty($result->track_id) ? NULL : $result->track_id;
                        $verify_order_id = empty($result->order_id) ? NULL : $result->order_id;
                        $verify_amount = empty($result->amount) ? NULL : $result->amount;
                        $hashed_card_no = empty($result->payment->hashed_card_no) ? NULL : $result->payment->hashed_card_no;
                        $card_no = empty($result->payment->hashed_card_no) ? NULL : $result->payment->hashed_card_no;

                        if (empty($verify_status) || empty($verify_track_id) || empty($verify_amount) || $verify_status < 100 || $verify_order_id !== $orderid) {

                            //generate msg and save to database as order
                            $msgForSaveDataTDataBase = $this->otherStatusMessages(1000) . "کد پیگیری :  $verify_track_id " . "شماره کارت :  $card_no " . "شماره کارت رمزنگاری شده : $hashed_card_no ";
                            $this->saveOrder($customer, $msgForSaveDataTDataBase, Configuration::get('PS_OS_PAYMENT'));
                            $msg = $this->idpay_get_failed_message($verify_track_id, $verify_order_id, 1000);
                            $this->errors[] = $msg;
                            $this->notification();
                            /**
                             * Redirect the customer to the order confirmation page
                             */
                            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . (int)$cart->id . '&id_module=' . (int)$this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);

                        } else {


                            //check double spending
                            $sql = '
                              SELECT idpay_id FROM `'._DB_PREFIX_.'cart`
                               WHERE id_cart  = "'. $cart->id .'"
                               AND idpay_id   = "'. $result->id .'"'
                               ;
                            $exist=Db::getInstance()->execute($sql);

                            if ((int)$verify_order_id !== $cart->id or !$exist ) {
                                $msgForSaveDataTDataBase = $this->otherStatusMessages(0) . "کد پیگیری :  $verify_track_id " . "شماره کارت :  $card_no " . "شماره کارت رمزنگاری شده : $hashed_card_no ";
                                $this->saveOrder($customer, $msgForSaveDataTDataBase, 8);
                                $msg = $this->idpay_get_failed_message($verify_track_id, $verify_order_id, 0);
                                $this->errors[] = $msg;
                                $this->notification();
                                /**
                                 * Redirect the customer to the order confirmation page
                                 */
                                Tools::redirect('index.php?controller=order-confirmation&id_cart=' . (int)$cart->id . '&id_module=' . (int)$this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);

                            }




                            if (Configuration::get('idpay_currency') == "toman") {
                                $amount /= 10;
                            }


                            //generate msg and save to database as order
                            $msgForSaveDataTDataBase = $this->otherStatusMessages($verify_status) . "کد پیگیری :  $verify_track_id " . "شماره کارت :  $card_no " . "شماره کارت رمزنگاری شده : $hashed_card_no ";
                            $this->saveOrder($customer, $msgForSaveDataTDataBase, Configuration::get('PS_OS_PAYMENT'));

                            $this->success[] = $this->idpay_get_success_message($verify_track_id, $verify_order_id, $verify_status);
                            $this->notification();
                            /**
                             * Redirect the customer to the order confirmation page
                             */
                            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . (int)$cart->id . '&id_module=' . (int)$this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);


                        }
                    }
                } else {

                    $msgForSaveDataTDataBase = $this->otherStatusMessages($status) . "کد پیگیری :  $track_id " . "شماره سفارش :  $order_id  ";
                    $this->saveOrder($customer, $msgForSaveDataTDataBase, 8);

                    $this->errors[] = $this->idpay_get_failed_message($track_id, $order_id, $status);
                    $this->notification();
                    /**
                     * Redirect the customer to the order confirmation page
                     */
                    Tools::redirect('index.php?controller=order-confirmation&id_cart=' . (int)$cart->id . '&id_module=' . (int)$this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);

                }

            } else {

                $this->errors[] = $this->idpay_get_failed_message($track_id, $order_id, 405);
                $this->notification();
                $msgForSaveDataTDataBase = $this->otherStatusMessages(1000) . "کد پیگیری :  $track_id " . "شماره سفارش :  $order_id  ";
                $this->saveOrder($customer, $msgForSaveDataTDataBase, 8);

                /**
                 * Redirect the customer to the order confirmation page
                 */
                Tools::redirect('index.php?controller=order-confirmation&id_cart=' . (int)$cart->id . '&id_module=' . (int)$this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);
            }


        } else {


            $this->errors[] = $this->otherStatusMessages(1000);
            $this->notification();
            /**
             * Redirect the customer to the order confirmation page
             */
            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . (int)$cart->id . '&id_module=' . (int)$this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);

        }


    }

    public function saveOrder($customer, $msgForSaveDataTDataBase, $status)
    {
        /**
         * Place the order
         * 8 for payment erro and Configuration::get('PS_OS_PAYMENT') for payment is OK
         */
        $this->module->validateOrder(
            (int)$this->context->cart->id,
            $status,
            (float)$this->context->cart->getOrderTotal(true, Cart::BOTH),
            $this->module->displayName . $msgForSaveDataTDataBase,
            null,
            null,
            (int)$this->context->currency->id,
            false,
            $customer->secure_key
        );


    }

    /**
     * @param $track_id
     * @param $order_id
     * @param null $msgNumber
     * @return string
     */
    function idpay_get_success_message($track_id, $order_id, $msgNumber = null)
    {
        $msg = $this->otherStatusMessages($msgNumber);
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], Configuration::get('idpay_success_massage')) . "<br>" . $msg;
    }

    /**
     * @param $track_id
     * @param $order_id
     * @param null $msgNumber
     * @return mixed
     */
    public function idpay_get_failed_message($track_id, $order_id, $msgNumber = null)
    {
        $msg = $this->otherStatusMessages($msgNumber);
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], Configuration::get('idpay_failed_massage') . "<br>" . $msg);

    }

    /**
     * @param $msgNumber
     * @get status from $_POST['status]
     * @return string
     */
    public function otherStatusMessages($msgNumber = null)
    {

        switch ($msgNumber) {
            case "1":
                $msg = "پرداخت انجام نشده است";
                break;
            case "2":
                $msg = "پرداخت ناموفق بوده است";
                break;
            case "3":
                $msg = "خطا رخ داده است";
                break;
            case "3":
                $msg = "بلوکه شده";
                break;
            case "5":
                $msg = "برگشت به پرداخت کننده";
                break;
            case "6":
                $msg = "برگشت خورده سیستمی";
                break;
            case "7":
                $msg = "انصراف از پرداخت";
                break;
            case "8":
                $msg = "به درگاه پرداخت منتقل شد";
                break;
            case "10":
                $msg = "در انتظار تایید پرداخت";
                break;
            case "100":
                $msg = "پرداخت تایید شده است";
                break;
            case "101":
                $msg = "پرداخت قبلا تایید شده است";
                break;
            case "200":
                $msg = "به دریافت کننده واریز شد";
                break;
            case "0":
                $msg = "سواستفاده از تراکنش قبلی";
                break;
            case "404":
                $msg = "واحد پول انتخاب شده پشتیبانی نمی شود.";
                $msgNumber = '404';
                break;
            case "405":
                $msg = "کاربر از انجام تراکنش منصرف شده است.";
                $msgNumber = '404';
                break;
            case "1000":
                $msg = "خطا دور از انتظار";
                $msgNumber = '404';
                break;
            case null:
                $msg = "خطا دور از انتظار";
                $msgNumber = '1000';
                break;
        }

        return $msg . ' -وضعیت: ' . "$msgNumber";

    }


}
