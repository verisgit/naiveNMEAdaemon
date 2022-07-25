<?php
/* This is the simplest web daemon to broadcast NMEA sentences from the given file.
Designed for debugging applications that use gpsd.
The file set in $nmeaFileName and must content correct sentences, one per line.
Required options:
-i log file name
-b bind to proto://address:port
Run:
$ php naiveNMEAdaemon.php -isample1.log -btcp://127.0.0.1:2222
gpsd run to connect this:
$ gpsd -N -n tcp://192.168.10.10:2222
*/
$options = getopt("i::t::b::",['run::','filtering::','updsat::','updtime','updbearing','updspeed::','savesentences::']);
//print_r($options); echo "\n";
if(!($nmeaFileName = filter_var(@$options['i'],FILTER_SANITIZE_URL))) $nmeaFileName = 'sample1.log'; 	// NMEA sentences file name;
$nmeaFileNames = explode(',',$nmeaFileName);
if(!($delay = filter_var(@$options['t'],FILTER_SANITIZE_NUMBER_INT))) $delay = 200000; 	// Min interval between sends sentences, in microseconds. 200000 are semi-realtime for sample1.log
if(!($bindAddres=filter_var(@$options['b'],FILTER_VALIDATE_DOMAIN))) $bindAddres = "tcp://127.0.0.1:2222"; 	// Daemon's access address;
if(!($run = filter_var(@$options['run'],FILTER_SANITIZE_NUMBER_INT))) $run = 0; 	// Overall time of work, in seconds. If 0 - infinity.
if(@$options['filtering']) $filtering = explode(',',$options['filtering']);	// GGA,GLL,GNS,RMC,VTG,GSA
else $filtering = false;
if(isset($options['updsat'])){		// заменять в GGA нулевое количество видимых спутников на какое-то, если есть координаты -- исправление кривизны gpsd, который не любит нулевого количества спутников
	if($updSat = filter_var($options['updsat'],FILTER_SANITIZE_NUMBER_INT)) $updSat = sprintf('%02d', $updSat);
	else $updSat = FALSE; 	// 
}
else $updSat = '06';
if(isset($options['updtime']) and $options['updtime']==FALSE) $updTime = FALSE;	// исправлять время везде, где оно есть, на сейчас
else $updTime = TRUE;
if(isset($options['updbearing'])) $updBearing = TRUE;	// в предложениях RMC устанавливать поле 8 Track made good по значению предыдущих координат и координат из этого предложения
else $updBearing = FALSE;
$saveSentences = filter_var(@$options['savesentences'],FILTER_SANITIZE_URL); 	// записывать ли предложения NMEA в отдельный файл. Например, результат фильтрации
// км/ч, если в RMC скорость 0, заменять на. 
// При этом не должно быть предложений GGA, потому что для них gpsd посчитает скорость по времени
// и рассоянию. Помогает --filtering=RMC
if(isset($options['updspeed'])){
	if(($updSpeed = filter_var($options['updspeed'],FILTER_SANITIZE_NUMBER_FLOAT))=='') $updSpeed = 10;
}
else $updSpeed = FALSE;
//echo "filtering=$filtering; saveSentences=$saveSentences; updSpeed=$updSpeed;\n"; var_dump($updSpeed);
//print_r($filtering);

if($nmeaFileName=='sample1.log') {
	echo "Usage:\n  php naiveNMEAdaemon.php [-isample1.log] [-t200000] [-btcp://127.0.0.1:2222] [--run0] [--filteringGGA,GLL,GNS,RMC,VTG,GSA] [--updsat6] [--updtime]\n";
	echo "\n";
	echo "  -i list of nmea log files, default sample1.log\n";
	echo "  -t delay between the log file string sent, microsecunds (1/1 000 000 sec.), default 200000\n";
	echo "  -b bind address:port, default tcp://127.0.0.1:2222\n";
	echo "  --run overall time of work, in seconds. Default 0 - infinity.\n";
	echo "  --filtering sends only listed sentences from list GGA,GLL,GNS,RMC,VTG,GSA Default - all sentences.\n";
	echo "  --updbearing sets field 8 'Track made good' of RMC sentences as the bearing from the previous point, boolean\n";
	echo "  --updsat sets specified number of satellites in GGA sentence if fix present, but number of satellites is 0. Default 6.\n";
	echo "  --updspeed sets field 7 'Speed over ground' of RMC sentences to the specified value if it is near zero. In km/h, real. Default 10.0\n";
	echo "  --updtime sets the time in sentences to current, boolean. Default true.\n";
	echo "  --savesentences writes NMEA sentences to file\n";
	echo "\n";
	echo "now run naiveNMEAdaemon.php -i$nmeaFileName -t$delay -b$bindAddres --updsat$updSat --updtime$updTime\n\n";
}


$strLen = 0;
$r = array(" | "," / "," - "," \ ");
$i = 0;
$startAllTime = time();
$statCollection = array();
date_default_timezone_set('UTC');	// чтобы менять время в посылках

$socket = stream_socket_server($bindAddres, $errno, $errstr);
if (!$socket) {
  return "$errstr ($errno)\n";
} 
echo "\nCreated streem socket server. Go to wait loop.\n";
echo "\nWe'll send";
if($filtering) echo " only ".implode(',',$filtering);
echo " NMEA sentences";
//echo " with delay $delay microsecunds between each";
if($run) echo " during $run second";
if($updSat) echo " correcting the number of visible satellites to $updSat";
if($updSat and $updTime) echo " and";
if($updTime) echo " correcting the time of message creation to now";
if($updBearing) echo ", with setting the 'Track made good' of RMC sentences as the bearing from the previous point";
if($updSpeed) echo ", with setting the 'Speed over ground' of RMC sentences to ".round($updSpeed/1.852,2)." knots if it's near zero";
if($saveSentences) echo " and with writing sentences to $saveSentences";
echo ".\n\n";

echo "Wait for first connection on $bindAddres";
$conn = stream_socket_accept($socket);

$nStr = 0; 	// number of sending string
$statSend = 0;
$time = ''; $date = '';	
$prevRMC = array();

if($saveSentences) $sentencesfh = fopen($saveSentences, 'w');
$handles = array();
foreach($nmeaFileNames as $i => $nmeaFileName){
	$handle = fopen($nmeaFileName, "r");
	if (FALSE === $handle) {
		echo "Failed to open file $nmeaFileName\n";
		unset($nmeaFileNames[$i]);
		continue;
	}
	$handles[] = $handle;
}
if(!$handles) exit("No logs to play, bye.\n");
echo "\rSending ".implode(',',$nmeaFileNames)." with delay {$delay}ms per string\n";
echo "\n";
while ($conn) { 	// 
	foreach($handles as $i => $handle) {
		if(($run AND ((time()-$startAllTime)>$run))) {
			foreach($handles as $handle) {
				fclose($handle);
			}
			echo "Timeout, go away                            \n";
			echo "Send $nStr str                         \n";
			statShow();
			break 2;
		}
		$startTime = microtime(TRUE);
		$nmeaData = trim(fgets($handle, 2048));	// без конца строки
		if($nmeaData==FALSE) { 	// достигнут конец файла
			rewind($handle);
			if($nStr) {
				echo "Send $nStr str                         \n";
				statShow();
			}
			continue;
		}
		
		$NMEAtype = substr($nmeaData,3,3);
		//echo "NMEAtype=$NMEAtype;                                        \n";
		if($filtering) {
			if(!in_array($NMEAtype,$filtering)) continue;	// будем посылать только указанное
		}
		//echo "nmeaData=$nmeaData;\n";
		//echo 'NMEAchecksumm '.NMEAchecksumm(substr($nmeaData,0,-3))."            \n";
		// Скорость есть в VTG и RMC
		// Координаты в GGA и RMC
		// fix указан в GSA (активные спутники), но там нет даты???
		
		//  Приведение времени к сейчас. Эпоху СЛЕДУЕТ начинать по RMC, и устанавливать
		// время всего остального равного времени RMC. (Есть ли более приоритетные сообщения?)
		// При этом (для gpsd?) время GGA можно установить в пусто, но тогда информация из GGA
		// не воспринимается?
		// Если ставить время по GGA -- скорости не будет вообще, даже если она есть в RMC
		// Если у GGA и RMC будет разное время -- будет скорость по RMC, и, видимо, эпоха
		// тоже будет начинаться по RMC, в результате может оказаться, что перемещение по GGA
		// в эту эпоху будет равно 0, и gpsd выдаст TPV с нулевой скоростью. При этом о других
		// скоростях gpsd не сообщает.
		
		switch($NMEAtype){
		
		case 'GGA':
			// gpsbabel создает NMEA с выражениями GGA, в которых число используемых спутников
			// всегда равно 0.
			// gpsd считает, что если координаты есть, а спутников нет -- это ошибка, но не игнорирует
			// такое сообщение, а сообщает, что координат нет (NO FIX, "mode":1)
			// Следующий код добавляет в сообщения GGA сколько-то спутников, если их 0 и есть координаты
			
			//echo "Before|$nmeaData|\n";
			$nmeaData = substr($nmeaData,0,strrpos($nmeaData,'*'));	// отрежем контрольную сумму
			$nmea = str_getcsv($nmeaData);	
			//echo "Before ";print_r($nmea);
			if(!intval($nmea[7]) and $updSat and $nmea[2]!=NULL and $nmea[4]!=NULL) { 	// есть широта и долгота и нет спутников
				//echo "GGA: не указано количество спутников, исправляем          \n";
				$nmea[7] = '06'; 	// будет столько спутников
			}
			//echo "Исходный момент привязки: {$nmea[1]}                     \n";
			//echo "GGA: time: $time                     \n";
			if($updTime) { 	//  Приведение времени к сейчас
				//$time = date('His.').str_pad(substr(round(substr(microtime(),0,10),2),2),2,'0');
				$nmea[1] = $time;
			}
			//$nmea[1] = '';
			//echo "After "; print_r($nmea);
			//echo "GGA: Lat $nmea[2],	Lon $nmea[4]                   \n";
			$nmeaData = implode(',',$nmea);
			//echo "$nmeaData\n";
			$nmeaData .= '*'.NMEAchecksumm($nmeaData);
			//echo "GGA After |$nmeaData|                                   \n";
			break;

		case 'GLL':
			$nmeaData = substr($nmeaData,0,strrpos($nmeaData,'*'));	// отрежем контрольную сумму
			$nmea = str_getcsv($nmeaData);	
			//echo "Before ";print_r($nmea);
			// Приведение времени к сейчас 
			if($updTime) { 	//  Приведение времени к сейчас
				//$time = date('His.').str_pad(substr(round(substr(microtime(),0,10),2),2),2,'0');
				$nmea[5] = $time;
			}
			//echo "After "; print_r($nmea);
			//echo "GLL: Lat $nmea[1],	Lon $nmea[3]                   \n";
			$nmeaData = implode(',',$nmea);
			//echo "$nmeaData\n";
			$nmeaData .= '*'.NMEAchecksumm($nmeaData);
			//echo "After |$nmeaData|                                   \n";
			break;

		case 'GNS':
			$nmeaData = substr($nmeaData,0,strrpos($nmeaData,'*'));	// отрежем контрольную сумму
			$nmea = str_getcsv($nmeaData);	
			//echo "Before ";print_r($nmea);
			// Приведение времени к сейчас 
			if($updTime) {
				//$time = date('His.').str_pad(substr(round(substr(microtime(),0,10),2),2),2,'0');
				$nmea[1] = $time;
			}
			//echo "After "; print_r($nmea);
			$nmeaData = implode(',',$nmea);
			//echo "$nmeaData\n";
			$nmeaData .= '*'.NMEAchecksumm($nmeaData);
			//echo "After |$nmeaData|                                   \n";
			break;
		
		case 'RMC':
			$nmeaData = substr($nmeaData,0,strrpos($nmeaData,'*'));	// отрежем контрольную сумму
			$nmea = str_getcsv($nmeaData);	
			//echo "Before ";print_r($nmea);
			// Хрен его знает, что это за статус, но при V gpsd это предложение игнорирует. А 
			// SignalK  -- нет.
			$nmea[2] = 'A'; 	// Status, A = Valid, V = Warning
			if($updBearing){	// исправление курса
				$prevRMC[8] = bearing(nmeaLatDegrees($prevRMC[3]),nmeaLonDegrees($prevRMC[5]),nmeaLatDegrees($nmea[3]),nmeaLonDegrees($nmea[5]));
				$tmp = $nmea;
				$nmea = $prevRMC;
				$prevRMC = $tmp;
				if(!$nmea[0]) continue 2;	// первый оборот, ещё нет всех данных
			}
			if($updSpeed !== FALSE){	// Изменение скорости
				//echo "nmea[7]={$nmea[7]}              	\n";
				if($nmea[7]<0.001) $nmea[7] = round($updSpeed/1.852,2);
			}
			if($updTime){ 	//  Приведение времени к сейчас	
				// Время устанавливается только здесь, стало быть, предложения RMC должны быть.	
				$time = date('His.').str_pad(substr(round(substr(microtime(),0,10),2),2),2,'0');
				$nmea[1] = $time; 	// 
				$date = date('dmy');
				$nmea[9] = $date; 	// 
			}
			//echo "RMC After "; print_r($nmea);
			//echo "RMC: Lat $nmea[3],	Lon $nmea[5]                          \n";
			$nmeaData = implode(',',$nmea);
			$nmeaData .= '*'.NMEAchecksumm($nmeaData);
			//echo "RMC After |$nmeaData|                                   \n";
			break;

		case 'VTG':
			$nmeaData = substr($nmeaData,0,strrpos($nmeaData,'*'));	// отрежем контрольную сумму
			$nmea = str_getcsv($nmeaData);	
			//echo "Before ";print_r($nmea);
			//echo "After "; print_r($nmea);
			$nmeaData = implode(',',$nmea);
			//echo "$nmeaData\n";
			$nmeaData .= '*'.NMEAchecksumm($nmeaData);
			//echo "After |$nmeaData|                                   \n";
			break;
		case 'GSA':
			$nmeaData = substr($nmeaData,0,strrpos($nmeaData,'*'));	// отрежем контрольную сумму
			$nmea = str_getcsv($nmeaData);	
			//echo "Before ";print_r($nmea);
			//echo "After "; print_r($nmea);
			$nmeaData = implode(',',$nmea);
			//echo "$nmeaData\n";
			$nmeaData .= '*'.NMEAchecksumm($nmeaData);
			//echo "After |$nmeaData|                                   \n";
			break;
		default:
		}
				
		if($saveSentences) $res = fwrite($sentencesfh, $nmeaData."\n");	// сохраним в файл

		statCollect($nmeaData);
		//$res = fwrite($conn, $nmeaData . "\r\n");
		$res = fwrite($conn, $nmeaData."\n");
		if($res===FALSE) {
			echo "Error write to socket. Break connection\n";
			fclose($conn);
			echo "Try to reopen\n";
			$conn = stream_socket_accept($socket);
			if(!$conn) {
				echo "Reopen false\n";
				break;
			}
		}
		/*
		// Периодически будем показывать, какие сентенции были
		if(($nStr-$statSend)>9) {
			statShow();
			$statSend = $nStr;
		}
		*/
		$endTime = microtime(TRUE);
		$nStr++;
		echo($r[$i]);	// вращающаяся палка
		echo " " . ($endTime-$startTime) . " string $nStr         \r";
		$i++;
		if($i>=count($r)) $i = 0;
		usleep($delay);
	};
}
foreach($handles as $handle) {
	fclose($handle);
}
@fclose($conn);
fclose($socket);
if($saveSentences) fclose($sentencesfh);

function statCollect($nmeaData) {
/**/
global $statCollection;
$nmeaData1 = substr(trim(str_getcsv($nmeaData)[0]),-3);
//if(strlen($nmeaData1)<3) echo "\n$nmeaData\n";
$statCollection["$nmeaData1"]++;
/*
if(strpos($nmeaData,'ALM')!==FALSE) $statCollection['ALM']++;
elseif(strpos($nmeaData,'AIVDM')!==FALSE) $statCollection['AIVDM']++;
elseif(strpos($nmeaData,'AIVDO')!==FALSE) $statCollection['AIVDO']++;
elseif(strpos($nmeaData,'DBK')!==FALSE) $statCollection['DBK']++;
elseif(strpos($nmeaData,'DBS')!==FALSE) $statCollection['DBS']++;
elseif(strpos($nmeaData,'DBT')!==FALSE) $statCollection['DBT']++;
elseif(strpos($nmeaData,'DPT')!==FALSE) $statCollection['DPT']++;
elseif(strpos($nmeaData,'GGA')!==FALSE) $statCollection['GGA']++;
elseif(strpos($nmeaData,'GLL')!==FALSE) $statCollection['GLL']++;
elseif(strpos($nmeaData,'GNS')!==FALSE) $statCollection['GNS']++;
elseif(strpos($nmeaData,'GSV')!==FALSE) $statCollection['GSV']++;
elseif(strpos($nmeaData,'HDG')!==FALSE) $statCollection['HDG']++;
elseif(strpos($nmeaData,'HDM')!==FALSE) $statCollection['HDM']++;
elseif(strpos($nmeaData,'HDT')!==FALSE) $statCollection['HDT']++;
elseif(strpos($nmeaData,'MTW')!==FALSE) $statCollection['MTW']++;
elseif(strpos($nmeaData,'MWV')!==FALSE) $statCollection['MWV']++;
elseif(strpos($nmeaData,'RMA')!==FALSE) $statCollection['RMA']++;
elseif(strpos($nmeaData,'RMB')!==FALSE) $statCollection['RMB']++;
elseif(strpos($nmeaData,'RMC')!==FALSE) $statCollection['RMC']++;
elseif(strpos($nmeaData,'VHW')!==FALSE) $statCollection['VHW']++;
elseif(strpos($nmeaData,'VWR')!==FALSE) $statCollection['VWR']++;
elseif(strpos($nmeaData,'ZDA')!==FALSE) $statCollection['ZDA']++;
elseif(strpos($nmeaData,'PGRMZ')!==FALSE) $statCollection['PGRMZ']++;
elseif($nmeaData) $statCollection['other']++;
*/
} 	// end function statCollect

function statShow() {
/**/
global $statCollection;
ksort($statCollection);
echo "Messages have been sent:                                   \n";
foreach($statCollection as $code => $count){
	echo "$code: $count\n";
}
echo "\n";
//$statCollection = array();
} // end statShow

function NMEAchecksumm($nmea){
/**/
if(!(is_string($nmea) and $nmea[0]=='$')) return FALSE; 	// only not AIS NMEA string
$checksum = 0;
for($i = 1; $i < strlen($nmea); $i++){
	if($nmea[$i]=='*') break;
	$checksum ^= ord($nmea[$i]);
}
$checksum = str_pad(strtoupper(dechex($checksum)),2,'0',STR_PAD_LEFT);
return $checksum;
} // end function NMEAchecksumm

function bearing($lat1,$lon1,$lat2,$lon2) {
/* азимут направления между двумя точками */

$lat1 = deg2rad($lat1);
$lon1 = deg2rad($lon1);
$lat2 = deg2rad($lat2);
$lon2 = deg2rad($lon2);
$y = sin($lon2 - $lon1) * cos($lat2);
$x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($lon2 - $lon1);
//echo "x=$x,y=$y";
$bearing = (rad2deg(atan2($y, $x)) + 360) % 360;
//echo "bearing=$bearing;              \n";
if($bearing >= 360) $bearing = $bearing-360;
/*
http://makinacorpus.github.io/Leaflet.GeometryUtil/leaflet.geometryutil.js.html#line689
$rad = M_PI/180;
$lat1 = $lat1 * $rad;
$lat2 = $lat2 * $rad;
$lon1 = $lon1 * $rad;
$lon2 = $lon2 * $rad;

$y = sin($lon2 - $lon1) * cos($lat2);
$x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($lon2 - $lon1);

$bearing = ((atan2($y, $x) * 180 / M_PI) + 360) % 360;
if($bearing >= 180) $bearing = $bearing-360;
*/
return $bearing;
} // end function bearing

function nmeaLatDegrees($nmeaDegStr){
$dd = (int)substr($nmeaDegStr,0,2);	// градусы
$mm = (float)substr($nmeaDegStr,2);	// минуты
//echo "nmeaLatDegrees ".($dd + $mm/60)."\n";
return $dd + $mm/60;
} // end function nmeaDegrees

function nmeaLonDegrees($nmeaDegStr){
$dd = (int)substr($nmeaDegStr,0,3);	// градусы
$mm = (float)substr($nmeaDegStr,3);	// минуты
//echo "nmeaLonDegrees ".($dd + $mm/60)."\n";
return $dd + $mm/60;
} // end function nmeaDegrees

?>
