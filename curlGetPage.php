<?php

// Version: 01 May 2016
// Added support of cookie file and post query
//

function httpGET ( $url, $useCache = true, $cookie = null, $referer = null )
{
    $cacheFileName = '';
    // Check if we have cache
    // - Extract domain part from URL
    if (preg_match("#^http(s){0,1}\:\/\/(.+?)(\/.+)$#", $url, $m)) {
	$xDomainName = $m[2];
	$xURL = str_replace("/", ":", $m[3]);

	if ($useCache && is_dir("loader") && (is_dir("loader/".$xDomainName) || mkdir("loader/".$xDomainName))) {
	    $cacheFileName = "loader/".$xDomainName."/".$xURL.".html";
	} else {
        $useCache = false;
    }
	
	// Check if we can load cached data
	if ($useCache && is_file($cacheFileName)) {
	    $fw = fopen($cacheFileName, "r");
	    $content = fread($fw, filesize($cacheFileName));
	    fclose($fw);
	    
	    $header = array(
		'http_code'	=> '200',
		'content'	=> $content,
	    );
	    return $header;
	}
    }


    $options = array(
        CURLOPT_RETURNTRANSFER => true,     // return web page
        CURLOPT_HEADER         => false,    // don't return headers
        CURLOPT_FOLLOWLOCATION => false,     // follow redirects
        CURLOPT_ENCODING       => "",       // handle all encodings
        CURLOPT_USERAGENT      => "Mozilla/5.0 (Windows NT 6.3; WOW64; rv:43.0) Gecko/20100101 Firefox/43.0", // who am i
        CURLOPT_AUTOREFERER    => true,     // set referer on redirect
        CURLOPT_CONNECTTIMEOUT => 120,      // timeout on connect
        CURLOPT_TIMEOUT        => 120,      // timeout on response
        CURLOPT_MAXREDIRS      => 10,       // stop after 10 redirects
        CURLOPT_SSL_VERIFYPEER => false,    // Disabled SSL Cert checks
	CURLOPT_COOKIEFILE     => 'cookie.txt',
	CURLOPT_COOKIEJAR      => 'cookie.txt',
    );

    if (($cookie != null) && (strlen($cookie) > 1)) {
	$options[CURLOPT_COOKIE] = $cookie;
    }

    if (($referer != null) && (strlen($referer) > 1)) {
	$options[CURLOPT_REFERER] = $referer;
    }


    $ch      = curl_init( $url );
    curl_setopt_array( $ch, $options );
    $content = curl_exec( $ch );
    $err     = curl_errno( $ch );
    $errmsg  = curl_error( $ch );
    $header  = curl_getinfo( $ch );
    curl_close( $ch );

    $header['errno']   = $err;
    $header['errmsg']  = $errmsg;
    $header['content'] = $content;

    if ($useCache && ($header['http_code'] == "200")) {
	    $fw = fopen($cacheFileName, "w");
	    fwrite($fw, $content);
	    fclose($fw);
    }

    return $header;
}


//
function httpPOST($url, $postKeys, $cookie=null, $referer=null) {

    $curlKeys = join("&", array_map(function($x){ return $x[0]."=".urlencode($x[1]); }, $postKeys));
    $options = array(
        CURLOPT_RETURNTRANSFER => true,     // return web page
        CURLOPT_HEADER         => false,    // don't return headers
        CURLOPT_FOLLOWLOCATION => true,     // follow redirects
        CURLOPT_ENCODING       => "",       // handle all encodings
        CURLOPT_USERAGENT      => "Mozilla/5.0 (Windows NT 6.3; WOW64; rv:43.0) Gecko/20100101 Firefox/43.0", // who am i
        CURLOPT_AUTOREFERER    => true,     // set referer on redirect
        CURLOPT_CONNECTTIMEOUT => 120,      // timeout on connect
        CURLOPT_TIMEOUT        => 120,      // timeout on response
        CURLOPT_MAXREDIRS      => 10,       // stop after 10 redirects
        CURLOPT_SSL_VERIFYPEER => false,     // Disabled SSL Cert checks
	CURLOPT_POST		=> true,
	CURLOPT_POSTFIELDS	=> $curlKeys,
	CURLOPT_COOKIEFILE     => 'cookie.txt',
	CURLOPT_COOKIEJAR      => 'cookie.txt',
    );

    if (($cookie != null) && (strlen($cookie) > 1)) {
	$options[CURLOPT_COOKIE] = $cookie;
    }


    if (($referer != null) && (strlen($referer) > 1)) {
	$options[CURLOPT_REFERER] = $referer;
    }

    $ch      = curl_init( $url );
    curl_setopt_array( $ch, $options );
    $content = curl_exec( $ch );
    $err     = curl_errno( $ch );
    $errmsg  = curl_error( $ch );
    $header  = curl_getinfo( $ch );
    curl_close( $ch );

    $header['errno']   = $err;
    $header['errmsg']  = $errmsg;
    $header['content'] = $content;
    
    $header['isComplete'] = ($header['http_code'] == "200")?true:false;
    return $header;
}

