<?php
require_once 'modules/admin/models/ServicePlugin.php';
include_once 'modules/billing/models/BillingTypeGateway.php';
require_once 'modules/billing/models/BillingGateway.php';
require_once 'modules/billing/models/Invoice.php';
require_once 'modules/billing/models/InvoiceEntry.php';
/**
* @package Plugins
*/
class PluginLatefee extends ServicePlugin
{
    protected $featureSet = 'billing';
    public $hasPendingItems = true;
    public $permission = 'billing_view';

    function getVariables()
    {
        $variables = array(
            lang('Plugin Name')   => array(
                'type'          => 'hidden',
                'description'   => '',
                'value'         => lang('Late Fee'),
            ),
            lang('Enabled')       => array(
                'type'          => 'yesno',
                'description'   => lang('When enabled, late invoices will be charged with additional late fees. This service should only run once per day, and preferable if run before <b>Invoice Reminder</b> service, to avoid sending reminders without the late fee.'),
                'value'         => '0',
            ),
            lang('Billing type name')       => array(
                'type'          => 'text',
                'description'   => lang('Enter the exact name of the <b>billing type</b> to be used to charge a late fee on invoices. Also make sure the <b>billing type</b> exist and it has set the value you want to charge for late fees.'),
                'value'         => '',
            ),
            lang('Days to charge late fee')       => array(
                'type'          => 'text',
                'description'   => lang('Enter the number of days after the due date to charge a late fee on invoices.  You may enter more than one day by seperating the numbers with a comma.  <strong><i>Note: A number followed by a + sign indicates to charge a late fee for all days greater than the previous number or use * to charge late fees each day.</i></strong><br><br><b>Example</b>: 5,10+ would charge late fee when five days late, and will charge late fee again when ten days late and once again on every following day.'),
                'value'         => '5,10+',
            ),
            lang('Run schedule - Minute')  => array(
                'type'          => 'text',
                'description'   => lang('Enter number, range, list or steps'),
                'value'         => '0',
                'helpid'        => '8',
            ),
            lang('Run schedule - Hour')  => array(
                'type'          => 'text',
                'description'   => lang('Enter number, range, list or steps'),
                'value'         => '0',
            ),
            lang('Run schedule - Day')  => array(
                'type'          => 'text',
                'description'   => lang('Enter number, range, list or steps'),
                'value'         => '*',
            ),
            lang('Run schedule - Month')  => array(
                'type'          => 'text',
                'description'   => lang('Enter number, range, list or steps'),
                'value'         => '*',
            ),
            lang('Run schedule - Day of the week')  => array(
                'type'          => 'text',
                'description'   => lang('Enter number in range 0-6 (0 is Sunday) or a 3 letter shortcut (e.g. sun)'),
                'value'         => '*',
            ),
        );

        return $variables;
    }

    function execute()
    {
        $invoicesList = array();
        $arrDays = explode(',', $this->settings->get('plugin_latefee_Days to charge late fee'));

        $billingTypeName = $this->settings->get('plugin_latefee_Billing type name');
        $billingTypeGateway = new BillingTypeGateway();
        $billingType = $billingTypeGateway->GetBillingTypeByName($billingTypeName);

        if(isset($billingType['id']) && $billingType['id'] != 0){
            $billingGateway = new BillingGateway($this->user);
            $invoicesList = $billingGateway->getUnpaidInvoicesDueDays($arrDays);

            foreach($invoicesList as $invoiceData){
                $params = array(
                    'm_CustomerID'        => $invoiceData['userId'],
                    'm_Description'       => $billingType['description'],
                    'm_Detail'            => $billingType['detail'],
                    'm_InvoiceID'         => $invoiceData['invoiceId'],
                    'm_Date'              => date("Y-m-d"),
                    'm_BillingTypeID'     => $billingType['id'],
                    'm_IsProrating'       => 0,
                    'm_Price'             => $billingType['price'],
                    'm_Recurring'         => 0,
                    'm_AppliesToID'       => 0,
                    'm_Setup'             => 0,
                    'm_Taxable'           => 0,
                    'm_TaxAmount'         => 0,
                );

                $invoiceEntry = new InvoiceEntry($params);
                $invoiceEntry->updateRecord();

                $invoice = new Invoice($invoiceData['invoiceId']);
                $invoice->recalculateInvoice();
                $invoice->update();
            }
        }

        return array($this->user->lang('%s invoice reminders were sent', count($invoicesList)));
    }

    function pendingItems()
    {
        $currency = new Currency($this->user);
        // Select all customers that have an invoice that needs generation
        $query = "SELECT i.`id`,i.`customerid`, i.`amount`, i.`balance_due`, (TO_DAYS(NOW()) - TO_DAYS(i.`billdate`)) AS days "
                ."FROM `invoice` i, `users` u "
                ."WHERE (i.`status`='0' OR i.`status`='5') AND u.`id`=i.`customerid` AND u.`status`='1' AND TO_DAYS(NOW()) - TO_DAYS(i.`billdate`) > 0 AND i.`subscription_id` = '' "
                ."ORDER BY i.`billdate`";
        $result = $this->db->query($query);
        $returnArray = array();
        $returnArray['data'] = array();
        while ($row = $result->fetch()) {
            $user = new User($row['customerid']);
            $tmpInfo = array();
            $tmpInfo['customer'] = '<a href="index.php?fuse=clients&controller=userprofile&view=profilecontact&frmClientID=' . $user->getId() . '">' . $user->getFullName() . '</a>';
            $tmpInfo['invoice_number'] = '<a href="index.php?controller=invoice&fuse=billing&frmClientID=' . $user->getId() . '&view=invoice&invoiceid=' . $row['id'] . ' ">' . $row['id'] . '</a>';
            $tmpInfo['amount'] = $currency->format($this->settings->get('Default Currency'), $row['amount'], true);
            $tmpInfo['balance_due'] = $currency->format($this->settings->get('Default Currency'), $row['balance_due'], true);
            $tmpInfo['days'] = $row['days'];
            $returnArray['data'][] = $tmpInfo;
        }
        $returnArray["totalcount"] = count($returnArray['data']);
        $returnArray['headers'] = array (
            $this->user->lang('Customer'),
            $this->user->lang('Invoice Number'),
            $this->user->lang('Amount'),
            $this->user->lang('Balance Due'),
            $this->user->lang('Days Overdue'),
        );
        return $returnArray;
    }

    function output() { }

    function dashboard()
    {
        $query = "SELECT COUNT(*) AS overdue "
                ."FROM `invoice` i, `users` u "
                ."WHERE (i.`status`='0' OR i.`status`='5') AND u.`id`=i.`customerid` AND u.`status`='1' AND TO_DAYS(NOW()) - TO_DAYS(i.`billdate`) > 0 AND i.`subscription_id` = '' ";
        $result = $this->db->query($query);
        $row = $result->fetch();
        if (!$row) {
            $row['overdue'] = 0;
        }
        return $this->user->lang('Number of invoices overdue: %d', $row['overdue']);
    }
}
?>
