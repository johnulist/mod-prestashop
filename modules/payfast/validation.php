<?php
/**
 * validation.php
 *
 * Copyright (c) 2011 PayFast (Pty) Ltd
 * 
 * LICENSE:
 * 
 * This payment module is free software; you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published
 * by the Free Software Foundation; either version 3 of the License, or (at
 * your option) any later version.
 * 
 * This payment module is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 * or FITNESS FOR A PARTICULAR PURPOSE. See the GNU Lesser General Public
 * License for more details.
 * 
 * @author     Jonathan Page
 * @copyright  2011 PayFast (Pty) Ltd
 * @license    http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link       http://www.payfast.co.za/help/prestashop
 */

include( dirname(__FILE__).'/../../config/config.inc.php' );
include( dirname(__FILE__).'/payfast.php' );
include( dirname(__FILE__).'/payfast_common.inc' );

// Check if this is an ITN request
// Has to be done like this (as opposed to "exit" as processing needs
// to continue after this check.
if( ( $_GET['itn_request'] == 'true' ) )
{
    // Variable Initialization
    $pfError = false;
    $pfErrMsg = '';
    $pfDone = false;
    $pfData = array();
    $pfHost = ( ( Configuration::get('PAYFAST_MODE') == 'live' ) ? '' : 'sandbox.' ) . 'payfast.co.za';
    $pfOrderId = '';
    $pfParamString = '';
    
    $payfast = new PayFast();

    pflog( 'PayFast ITN call received' );
    
    //// Notify PayFast that information has been received
    if( !$pfError && !$pfDone )
    {
        header( 'HTTP/1.0 200 OK' );
        flush();
    }

    //// Get data sent by PayFast
    if( !$pfError && !$pfDone )
    {
        pflog( 'Get posted data' );
    
        // Posted variables from ITN
        $pfData = pfGetData();
    
        pflog( 'PayFast Data: '. print_r( $pfData, true ) );
    
        if( $pfData === false )
        {
            $pfError = true;
            $pfErrMsg = PF_ERR_BAD_ACCESS;
        }
    }

    //// Verify security signature
    if( !$pfError && !$pfDone )
    {
        pflog( 'Verify security signature' );
    
        // If signature different, log for debugging
        if( !pfValidSignature( $pfData, $pfParamString ) )
        {
            $pfError = true;
            $pfErrMsg = PF_ERR_INVALID_SIGNATURE;
        }
    }

    //// Verify source IP (If not in debug mode)
    if( !$pfError && !$pfDone && !PF_DEBUG )
    {
        pflog( 'Verify source IP' );
    
        if( !pfValidIP( $_SERVER['REMOTE_ADDR'] ) )
        {
            $pfError = true;
            $pfErrMsg = PF_ERR_BAD_SOURCE_IP;
        }
    }

    //// Get internal cart
    if( !$pfError && !$pfDone )
    {
        // Get order data
        $cart = new Cart((int) $pfData['custom_int1']);

        pflog( "Purchase:\n". print_r( $cart, true )  );
    }

    //// Verify data received
    if( !$pfError )
    {
        pflog( 'Verify data received' );
    
        $pfValid = pfValidData( $pfHost, $pfParamString );
    
        if( !$pfValid )
        {
            $pfError = true;
            $pfErrMsg = PF_ERR_BAD_ACCESS;
        }
    }
        
    //// Check data against internal order
    if( !$pfError && !$pfDone )
    {
       // pflog( 'Check data against internal order' );

        // Check order amount
        if( !pfAmountsEqual( $pfData['amount_gross'], $cart->getOrderTotal() ) )
        {
            $pfError = true;
            $pfErrMsg = PF_ERR_AMOUNT_MISMATCH;
        }
        // Check secure ID
        elseif( strcasecmp( $pfData['custom_str1'], $cart->secure_key ) != 0 )
        {
            $pfError = true;
            $pfErrMsg = PF_ERR_SESSIONID_MISMATCH;
        }
    }

    $vendor_name = Configuration::get('PS_SHOP_NAME');
    $vendor_url = Tools::getShopDomain(true, true);

    //// Check status and update order
    if( !$pfError && !$pfDone )
    {
        pflog( 'Check status and update order' );

        $sessionid = $pfData['custom_str1'];
        $transaction_id = $pfData['pf_payment_id'];
        
        if (empty(Context::getContext()->link))
        Context::getContext()->link = new Link();

        switch( $pfData['payment_status'] )
        {
            case 'COMPLETE':
                pflog( '- Complete' );

                // Update the purchase status
                $payfast->validateOrder((int)$pfData['custom_int1'], _PS_OS_PAYMENT_, (float)$pfData['amount_gross'], 
                    $payfast->displayName, NULL, array('transaction_id'=>$transaction_id), NULL, false, $pfData['custom_str1']);
                
                break;

            case 'FAILED':
                pflog( '- Failed' );

                // If payment fails, delete the purchase log
                $payfast->validateOrder((int)$pfData['custom_int1'], _PS_OS_ERROR_, (float)$pfData['amount'], 
                    $payfast->displayName, NULL,array('transaction_id'=>$transaction_id), NULL, false, $pfData['custom_str1']);

                break;

            case 'PENDING':
                pflog( '- Pending' );

                // Need to wait for "Completed" before processing
                break;

            default:
                // If unknown status, do nothing (safest course of action)
            break;
        }
    }

    // If an error occurred
    if( $pfError )
    {
        pflog( 'Error occurred: '. $pfErrMsg );
    }

    // Close log
    pflog( '', true );
    exit();
}
