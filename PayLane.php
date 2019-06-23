<?php
/**
 * This is payment adapter for paylane
 * Created By Dimas Wibowo
 */

class Payment_Adapter_PayLane implements \Box\InjectionAwareInterface
{
  protected $di;
  private $config = array();

  public function setDi($di)
  {
    $this->di = $di;
  }

  public function getDi()
  {
    return $this->di;
  }

  /**
   * Setup and check configuration
   * @param [type] $config [description]
   */
  public function __construct($config)
  {
    $this->config = $config;

    if(!function_exists('curl_exec')) {
      throw new Payment_Exception('PHP Curl extension not enabled');
    }

    if(!isset($this->config['email'])) {
      throw new Payment_Exception('Payment gateway "PayLane" is not configured properly. Please update configuration parameter "PayLane Email address" at "Configuration -> Payments".');
    }

    if(!isset($this->config['merchant_id'])) {
      throw new Payment_Exception('Payment gateway "PayLane" is not configured properly. Please update configuration parameter "PayLane Merchant Id" at "Configuration -> Payments".');
    }

    if(!isset($this->config['salt'])) {
      throw new Payment_Exception('Payment gateway "PayLane" is not configured properly. Please update configuration parameter "PayLane Has Salt" at "Configuration -> Payments".');
    }
  }

  /**
   * Get configuration
   * @return [type] [description]
   */
  public static function getConfig()
  {
    return array(
        'supports_one_time_payments'   =>  true,
        'supports_subscriptions'     =>  true,
        'description'     =>  'Enter your PayLane data to start accepting payments by PayLane.',
        'form'  => array(
            'email' => array('text', array(
                        'label' => 'PayLane email address for payments',
                        'validators'=>array('EmailAddress'),
                ),
             ),
            'merchant_id' => array('text', array(
                        'label' => 'PayLane Merchant ID (Keep this secret)',
                ),
             ),
            'salt' => array('text', array(
                        'label' => 'PayLane Hash salt (Keep this secret)',
                ),
             ),
        ),
    );
  }

  /**
   * Get HTML
   * @param  [type] $api_admin    [description]
   * @param  [type] $invoice_id   [description]
   * @param  [type] $subscription [description]
   * @return [type]               [description]
   */
  public function getHtml($api_admin, $invoice_id, $subscription)
  {
    $url = 'https://secure.paylane.com/order/cart.html';
    $invoice = $api_admin->invoice_get(array('id'=>$invoice_id));
    $data = array();
    $data = $this->getOneTimePaymentFields($invoice);

    return $this->_bikinForm($url, $data);
  }

  /**
   * Processing Transaction
   * @param  [type] $api_admin  [description]
   * @param  [type] $id         [description]
   * @param  [type] $data       [description]
   * @param  [type] $gateway_id [description]
   * @return [type]             [description]
   */
  public function processTransaction($api_admin, $id, $data, $gateway_id)
  {
    $ipn = $data['post'];
    if($this->config['test_mode']) {
      print_r(json_encode($ipn)); die();
    }

    $tx = $api_admin->invoice_transaction_get(array('id'=>$id));

    if(!$tx['invoice_id']) {
      $api_admin->invoice_transaction_update(array('id'=>$id, 'invoice_id'=>$data['get']['bb_invoice_id']));
    }

    if(!$tx['txn_id'] && isset($ipn['id_sale'])) {
      $api_admin->invoice_transaction_update(array('id'=>$id, 'txn_id'=>$ipn['id_sale']));
    }

    if(!$tx['txn_status'] && isset($ipn['status'])) {
      $api_admin->invoice_transaction_update(array('id'=>$id, 'txn_status'=>$ipn['status']));
    }

    if(!$tx['amount'] && isset($ipn['amount'])) {
      $api_admin->invoice_transaction_update(array('id'=>$id, 'amount'=>$ipn['amount']));
    }

    if(!$tx['currency'] && isset($ipn['currency'])) {
      $api_admin->invoice_transaction_update(array('id'=>$id, 'currency'=>$ipn['currency']));
    }
    $invoice = $api_admin->invoice_get(array('id'=>$data['get']['bb_invoice_id']));
    $client_id = $invoice['client']['id'];
    if($ipn['status'] == 'PERFORMED') {
      $bd = array(
        'id'            =>  $client_id,
        'amount'        =>  $ipn['amount'],
        'description'   =>  'Paylane transaction '.$ipn['id_sale'],
        'type'          =>  'Paylane',
        'rel_id'        =>  $ipn['id_sale'],
      );
      if ($this->isIpnDuplicate($ipn)){
        throw new Payment_Exception('IPN is duplicate');
      }
      $api_admin->client_balance_add_funds($bd);
      if($tx['invoice_id']) {
        $api_admin->invoice_pay_with_credits(array('id'=>$tx['invoice_id']));
      }
      $api_admin->invoice_batch_pay_with_credits(array('client_id'=>$client_id));
    }

    $d = array(
      'id'        => $id,
      'error'     => '',
      'error_code'=> '',
      'status'    => 'processed',
      'updated_at'=> date('Y-m-d H:i:s'),
    );
    $api_admin->invoice_transaction_update($d);

    header("Location: ". $this->config['return_url']);
    exit;
  }

  /**
   * Formatting money
   * @param  [type] $amount   [description]
   * @param  [type] $currency [description]
   * @return [type]           [description]
   */
  private function moneyFormat($amount, $currency)
  {
    //HUF currency do not accept decimal values
    if($currency == 'HUF') {
      return number_format($amount, 0);
    }
    return number_format($amount, 2, '.', '');
  }

  /**
   * Generate form
   * @param  [type] $url    [description]
   * @param  [type] $data   [description]
   * @param  string $method [description]
   * @return [type]         [description]
   */
  private function _bikinForm($url, $data, $method = 'post')
  {
    $form  = '';
    $form .= '<form name="payment_form" action="'.$url.'" method="'.$method.'">' . PHP_EOL;
    foreach($data as $key => $value) {
      $form .= sprintf('<input type="hidden" name="%s" value="%s" />', $key, $value) . PHP_EOL;
    }
    $form .=  '<input class="bb-button bb-button-submit" type="submit" value="Pay with PayLane" id="payment_button"/>'. PHP_EOL;
    $form .=  '</form>' . PHP_EOL . PHP_EOL;
    if(isset($this->config['auto_redirect']) && $this->config['auto_redirect']) {
      $form .= sprintf('<h2>%s</h2>', __('Redirecting to PayLane.com'));
      $form .= "<script type='text/javascript'>$(document).ready(function(){    document.getElementById('payment_button').style.display = 'none';    document.forms['payment_form'].submit();});</script>";
    }
    return $form;
  }

  /**
   * Check if ipn is duplicate
   * @param  array   $ipn [description]
   * @return boolean      [description]
   */
  public function isIpnDuplicate(array $ipn)
  {
    $sql = 'SELECT id
    FROM transaction
    WHERE txn_id = :transaction_id
    AND txn_status = :transaction_status
    AND type = :transaction_type
    AND amount = :transaction_amount
    LIMIT 2';
    $bindings = array(
      ':transaction_id' => $ipn['txn_id'],
      ':transaction_status' => $ipn['payment_status'],
      ':transaction_type' => $ipn['txn_type'],
      ':transaction_amount' => $ipn['mc_gross'],
    );
    $rows = $this->di['db']->getAll($sql, $bindings);
    if (count($rows) > 1){
      return true;
    }
    return false;
  }

  /**
   * Function for get invoice title
   * @param  array  $invoice [description]
   * @return [type]          [description]
   */
  public function getInvoiceTitle(array $invoice)
  {
    $p = array(
      ':id'=>sprintf('%05s', $invoice['nr']),
      ':serie'=>$invoice['serie'],
      ':title'=>$invoice['lines'][0]['title']
    );
    return __('Payment for invoice :serie:id [:title]', $p);
  }

  /**
   * Get description
   * @param  array  $invoice [description]
   * @return [type]          [description]
   */
  public function getDescription(array $invoice)
  {
    $p = array(
      ':id'=>sprintf('%05s', $invoice['nr']),
      ':serie'=>$invoice['serie'],
      ':title'=>$invoice['lines'][0]['title']
    );
    return __(':serie:id', $p);
  }

  /**
   * Get one time payment fields
   * @param  array  $invoice [description]
   * @return [type]          [description]
   */
  public function getOneTimePaymentFields(array $invoice)
  {
    $data = array();
    $data['amount']                     = $this->moneyFormat($invoice['total'], $invoice['currency']); // Regular subscription price.
    $data['currency']                   = $invoice['currency'];
    $data['merchant_id']                = $this->config["merchant_id"];
    $data['description']                = $this->getDescription($invoice);
    $data['transaction_description']    = $this->getInvoiceTitle($invoice);
    $data['transaction_type']           = "S";
    $data['back_url']                   = $this->config['notify_url'];
    $data['language']                   = "en";
    $data['hash']                       = SHA1( $this->config["salt"] . "|" . $this->getDescription($invoice) . "|" . $this->moneyFormat($invoice['total'], $invoice['currency']) . "|" . $invoice['currency'] . "|" . "S" );
    return $data;
  }
}
