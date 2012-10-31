<?php
/**
 *
 * This file is part of Open Library System.
 * Copyright © 2009, Dansk Bibliotekscenter a/s,
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


require_once('OLS_class_lib/webServiceServer_class.php');
require_once('OLS_class_lib/oci_class.php');
require_once('OLS_class_lib/z3950_class.php');

class openHoldings extends webServiceServer {
 
  private $url_itemorder_bestil = array();
  protected $curl;
  protected $dom;

  public function __construct() {
    webServiceServer::__construct('openholdingstatus.ini');
    $this->curl = new curl();
    $this->curl->set_option(CURLOPT_TIMEOUT, 30);
    $this->dom = new DomDocument();
    $this->dom->preserveWhiteSpace = false;
  }

 /*
  * request:
  * - agencyId: Identifier of agency
  * - pid: Identifier of Open Search object
  * - mergePids: merge localisations for all pids
  * response:
  * - localisations
  * - - pid: Identifier of Open Search object
  * - - agencyId: Identifier of agency
  * - - note: Note from local library
  * - - codes: string
  * - error
  * - - pid: Identifier of Open Search object
  * - - responderId: librarycode for lookup-library
  * - - errorMessage: 
  */
  public function localisations($param) {
    $lr = &$ret->localisationsResponse->_value;
    if (!$this->aaa->has_right('netpunkt.dk', 500)) {
      $lr->error->_value->responderId->_value = $param->agencyId->_value;
      $lr->error->_value->errorMessage->_value = 'authentication_error';
      return $ret;
    }

    is_array($param->pid) ? $pids = $param->pid : $pids[] = $param->pid;
    $sort_n_merge = ($this->xs_boolean($param->mergePids->_value) && count($pids) > 1);
    if ($sort_n_merge) {
      $url = sprintf($this->config->get_value('agency_request_order','setup'), 
                     $this->strip_agency($param->agencyId->_value));
      $res = $this->curl->get($url);
      $curl_status = $this->curl->get_status();
      if ($curl_status['http_code'] == 200) {
        if ($this->dom->loadXML($res)) {
          foreach ($this->dom->getElementsByTagName('agencyId') as $aid)
            $r_order[$aid->nodeValue] = count($r_order);
        }
        else {
          $error = 'cannot_parse_request_order';
        }
      }
      else {
        $error = 'error_fetching_request_order';
        verbose::log(ERROR, 'OpenHoldings:: fetch request order http code: ' . $curl_status['http_code'] .
                            ' error: "' . $curl_status['error'] .
                            '" for: ' . $curl_status['url']);
      }
    }

    if ($error) {
      $lr->error->_value->responderId->_value = $param->agencyId->_value;
      $lr->error->_value->errorMessage->_value = $error;
      return $ret;
    }

// if more than one pid, this could be parallelized
    foreach ($pids as $pid) {
      $url = sprintf($this->config->get_value('ols_get_holdings','setup'), 
                     $this->strip_agency($param->agencyId->_value), 
                     urlencode($pid->_value));
      $res = $this->curl->get($url);
      $curl_status = $this->curl->get_status();
      if ($curl_status['http_code'] == 200) {
        if ($this->dom->loadXML($res)) {
// <holding fedoraPid="870970-basis:28542941">
//   <agencyId>715700</agencyId>
//   <note></note>
//   <codes></codes>
// </holding>
          if ($holdings = $this->dom->getElementsByTagName('holding')) {
            foreach ($holdings as $holding) {
              foreach ($holding->childNodes as $node) {
                $hold[$node->localName] = $node->nodeValue;
              }
              $hold['fedoraPid'] = $holding->getAttribute('fedoraPid');
              if ($sort_n_merge) {
                if (!isset($r_order[ $hold['agencyId'] ]))
                  $r_order[ $hold['agencyId'] ] = count($r_order) + 1000;
                $hold['sort'] = sprintf('%06s', $r_order[ $hold['agencyId'] ]);
              }
              $pid_hold[] = $hold;
              unset($hold);
            }
            if ($sort_n_merge) {
              $h_arr[0]['pids'][] = $pid->_value;
              if (empty($h_arr[0]['holds'])) {
                $h_arr[0]['holds'] = array();
              }
              if (is_array($pid_hold)) {
                $h_arr[0]['holds'] = array_merge($h_arr[0]['holds'], $pid_hold);
              }
            }
            else {
              $h_arr[] = array('pids' => array($pid->_value), 'holds' => $pid_hold);
            }
            unset($pid_hold);
          }
        }
        else {
          $error = 'cannot_parse_library_answer';
        }
      }
      else {
        $error = 'no_holding_return_from_library';
        verbose::log(ERROR, 'OpenHoldings:: http code: ' . $curl_status['http_code'] .
                            ' error: "' . $curl_status['error'] .
                            '" for: ' . $curl_status['url']);
      }
      if ($error) {
        $err->pid->_value = $pid->_value;
        $err->responderId->_value = $param->agencyId->_value;
        $err->errorMessage->_value = $error;
        $lr->error[]->_value = $err;
        unset($err);
        unset($error);
      }
    }

    if ($sort_n_merge && is_array($h_arr)) {
      usort($h_arr[0]['holds'], array($this, 'compare'));
    }

//print_r($h_arr); die();
    if (is_array($h_arr)) {
      foreach ($h_arr as $holds) {
        foreach ($holds['pids'] as $pid)
          $one_pid->pid[]->_value = $pid;
        if (isset($holds['holds'])) {
          foreach ($holds['holds'] as $hold) {
            $agency->localisationPid ->_value = $hold['fedoraPid'];
            $agency->agencyId->_value = $hold['agencyId'];
            if ($hold['note']) $agency->note->_value = $hold['note'];
            if ($hold['codes']) $agency->codes->_value = $hold['codes'];
            if ($hold['callNumber']) $agency->callNumber->_value = $hold['callNumber'];
            if ($hold['localIdentifier']) $agency->localIdentifier->_value = $hold['localIdentifier'];
            $one_pid->agency[]->_value = $agency;
            unset($agency);
          }
        }
        $lr->localisations[]->_value = $one_pid;
        unset($one_pid);
      }
    }

    return $ret;
  }


 /*
  * request:
  * - lookupRecord
  * - - responderId: librarycode for lookup-library
  * - - pid
  * - or next 2
  * - - bibliographicRecordId: requester record id 
  * - - bibliographicRecordAgencyId: requester record library
  * response:
  * - responder
  * - - localHoldingsId
  * - - note:
  * - - willLend;
  * - - expectedDelivery;
  * - - pid
  * - or next 2
  * - - bibliographicRecordId: requester record id 
  * - - bibliographicRecordAgencyId: requester record library
  * - - responderId: librarycode for lookup-library
  * - error
  * - - pid
  * - or next 2
  * - - bibliographicRecordId: requester record id 
  * - - bibliographicRecordAgencyId: requester record library
  * - - responderId: librarycode for lookup-library
  * - - errorMessage: 
  */
  public function holdings($param) {
    $hr = &$ret->holdingsResponse->_value;
    if (!$this->aaa->has_right('netpunkt.dk', 500))
      $auth_error = 'authentication_error';
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
        unset($recid);
        if (is_scalar($fh)) {
          $this->add_recid($err, $holding);
          //$err->bibliographicRecordId->_value = $holding->_value->bibliographicRecordId->_value;
          //$err->bibliographicRecordAgencyId->_value = $holding->_value->bibliographicRecordAgencyId->_value;
          $err->responderId->_value = $holding->_value->responderId->_value;
          $err->errorMessage->_value = $fh;
          $hr->error[]->_value = $err;
          unset($err);
        } else {
          $this->add_recid($fh, $holding);
          //$fh->bibliographicRecordId->_value = $holding->_value->bibliographicRecordId->_value;
          //$fh->bibliographicRecordAgencyId->_value = $holding->_value->bibliographicRecordAgencyId->_value;
          $fh->responderId->_value = $holding->_value->responderId->_value;
          $hr->responder[]->_value = $fh;
        }
      }
    }

    return $ret;
  }

  private function add_recid(&$obj, &$hold) {
    if (isset($hold->_value->pid)) {
      $obj->pid->_value = $hold->_value->pid->_value;
    }
    else {
      $obj->bibliographicRecordId->_value = $hold->_value->bibliographicRecordId->_value;
      $obj->bibliographicRecordAgencyId->_value = $hold->_value->bibliographicRecordAgencyId->_value;
    }
  }

 /*
  * struct lookupRecord {
  *   string responderId;
  *   string pid;
  * - or next 2
  *   string bibliographicRecordId;
  *   string bibliographicRecordAgencyId;
  *  }
  */
  private function find_holding($holding) {
    static $z3950;
    if ($zurl = $this->find_z_url($holding->responderId->_value)) {
      if (empty($z3950)) $z3950 = new z3950();
      list($target, $database) = explode('/', $zurl);
      $z3950->set_target($target);
      $z3950->set_database($database);
      $z3950->set_syntax('xml');
      $z3950->set_element('B3');
      $z3950->set_schema('1.2.840.10003.13.7.2');
      $z3950->set_start(1);
      $z3950->set_step(1);
      if (isset($holding->pid)) {
        list($bibpart, $recid) = explode(':', $holding->pid->_value);
        //list($holding->bibliographicRecordAgencyId->_value, $source) = explode('-', $bibpart);
        $z3950->set_rpn('@attr 4=103 @attr BIB1 1=12 ' . $recid);
      } else {
        $z3950->set_rpn('@attr 4=103 @attr BIB1 1=12 ' . $holding->bibliographicRecordId->_value);
      }
      $this->watch->start('z3950');
      $hits = $z3950->z3950_search(5);
      $this->watch->stop('z3950');
      if ($z3950->get_error()) {
        verbose::log(ERROR, 'OpenHoldings:: ' . $zurl . ' Z3950 error: ' . $z3950->get_error_string());
        return 'error_searching_library';
      }
      if (!$hits)
        return 'item_not_found';
      $record = $z3950->z3950_record(1);
      if (empty($record))
        return 'no_holding_return_from_library';
      if ($status = $this->parse_holding($record))
        return $this->parse_status($status);
      else
        return 'cannot_parse_library_answer';
    } else
      return 'service_not_supported_by_library';
  }

  /** \brief Parse status for availability
   *
   */
  private function parse_status($status) {
    if (count($status) == 1 && $status[0]['policy']) {
      $s = &$status[0];
      $ret->localHoldingsId->_value = $s['id'];
      if ($s['note'])
        $ret->note->_value = $s['note'];
      $h_date = substr($s['date'],0,10);
      if ($s['policy'] == 1) {
        $ret->willLend->_value = 'true';
        if ($h_date >= date('Y-m-d'))
          $ret->expectedDelivery->_value = $h_date;
      } elseif (($s['policy'] == 2))
          $ret->willLend->_value = 'false';
    } elseif (count($status) > 1) {
      $ret->willLend->_value = 'true';
      $pol = 0;
      foreach ($status as $s)
        if ($s['policy'] <> 1) {
          $ret->willLend->_value = 'false';
          break ;
        }
      $ret->note->_value = 'check_local_library';
    } else 
      $ret = 'no_holdings_specified_by_library';

    return $ret;
  }

  /** \brief Parse a holding record and extract the status
   *
   */
  private function parse_holding($holding) {
    if ($this->dom->loadXML($holding)) {
      //echo str_replace('?', '', $holding);
      foreach ($this->dom->getElementsByTagName('bibView-11') as $item) {
        $h = array();
        foreach ($item->attributes as $key => $attr)
          if ($key == 'targetBibPartId-40')
            $h['id'] = $attr->nodeValue;
        foreach ($item->getElementsByTagName('bibPartLendingInfo-116') as $info) {
          foreach ($info->attributes as $key => $attr)
            switch ($key) {
              case 'servicePolicy-109' : 
                $h['policy'] = $attr->nodeValue;
                break;
              case 'servicefee-110' : 
                $h['fee'] = 'fee'; // $attr->nodeValue; 2do in seperate tag??
                break;
              case 'expectedDispatchDate-111' : 
                $h['date'] = $attr->nodeValue;
                break;
              case 'serviceNotes-112' : 
                $h['note'] = $attr->nodeValue;
                break;
            }
        }
        //foreach ($item->getElementsByTagName('bibPartEnumeration-45') as $info) { }
        //foreach ($item->getElementsByTagName('bibPartChronology-46') as $info) { }

        $hold[] = $h;
      }
      if (empty($hold))
        return array(array('note' => 'No holding'));
      else
        return $hold;
    } else
      return FALSE;
  }

  private function find_z_url($lib) {
    if (empty($this->url_itemorder_bestil[$lib])) {
      $url = sprintf($this->config->get_value('agency_server_information','setup'), 
                     $this->strip_agency($lib));
      $res = $this->curl->get($url);
      $curl_status = $this->curl->get_status();
      if ($curl_status['http_code'] == 200) {
        if ($this->dom->loadXML($res)) {
          $this->url_itemorder_bestil[$lib] = $this->dom->getElementsByTagName('address')->item(0)->nodeValue;
        }
        else {
          verbose::log(ERROR, 'OpenHoldings:: Cannot parse serverInformation url ' . $url);
          return FALSE;
        }
      }
      else {
        verbose::log(ERROR, 'OpenHoldings:: fetch serverInformation http code: ' . $curl_status['http_code'] .
                            ' error: "' . $curl_status['error'] .
                            '" for: ' . $curl_status['url']);
        return FALSE;
      }
    }
    return $this->url_itemorder_bestil[$lib];
  }

  /** \brief
   *  return only digits, so something like DK-710100 returns 710100
   */
  private function strip_agency($id) {
    return preg_replace('/\D/', '', $id);
  }

  /** \brief
   *  return true if xs:boolean is so
   */
  private function xs_boolean($str) {
    return (strtolower($str) == 'true' || $str == 1);
  }

  /** \brief
   *  return true if xs:boolean is so
   */
  private function compare($a, $b) {
    return $a['sort'] > $b['sort'];
  }

}

/*
 * MAIN
 */

$ws=new openHoldings();
$ws->handle_request();

?>
