<?php
/**
 * Created by PhpStorm.
 * User: Jerry
 * Date: 2017/11/27
 * Time: 下午2:53
 */

class ControllerExtensionPaymentPchomepay extends Controller
{
    private $name_prefix;
    private $id_prefix;
    private $module_code;
    private $module_path;
    private $module_name;
    private $lang_prefix;

    public function __construct($registry) {
        parent::__construct($registry);

        // Set the variables
        $this->name_prefix = 'payment_pchomepay';
        $this->id_prefix = 'payment-pchomepay';
        $this->module_code = 'payment_pchomepay';
        $this->module_path = 'extension/payment/pchomepay';
        $this->module_name = 'model_extension_payment_pchomepay';
        $this->lang_prefix = 'pchomepay_';
    }

    public function index()
    {
        $this->load->language($this->module_path);

        $data['text_testmode'] = $this->language->get($this->lang_prefix . 'text_testmode');
        $data['text_title'] = $this->language->get($this->lang_prefix . 'text_title');
        $data['testmode'] = $this->config->get($this->name_prefix . '_test');
        $data['action'] = $this->url->link($this->module_path . '/redirect', '', 'SSL');
        $data['entry_payment_method'] = $this->language->get($this->lang_prefix . 'entry_payment_method');
        $data['text_checkout_button'] = $this->language->get($this->lang_prefix . 'text_checkout_button');

        if (isset($this->session->data[$this->module_name]['chosen_payment']) === true) {
            $chosen_payment = $this->session->data[$this->module_name]['chosen_payment'];
            $data['chosen_payemnt'] = $this->language->get($this->lang_prefix. 'text_' . $chosen_payment);
        } else {
            $data['chosen_payemnt'] = '';
        }

        $payment_methods = $this->config->get($this->name_prefix . '_payment_methods');

        if (empty($payment_methods)) {
            $payment_methods = array();
        } else {
            // Get the translation of payment methods
            foreach ($payment_methods as $name) {
                $lower_name = strtolower($name);
                $lang_key = $this->lang_prefix . 'text_' . $lower_name;
                $data['pchomepay_payment_methods'][$name] = $this->language->get($lang_key);
                unset($lang_key, $name);
            }
        }

        $data['name_prefix'] = $this->name_prefix;
        $data['id_prefix'] = $this->id_prefix;

        return $this->load->view($this->module_path, $data);
    }

    public function savePayment() {
        $function_name = __FUNCTION__;
        $inputs = $_POST;

        // Check the received variables
        if ($inputs === false) {
            echo json_encode(array('response' => $function_name . ' failed(1)'));
            exit;
        }

        // Save chosen payment
        $this->session->data[$this->module_name]['chosen_payment'] = $_POST['cp'];

        echo json_encode(array('response' => 'ok', 'input'=> $_POST));
        exit;
    }

    public function cleanSession() {
        if (isset($this->session->data[$this->module_name]['chosen_payment']) === true) {
            unset($this->session->data[$this->module_name]['chosen_payment']);
        }

        echo json_encode(array('response' => 'ok'));
        exit;
    }

    public function redirect()
    {
        try {
            $this->load->model('checkout/order');
            $this->load->model($this->module_path);

            $invoke_result = $this->model_extension_payment_pchomepay->invokePChomePayModule();

            if (!$invoke_result) {
                throw new Exception($this->language->get($this->lang_prefix . 'error_module_miss'));
            }

            $payment_methods = $this->config->get($this->name_prefix . '_payment_methods');
            $choose_payment = $this->session->data[$this->module_name]['chosen_payment'];

            // Check choose payment
            if (isset($this->session->data[$this->module_name]['chosen_payment']) === false) {
                throw new Exception($this->language->get($this->name_prefix  . '_error_payment_missing'));
            }

            // Validate choose payment
            if (in_array($choose_payment, $payment_methods) === false) {
                throw new Exception($this->language->get($this->lang_prefix . 'error_invalid_payment'));
            }

            // Validate the order id
            if (isset($this->session->data['order_id']) === false) {
                throw new Exception($this->language->get($this->lang_prefix . 'error_order_id_miss'));
            }

            $order_id = $this->session->data['order_id'];

            $postPaymentData = $this->getPChomepayPaymentData($order_id, $choose_payment);

            if ($postPaymentData) {
                // 建立訂單
                $result = $this->model_extension_payment_pchomepay->postPayment($postPaymentData);

                if (empty($result)) {
                    $this->ocLog("交易失敗：伺服器端未知錯誤，請聯絡 PChomePay支付連。");
                    throw new Exception("嘗試使用付款閘道 API 建立訂單時發生錯誤，請聯絡網站管理員。");
                }
            }

            # Update order status and comments
            $status_id = $this->config->get($this->name_prefix . '_create_status');
            $this->model_checkout_order->addOrderHistory($order_id, $status_id, '訂單編號：' . $result->order_id);

            # Clean the cart
            $this->cart->clear();

            # Add to activity log
            $this->load->model('account/activity');
            if ($this->customer->isLogged()) {
                $activity_data = array(
                    'customer_id' => $this->customer->getId(),
                    'name'        => $this->customer->getFirstName() . ' ' . $this->customer->getLastName(),
                    'order_id'    => $order_id
                );

                $this->model_account_activity->addActivity('order_account', $activity_data);
            } else {
                $activity_data = array(
                    'name'     => $this->session->data['guest']['firstname'] . ' ' . $this->session->data['guest']['lastname'],
                    'order_id' => $order_id
                );

                $this->model_account_activity->addActivity('order_guest', $activity_data);
            }

            # Clean the session
            unset($this->session->data['shipping_method']);
            unset($this->session->data['shipping_methods']);
            unset($this->session->data['payment_method']);
            unset($this->session->data['payment_methods']);
            unset($this->session->data['guest']);
            unset($this->session->data['comment']);
            unset($this->session->data['order_id']);
            unset($this->session->data['coupon']);
            unset($this->session->data['reward']);
            unset($this->session->data['voucher']);
            unset($this->session->data['vouchers']);
            unset($this->session->data['totals']);

            $this->response->redirect($result->payment_url);
            exit();
        } catch (Exception $e) {
            // Process the exception
            $this->session->data['error'] = $e->getMessage();
            $this->response->redirect($this->url->link('checkout/checkout', '', $this->url_secure));
        }

    }

    private function getPChomepayPaymentData($order_id, $choose_payment)
    {
        $order_info = $this->model_checkout_order->getOrder($order_id);

        if ($order_info) {
            $order_id = 'AO' . date('Ymd') . $order_info['order_id'];
            $amount = $this->model_extension_payment_pchomepay->formatOrderTotal($order_info['total']);
            $return_url = $this->url->link('checkout/success');
            $notify_url = $this->url->link('/extension/payment/pchomepay/callback', '', true);
            $fail_return_url = $this->url->link('checkout/checkout');
            $buyer_email = $order_info['email'];

            $atm_expiredate = $this->config->get($this->name_prefix . '_atm_expiredate');

            if (isset($atm_expiredate) && (!preg_match('/^\d*$/', $atm_expiredate) || $atm_expiredate < 1 || $atm_expiredate > 5)) {
                $atm_expiredate = 5;
            }

            $pay_type = [$choose_payment];

            $atm_info = (object)['expire_days' => (int)$atm_expiredate];

            $card_installment = $this->config->get($this->name_prefix . '_card_installment');
            $card_info = [];

            foreach ($card_installment as $items) {
                switch ($items) {
                    case 'CARD_3':
                        $card_installment['installment'] = 3;
                        break;
                    case 'CARD_6':
                        $card_installment['installment'] = 6;
                        break;
                    case 'CARD_12':
                        $card_installment['installment'] = 12;
                        break;
                    default :
                        unset($card_installment);
                        break;
                }
                if (isset($card_installment)) {
                    $card_info[] = (object)$card_installment;
                }
            }

            $items = [];

            foreach ($this->cart->getProducts() as $item) {
                $product = [];
                $product['name'] = $item['name'];
                $product['url'] = $this->url->link('product/product', 'product_id=' . $item['product_id']);;

                $items[] = (object)$product;
            }

            $pchomepay_args = [
                'order_id' => $order_id,
                'pay_type' => $pay_type,
                'amount' => $amount,
                'return_url' => $return_url,
                'notify_url' => $notify_url,
                'fail_return_url' => $fail_return_url,
                'items' => $items,
                'buyer_email' => $buyer_email,
                'atm_info' => $atm_info,
            ];

            if ($card_info) $pchomepay_args['card_info'] = $card_info;

            $this->ocLog(json_encode($pchomepay_args));

            return json_encode($pchomepay_args);
        }

        return null;
    }

    public function callback()
    {
        $this->load->language('extension/payment/pchomepay');
        $this->load->model($this->module_path);
        $this->load->model('checkout/order');

        if (!isset($_REQUEST['notify_type']) || !isset($_REQUEST['notify_message'])) {
            http_response_code(404);
            exit;
        }

        $invoke_result = $this->model_extension_payment_pchomepay->invokePChomePayModule();

        if (!$invoke_result) {
            throw new Exception($this->language->get('error_module_miss'));
        }

        $notify_type = $_REQUEST['notify_type'];
        $notify_message = $_REQUEST['notify_message'];

        $order_data = json_decode(str_replace('\"', '"', $notify_message));

        $order_id = substr($order_data->order_id, 10);

        # 紀錄訂單付款方式
        switch ($order_data->pay_type) {
            case 'ATM':
                $pay_type_note = '付款方式 : ATM';
                $pay_type_note .= '<br>ATM虛擬帳號: ' . $order_data->payment_info->bank_code . ' - ' . $order_data->payment_info->virtual_account;
                break;
            case 'CARD':
                if ($order_data->payment_info) {
                    if ($order_data->payment_info->installment == 1) {
                        $pay_type_note = '付款方式 : 信用卡 (一次付清)';
                    } else {
                        $pay_type_note = '付款方式 : 信用卡 分期付款 (' . $order_data->payment_info->installment . '期)';
                    }
                    if ($this->config->get($this->name_prefix . '_card_last_number')) $pay_type_note .= '<br>末四碼: ' . $order_data->payment_info->card_last_number;
                } else {
                    $pay_type_note = '付款方式 : 信用卡';
                }
                break;
            case 'ACCT':
                $pay_type_note = '付款方式 : 支付連餘額';
                break;
            case 'EACH':
                $pay_type_note = '付款方式 : 銀行支付';
                break;
            case 'PI':
                $pay_type_note = '付款方式 : Pi錢包';
                break;
            case 'IPL7':
                $pay_type_note = '付款方式 : 7-11取貨付款';
                break;
            default:
                $pay_type_note = '未選擇付款方式';
        }

        //  order status
        //       1        PENDING       訂單剛剛創建,等待處理.
        //       2        PROCESSING    當客戶付款完成,訂單狀態即為處理中.
        //       3        SHIPPED       當訂單已發出,訂單狀態請設為Shipped.
        //       5        COMPLETE      客戶已確認收貨,訂單狀態請設為Complete.
        //       7        CANCELLED     出於某些原因,訂單取消.請將訂單狀態設為Cancelled.
        //      10        FAILED        訂單失敗
        //      11        REFUNDED      如客戶退貨或退款.訂單狀態請設為Refunded.
        //      14        EXPIRED       訂單逾期

        if ($notify_type == 'order_audit') {
            $comment = sprintf('訂單交易等待中。<br>error code : %1$s<br>message : %2$s', $order_data->status_code, pchomepayOrderStatusEnum::getErrMsg($order_data->status_code));
            $status_id = $this->config->get($this->name_prefix . '_processing_status');
            $this->model_checkout_order->addOrderHistory($order_id, $status_id, $comment);
        } elseif ($notify_type == 'order_expired') {
            if ($order_data->status_code) {
                $comment = $pay_type_note . '<br>' . sprintf('訂單已失敗。<br>error code : %1$s<br>message : %2$s', $order_data->status_code, pchomepayOrderStatusEnum::getErrMsg($order_data->status_code));
                $status_id = $this->config->get($this->name_prefix . '_failed_status');
                $this->model_checkout_order->addOrderHistory($order_id, $status_id, $comment);
            } else {
                $status_id = $this->config->get($this->name_prefix . '_failed_status');
                $this->model_checkout_order->addOrderHistory($order_id, $status_id, $pay_type_note . '<br>訂單已失敗。');
            }
        } elseif ($notify_type == 'order_confirm') {
            $status_id = $this->config->get($this->name_prefix . '_success_status');
            $this->model_checkout_order->addOrderHistory($order_id, $status_id, $pay_type_note . '<br>訂單已成功。');
        }

        echo 'success';
        exit();
    }


    public function ocLog($message)
    {
        $message = json_encode($message);
        $today = date('Ymd');
        $log = new Log("PChomePay-{$today}.log");
        $log->write('class ' . get_class() . ' : ' . $message . "\n");
    }
}