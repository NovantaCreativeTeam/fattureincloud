<?php
/**
* FattureInCloud Prestashop Module
*
*  @author    Websuvius di Michele Matto <michele@websuvius.it>
*  @copyright FattureInCloud - Madbit Entertainment S.r.l.
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*/

use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__).'/libs/fattureincloudClient.php';

class fattureincloud extends Module
{
    protected $config_form = false;
    
    /**
    * Initial configuration
    */
    public function __construct()
    {
        $this->name = 'fattureincloud';
        $this->tab = 'billing_invoicing';
        $this->version = '2.2.1';
        $this->author = 'FattureInCloud';
        $this->need_instance = 1;

        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = 'Fatture In Cloud';
        $this->description = 'Collega il tuo negozio Prestashop al tuo account FattureInCloud! Sincronizza gli ordini, le anagrafiche ed emetti fatture!';

        $this->confirmUninstall = 'Sicuro di voler disinstallare il modulo FattureInCloud?';

        $this->ps_versions_compliancy = array('min' => '1.7.6.0', 'max' => _PS_VERSION_);
    }
    
    /**
    * Install module: sql table, tab and hooks
    */
    public function install()
    {
        include(dirname(__FILE__).'/sql/install.php');
        
        if (!parent::install()) {
            return false;
        }
        
        $this->installTab();
        
        // Hook for new order
        $this->registerHook('actionValidateOrder');
        // Hook for new invoices
        $this->registerHook('actionOrderStatusPostUpdate');
        // Hook for invoice number formatting
        $this->registerHook('actionInvoiceNumberFormatted');
        // Hook for invoice download
        $this->registerHook('displayPDFInvoice');
            
        // Hooks for additional tax field on back-end
        $this->registerHook('actionTaxFormBuilderModifier');
        $this->registerHook('actionAfterCreateTaxFormHandler');
        $this->registerHook('actionAfterUpdateTaxFormHandler');
        
        return true;
    }
    
    /**
    * Uninstall module
    */
    public function uninstall()
    {
        if (!parent::uninstall() || !$this->uninstallTab() ||
            !Configuration::updateValue('FATTUREINCLOUD_DEVICE_CODE', '') ||
            !Configuration::updateValue('FATTUREINCLOUD_COMPANY_ID', '') ||
            !Configuration::updateValue('FATTUREINCLOUD_ACCESS_TOKEN', '') ||
            !Configuration::updateValue('FATTUREINCLOUD_REFRESH_TOKEN', '')||
            !Configuration::updateValue('FATTUREINCLOUD_ORDERS_CREATE', 0)||
            !Configuration::updateValue('FATTUREINCLOUD_ORDERS_SUFFIX', '')||
            !Configuration::updateValue('FATTUREINCLOUD_ORDERS_UPDATE_STORAGE', 0)||
            !Configuration::updateValue('FATTUREINCLOUD_INVOICES_CREATE', 0)||
            !Configuration::updateValue('FATTUREINCLOUD_INVOICES_SEND_TO_SDI', 0)||
            !Configuration::updateValue('FATTUREINCLOUD_INVOICES_SUFFIX', '')||
            !Configuration::updateValue('FATTUREINCLOUD_CUSTOMERS_UPDATE', 0)||
            !Configuration::updateValue('FATTUREINCLOUD_RC_CENTER', '')
        ) {
            return false;
        }
        
        return true;
    }
    
    /**
    * Install invisible tab to handle ajax calls
    */
    public function installTab()
    {
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminFattureincloud';
        $tab->name = array();
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'FattureInCloud';
        }
        $tab->id_parent = -1;
        $tab->module = $this->name;
        $tab->add();
    }
    
    /**
    * Uninstall tab
    */
    public function uninstallTab()
    {
        $id_tab = (int) Tab::getIdFromClassName('AdminFattureincloud');
        if ($id_tab) {
            $tab = new Tab($id_tab);
            return $tab->delete();
        }
    
        return false;
    }
    
    /**
    * Load the configuration form on back-end
    */
    public function getContent()
    {
        if (((bool)Tools::isSubmit('submitFattureInCloudModule')) == true) {
            $this->postProcess();
        }
        
        $this->context->smarty->assign('module_dir', $this->_path);
        
        $admin_controller_link = $this->context->link->getAdminLink('AdminFattureincloud');
        Media::addJsDef(array('admin_controller_link' => $admin_controller_link));
        
        if (Configuration::get('FATTUREINCLOUD_ACCESS_TOKEN') == "" || Configuration::get('FATTUREINCLOUD_REFRESH_TOKEN') == "") {
            $fic_client = new FattureInCloudClient();
            $fic_client->setUserAgent("FattureInCloud/Prestashop/" . $this->version);
            $device_code_request = $fic_client->oauthDevice();
            
            $this->context->smarty->assign('device_code_request', $device_code_request);
            
            $output = $this->context->smarty->fetch($this->local_path.'views/templates/admin/connect.tpl');
        } elseif (Configuration::get('FATTUREINCLOUD_COMPANY_ID') == "" && Configuration::get('FATTUREINCLOUD_ACCESS_TOKEN') != "" && Configuration::get('FATTUREINCLOUD_REFRESH_TOKEN') != "") {
            $fic_client = new FattureInCloudClient();
            $fic_client->setUserAgent("FattureInCloud/Prestashop/" . $this->version);
            $fic_client->setAccessToken(Configuration::get('FATTUREINCLOUD_ACCESS_TOKEN'));
            
            $controlled_companies_request = $fic_client->getControlledCompanies();
                
            $this->context->smarty->assign('controlled_companies_request', $controlled_companies_request);
                
            $output = $this->context->smarty->fetch($this->local_path.'views/templates/admin/selectCompany.tpl');
        } else {
            $output = $this->context->smarty->fetch($this->local_path.'views/templates/admin/configure.tpl');
            $output.= $this->renderForm();
        }
        
        return $output;
    }
    
    /**
     * Create the form that will be displayed in the configuration page
     */
    protected function renderForm()
    {
        $helper = new HelperForm();
    
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
    
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitFattureInCloudModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
    
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );
    
        return $helper->generateForm(array($this->getConfigForm()));
    }
    
    /**
     * Create the structure of configuration form
     */
    protected function getConfigForm()
    {
        $legend_title = 'Configura l\'integrazione FattureInCloud';
                
        $form_fields = array();
        
        $form_fields[] = array(
            'col' => 3,
            'type' => 'text',
            'desc' => 'Il centro di ricavo utilizzato per gli ordini e le fatture provenienti da questo negozio',
            'name' => 'FATTUREINCLOUD_RC_CENTER',
            'label' => 'Centro di ricavo',
            'required' => false,
        );
            
        $form_fields[] = array(
            'type' => 'switch',
            'label' => 'Crea ordini',
            'name' => 'FATTUREINCLOUD_ORDERS_CREATE',
            'is_bool' => true,
            'desc' => 'Quando arriva un nuovo ordine ne verrà creato uno su FattureInCloud',
            'values' => array(
                array(
                    'id' => 'active_on',
                    'value' => true,
                    'label' => 'Attivato'
                ),
                array(
                    'id' => 'active_off',
                    'value' => false,
                    'label' => 'Disattivato'
                ),
            ),
        );
                        
        $form_fields[] = array(
            'col' => 3,
            'type' => 'text',
            'desc' => 'Il sezionale che verrà aggiunto in automatico alla numerazione degli ordini',
            'name' => 'FATTUREINCLOUD_ORDERS_SUFFIX',
            'label' => 'Sezionale ordini',
            'required' => false,
        );
            
        $form_fields[] = array(
            'type' => 'switch',
            'label' => 'Scarica magazzino da ordine',
            'name' => 'FATTUREINCLOUD_ORDERS_UPDATE_STORAGE',
            'is_bool' => true,
            'desc' => 'Se attivo scarica dal magazzino la quantità di prodotto presente nell\'ordine.',
            'values' => array(
                array(
                    'id' => 'active_on',
                    'value' => true,
                    'label' => 'Attivato',
                ),
                array(
                    'id' => 'active_off',
                    'value' => false,
                    'label' => 'Disattivato',
                ),
            ),
        );
                    
        $form_fields[] = array(
            'type' => 'switch',
            'label' => 'Crea fatture',
            'name' => 'FATTUREINCLOUD_INVOICES_CREATE',
            'is_bool' => true,
            'desc' => 'Quando un ordine viene pagato verrà creata una fattura su FattureInCloud. Se disattivato Prestashop utilizzerà le funzionalità di fatturazione standard.',
            'values' => array(
                array(
                    'id' => 'active_on',
                    'value' => true,
                    'label' => 'Attivato'
                ),
                array(
                    'id' => 'active_off',
                    'value' => false,
                    'label' => 'Disattivato'
                ),
            ),
        );
            
        $form_fields[] = array(
            'type' => 'switch',
            'label' => 'Invia fatture elettroniche',
            'name' => 'FATTUREINCLOUD_INVOICES_SEND_TO_SDI',
            'is_bool' => true,
            'desc' => 'Invia la fattura elettronica al sistema di interscambio dopo averla creata.',
            'values' => array(
                array(
                    'id' => 'active_on',
                    'value' => true,
                    'label' => 'Attivato'
                ),
                array(
                    'id' => 'active_off',
                    'value' => false,
                    'label' => 'Disattivato'
                ),
            ),
        );
                        
        $form_fields[] = array(
            'col' => 3,
            'type' => 'text',
            'desc' => 'Il sezionale che verrà aggiunto in automatico alla numerazione delle fatture',
            'name' => 'FATTUREINCLOUD_INVOICES_SUFFIX',
            'label' => 'Sezionale fatture',
            'required' => false,
        );
                        
        $form_fields[] = array(
            'type' => 'switch',
            'label' => 'Aggiorna anagrafica cliente',
            'name' => 'FATTUREINCLOUD_CUSTOMERS_UPDATE',
            'is_bool' => true,
            'desc' => 'Se attivo aggiorna i dati del cliente modificando quelli presenti nell\'anagrafica FattureInCloud. Al contrario i dati saranno utilizzati solo sul documento.',
            'values' => array(
                array(
                    'id' => 'active_on',
                    'value' => true,
                    'label' => 'Attivato',
                ),
                array(
                    'id' => 'active_off',
                    'value' => false,
                    'label' => 'Disattivato',
                ),
            ),
        );
            
        $form = array(
            'form' => array(
                'legend' => array(
                    'title' => $legend_title,
                    'icon' => 'icon-cogs',
                ),
                'input' => $form_fields,
                'submit' => array(
                    'title' => 'Salva configurazioni',
                ),
            ),
        );
            
        return $form;
    }
    
    /**
     * Set values for the inputs of configuration form
     */
    protected function getConfigFormValues()
    {
        return array(
            'FATTUREINCLOUD_ORDERS_CREATE' => Configuration::get('FATTUREINCLOUD_ORDERS_CREATE'),
            'FATTUREINCLOUD_ORDERS_SUFFIX' => Configuration::get('FATTUREINCLOUD_ORDERS_SUFFIX'),
            'FATTUREINCLOUD_ORDERS_UPDATE_STORAGE' => Configuration::get('FATTUREINCLOUD_ORDERS_UPDATE_STORAGE'),
            'FATTUREINCLOUD_INVOICES_CREATE' => Configuration::get('FATTUREINCLOUD_INVOICES_CREATE'),
            'FATTUREINCLOUD_INVOICES_SEND_TO_SDI' => Configuration::get('FATTUREINCLOUD_INVOICES_SEND_TO_SDI'),
            'FATTUREINCLOUD_INVOICES_SUFFIX' => Configuration::get('FATTUREINCLOUD_INVOICES_SUFFIX'),
            'FATTUREINCLOUD_CUSTOMERS_UPDATE' => Configuration::get('FATTUREINCLOUD_CUSTOMERS_UPDATE'),
            'FATTUREINCLOUD_RC_CENTER' => Configuration::get('FATTUREINCLOUD_RC_CENTER'),
        );
    }
    
    /**
     * Save configuration form data
     */
    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();
        
        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }
    }
    
    /**
     * Read extra address fields values
     */
    protected function readExtraAddressValues($id_address = null)
    {
        $query = 'SELECT 
                    a.`fic_client_id`,
                    a.`sdi` as ei_code,
                    a.`pec` as certified_email'
            .' FROM `'. _DB_PREFIX_.'address` a '
            .' WHERE a.id_address = '.(int)$id_address;
            
        return  Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow($query);
    }
    
    /**
     * Write extra address fields to database
     */
    protected function writeExtraAddressValues($id_address, $fic_client_id)
    {
        $query = 'UPDATE `'._DB_PREFIX_.'address` a '
            .' SET  a.`fic_client_id` = "'.pSQL($fic_client_id).'"'
            .' WHERE a.id_address = '.(int)$id_address;
        
        $result = Db::getInstance()->execute($query);
    }
    
    /**
     * Read extra tax fields values
     */
    protected function readExtraTaxValues($id_tax = null)
    {
        $query = 'SELECT t.`fic_vat_id`'
            .' FROM `'. _DB_PREFIX_.'tax` t '
            .' WHERE t.id_tax = '.(int)$id_tax;
            
        return  Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow($query);
    }
    
    /**
     * Write extra tax fields to database
     */
    protected function writeExtraTaxValues($id_tax, $fic_vat_id_value)
    {
        if ($fic_vat_id_value != null) {
            $query = 'UPDATE `'._DB_PREFIX_.'tax` t '
                .' SET  t.`fic_vat_id` = "'.pSQL($fic_vat_id_value).'"'
                .' WHERE t.id_tax = '.(int)$id_tax;
            
            $result = Db::getInstance()->execute($query);
        }
    }

    /**
     * Add extra tax fields to back-end tax form
     */
    public function hookActionTaxFormBuilderModifier($params)
    {
        $formBuilder = $params['form_builder'];
       
        $allFields = $formBuilder->all();
        
        foreach ($allFields as $inputField => $input) {
            $formBuilder->remove($inputField);
        }
        
        $fic_vat_id_value = null;
        
        if ($params['id'] != null) {
            $fic_vat_id_value = $this->getDefaultVatID($params['data']['rate'], null);
            
            $fic_extra_values = $this->readExtraTaxValues($params['id']);
                    
            if ($fic_extra_values['fic_vat_id'] != null) {
                $fic_vat_id_value = $fic_extra_values['fic_vat_id'];
            }
        }
        
        $fic_client = $this->initFattureInCloudClient();
                        
        $vat_list_request = $fic_client->getVatTypes();
        
        if (!$vat_list_request || isset($vat_list_request['error'])) {
            $this->writeLog("ERROR - Lista IVA non recuperata: " . json_encode($vat_list_request));
        } else {
            $choices = array();
            
            foreach ($vat_list_request['data'] as $vat) {
                $key = $vat['value'] . "%";
                
                if ($vat['description'] != "") {
                    $key .= " - " . $vat['description'];
                }
                
                $choices[pSQL($key)] = $vat['id'];
            }
            
            foreach ($allFields as $inputField => $input) {
                $formBuilder->add($input);
                
                if ($inputField == 'rate') {
                    $formBuilder->add(
                        'fic_vat_id',
                        ChoiceType::class,
                        [
                            'label' => 'Corrispondenza in FattureInCloud',
                            'required' => false,
                            'data' => $fic_vat_id_value,
                            'choices' => $choices
                        ]
                    );
                }
            }
        }
    }
    
    /**
     * Hook write extra tax fields method to new tax creation on back-end
     */
    public function hookActionAfterCreateTaxFormHandler($params)
    {
        $this->writeExtraTaxValues($params['id'], $params['form_data']['fic_vat_id']);
    }
    
    /**
     * Hook write extra tax fields method to tax update on back-end
     */
    public function hookActionAfterUpdateTaxFormHandler($params)
    {
        $this->writeExtraTaxValues($params['id'], $params['form_data']['fic_vat_id']);
    }
    
    /**
     * Create new order on FattureInCloud
     */
    public function hookActionValidateOrder($params)
    {
        if (!$this->active) {
            return;
        }
        
        $order = $params['order'];
        
        if (Validate::isLoadedObject($order)) {
            $order_id = (int)$order->id;
        
            if (Configuration::get('FATTUREINCLOUD_ORDERS_CREATE')) {
                $this->writeLog("INFO - Creazione Ordine: " . $order_id);
                
                $order_to_create = $this->composeOrder($order_id);
                
                $fic_client = $this->initFattureInCloudClient();
                
                $create_order_request = $fic_client->createIssuedDocument($order_to_create);
                
                if (!$create_order_request || isset($create_order_request['error'])) {
                    $this->writeLog("ERROR - Ordine non creato: " . json_encode($create_order_request) . " - " . $fic_client->toJson() . " - " . json_encode($order_to_create));
                } else {
                    $number_to_save = $create_order_request['data']['number'];
                    
                    if ($create_order_request['data']['numeration'] != "") {
                        $number_to_save .= $create_order_request['data']['numeration'];
                    }
                    
                    $number_to_save .= "/" . $create_order_request['data']['year'];
                    
                    $query = 'INSERT INTO `'._DB_PREFIX_.'fattureInCloud`
                        (`ps_order_id`,`fic_order_id`,`fic_order_number`,`fic_order_download_token`,`fic_order_download_url`)
                        VALUES (
                        '.$order_id.',
                        '.$create_order_request['data']['id'].',
                        "'.$number_to_save.'",
                        "'.$create_order_request['data']['permanent_token'].'",
                        "'.$create_order_request['data']['url'].'"
                    );';
                    
                    $this->writeLog("INFO - Ordine creato: #" . $number_to_save);
                    
                    if (Db::getInstance()->execute($query) == false) {
                        return false;
                    }
                }
            }
        }
    }
    
    /**
     * Prepare order for creation
     */
    public function composeOrder($order_id)
    {
        $order_to_create = $this->mapPrestashopToFicDocument("order", $order_id);
        
        return $order_to_create;
    }
    
    /**
     * Create new invoice on FattureInCloud
     */
    public function hookActionOrderStatusPostUpdate($order)
    {
        if (!$this->active) {
            return;
        }
        
        $order_status = $order['newOrderStatus'];
        $order_id = $order['id_order'];
        $order_complete = new Order($order_id);
        
        if (Configuration::get('FATTUREINCLOUD_INVOICES_CREATE')
            && $order_status->paid == true
            && ($order_complete->current_state == Configuration::get('PS_OS_PAYMENT')
                || $order_complete->current_state == Configuration::get('PS_OS_WS_PAYMENT'))
            ) {
            $this->writeLog("INFO - Creazione Fattura: " . $order_id);
            
            $create_invoice = true;
            
            $fic_client = $this->initFattureInCloudClient();
            
            // Check if document already exists
            $sql_invoice_check = 'SELECT * FROM  `'._DB_PREFIX_.'fattureInCloud` WHERE `ps_order_id` = '.$order_id.' AND fic_invoice_id IS NOT NULL';
        
            if ($row_invoice_check = Db::getInstance()->getRow($sql_invoice_check)) {
                $get_document_detail_request = $fic_client->getIssuedDocumentDetails($row_invoice_check['fic_invoice_id']);
                
                if (isset($get_document_detail_request['error'])) {
                    $sql_invoice_delete = 'DELETE FROM `'._DB_PREFIX_.'fattureInCloud` WHERE id_fattureInCloud = '.$row_invoice_check['id_fattureInCloud'].';';
                    Db::getInstance()->execute($sql_invoice_delete);
                } else {
                    $create_invoice = false;
                }
            }
            
            if ($create_invoice) {
            
                $invoice_to_create = $this->composeInvoice($order_id);
                
                $create_invoice_request = $fic_client->createIssuedDocument($invoice_to_create);
                
                if (!$create_invoice_request || isset($create_invoice_request['error'])) {
                    $this->writeLog("ERROR - Fattura non creata: " . json_encode($create_invoice_request) . " - " . $fic_client->toJson() . " - " . json_encode($invoice_to_create));
                } else {
                    $number_to_save = $create_invoice_request['data']['number'];
                    
                    if ($create_invoice_request['data']['numeration'] != "") {
                        $number_to_save .= $create_invoice_request['data']['numeration'];
                    }
                    
                    $number_to_save .= "/" . $create_invoice_request['data']['year'];
                    
                    $query = 'INSERT INTO `'._DB_PREFIX_.'fattureInCloud`
                        (`ps_order_id`,`fic_invoice_id`,`fic_invoice_number`,`fic_invoice_download_token`,`fic_invoice_download_url`)
                        VALUES (
                        '.$order_id.',
                        '.$create_invoice_request['data']['id'].',
                        "'.$number_to_save.'",
                        "'.$create_invoice_request['data']['permanent_token'].'",
                        "'.$create_invoice_request['data']['url'].'"
                    );';
                    
                    Db::getInstance()->execute($query);
                        
                    $this->writeLog("INFO - Fattura creata: #" . $number_to_save);
                    
                    if (Configuration::get('FATTUREINCLOUD_INVOICES_SEND_TO_SDI')) {
                        $verify_einvoice_request = $fic_client->verifyEInvoiceXML($create_invoice_request['data']['id']);
                        
                        if (isset($verify_einvoice_request["error"])) {
                            $this->writeLog("ERROR - Verifica XML fallita: " . json_encode($verify_einvoice_request));
                        } else {
                            $send_einvoice_request = $fic_client->sendEInvoice($create_invoice_request['data']['id']);
                            
                            if (isset($send_einvoice_request["error"])) {
                                $this->writeLog("ERROR - Invio fattura elettronica fallito: " . json_encode($send_einvoice_request));
                            }
                        }
                    }
                }
                
            } else {
                
                $this->writeLog("INFO - Creazione fattura interrotta: Già esiste una fattura per questo ordine: " . json_encode($get_document_detail_request));
                
            }
        }
    }
    
    /**
     * Prepare invoice for creation
     */
    public function composeInvoice($order_id)
    {
        $invoice_to_create = $this->mapPrestashopToFicDocument("invoice", $order_id);
       
        return $invoice_to_create;
    }
    
    /**
     * Get default FattureInCloud VAT ID by rate, or create a new vat type
     */
    public function getDefaultVatID($vat_rate, $id_tax)
    {
        $vat_ids = array(
            '24' => 41,
            '23' => 40,
            '22' => 0,
            '21' => 1,
            '20' => 2,
            '10' => 3,
            '8' => 29,
            '5' => 54,
            '4' => 4,
            '0' => 6
        );
        
        if (isset($vat_ids[$vat_rate])) {
            return $vat_ids[$vat_rate];
        } elseif ($id_tax!= null) {
            $fic_client = $this->initFattureInCloudClient();
            
            
            $vat_list_request = $fic_client->getVatTypes();
            
            if (isset($vat_list_request['error'])) {
                $this->writeLog("ERROR - Lista IVA non recuperata: " . json_encode($vat_list_request));
            } else {
                
                foreach ($vat_list_request['data'] as $vat) {
                    
                    if ($vat['value'] == $vat_rate) {
                        $this->writeLog("INFO - Vat type recuperato: " . json_encode($vat));
                        $this->writeExtraTaxValues($id_tax, $vat['id']);
                        return $vat['id'];
                    }
                }
            }
            
            $vat_to_create = array("data" => array("value" => $vat_rate));
                        
            $new_vat_request = $fic_client->createVatType($vat_to_create);
            
            if (isset($new_vat_request['error'])) {
                $this->writeLog("ERROR - Vat type non creato ( " . json_encode($vat_to_create). " ): " . json_encode($new_vat_request));
                return null;
            } else {
                $this->writeLog("INFO - Vat type creato: " . json_encode($new_vat_request));
                $this->writeExtraTaxValues($id_tax, $new_vat_request['data']['id']);
                return $new_vat_request['data']['id'];
            }
        } else {
            return null;
        }
    }
    
    /**
     * Calculate gross price by net price and tax rate
     */
    public function calculateGrossPrice($net_price, $tax_rate) {
        
        $price_to_return = $net_price + (($net_price * $tax_rate) / 100);
        
        return $price_to_return;
    }
    
    /**
     * Get saved FattureInCloud VAT ID from tax table
     */
    public function getVatID($id_tax)
    {
        $query = 'SELECT t.*'
            .' FROM `'. _DB_PREFIX_.'tax` t '
            .' WHERE t.id_tax = '.(int)$id_tax;
            
        $vat_details =  Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow($query);
        
        if ($vat_details['fic_vat_id'] != null) {
            return $vat_details['fic_vat_id'];
        } else {
            return $this->getDefaultVatID((int) $vat_details['rate'], $id_tax);
        }
    }
    
    /**
     * Search for Payment account on FattureInCloud by name or create it if not found
     */
    public function getPaymentAccountIDByName($payment_name)
    {
        $fic_client = $this->initFattureInCloudClient();
        
        $query = 'SELECT p.*'
            .' FROM `'. _DB_PREFIX_.'fattureInCloud_payment_accounts` p '
            .' WHERE p.payment_account_name = "' . $payment_name . '";';
            
        $payment_details =  Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow($query);
        
        if ($payment_details['payment_account_id'] != null) {
            
            $this->writeLog("INFO - Conto di pagamento già presente nel DB : " . json_encode($payment_details));
            
            return $payment_details['payment_account_id'];
        }
        
        $payment_accounts_fieldset = array(
            "fieldset" => "detailed"
        );
        
        $payment_accounts_request = $fic_client->getPaymentAccounts($payment_accounts_fieldset);
        
        if (isset($payment_accounts_request['error'])) {
            $this->writeLog("ERROR - Ricerca conti di pagamento fallita: " . json_encode($payment_accounts_request));
        } else {
            
            $this->writeLog("INFO - Importazione conti di pagamenti");
            
            $query = 'TRUNCATE TABLE `'._DB_PREFIX_.'fattureInCloud_payment_accounts`;';
            Db::getInstance(_PS_USE_SQL_SLAVE_)->execute($query);
                
            $payment_id_to_return = 0;
            $favorite_payment_id = 0;
            
            $this->writeLog("DEBUG - Payment accounts: " . json_encode($payment_accounts_request));
            
            if ($payment_accounts_request['data'][0] != null) {
                $favorite_payment_id = $payment_accounts_request['data'][0]['id'];
            }
            
            foreach ($payment_accounts_request['data'] as $payment_account) {
                
                $query = 'INSERT INTO `'._DB_PREFIX_.'fattureInCloud_payment_accounts`
                    (`payment_account_id`,`payment_account_name`)
                    VALUES (
                    '.$payment_account['id'].',
                    "'.$payment_account['name'].'"
                );';
                
                Db::getInstance(_PS_USE_SQL_SLAVE_)->execute($query);
                
                if (trim(strtolower($payment_account['name'])) == trim(strtolower($payment_name))) {
                    
                    $this->writeLog("INFO - Conto di pagamento trovato su FattureInCloud: " . json_encode($payment_account));
                    
                    $payment_id_to_return = $payment_account['id'];
                }
                
                if ($payment_account['favorite'] == true) {
                    $favorite_payment_id = $payment_account['id'];
                }
                
            }
            
            if ($payment_id_to_return != 0) {
                return $payment_id_to_return;
            }
        }
        
        $payment_account_to_create = array("data" => array("name" => $payment_name));
        $create_payment_account_request = $fic_client->createPaymentAccount($payment_account_to_create);
        
        if (isset($create_payment_account_request['error'])) {
            $this->writeLog("ERROR - Creazione conto di pagamento fallita: " . json_encode($create_payment_account_request));
            
            if ($favorite_payment_id != 0) {
                return $favorite_payment_id;
            }
            
        } else {
            $this->writeLog("INFO: Conto di pagamento creato: #" . $create_payment_account_request['data']['id']);
            
            $query = 'INSERT INTO `'._DB_PREFIX_.'fattureInCloud_payment_accounts`
                (`payment_account_id`,`payment_account_name`)
                VALUES (
                '.$create_payment_account_request['data']['id'].',
                "'.$payment_name.'"
            );';
            
            Db::getInstance(_PS_USE_SQL_SLAVE_)->execute($query);
                
            return $create_payment_account_request['data']['id'];
        }
    }
    
    /**
     * Get MPXX code by Prestashop payment module
     */
    public function getEIPaymentCodeByModule($payment_module)
    {
        
        $ei_payment = array();
        
        $ei_payment['payment_method'] = "MP08";
        
        if ($payment_module == 'ps_cashondelivery') { 
            $ei_payment['payment_method'] = "MP01";
        } elseif ($payment_module == 'ps_checkpayment') { 
            $ei_payment['payment_method'] = "MP02";
        } elseif (strpos($payment_module, 'wire') !== false) { 
            $ei_payment['payment_method'] = "MP05";
            
            $payment_module_details = Module::getInstanceByName($payment_module);
            
            if (!empty($payment_module_details->owner)) {
                $ei_payment['bank_beneficiary'] = $payment_module_details->owner;
            }
            
            if (!empty($payment_module_details->details)) {
                $ei_payment['bank_iban'] = $payment_module_details->details;
            }
        } 
        
        return $ei_payment;
    }
    
    /**
     * Map Prestashop order to FattureInCloud issuedDocument model
     */
    public function mapPrestashopToFicDocument($document_type, $order_id)
    {
        $order = new Order($order_id);
       
        $billing_address_id = $order->id_address_invoice;
        $billing_address = new Address($billing_address_id);
        
        $customer_id = $billing_address->id_customer;
        $customer = new Customer($customer_id);
        
        if ($order->id_address_delivery) {
            $shipping_address_id = $order->id_address_delivery;
            $shipping_address = new Address($shipping_address_id);
        }
        
        $billing_address_country = new Country($billing_address->id_country, $customer->id_lang);
        $billing_address_state = new State($billing_address->id_state, $customer->id_lang);
        
        $entity = array();
        
        if ($billing_address->company && trim($billing_address->company) != "") {
            $entity['name'] = $billing_address->company;
        } else {
            $entity['name'] = $customer->firstname.' '.$customer->lastname;
        }
        
        $composed_billing_address = $billing_address->address1;
        
        if ($billing_address->address2 && trim($billing_address->address2) != "") {
            $composed_billing_address .= " - ".$billing_address->address2;
        }
        
        $composed_shipping_address = "";
        
        if (isset($shipping_address) && $shipping_address->id != $billing_address->id) {
            $shipping_address_country = new Country($shipping_address->id_country, $customer->id_lang);
            $shipping_address_state = new State($shipping_address->id_state, $customer->id_lang);

            if ($shipping_address->company && trim($shipping_address->company) != "") {
                $composed_shipping_address =  $shipping_address->company;
            }
        
            $composed_shipping_address .= "\r\n". $shipping_address->address1;
            
            if ($shipping_address->address2 && trim($shipping_address->address2) != "") {
                $composed_shipping_address .= "\r\n". $shipping_address->address2;
            }
            
            
            $composed_shipping_address .= "\r\n". $shipping_address->postcode . ' ' . $shipping_address->city . ' ' . $shipping_address_state->iso_code;
            $composed_shipping_address .= "\r\n". $shipping_address_country->name;
        }
        
        $entity['address_street'] = $composed_billing_address;
        $entity['address_postal_code'] = $billing_address->postcode;
        $entity['address_city'] = $billing_address->city;
        
        if (!empty($billing_address_state->iso_code)) {
            $entity['address_province'] = $billing_address_state->iso_code;
        }
        
        $entity['country_iso'] = $billing_address_country->iso_code;
        $entity['shipping_address'] = $composed_shipping_address;
        
        if ($billing_address->dni) {
            $entity['tax_code'] = $billing_address->dni;
        }
        
        if ($billing_address->vat_number) {
            $entity['vat_number'] = $billing_address->vat_number;
        }
    
        $entity['code'] = 'PS-'. $customer_id . ' - '. $billing_address_id;
        
        if ($customer->email) {
            $entity['email'] = $customer->email;
        }
        
        if ($billing_address->phone) {
            $entity['phone'] = $billing_address->phone;
        }
        
        $fic_address_fields = $this->readExtraAddressValues($billing_address_id);
        
        if (isset($fic_address_fields['fic_client_id'])) {
            $entity['id'] = $fic_address_fields['fic_client_id'];
        }
        
        if (isset($fic_address_fields['certified_email'])) {
            $entity['certified_email'] = $fic_address_fields['certified_email'];
        }
        
        if (isset($fic_address_fields['ei_code'])) {
            $entity['ei_code'] = $fic_address_fields['ei_code'];
        }
            
        $fic_client = $this->initFattureInCloudClient();
        
        $client_found = true;
        
        if (!isset($entity['id'])) {
            $client_found = false;
            
            $check_client_filters = array(
                "fields" => "id,code,vat_number,tax_code",
                "filter_type" => "or",
                "filter" => array(
                    0 => array(
                        "field" => "code",
                        "value" => $entity['code'],
                        "op" => "="
                    )
                )
            );
            
            if (isset($entity['vat_number'])) {
                $check_client_filters["filter"][] = array(
                    "field" => "vat_number",
                    "value" => $entity['vat_number'],
                    "op" => "="
                );
            }
            
            if (isset($entity['tax_code'])) {
                $check_client_filters["filter"][] = array(
                    "field" => "tax_code",
                    "value" => $entity['tax_code'],
                    "op" => "="
                );
            }
            
            $check_client_request = $fic_client->getClients($check_client_filters);
            
            if (isset($check_client_request['error'])) {
                $this->writeLog("ERROR - Ricerca cliente fallita: " . json_encode($check_client_request));
            } else {
                
                foreach($check_client_request['data'] as $check_client) {
                    
                    if ($check_client['code'] == $entity['code']) {
                        
                        $this->writeLog("INFO - Cliente trovato su FIC per codice: " . json_encode($check_client));
                        
                        $client_found = true;
                        $entity['id'] = $check_client['id'];
                        $this->writeExtraAddressValues($billing_address_id, $entity['id']);
                        break;
                    }
                    
                }
                
                foreach($check_client_request['data'] as $check_client) {
                    
                    if ($check_client['vat_number'] == $entity['vat_number']
                        || $check_client['tax_code'] == $entity['tax_code']) {
                        
                        $this->writeLog("INFO - Cliente trovato su FIC per vat_number/tax_code: " . json_encode($check_client));
                        
                        $client_found = true;
                        $entity['id'] = $check_client['id'];
                        $this->writeExtraAddressValues($billing_address_id, $entity['id']);
                        break;
                    }
                    
                }
            }
        }
        
        
        if ($client_found) {
            if (Configuration::get('FATTUREINCLOUD_CUSTOMERS_UPDATE')) {
                $entity_to_edit = array("data" => $entity);
                
                $edit_client_request = $fic_client->editClient($entity_to_edit);
                
                if (isset($edit_client_request['error'])) {
                    $this->writeLog("ERROR - Aggiornamento cliente fallito: " . json_encode($edit_client_request));
                } else {
                    $this->writeLog("INFO - Cliente aggiornato: " . json_encode($edit_client_request));
                }
            }
        } else {
            $entity_to_create = array("data" => $entity);
            
            $new_client_request = $fic_client->createClient($entity_to_create);
            
            if (isset($new_client_request['error'])) {
                $this->writeLog("ERROR - Cliente non creato: " . json_encode($new_client_request));
            } else {
                $this->writeLog("INFO - Cliente creato: " . json_encode($new_client_request));
                $entity['id'] = $new_client_request['data']['id'];
                $this->writeExtraAddressValues($billing_address_id, $entity['id']);
            }
        }
        
        $items = array();
        $products = $order->getProducts();
        
        $fic_no_vat_id = $this->getDefaultVatID(0, null);
        
        foreach ($products as $product) {
            
            $item = array();
            
            $item['stock'] = false;
            
            if (!empty($product['product_reference'])) {
                $item['code'] = $product['product_reference'];
            } else {
                $item['code'] = $product['reference'];
            }
            
            if (!empty($item['code'])) {
                $check_product_filters = array(
                    "filter" => array(
                        array(
                            "field" => "code",
                            "value" => $item['code'],
                            "op" => "="
                        )
                    )
                );
                
                $check_product_request = $fic_client->getProducts($check_product_filters);
                
                if (isset($check_product_request['error'])) {
                    $this->writeLog("ERROR - Ricerca prodotto fallita: " . json_encode($check_product_request));
                } elseif ($check_product_request['total'] == 1) {
                    $item['product_id'] = $check_product_request['data'][0]['id'];
                    if ($document_type == "order" && Configuration::get('FATTUREINCLOUD_ORDERS_UPDATE_STORAGE')) {
                        $item['stock'] = true;
                    } else if ($document_type != "order" && Configuration::get('FATTUREINCLOUD_ORDERS_CREATE') && Configuration::get('FATTUREINCLOUD_ORDERS_UPDATE_STORAGE')) {
                        $item['stock'] = false;
                    }
                }
            }
            
            $item['name'] = $product['product_name'];
            $item['qty'] = $product['product_quantity'];
            
            if (!empty($product['reduction_percent']) && floatval($product['reduction_percent']) > 0) {
                
                $item['gross_price'] = $this->calculateGrossPrice($product['original_product_price'], $product['tax_rate']);
                $item['discount'] = $product['reduction_percent'];
            
            } else if (!empty($product['reduction_amount_tax_excl']) && floatval($product['reduction_amount_tax_excl']) > 0) {
                $item['gross_price'] = $this->calculateGrossPrice($product['original_product_price'], $product['tax_rate']);
            } else {
                $item['gross_price'] = $product['unit_price_tax_incl'];
            }
            
            $item['vat'] = array();
            $item['vat']['id'] = $fic_no_vat_id;
            
            if (!empty($product['tax_calculator']->taxes[0])) {
                $vat_id = $product['tax_calculator']->taxes[0]->id;
                $item['vat']['id'] = $this->getVatID($vat_id);
            }
            
            $items[] = $item;
            
            if (!empty($product['reduction_amount_tax_excl']) && floatval($product['reduction_amount_tax_excl']) > 0) {
                    
                $discount_item = array(
                    'name' => 'Sconto applicato',
                    'qty' => 1,
                    'gross_price' => - $product['reduction_amount_tax_incl'],
                    'vat' => $item['vat']
                );
                
                $items[] = $discount_item;
            }
            
        }
        
        $coupons = $order->getCartRules();
        
        $overall_discount = 0;
        
        $used_coupons = array();
        
        foreach ($coupons as $coupon) {
            $used_coupons[] = $coupon['name'];
            $overall_discount += $coupon['value'];
        }
        
        if ($overall_discount > 0) {
            $coupon_description = implode(", ", $used_coupons);
            
            $coupon_description .= "\n\n" . "Il valore dei coupon utilizzati è riportato tra i totali alla voce sconto.";
            
            $coupon_item = array(
                'name' => 'Coupon utilizzati',
                'description' => $coupon_description,
                'qty' => count($used_coupons),
                'gross_price' => 0,
                'vat' => array('id' => 6)
            );
            
            $items[] = $coupon_item;
        }
        
        $carrier = new Carrier($order->id_carrier);
        
        $fic_carrier_vat_id = $fic_no_vat_id;
        
        if (!empty($carrier->getTaxCalculator($shipping_address)->taxes[0])) {
            $carrier_vat_id = $carrier->getTaxCalculator($shipping_address)->taxes[0]->id;
            $fic_carrier_vat_id = $this->getVatID($carrier_vat_id);
        }
        
        if ($order->total_shipping > 0) {
            $item = array();
            $item['name'] = "Spedizione: " . $carrier->name;
            $item['gross_price'] = $order->total_shipping_tax_incl;
            $item['vat'] = array();
            $item['vat']['id'] = $fic_carrier_vat_id;
            $items[] = $item;
        }
        
        if ($order->total_wrapping > 0) {
            $item = array();
            $item['name'] = "Imballo";
            $item['gross_price'] = $order->total_wrapping_tax_incl;
            $item['vat'] = array();
            $item['vat']['id'] = $fic_carrier_vat_id;
            $items[] = $item;
        }
        
        $order_state = new OrderState($order->current_state);
        
        $payments = array();
        $payment = array();
        $payment['due_date'] = date("Y-m-d", strtotime($order->date_add));
        
        if ($order_state->paid) {
            
            $payment['status'] = "paid";
            
            $paid_date_time = strtotime($order->invoice_date);
            
            if ($paid_date_time > 0) {
                $payment['paid_date'] = date("Y-m-d", $paid_date_time);
            } else {
                $payment['paid_date'] = date("Y-m-d");
            }
            
            $payment_account_id = $this->getPaymentAccountIDByName($order->payment);
            
            if ($payment_account_id != null) {
                $payment['payment_account'] = array("id" => $payment_account_id);
            }
       
        } else {
            $payment['status'] = "not_paid";
        }
        
        $payments[] = $payment;
        
        $order_currency = new Currency($order->id_currency);
        $currency = array('id' => $order_currency->iso_code, 'exchange_rate' => 1);
        
        if (!empty($order_currency->conversion_rate)) {
            $currency['exchange_rate'] = number_format($order_currency->conversion_rate, 5);
        }
        
        $document = array(
            "data" => array(
                "type" => $document_type,
                "currency" => $currency,
                "entity" => $entity,
                "items_list" => $items,
                "visible_subject" => 'Ordine #' . $order->reference,
                "subject" => 'Ordine #' . $order->reference,
                "payments_list" => $payments,
                "payment_method" => array(
                    "name" => $order->payment
                ),
                "show_payment" => false,
                "show_payment_method" => true,
                "rc_center" => Configuration::get('FATTUREINCLOUD_RC_CENTER'),
                "use_gross_prices" => true
            ),
            "options" => array(
                "fix_payments" => true
            )
        );
        
        if ($overall_discount > 0) {
            $document["data"]["amount_due_discount"] = -number_format($overall_discount, 2);
        }
        
        if ($notes = $order->getFirstMessage()) {
            $document["data"]["notes"] = $notes;
        }
        
        if ($document_type == "order") {
            
            if (Configuration::get('FATTUREINCLOUD_ORDERS_SUFFIX')) {
                $document['data']['numeration'] = Configuration::get('FATTUREINCLOUD_ORDERS_SUFFIX');
            }
            
        } elseif ($document_type == "invoice") {
        
            if (Configuration::get('FATTUREINCLOUD_INVOICES_SUFFIX')) {
                $document['data']['numeration'] = Configuration::get('FATTUREINCLOUD_INVOICES_SUFFIX');
            }
            
            if (Configuration::get('FATTUREINCLOUD_INVOICES_SEND_TO_SDI')) {
                $document['data']['e_invoice'] = true;
                $document['data']['ei_data'] = $this->getEIPaymentCodeByModule($order->module);
            }
        
        }
        
        return $document;
    }
    
    /**
     * Get FattureInCloud invoice number from database when needed
     */
    public function hookActionInvoiceNumberFormatted($params)
    {
        $order_id = $params['OrderInvoice']->id_order;
        
        $query = 'SELECT * FROM  `'._DB_PREFIX_.'fattureInCloud` WHERE `ps_order_id` = '.$order_id.' AND fic_invoice_id IS NOT NULL;';
        
        if ($row = Db::getInstance()->getRow($query)) {
            if ($row['fic_invoice_number'] != null && $row['fic_invoice_number'] != "") {
                return $row['fic_invoice_number'];
            }
        }
    }
    
    /**
     * Sanitize file name
     */
    public function prepareFileName($string)
    {
        $string = preg_replace('/[^a-zA-Z0-9_]+/', '_', $string);
        return $string;
    }
    
    /**
     * Display Invoice PDF if found
     */
    public function hookDisplayPDFInvoice($params)
    {
        if (Configuration::get('FATTUREINCLOUD_INVOICES_CREATE')) {
            $order_id = $params['object']->id_order;
            
            $order = new Order((int)$order_id);
            
            if (!Validate::isLoadedObject($order)) {
                die(Tools::displayError('The order cannot be found within your database.'));
            }
            
            $sql_invoice_to_display = 'SELECT * FROM  `'._DB_PREFIX_.'fattureInCloud` WHERE `ps_order_id` = '.$order_id.' AND fic_invoice_id IS NOT NULL;';
            
            if ($invoice_to_display = Db::getInstance()->getRow($sql_invoice_to_display)) {
                $pdf_url = "";
                
                if (isset($invoice_to_display['fic_invoice_download_url'])) {
                    $pdf_url = $invoice_to_display['fic_invoice_download_url'];
                } elseif (isset($invoice_to_display['fic_invoice_download_token'])) {
                    $pdf_url = "https://compute.fattureincloud.it/doc/" . $invoice_to_display['fic_invoice_download_token'] . ".pdf";
                } else {
                    die(Tools::displayError('No invoice is available.'));
                }
                
                $billing_address_id = $order->id_address_invoice;
                $billing_address = new Address($billing_address_id);
                
                $customer_id = $billing_address->id_customer;
                $customer = new Customer($customer_id);
                
                $customer_name = "";
                
                if ($billing_address->company && trim($billing_address->company) != "") {
                    $customer_name = $billing_address->company;
                } else {
                    $customer_name = $customer->firstname.'_'.$customer->lastname;
                }
                
                $pdf_filename = $this->prepareFileName("Fattura-" . $invoice_to_display['fic_invoice_number'] . '-' . $customer_name) . '.pdf';
                
                file_put_contents($pdf_filename, file_get_contents($pdf_url));
                
                header('Content-Description: File Transfer');
                header('Content-Type: application/pdf');
                header('Content-Length: ' . filesize($pdf_filename));
                header('Content-Disposition: attachment; filename=' . basename($pdf_filename));
                
                readfile($pdf_filename);
                
                exit();
            }
        }
    }
    
    /**
     * Init FattureInCloudClient with required data
     */
    public function initFattureInCloudClient()
    {
        $fic_client = new FattureInCloudClient();
        $fic_client->setDeviceCode(Configuration::get('FATTUREINCLOUD_DEVICE_CODE'));
        $fic_client->setCompanyId(Configuration::get('FATTUREINCLOUD_COMPANY_ID'));
        $fic_client->setAccessToken(Configuration::get('FATTUREINCLOUD_ACCESS_TOKEN'));
        $fic_client->setRefreshToken(Configuration::get('FATTUREINCLOUD_REFRESH_TOKEN'));
        
        $fic_client->setUserAgent("FattureInCloud/Prestashop/" . $this->version);
        
        return $fic_client;
    }
    
    /**
     * Write log file
     */
    public function writeLog($line)
    {
        $log_file_path = _PS_MODULE_DIR_ . 'fattureincloud/log/';
        $current_log_file = $log_file_path . 'log_' . date("Y-m-d"). '.log';
        
        if (!file_exists($current_log_file)) {
            $log_rotate_days = 15;
            
            $current_log_date = date_create_from_format('Y-m-d', date("Y-m-d"));
            
            foreach (glob($log_file_path . '*.log') as $log) {
                $log_file_name = basename($log, ".log");
                $log_date_split = explode("_", $log_file_name);
                $log_date = date_create_from_format('Y-m-d', $log_date_split[1]);
                $date_difference = date_diff($log_date, $current_log_date);
            
                if ($date_difference->d > $log_rotate_days) {
                    unlink($log);
                }
            }
        }
        
        $log_line = "[" . date("Y/m/d h:i:s", time()) . "] [v" . $this->version . "] " . $line . "\n";
        file_put_contents($current_log_file, $log_line, FILE_APPEND);
    }
}
