<?php
/**
 * Created by PhpStorm.
 * User: Jerry
 * Date: 2017/11/21
 * Time: 上午10:16
 */

class ControllerExtensionPaymentPchomepay extends Controller
{
    private $error = array();
    private $name_prefix = 'payment_pchomepay';
    private $id_prefix = 'payment-pchomepay';
    private $module_code = 'payment_pchomepay';
    private $module_path = 'extension/payment/pchomepay';

    public function index()
    {
        $this->load->language($this->module_path);

        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting($this->module_code, $this->request->post);

            $this->session->data['success'] = $this->language->get('text_success');

            $this->response->redirect($this->url->link('marketplace/extension', "user_token={$this->session->data['user_token']}&type=payment", true));
        }

        $translation_names = [
            'heading_title',
            'text_edit',
            'text_enabled',
            'text_disabled',
            'text_all_zones',
            'text_yes',
            'text_no',
            'text_card',
            'text_atm',
            'text_each',
            'text_acct',
            'text_card_0',
            'text_card_3',
            'text_card_6',
            'text_card_12',
            'entry_status',
            'entry_appid',
            'entry_secret',
            'entry_sandbox_secret',
            'entry_test',
            'entry_debug',
            'entry_payment_methods',
            'entry_card_installment',
            'entry_atm_expiredate',
            'entry_card_last_number',
            'entry_completed_status',
            'entry_expired_status',
            'entry_failed_status',
            'entry_pending_status',
            'entry_processed_status',
            'entry_refunded_status',
            'entry_create_status',
            'entry_processing_status',
            'entry_success_status',
            'entry_failed_status',
            'entry_geo_zone',
            'entry_sort_order',
            'help_test',
            'help_debug',
            'button_save',
            'button_cancel',
            'tab_general',
            'tab_order_status'
        ];

        foreach ($translation_names as $name) {
            $data[$name] = $this->language->get($name);
        }

        if (isset($this->error['warning'])) {
            $data['error_warning'] = $this->error['warning'];
        } else {
            $data['error_warning'] = '';
        }

        if (isset($this->error['appid'])) {
            $data['error_appid'] = $this->error['appid'];
        } else {
            $data['error_appid'] = '';
        }

        if (isset($this->error['secret'])) {
            $data['error_secret'] = $this->error['secret'];
        } else {
            $data['error_secret'] = '';
        }

        if (isset($this->error['atm_expiredate'])) {
            $data['error_atm_expiredate'] = $this->error['atm_expiredate'];
        } else {
            $data['error_atm_expiredate'] = '';
        }

        if (isset($this->error['payment_methods'])) {
            $data['error_payment_methods'] = $this->error['payment_methods'];
        } else {
            $data['error_payment_methods'] = '';
        }

        $data['breadcrumbs'] = array();

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_payment'),
            'href' => $this->url->link('extension/payment', 'user_token=' . $this->session->data['user_token'], true)
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link($this->module_path, 'user_token=' . $this->session->data['user_token'], true)
        );

        $data['action'] = $this->url->link($this->module_path, 'user_token=' . $this->session->data['user_token'], true);

        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'], true);

        # Get PChomePay setting
        $pchomepay_settings = array(
            'status',
            'appid',
            'secret',
            'sandbox_secret',
            'test',
            'debug',
            'payment_methods',
            'card_installment',
            'atm_expiredate',
            'geo_zone_id',
            'sort_order',
            'card_last_number',
            'create_status',
            'processing_status',
            'success_status',
            'failed_status',
        );

        foreach ($pchomepay_settings as $setting_name) {
            $pchomepay_setting_name = $this->name_prefix . '_' . $setting_name;

            if (isset($this->request->post[$pchomepay_setting_name])) {
                $data[$pchomepay_setting_name] = $this->request->post[$pchomepay_setting_name];
            } else {
                $data[$pchomepay_setting_name] = $this->config->get($pchomepay_setting_name);
            }
        }

        unset($pchomepay_settings);

        // Default value
        $default_values = array(
            'create_status' => 1,
            'processing_status' => 2,
            'success_status' => 15,
        );

        foreach ($default_values as $name => $value) {
            if (is_null($data[$this->name_prefix . '_' . $name])) {
                $data[$this->name_prefix . '_' . $name] = $value;
            }
        }

        $this->load->model('localisation/order_status');

        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        $this->load->model('localisation/geo_zone');

        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $data['name_prefix'] = $this->name_prefix;
        $data['id_prefix'] = $this->id_prefix;

        $this->response->setOutput($this->load->view($this->module_path, $data));
    }

    private function validate()
    {
        if (!$this->user->hasPermission('modify', $this->module_path)) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        if (!$this->request->post["{$this->name_prefix}_appid"] || !$this->request->post["{$this->name_prefix}_secret"] || !$this->request->post["{$this->name_prefix}_atm_expiredate"] || !$this->request->post["{$this->name_prefix}_payment_methods"]) {
            $this->error['appid'] = $this->language->get('error_appid');
            $this->error['secret'] = $this->language->get('error_secret');
            $this->error['atm_expiredate'] = $this->language->get('error_atm_expiredate');
            $this->error['payment_methods'] = $this->language->get('error_payment_methods');
        }

        if (isset($this->request->post["{$this->name_prefix}_atm_expiredate"]) && (!preg_match('/^\d*$/', $this->request->post["{$this->name_prefix}_atm_expiredate"]) || $this->request->post["{$this->name_prefix}_atm_expiredate"] < 1 || $this->request->post["{$this->name_prefix}_atm_expiredate"] > 5)) {
            $this->error['atm_expiredate'] = $this->language->get('error_atm_expiredate_number');
        }

        return !$this->error;
    }
}