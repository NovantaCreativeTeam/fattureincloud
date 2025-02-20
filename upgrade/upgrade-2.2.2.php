<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_2_2($module) {
    return $module->registerHook('displayAdminOrderSide');
}