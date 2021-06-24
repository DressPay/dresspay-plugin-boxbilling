<?php
class Payment_Adapter_DressPay extends Payment_AdapterAbstract implements \Box\InjectionAwareInterface
{
    protected $di;
    public function setDi($di)
    {
        $this->di = $di;
    }
    public function getDi()
    {
        return $this->di;
    }
    public function init()
    {
        if (!$this->getParam('token')) {
                throw new Payment_Exception('Payment gateway "DressPay" is not configured properly. Please update configuration parameter "Token" at "Configuration -> Payment gateways".');
        }
        if (!$this->getParam('clientid')) {
                throw new Payment_Exception('Payment gateway "DressPay" is not configured properly. Please update configuration parameter "Client ID" at "Configuration -> Payment gateways".');
        }
        $this->_config['charset']       = 'utf-8';
    }
    public function getType()
    {
        return Payment_AdapterAbstract::TYPE_FORM;
    }

    public static function getConfig()
    {
        return array(
            'supports_one_time_payments'    =>  true,
            'supports_subscriptions'        =>  false,
            'description'                   =>  'Clients will be redirected to Dresspay to make payment.',
            'form'  => array(
                 'token' => array('text', array(
                                        'label' => 'DressPay security token. After signing contract online successfully, Dresspay provides the 32bits security code',
                        ),
                 ),
                 'clientid' => array('text', array(
                                        'label' => 'DressPay client ID.',
                        ),
                 ),
            ),
        );
    }
    public function getServiceUrl()
    {
        if($this->testMode) {
            return 'https://api.dresspay.org/testgateway';
        }
            return 'https://api.dresspay.org/gateway';
    }

    public function singlePayment(Payment_Invoice $invoice) 
    {
            $client = $invoice->getBuyer();

    $parameter = array(
        'notify_url'        => $this->getParam('notify_url'),
        'return_url'        => $this->getParam('thankyou_url'),
        'subject'           => $invoice->getTitle(),
        'out_trade_no'      => $invoice->getId(),
        'price'             => $invoice->getTotalWithTax(),
        'clientid'          => $this->getParam('clientid'),
    );

    ksort($parameter);
    reset($parameter);
    $data = $parameter;
    $data['sign'] = $this->_generateSignature($parameter);

    return $data;
    }
    public function recurrentPayment(Payment_Invoice $invoice) 
    {
            throw new Payment_Exception('DressPay does not support recurrent payments');
    }
    public function getTransaction($data, Payment_Invoice $invoice)
    {
        $ipn = $data['post'];
        
        $uniqid = md5($ipn['trade_no'].$ipn['status']);
        
                $tx = new Payment_Transaction();
                $tx->setId($uniqid);
                $tx->setAmount($ipn['total_fee']);
                $tx->setCurrency($invoice->getCurrency());
        
        $contract = $this->getParam('type');
        switch ($ipn['status']) {
            case 'SUCCESS':
                $tx->setType(Payment_Transaction::TXTYPE_PAYMENT);
                $tx->setStatus(Payment_Transaction::STATUS_COMPLETE);
                break;

            default:
                $tx->setStatus($ipn['trade_status']);
                break;
        }
                return $tx;
    }

    public function isIpnValid($data)
    {
        $ipn = $data['post'];

        ksort($ipn);
        reset($ipn);

        $sign = '';
        foreach ($ipn AS $key=>$val)
        {
            if ($key != 'sign')
            {
                $sign .= "$key=$val&";
            }
        }

        $sign = substr($sign, 0, -1) . $this->getParam('token');
        return (md5($sign) == $ipn['sign']);
    }

    private function _generateSignature(array $parameter)
    {
        $sign  = '';
        foreach ($parameter AS $key => $val)
        {
            $sign  .= "$key=$val&";
        }
        $sign  = substr($sign, 0, -1) . $this->getParam('token');
        return md5($sign);
    }
    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        if(!$this->isIpnValid($data)) {
            throw new Payment_Exception('IPN is not valid');
        }

        $ipn = $data['post'];

        $tx = $this->di['db']->load('Transaction', $id);

        if(!$tx->invoice_id) {
            $tx->invoice_id = $ipn['out_trace_no'];
        }

        if(!$tx->invoice_id) 
        {
            throw new Payment_Exception('Invoice id could not be determined for this transaction');
        }

        if ($this->isIpnDuplicate($ipn)){
            throw new Payment_Exception('IPN is duplicate');
        }

        if(!$tx->txn_id) {
            $tx->txn_id = $ipn['uid'];
        }

        if(!$tx->txn_status) {
            $tx->txn_status = $ipn['status'];
        }

        if(!$tx->amount) {
            $tx->amount = $ipn['price'];
        }

        $tx->type = 'payment';

        $this->di['db']->store($tx);

        switch($ipn['status']){
                case 'SUCCESS': {
                        $tx->txn_status = 'complete';
                        $api_admin->invoice_update(array('id' => $tx->invoice_id, 'status'=>'paid', 'paid_at' => date('Y-m-d H:i:s')));
                        break;
                }
                default: {
                        break;
                }
        }
        $tx->status = 'processed';
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);
        return true;
    }

    public function isIpnDuplicate(array $ipn)
    {
        $sql = 'SELECT id
                FROM transaction
                WHERE txn_id = :transaction_id
                  AND txn_status = :transaction_status
                  AND amount = :transaction_amount
                LIMIT 2';

        $bindings = array(
            ':transaction_id' => $ipn['uid'],
            ':transaction_status' => $ipn['status'],
            ':transaction_amount' => $ipn['price'],
        );

        $rows = $this->di['db']->getAll($sql, $bindings);
        if (count($rows) > 1){
            return true;
        }


        return false;
    }
}
