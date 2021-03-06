<?php
//v2.0
try{
    require 'config.php';
    require 'functions.php';
}
catch (Exception $e){
    error_log ( "Error with PMS Load Balancer in ".__FILE__." at line 3. Unable to get config and/or functions");
    exit;
}

$unm = "";
$utoken = "";
$uhtoken = "";
$logdir = "./";
setupLogging();

$headers = array();
$userheaders = array();
$accepteh=FALSE;
$acceptr=FALSE;

$ouri = $_SERVER['REQUEST_URI'];
$originalurl = "http://$destserver$ouri";

checkBlockUrls($ouri, $originalurl);

$ruri = fixRequestUri($ouri);

$finalurl = "http://$destserver$ruri";
if($clientstreaming === FALSE && stripos($finalurl,"/library/parts/") !== FALSE || stripos($finalurl,"/video/:/transcode/") !== FALSE){
    $finalurlre = "http://$destserverre$ruri";
    header("Location: $finalurlre");
    logger("Redirecting to reduce load");
    exit;
}
foreach (getallheaders() as $name => $value) {
    $ovalue = $value;
    if( $swapidentity === TRUE || $enableanonymousaccess === FALSE){
        switch ($name) {
            case 'X-Plex-Token':
                logger("fixing $name");
                $uhtoken = $value;
                logger("found user token $uhtoken");
                $value = $token;
                logger("adding $name $value");
            break;
            case 'X-Plex-Username':
                logger("fixing $name");
                $unm = $value;
                logger("found username $unm");
                $value = $username;
                logger("adding $name $value");
            break;
            case 'Referer':
            case 'Host':
            case 'Origin':
                logger("fixing $name");
                $value = str_replace($lbip,$destserver, $value);
                logger("adding $name $value");
            break;
            case 'Range':
                if($clientstreaming === TRUE && stripos($finalurl,"/library/parts/") !== FALSE){
                    logger("checking range $value");
                    $valsplit = explode("-",$value);
                    $valsplit[0] = explode("=",$valsplit[0])[1];
                    if(count($valsplit) > 1 && intval($valsplit[1])-intval($valsplit[0])>$streamsize){
                        logger('fixing range');
                        $newend=intval($valsplit[0])+$streamsize;
                        $value = "bytes=".$valsplit[0]."-$newend";
                    }
                    elseif(count($valsplit) == 1 || (count($valsplit) ==2 && $valsplit[1]=="") ){
                        logger('fixing range');
                        $newend=intval($valsplit[0])+$streamsize;
                        $value = "bytes=".$valsplit[0]."-$newend";
                    }
                    logger("adding $name $value");
                }
            break;
            /*
              //In my tests I found that the client identifier doesnt much matter.
              //Only the username and token needs to be changed
            case 'Cookie':
                $value = replaceCookie($value, $client);
            break;
            case 'X-Plex-Client-Identifier':
                $value = $client;
            break;*/
        }
    }
    if(stripos($name,"Accept") !== FALSE && stripos($value,"gzip") !== FALSE){
        //Detect gzip encoding
        $acceptr=TRUE;
    }

    $headers[] = "$name: $value";
    $userheaders[] = "$name: $ovalue";
}


if($swapidentity === TRUE && $enableanonymousaccess === FALSE 
    && $unm == "" && ($utoken != "" || $uhtoken != "" ) ){
    if($uhtoken == ""){
       $uhtoken = $utoken;
       $userheaders[] = "X-Plex-Token: $uhtoken";
    }
    elseif($utoken == ""){
       $utoken = $uhtoken;
    }
    logger("looking up username with token $utoken");
    $unm = getUsername($userheaders);
}
if( $namedusersonly === TRUE && !isset($lbuuidkvp[$unm]) ){
    logger("blocking user $unm");
    header("Location: $originalurl");
    exit;
}
$ch = curl_init($finalurl);
curl_setopt($ch,CURLOPT_ENCODING, '');
curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
curl_setopt($ch,CURLOPT_VERBOSE,1);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $_SERVER['REQUEST_METHOD']);
$output = curl_exec($ch);

//Get the response code before closing curl
$httpCode = curl_getinfo ( $ch, CURLINFO_HTTP_CODE );
curl_close($ch);

//Split the response to retreive headers
list($header, $body) = explode("\r\n\r\n", $output, 2);
$header = explode("\r\n",$header);

//Send response code
http_response_code(intval($httpCode));
$firstrun = TRUE;

logger("Server response code $httpCode");
foreach($header as $value){
    if($firstrun === TRUE){
        //The first header is always HTTP 1/1 CODE
        //Sending this messes things up
        $firstrun = FALSE;
        continue;
    }
    if(stripos($value,"Content") !== FALSE && stripos($value,"gzip") !== FALSE){
        //Detect gzip encoding
        $accepteh=TRUE;
    }
    else if(stripos($value,$destserver) !== FALSE){
        logger("Fixing ip from $destserver to $lbip");
        $value = str_replace($destserver, $lbip, $value);
    }
    header("$value");
}
if( $swapidentity === TRUE && $enableanonymousaccess === FALSE ){
    $body = swapBackBody($body);
}

if(($accepteh === TRUE && $acceptr === TRUE)){
    echo gzencode($body);
}
else{
    echo $body;
}

exit;
?>