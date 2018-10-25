<?php

/**
 *
 * Biscolab Laravel Gestpay - GestpayBuilder Class
 * web : robertobelotti.com, github.com/biscolab
 *
 * @package Biscolab\Gestpay
 * @author author: Roberto Belotti - info@robertobelotti.com
 * @license MIT License @ https://github.com/biscolab/laravel-gestpay/blob/master/LICENSE
 */

namespace Biscolab\Gestpay;

use Exception;
use Redirect;
use Biscolab\Gestpay\GestpayResponse;

class GestpayBuilder {

	/**
	 * The shopLogin
	 * please visit http://api.gestpay.it/#encrypt
	 */
	protected $shopLogin;

	/**
	 * The uicCode
	 * please visit http://api.gestpay.it/#encrypt
	 */	
	protected $uicCode;

	/**
	 * The test
	 * Indicates whether the software is in test mode
	 */	
	protected $test;

	/**
	 * The API Official request URI
	 */
    protected $api_prod_url = 'https://ecommS2S.sella.it/gestpay/GestPayWS/WsCryptDecrypt.asmx?wsdl';

	/**
	 * The API TEST request URI
	 */    
    protected $api_test_url = 'https://sandbox.gestpay.net/gestpay/GestPayWS/WsCryptDecrypt.asmx?wsdl';	
	
	/**
	 * PaymentPage Official URI
	 */
    protected $payment_page_prod_url = 'https://ecomm.sella.it/pagam/pagam.aspx';
	
	/**
	 * PaymentPage TEST URI
	 */    
    protected $payment_page_test_url = 'https://testecomm.sella.it/pagam/pagam.aspx';	

	/**
	 * see http://api.gestpay.it/#introduction
	 *
	 * @param $shopLogin string Your shop login code
	 * @param $uicCode string Currency code - 242 is euro - see http://api.gestpay.it/#currency-codes
	 * @param $test boolean Indicates whether the software is in test mode
	 */
	public function __construct($shopLogin, $uicCode, $test = false)
	{
		$this->shopLogin	= $shopLogin;
		$this->uicCode		= $uicCode;
		$this->test			= $test;
	}
	
	/**
	 * Build and Encrypt XML string in order to Perform payment
	 *
	 * @param $amount the Transaction amount. Do not insert thousands separator. Decimals, max. 2 numbers, are optional and separator is the point (Mandatory)
	 * @param $shopTransactionId the Identifier attributed to merchant’s transaction (Mandatory)
	 * @param $customParameters array of custom payment parameters, default = [] - see http://docs.gestpay.it/gs/how-gestpay-works.html#configuration-of-fields--parameters
	 * @param $languageId the language ID (for future use), default = NULL - see http://api.gestpay.it/#language-codes
	 * @param mixed $shopLogin the shopLogin if you want to override the default config, default = NULL
	 *
	 * @return boolean | redirect on payment page
	 */
    public function pay($amount, $shopTransactionId, $customParameters = [], $languageId = null, $shopLogin = null)
    {

		$customInfo = http_build_query($customParameters, '', '*P1*');
		$encrData = ['amount' => $amount, 'shopTransactionId' => $shopTransactionId, 'customInfo' => $customInfo];
		
		if(!is_null($languageId)){
			$encrData["languageId"] = $languageId;
		}
		
		if(strlen($shopLogin) > 0){
			$this->shopLogin = $shopLogin;
		}
		
        $res = $this->Encrypt($encrData);

        if ( false !== strpos($res, '<TransactionResult>OK</TransactionResult>') && preg_match('/<CryptDecryptString>([^<]+)<\/CryptDecryptString>/', $res, $match) ) {
        	$payment_page_url = ($this->test)? $this->payment_page_test_url : $this->payment_page_prod_url;
            return Redirect::to($payment_page_url.'?a=' . $this->shopLogin . '&b=' . $match[1]);
        } else {

			$xml = self::cleanXML($res);
			$errorMessage = $xml->Body->EncryptResponse->EncryptResult->GestPayCryptDecrypt->ErrorDescription;
			$errorCode = $xml->Body->EncryptResponse->EncryptResult->GestPayCryptDecrypt->ErrorCode;
			throw new Exception($errorMessage . " (Code: {$errorCode})" );

		}

	}

	/**
	 * http://api.gestpay.it/#encrypt
	 *
	 * @param array $data
	 *
	 * @return string Encrypted XML string
	 */
    public function Encrypt($data = [])
    {
    	$xml_data = '';

    	if(!isset($data['amount'])) {throw new Exception('Manca importo');}
    	if(!isset($data['shopTransactionId'])) {throw new Exception('Manca transazione');}

    	$data = array_merge(['shopLogin' => $this->shopLogin, 'uicCode' => $this->uicCode], $data);

    	foreach ($data as $key => $value) {
    		$xml_data.= '<'.$key.'>'.$value.'</'.$key.'>';
    	}

        $xml = file_get_contents( dirname(__FILE__) . '/../xml/encrypt.xml');

        $xml = str_replace('{request}', $xml_data, $xml);

        return $this->call($xml, 'Encrypt');
    }

	/**
	 * Decrypt SOAP response 
	 * http://api.gestpay.it/#decrypt
	 *
	 * @param string $CryptedString The SOAP response crypted string
	 *
	 * @return string XML SOAP API call
	 */
    function Decrypt($CryptedString)
    {
        $xml_data = '';
    	$data = ['shopLogin' => $this->shopLogin, 'CryptedString' => $CryptedString];
    	foreach ($data as $key => $value) {
    		$xml_data.= '<'.$key.'>'.$value.'</'.$key.'>';
    	}
    	$xml = file_get_contents( dirname(__FILE__) . '/../xml/decrypt.xml');
        $xml = str_replace('{request}', $xml_data, $xml);

        $res = $this->call($xml, 'Decrypt');

        return $res;
    }

	/**
	 * Decrypt SOAP response in order to checks whether the payment has been successful
	 *
	 * @param mixed $shopLogin the shopLogin if you want to override the default config, default = NULL
	 * @return array $result containing 'transaction_result' (boolean true|false) and 'shop_transaction_id'
	 */
    public function checkResponse($shopLogin = null)
    {

    	$b = request()->input('b');
		
		if(strlen($shopLogin) > 0){
			$this->shopLogin = $shopLogin;
		}

        $xml_response = $this->Decrypt($b);

        $xml = self::cleanXML($xml_response);

        $response = $xml->Body->DecryptResponse->DecryptResult->GestPayCryptDecrypt;

        $transaction_result 	= (strtolower($response->TransactionResult) == 'ok');
        $shop_transaction_id 	= (string)$response->ShopTransactionID;        
        $error_code 			= (string)$response->ErrorCode;        
        $error_description 		= (string)$response->ErrorDescription;        
		$custom_infoStr	 		= str_replace('*P1*', '&' , urldecode($response->CustomInfo));
		parse_str($custom_infoStr, $custom_info);

        $result = new GestpayResponse($transaction_result, $shop_transaction_id, $error_code, $error_description, $custom_info);
		$result->popolate_full_response($response);

        return $result;
    }

	/**
	 * perform GestPay API call
	 *
	 * @param string $xml The XML string to send
	 * @param string $op The function called - Default 'Encrypt'
	 *
	 * @return string The SOAP response
	 */
    public function call($xml, $op = 'Encrypt')
    {
        $header = array(
            "Content-type: text/xml; charset=utf-8\"",
            "Accept: text/xml",
            "Content-length: ".strlen($xml),
            "SOAPAction: \"https://ecomm.sella.it/".$op."\"",
        );

        $api_url = ($this->test)? $this->api_test_url : $this->api_prod_url;

        $soap = curl_init();
        curl_setopt($soap, CURLOPT_URL, $api_url );
        curl_setopt($soap, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($soap, CURLOPT_TIMEOUT,        10);
        curl_setopt($soap, CURLOPT_RETURNTRANSFER, true );
        curl_setopt($soap, CURLOPT_POST,           true );
        curl_setopt($soap, CURLOPT_POSTFIELDS,     $xml);
        curl_setopt($soap, CURLOPT_HTTPHEADER,     $header);

        $xml_res = curl_exec($soap);

        curl_close($soap);

        return $xml_res;
    }

	/**
	 * Clean SOAM XML code 
	 *
	 * @param string $xml_response The XML string to "clean up"
	 *
	 * @return string 
	 */
    public static function cleanXML($xml_response){
        $clean_xml = str_ireplace(['SOAP-ENV:', 'SOAP:'], '', $xml_response);
        return simplexml_load_string($clean_xml);    	
    }

}
