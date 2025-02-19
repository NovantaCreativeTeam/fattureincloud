<?php
/**
* FattureInCloud Prestashop Module
*
*  @author    Websuvius di Michele Matto <michele@websuvius.it>
*  @copyright FattureInCloud - Madbit Entertainment S.r.l.
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*/
$sql = array();

// Create sql table to store documents details
$sql_create_main_table = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'fattureInCloud` (
    `id_fattureInCloud` int(11) NOT NULL AUTO_INCREMENT,
    `ps_order_id` int(11),
    `fic_order_id` bigint(20),
    `fic_invoice_id` bigint(20),
    `fic_order_download_token` varchar(255),
    `fic_invoice_download_token` varchar(255),
    `fic_order_download_url` varchar(255),
    `fic_invoice_download_url` varchar(255),
    `fic_order_number` varchar(255),
    `fic_invoice_number` varchar(255),
    PRIMARY KEY  (`id_fattureInCloud`)
) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8;';

Db::getInstance()->execute($sql_create_main_table);

// Create sql table to store payment accounts
try {
    $sql_create_payment_accounts_table = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'fattureInCloud_payment_accounts` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `payment_account_id` bigint(20),
        `payment_account_name` varchar(255),
        PRIMARY KEY  (`id`)
    ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8;';
    
    Db::getInstance()->execute($sql_create_payment_accounts_table);
} catch (Exception $e) {
}

// Add new fields to fattureInCloud table ( to update old plugin versions )
try {
    $sql_new_fields =  'ALTER TABLE `'. _DB_PREFIX_.'fattureInCloud` 
        ADD `fic_order_download_url` varchar(255),
        ADD `fic_invoice_download_url` varchar(255)';
    Db::getInstance()->execute($sql_new_fields);
} catch (Exception $e) {
}

// Add client_id to address table
try {
    $sql_add_fic_client_id =  'ALTER TABLE `'. _DB_PREFIX_.'address` ADD `fic_client_id` bigint(20)';
    Db::getInstance()->execute($sql_add_fic_client_id);
} catch (Exception $e) {
}

// Add vat_id to tax table
try {
    $sql_add_fic_vat_id =  'ALTER TABLE `'. _DB_PREFIX_.'tax` ADD `fic_vat_id` bigint(20)';
    Db::getInstance()->execute($sql_add_fic_vat_id);
} catch (Exception $e) {
}

// Disable automatic invoice on status 2 / 12
$sql_check_field = 'SHOW COLUMNS FROM '._DB_PREFIX_.'order_state LIKE "pdf_invoice";';
 
if (Db::getInstance()->executeS($sql_check_field)) {
    $sql_update_states = 'UPDATE '._DB_PREFIX_.'order_state 
                SET pdf_invoice = 0
                WHERE id_order_state = 2 OR id_order_state = 12;';
                
    Db::getInstance()->execute($sql_update_states);
}
