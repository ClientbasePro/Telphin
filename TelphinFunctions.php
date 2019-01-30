<?php

  // Интеграция CRM Clientbase с телефонией Телфин (Telphin)
  // https://ClientbasePro.ru
  // https://ringme-confluence.atlassian.net/wiki/spaces/RAPD/overview
  
require_once 'common.php'; 

    // функция возвращает токен для авторизации в Телфин
function GetTelphinToken() {
  $now = date("Y-m-d H:i:s");
        // сначала пробуем получить токен из таблицы 
  $res = data_select_field(TELPHIN_TOKEN_TABLE, 'f'.TELPHIN_TOKEN_FIELD_TOKEN.' AS token', "status=0 AND f".TELPHIN_TOKEN_FIELD_TOKEN."!='' AND f".TELPHIN_TOKEN_FIELD_DATE.">'".$now."' ORDER BY f".TELPHIN_TOKEN_FIELD_DATE." DESC LIMIT 1");
  $row = sql_fetch_assoc($res);
  if ($token=$row['token']) {
      // дополнительно проверяем авторизацию по нему
    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => 'https://apiproxy.telphin.ru/api/ver1.0/user/',
      CURLOPT_HTTPHEADER => array('Authorization: Bearer '.$token, 'Content-Type: application/json'),
      CURLOPT_RETURNTRANSFER => true
    ));
    if ($response=curl_exec($curl)) {
      $answer = json_decode($response);
      if ('401'!=$answer->status && 'Unauthorized'!=$answer->message) return $token;
    }
    curl_close($curl);
  }
    // если в БД его нет или токен из таблицы не прошёл авторизацию, то запрашиваем у Телфина
  $curl = curl_init();
  curl_setopt_array($curl, array(
    CURLOPT_URL => 'https://apiproxy.telphin.ru/oauth/token',
    CURLOPT_HTTPHEADER => array("Content-type: application/x-www-form-urlencoded"),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => 1,
    CURLOPT_POSTFIELDS => 'grant_type=client_credentials&client_id='.TELPHIN_APP_ID.'&client_secret='.TELPHIN_APP_SECRET
  ));
        // получаем сам токен
  if ($response=curl_exec($curl)) {
    $answer = json_decode($response);
    if ($answer->access_token && 'Bearer'==$answer->token_type) $token = $answer->access_token;
  }
  curl_close($curl);
  if ($token) { 
    data_insert(TELPHIN_TOKEN_TABLE, EVENTS_ENABLE, array('f'.TELPHIN_TOKEN_FIELD_TOKEN=>$token, 'f'.TELPHIN_TOKEN_FIELD_DATE=>date("Y-m-d H:i:s", time()+3600))); 
    return $token; 
  }
  return false;
}

    // функция возвращает client_id (id АТС)
function GetTelphinClientId() {    
    // сначала пробуем получить client_id из таблицы 
  $res = data_select_field(TELPHIN_TOKEN_TABLE, 'f'.TELPHIN_TOKEN_FIELD_CLIENTID.' AS client_id', "status=0 AND f".TELPHIN_TOKEN_FIELD_CLIENTID."!='' ORDER BY f".TELPHIN_TOKEN_FIELD_DATE." DESC LIMIT 1");
  $row = sql_fetch_assoc($res);
  if ($client_id=$row['client_id']) return $client_id;
        // если из таблицы не взяли, пытаемся получить clientId запросом
  if ($token=GetTelphinToken()) {
    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => 'https://apiproxy.telphin.ru/api/ver1.0/user/',
      CURLOPT_HTTPHEADER => array('Authorization: Bearer '.$token, 'Content-Type: application/json'),
      CURLOPT_RETURNTRANSFER => true
    ));
    if ($response=curl_exec($curl)) {
      $answer = json_decode($response);
      if ($client_id=$answer->client_id) { curl_close($curl); data_update(TELPHIN_TOKEN_TABLE, EVENTS_ENABLE, array('f'.TELPHIN_TOKEN_FIELD_CLIENTID=>$client_id), "status=0 AND f".TELPHIN_TOKEN_FIELD_CLIENTID."='' ORDER BY f".TELPHIN_TOKEN_FIELD_DATE." DESC LIMIT 1"); return $client_id; }     
    }
  }
  curl_close($curl);
  return false;
}

    // функция возвращает данные по токену $token и запросу $url
function GetTelphinData($url) {
    // проверка наличия входных данных
  if (!$url) return false;
  if ($token=GetTelphinToken()) {
      // отправка запроса
    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => 'https://apiproxy.telphin.ru/api/ver1.0/'.$url,
      CURLOPT_HTTPHEADER => array('Authorization: Bearer '.$token, 'Content-Type: application/json'),
      CURLOPT_RETURNTRANSFER => true
    ));
    if ($response=curl_exec($curl)) if ($answer=json_decode($response)) { curl_close($curl); return $answer; }             
    curl_close($curl);
  }
  return false;
}

    // функция инициирует исходящий звонок от $from (первое плечо коллбэка) на $to (второе плечо коллбэка) через $extension_id (идентификатор линии в Телфине)
function TelphinCallback($from, $to, $extension_id) {
        // проверка наличия входных данных
  if (!$from  || !$to || !$extension_id) return false;
  if ($token=GetTelphinToken()) {
    $curl = curl_init('https://apiproxy.telphin.ru/api/ver1.0/extension/'.$extension_id.'/callback/');
    curl_setopt_array($curl, array(
      CURLOPT_HTTPHEADER => array('Authorization: Bearer '.$token, 'Content-Type: application/json'),
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => 1,
      CURLOPT_POSTFIELDS => '{"dst_num":"'.$to.'","src_num":["'.$from.'"]}'
    ));       
    if ($response=curl_exec($curl)) {
      if ($answer=json_decode($response)) { 
        curl_close($curl); 
        return $answer->call_id;         
      }
      curl_close($curl);
      return 'curl response json_decode error: '.json_last_error();
    }                       
    curl_close($curl);
    return 'curl_exec error: '.curl_error($curl);
  }
  return 'token error';
}

    // функция возвращает extension_id внутреннего номера $num
function GetTelphinExtensionId($num) {
    // проверка наличия входных данных
  if (!$num=intval($num)) return false;
  if ($token=GetTelphinToken()) {
    if ($client_id=GetTelphinClientId()) {
      $curl = curl_init();
        // если есть внутренний номер - получаем его extension
      curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://apiproxy.telphin.ru/api/ver1.0/client/'.$client_id.'/extension/',
        CURLOPT_HTTPHEADER => array('Authorization: Bearer '.$token, 'Content-Type: application/json'),
        CURLOPT_RETURNTRANSFER => true
      ));
      if ($response=curl_exec($curl)) {            
        if ($answer=json_decode($response)) { 
          curl_close($curl);
          foreach ($answer as $row) if (TELPHIN_INNERPREFIX.'*'.$num==$row->name && 'phone'==$row->type) return $row->id;         
        }
        curl_close($curl);
        return 'curl response json_decode error: '.json_last_error();
      }
      curl_close($curl);
      return 'curl_exec error: '.curl_error($curl);
    }
    return 'client_id error';
  }
  return 'token error';   
}

  // функция копирует CDR Телфина за период с $date1 по $date2, по умолчанию за сегодня
function CopyTelphinCDR($date1, $date2) {
    // проверяем входные данные
  $date1 = ($date1) ? date("Y-m-d 00:00:00",strtotime($date1)) : date("Y-m-d 00:00:00");
  $date2 = ($date1) ? date("Y-m-d 23:59:59",strtotime($date1)) : date("Y-m-d 23:59:59");
    // получаем в Телфин выгрузку CDR
  $d = GetTelphinData('client/'.(GetTelphinClientId()).'/call_history/?start_datetime='.(urlencode($date1)).'&order=desc&end_datetime='.(urlencode($date2)));
    // проходим по всем записям
  foreach ($d->call_history as $row) {
    $data_['flow'] = $row->flow;
    $data_['date'] = date("Y-m-d H:i:s", strtotime($row->init_time_gmt)+3*60*60);
    $data_['length'] = $row->duration;
    $data_['result'] = $row->result;
    $data_['extension_type'] = $row->extension_type;
    $data_['from'] = ('*'==substr($row->from_username,-4,1)) ? substr($row->from_username,-3) : $row->from_username;
    $data_['to'] = ('*'==substr($row->to_username,-4,1)) ? substr($row->to_username,-3) : $row->to_username;
    $data_['call_uuid'] = $row->call_uuid;
    foreach ($row->cdr as $cdr) {
        $subdata['flow'] = $cdr->flow;
        $subdata['date'] = date("Y-m-d H:i:s", strtotime($cdr->init_time_gmt)+3*60*60);
        $subdata['length'] = $cdr->duration;
        $subdata['result'] = $cdr->result;
        $subdata['extension_type'] = $cdr->extension_type;
        $subdata['from'] = ('*'==substr($cdr->from_username,-4,1)) ? substr($cdr->from_username,-3) : $cdr->from_username;
        $subdata['to'] = ('*'==substr($cdr->to_username,-4,1)) ? substr($cdr->to_username,-3) : $cdr->to_username;
        if ($cdr->record_uuid) $subdata['record_uuid'] = $cdr->record_uuid; 
        $sub[] = $subdata;          
    }
    if ($sub) $data_['history'] = json_encode($sub);
    $subdata = $sub = '';
      // добавляем записи в таблицу TELPHIN_CDR_TABLE
    $sql = sql_query("INSERT IGNORE INTO ".DATA_TABLE.TELPHIN_CDR_TABLE." (
                                f".TELPHIN_CDR_FIELD_FLOW.", 
                                f".TELPHIN_CDR_FIELD_DATE.", 
                                f".TELPHIN_CDR_FIELD_LENGTH.", 
                                f".TELPHIN_CDR_FIELD_RESULT.", 
                                f".TELPHIN_CDR_FIELD_EXTENSION_TYPE.", 
                                f".TELPHIN_CDR_FIELD_FROM.",
                                f".TELPHIN_CDR_FIELD_TO.",
                                f".TELPHIN_CDR_FIELD_CALL_UUID.",
                                f".TELPHIN_CDR_FIELD_HISTORY."
                        )
                        VALUES ('".$data_['flow']."',
                                '".$data_['date']."',
                                '".$data_['length']."',
                                '".$data_['result']."',
                                '".$data_['extension_type']."',
                                '".$data_['from']."',
                                '".$data_['to']."',
                                '".$data_['call_uuid']."',
                                '".$data_['history']."'
                        )");   
  }
  return true;
}

  // функция создаёт новую запись в таблице Requests Телфин с информацией о звонке Телфин $data_
function CreateTelphinRequest($data_) {
    if ($data_['f'.TELPHIN_REQUESTS_FIELD_CALLED_NUMBER] && $data_['f'.TELPHIN_REQUESTS_FIELD_EVENT_TYPE] && $data_['f'.TELPHIN_REQUESTS_FIELD_CALLERID_NUM] && $data_['f'.TELPHIN_REQUESTS_FIELD_CALL_ID]) {
      // определяем по типу звонка, где чей номер (исх/вх)
    if ('dial-out'==$data_['f'.TELPHIN_REQUESTS_FIELD_EVENT_TYPE] && $data_['f'.TELPHIN_REQUESTS_FIELD_CALLERID_NUM]) { $n1 = $data_['f'.TELPHIN_REQUESTS_FIELD_CALLERID_NUM]; $n2 = $data_['f'.TELPHIN_REQUESTS_FIELD_CALLED_NUMBER]; }
    if ('dial-in'==$data_['f'.TELPHIN_REQUESTS_FIELD_EVENT_TYPE] && $data_['f'.TELPHIN_REQUESTS_FIELD_CALLED_NUMBER]) { $n1 = $data_['f'.TELPHIN_REQUESTS_FIELD_CALLED_NUMBER]; $n2 = $data_['f'.TELPHIN_REQUESTS_FIELD_CALLERID_NUM]; }
      // привязка к пользователю
    if ($n1) {
        // 1. прямой поиск по номеру внутр. телефона среди сотрудников
      $row = sql_fetch_assoc(data_select_field(WORKERS_TABLE, 'id, f'.WORKERS_FIELD_USER.' AS userId', "status=0 AND f".WORKERS_FIELD_INNER_PHONE."='".$n1."' LIMIT 1"));
      $data_['f'.TELPHIN_REQUESTS_FIELD_USER] = ($row['userId']) ? $row['userId'] : '';
      $data_['f'.TELPHIN_REQUESTS_FIELD_WORKER] = ($row['id']) ? $row['id'] : '';
        // 2. поиск в callback-ах, по callId
      if (!$data_['f'.TELPHIN_REQUESTS_FIELD_WORKER]) {
        $row = sql_fetch_assoc(data_select_field(WORKERS_TABLE, 'id, f'.TELPHIN_REQUESTS_FIELD_USER.' AS userId', "status=0 AND f".TELPHIN_REQUESTS_FIELD_USER." IN (SELECT user_id FROM ".DATA_TABLE.TELPHIN_CALLBACKS_TABLE." WHERE f".TELPHIN_CALLBACKS_FIELD_CALL_ID."='".$data_['f'.TELPHIN_REQUESTS_FIELD_CALL_ID]."') LIMIT 1"));
        $data_['f'.TELPHIN_REQUESTS_FIELD_USER] = ($row['userId']) ? $row['userId'] : '';
        $data_['f'.TELPHIN_REQUESTS_FIELD_WORKER] = ($row['id']) ? $row['id'] : '';
      } 
    }
    $data_['f'.TELPHIN_REQUESTS_FIELD_ACCOUNT] = GetAccount($n2);
      // если завершаем вызов, ищем пользователя по ранее созданному запросу
    if ('hangup'==$data_['f'.TELPHIN_REQUESTS_FIELD_EVENT_TYPE] && $data_['f'.TELPHIN_REQUESTS_FIELD_CALL_ID]) {
      $res = data_select_field(TELPHIN_REQUESTS_TABLE, 'f'.TELPHIN_REQUESTS_FIELD_WORKER.' AS workerId, f'.TELPHIN_REQUESTS_FIELD_ACCOUNT.' AS accountId', "status=0 AND f11647!='' AND f".TELPHIN_REQUESTS_FIELD_EVENT_TYPE." IN ('dial-out','dial-in') AND f".TELPHIN_REQUESTS_FIELD_CALL_ID."='".$data_['f'.TELPHIN_REQUESTS_FIELD_CALL_ID]."' ORDER BY add_time DESC LIMIT 1");
      $row = sql_fetch_assoc($res);
      if ($row['workerId']) $data_['f'.TELPHIN_REQUESTS_FIELD_WORKER] = $row['workerId'];
      if ($row['accountId']) $data_['f'.TELPHIN_REQUESTS_FIELD_ACCOUNT] = $row['accountId'];   
    }
    return data_insert(TELPHIN_REQUESTS_TABLE, EVENTS_ENABLE, $data_);
  }     
}

  // функция копирует файл записи звонка из Телфин из строки $someId, с идентификатором $recordId, с датой звонка $date, в локальную папку modules/telphin/y/m/d/$someId.wav
  // и возвращает массив из HTML-кода плеера со ссылкой на файл и описанием результата
function CopyTelphinCallRecord($someId, $recordId, $date) {
    // нормализуем входные данные
  $someId = intval($someId);
  $date = date('Y-m-d H:i:s',$str=strtotime($date));
  $extension = intval(substr($recordId,0,6));
  if (!$someId || !$recordId || !$date || !$extension) return false;
    // папка для сохранения файла локально
  $path = 'modules/telphin/'.date("Y",$str).'/'.date("m",$str).'/'.date("d",$str);
  $fileName = $path.'/'.$someId.'.wav';
    // 1. пробуем найти локальный файл  
  if (false!==file_get_contents($fileName)) {
    $player = '<div class="cb_player"'; 
    $player .= ' id="cb_player';
    $player .= $someId;
    $player .= '" source="'.$fileName;
    $player .= '" onclick="event.cancelBubble=true"';
    $player .= ' onmouseup="event.cancelBubble=true"';
    $player .= ' style="min-width:150px; max-width:250px"></div>';
    return array('player'=>$player, 'result'=>'local');
  }
    // 2. пробуем загрузить файл из облака Телфин   
  else {   
    $url = '/extension/'.$extension.'/record/'.$recordId.'/storage_url/';
    if ($d=GetTelphinData($url)) {
      if ($record_url=$d->record_url) {
        $c = curl_init($record_url);
        curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
        if ($file=curl_exec($c)) {            
          mkdir($path, 0777, true);
          if (file_put_contents($fileName, $file)) {
            if (count($file)) {
              $player = '<div class="cb_player"'; 
              $player .= ' id="cb_player';
              $player .= $someId;
              $player .= '" source="'.$fileName;
              $player .= '" onclick="event.cancelBubble=true"';
              $player .= ' onmouseup="event.cancelBubble=true"';
              $player .= ' style="min-width:150px; max-width:250px"></div>';
            return array('player'=>$player, 'result'=>'cloud');
            }
            return array('result'=>'error in saved file by NULL size');
          }
          return array('result'=>'error to save cloud file to local');
        }
        return array('result'=>'error to get cloud file');
      }
      return array('result'=>'error to get cloud file link');
    }
    return array('result'=>'error in GetTelphinData');
  }
}

?>