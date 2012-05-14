<?php
/**
 *
 * This file is part of Open Library System.
 * Copyright � 2009, Dansk Bibliotekscenter a/s,
 * Tempovej 7-11, DK-2750 Ballerup, Denmark. CVR: 15149043
 *
 * Open Library System is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Open Library System is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Open Library System.  If not, see <http://www.gnu.org/licenses/>.
*/


require_once("OLS_class_lib/webServiceServer_class.php");
require_once("OLS_class_lib/oci_class.php");
require_once("OLS_class_lib/z3950_class.php");

class openHoldings extends webServiceServer {
 
  private $url_itemorder_bestil = array();

 /*
  * request:
  * - lookupRecord
  * - - responderId: librarycode for lookup-library
  * - - bibliographicRecordId: requester record id 
  * - - bibliographicRecordAgencyId: requester record library
  * or
  * - lookupPid
  * - - agencyId: Identifier of agency
  * - - pid: Identifier of Open Search object
  * response:
  * - agencies
  * - - pid: Identifier of Open Search object
  * - - agencyId: Identifier of agency
  * - responder
  * - - bibliographicRecordId: requester record id 
  * - - bibliographicRecordAgencyId: requester record library
  * - - responderId: librarycode for lookup-library
  * - - localHoldingsId
  * - - willLend;
  * - - note:
  * - - expectedDelivery;
  * - error
  * - - pid: Identifier of Open Search object
  * - - responderId: librarycode for lookup-library
  * - - errorMessage: 
  * }
  */
  function holdingsService($param) {
    $hr = &$ret->holdingsResponse->_value;
    if (!$this->aaa->has_right("openholdingstatus", 500))
      $auth_error = "authentication_error";
    if (isset($param->lookupPid)) {
      // force to array
      if (!is_array($param->lookupPid)) {
        $help = $param->lookupPid;
        unset($param->lookupPid);
        $param->lookupPid[] = $help;
      }
      $curl = new curl();
      $curl->set_option(CURLOPT_TIMEOUT, 30);
      foreach ($param->lookupPid as $pid) {
        $url = sprintf($this->config->get_value("ols_get_holdings","setup"), 
                       $this->strip_agency($pid->_value->agencyId->_value), 
                       urlencode($pid->_value->pid->_value));
        $res = $curl->get($url);
        $curl_status = $curl->get_status();
        if ($curl_status['http_code'] == 200) {
          static $dom;
          if (empty($dom)) {
            $dom = new DomDocument();
            $dom->preserveWhiteSpace = false;
          }
          if ($dom->loadXML($res)) {
// <holding fedoraPid="870970-basis:28542941">
//   <agencyId>715700</agencyId>
//   <note></note>
//   <codes></codes>
// </holding>
            if ($result = $dom->getElementsByTagName('holding')->item(0)) {
              foreach ($result->childNodes as $node) {
                $hold[$node->localName] = $node->nodeValue;
              }
              $fh->pid->_value = $pid->_value->pid->_value;
              $fh->agencyId->_value = $hold['agencyId'];
              unset($hold);
              $hr->agencies[]->_value = $fh;
            }
          }
          else {
            $error = 'cannot_parse_library_answer';
          }
        }
        else {
          $error = 'no_holding_return_from_library';
        }
        if ($error) {
          $err->pid->_value = $pid->_value->pid->_value;
          $err->responderId->_value = $pid->_value->agencyId->_value;
          $err->errorMessage->_value = $error;
          $hr->error[]->_value = $err;
          unset($err);
          unset($error);
        }
//var_dump($pid); 
//var_dump($url); 
//var_dump($res); 
//var_dump($curl_status); die();
        
      }
    }
    if (isset($param->lookupRecord)) {
      // force to array
      if (!is_array($param->lookupRecord)) {
        $help = $param->lookupRecord;
        unset($param->lookupRecord);
        $param->lookupRecord[] = $help;
      }
      foreach ($param->lookupRecord as $holding) {
        if (!$fh = $auth_error)
          $fh = $this->find_holding($holding->_value);
        if (is_scalar($fh)) {
          $err->bibliographicRecordId->_value = $holding->_value->bibliographicRecordId->_value;
          $err->bibliographicRecordAgencyId->_value = $holding->_value->bibliographicRecordAgencyId->_value;
          $err->responderId->_value = $holding->_value->responderId->_value;
          $err->errorMessage->_value = $fh;
          $hr->error[]->_value = $err;
          unset($err);
        } else {
          $fh->bibliographicRecordId->_value = $holding->_value->bibliographicRecordId->_value;
          $fh->bibliographicRecordAgencyId->_value = $holding->_value->bibliographicRecordAgencyId->_value;
          $fh->responderId->_value = $holding->_value->responderId->_value;
          $hr->responder[]->_value = $fh;
        }
      }
    }

    return $ret;
  }

 /*
  * struct lookupRecord {
  *   string responderId;
  *   string bibliographicRecordId;
  *   string bibliographicRecordAgencyId;
  *  }
  */
  private function find_holding($holding) {
    static $z3950;
    if ($zurl = $this->find_z_url($holding->responderId->_value)) {
      if (empty($z3950)) $z3950 = new z3950();
      list($target, $database) = explode("/", $zurl);
      $z3950->set_target($target);
      $z3950->set_database($database);
      $z3950->set_syntax("xml");
      $z3950->set_element("B3");
      $z3950->set_schema("1.2.840.10003.13.7.2");
      $z3950->set_start(1);
      $z3950->set_step(1);
      $z3950->set_rpn("@attr 4=103 @attr BIB1 1=12 " . $holding->bibliographicRecordId->_value);
      $this->watch->start("z3950");
      $hits = $z3950->z3950_search(5);
      $this->watch->stop("z3950");
      if ($z3950->get_error()) {
        verbose::log(ERROR, "OpenHoldings:: " . $zurl . " Z3950 error: " . $z3950->get_error_string());
        return "error_searching_library";
      }
      if (!$hits)
        return "item_not_found";
      $record = $z3950->z3950_record(1);
      if (empty($record))
        return "no_holding_return_from_library";
      if ($status = $this->parse_holding($record))
        return $this->parse_status($status);
      else
        return "cannot_parse_library_answer";
    } else
      return "service_not_supported_by_library";
  }

  /** \brief Parse status for availability
   *
   */
  private function parse_status($status) {
    if (count($status) == 1 && $status[0]["policy"]) {
      $s = &$status[0];
      $ret->localHoldingsId->_value = $s["id"];
      if ($s["note"])
        $ret->note->_value = $s["note"];
      $h_date = substr($s["date"],0,10);
      if ($s["policy"] == 1) {
        $ret->willLend->_value = "true";
        if ($h_date >= date("Y-m-d"))
          $ret->expectedDelivery->_value = $h_date;
      } elseif (($s["policy"] == 2))
          $ret->willLend->_value = "false";
    } elseif (count($status) > 1) {
      $ret->willLend->_value = "true";
      $pol = 0;
      foreach ($status as $s)
        if ($s["policy"] <> 1) {
          $ret->willLend->_value = "false";
          break ;
        }
      $ret->note->_value = "check_local_library";
    } else 
      $ret = "no_holdings_specified_by_library";

    return $ret;
  }

  /** \brief Parse a holding record and extract the status
   *
   */
  private function parse_holding($holding) {
    static $dom;
    if (empty($dom)) {
      $dom = new DomDocument();
      $dom->preserveWhiteSpace = false;
    }
    if ($dom->loadXML($holding)) {
      //echo str_replace("?", "", $holding);
      foreach ($dom->getElementsByTagName("bibView-11") as $item) {
        $h = array();
        foreach ($item->attributes as $key => $attr)
          if ($key == "targetBibPartId-40")
            $h["id"] = $attr->nodeValue;
        foreach ($item->getElementsByTagName("bibPartLendingInfo-116") as $info) {
          foreach ($info->attributes as $key => $attr)
            switch ($key) {
              case "servicePolicy-109" : 
                $h["policy"] = $attr->nodeValue;
                break;
              case "servicefee-110" : 
                $h["fee"] = "fee"; // $attr->nodeValue; 2do in seperate tag??
                break;
              case "expectedDispatchDate-111" : 
                $h["date"] = $attr->nodeValue;
                break;
              case "serviceNotes-112" : 
                $h["note"] = $attr->nodeValue;
                break;
            }
        }
        //foreach ($item->getElementsByTagName("bibPartEnumeration-45") as $info) { }
        //foreach ($item->getElementsByTagName("bibPartChronology-46") as $info) { }

        $hold[] = $h;
      }
      if (empty($hold))
        return array(array("note" => "No holding"));
      else
        return $hold;
    } else
      return FALSE;
  }

  private function find_z_url($lib) {
    static $oci;
    if (empty($this->url_itemorder_bestil[$lib])) {
      if (empty($oci)) {
        $oci = new Oci($this->config->get_value("vip_credentials","setup"));
        $oci->set_charset("UTF8");
        $oci->connect();
        if ($err = $oci->get_error_string()) {
          verbose::log(FATAL, "OpenHoldings:: OCI connect error: " . $err);
          return FALSE;
        }
      }
      list($country, $bibno) = explode("-", $lib);
      $oci->bind("bind_bib_nr", $bibno);
      $oci->set_query("SELECT url_itemorder_bestil FROM vip_danbib WHERE bib_nr = :bind_bib_nr");
      $vd_row = $oci->fetch_into_assoc();
      $this->url_itemorder_bestil[$lib] = $vd_row["URL_ITEMORDER_BESTIL"];
    }
    return $this->url_itemorder_bestil[$lib];
  }

  /** \brief
   *  return only digits, so something like DK-710100 returns 710100
   */
  private function strip_agency($id) {
    return preg_replace('/\D/', '', $id);
  }

}

/*
 * MAIN
 */

$ws=new openHoldings('openholdingstatus.ini');
$ws->handle_request();

?>
