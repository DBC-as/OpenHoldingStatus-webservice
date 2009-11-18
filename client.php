<?php
/**
 *
 * This file is part of openLibrary.
 * Copyright © 2009, Dansk Bibliotekscenter a/s,
 * Tempovej 7-11, DK-2750 Ballerup, Denmark. CVR: 15149043
 *
 * openLibrary is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * openLibrary is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with openLibrary.  If not, see <http://www.gnu.org/licenses/>.
*/

define("WSDL", "openholdings.wsdl");

try {
  $client = new SoapClient(WSDL,  array('trace' => 1, "cache_wsdl" => WSDL_CACHE_NONE));
  //$client = new SoapClient(WSDL,  array('trace' => 1));
  $options = array('proxy_host' => "phobos.dbc.dk", 'proxy_port' => 3128);
  $options = array('connection_timeout' => 2);
  //$client = new SoapClient(WSDL, $options);
  $client->__setLocation('http://vision.dbc.dk/~fvs/broend/OpenLibrary/OpenHoldings/trunk/server.php');

  $params = array("agencyId" => "820010",
                  "autService" => "1",
                  "materialType" => "");

var_dump($client->__getFunctions());
var_dump($client->__getTypes());

  $result = $client->openAgencyAutomation($params);
} catch (SoapFault $fault) {
  echo "Fejl: ";
  echo $fault->faultcode . ":" . $fault->faultstring;
  var_dump($fault);
}

if (FALSE) {
  $s_types = $client->__getTypes();
  foreach ($s_types as $s_type)
    var_dump($s_type);
}
echo "Request:<br/>" . str_replace("<", "&lt;", $client->__getLastRequest()) . "<br/>";
echo "RequestHeaders:<br/>" . str_replace("<", "&lt;", $client->__getLastRequestHeaders()) . "<br/>";
echo "Response:<br/>" . str_replace("<", "&lt;", $client->__getLastResponse()) . "<br/>";

?>
