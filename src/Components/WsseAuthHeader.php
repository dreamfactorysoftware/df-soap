<?php

namespace DreamFactory\Core\Soap\Components;

use SoapHeader;
use SoapVar;

class WsseAuthHeader extends SoapHeader
{
    // Namespaces
    private $ns_wsse = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
    private $ns_wsu = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
    private $password_type = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordDigest';
    private $encoding_type = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary';

    function __construct($username, $password, $wsse_username_token = null)
    {
        $simple_nonce = random_int(0, mt_getrandmax());
        $created_at = gmdate('Y-m-d\TH:i:s\Z');
        $encoded_nonce = base64_encode($simple_nonce);
        $password_digest = base64_encode(sha1($simple_nonce . $created_at . $password, true));

        // Creating WSS identification header using SimpleXML
        $root = new \SimpleXMLElement('<root/>');
        $security = $root->addChild('wsse:Security', null, $this->ns_wsse);
        $usernameToken = $security->addChild('wsse:UsernameToken', null, $this->ns_wsse);
        if($wsse_username_token){
            $usernameToken->addAttribute('wsu:Id', $wsse_username_token, $this->ns_wsu);
        }
        $usernameToken->addChild('Username', $username, $this->ns_wsse);
        $usernameToken->addChild('Password', $password_digest, $this->ns_wsse)->addAttribute('Type', $this->password_type);
        $usernameToken->addChild('Nonce', $encoded_nonce, $this->ns_wsse)->addAttribute('EncodingType', $this->encoding_type);
        $usernameToken->addChild('Created', $created_at, $this->ns_wsu);

        // Recovering XML value from that object
        $root->registerXPathNamespace('wsse', $this->ns_wsse);
        $full = $root->xpath('/root/wsse:Security');
        $auth = $full[0]->asXML();
        parent::__construct($this->ns_wsse, 'Security', new SoapVar($auth, XSD_ANYXML), true);
    }
}
