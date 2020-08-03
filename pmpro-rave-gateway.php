<?php
/*
Plugin Name: Rave Payment Gateway for Paid Memberships Pro
Description: Rave Payment Gateway for Paid Memberships Pro
Version: 1.0.0
*/

define("PMPRO_RAVE_PAYMENTGATEWAY_DIR", dirname(__FILE__));


//load payment gateway class
require_once(PMPRO_RAVE_PAYMENTGATEWAY_DIR . "/classes/class.pmprogateway_rave.php");
