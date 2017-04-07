#!/usr/bin/php
<?php
define("ROOT_PATH", "/www/szabto.com/public/szori/");

require_once ROOT_PATH."config.php";
require_once ROOT_PATH.'php-excel-reader/excel_reader2.php';


$threadId = rand(0,9999);

mylog("Caught incoming mail");
$fd = fopen("php://stdin", "r");
$email = "";

while (!feof($fd)) {
	$line = fread($fd, 1024);
	$email .= $line;
}
fclose($fd);

$sp = strpos($email, "Content-Description: ujetlap");
if( $sp < 0 || $sp === false ) {
	mylog("BAD MAIL, IGNORING");
	echo "DO NOT USE THIS EMAIL PLEASE";
	return;
}
while(file_exists(ROOT_PATH."tst.xls")) { myLog("OLD XLS still exists, so waiting..."); sleep(1); }

mylog("Got xls, connecting to db");
mysql_connect($config["host"], $config["name"], $config["password"]);
mysql_select_db($config["database"]);
mysql_query("SET NAMES utf8");

$lastXls = null;
while(false !== $sp = strpos($email, "Content-Description: ujetlap")) {
	$email = substr($email, $sp);
	$email = substr($email, strpos($email, "base64") + 6);
	$endPos = strpos($email, "--");
	$xls = substr($email, 0, $endPos);
	$email = substr($email, $endPos);

	$f = fopen(ROOT_PATH."tst.xls", "w");
	fwrite($f, base64_Decode($xls));
	fclose($f);

	$lastXls = parseXls();
}

if( $lastXls != null ) {
	mylog("Sending notification");
	sendNotification($lastXls);
}

unlink(ROOT_PATH."tst.xls");

mylog("Done");
mysql_close();

function sendNotification( $xlsData ) {
	global $config;
	$data = array(
		"to" => "/topics/newmenu",
		"data" => array(
			"menu_id" => $xlsData["id"]
		),
		"notification"=> array(
			"title" => "Új étlap",
			"text" => "Új étlap érkezett - ".$xlsData["date"],
	    	"sound" => "default"
		),
		"priority" => 10
	);
	$dataString = json_encode($data, JSON_UNESCAPED_SLASHES);
	$ch = curl_init('https://fcm.googleapis.com/fcm/send');

	curl_setopt($ch, CURLOPT_HTTPHEADER, array(
		"Content-Type:  application/json",
		"Authorization: key=".$config["google_key"],
		"Content-length: ".strlen($dataString)
	));                                                                    
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $dataString);

	$response = curl_exec($ch);

	curl_close($ch);
}

function parseXls( $file = "tst.xls" ) {
	$sheet = new Spreadsheet_Excel_Reader(ROOT_PATH."tst.xls");
	mylog("xls open, starting action");

	$date = $sheet->val(9,2);

	$foods = array();

	$heads = explode(" ", "LEVE FRISSE NAPI ITAL KÖRET SALÁT ÉDES");

	preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{2,4})/i', $date, $matches);
	$date = $matches[3]."-".$matches[1]."-".$matches[2];
	mylog("XLS date is: ". $date);

	$currentCategory = "";
	for( $i=0;$i<2;$i++ ) {
		for( $x=10;$x<60;$x++ ) {

			$name = trim($sheet->val($x, $i*4+2));
			$price_low = intval(str_replace(array(",", '$'), '', trim($sheet->val($x, $i*4+4))));
			$price_high = intval(str_replace(array(",", '$'), '', trim($sheet->val($x, $i*4+3))));

			if( $name ) {
				$isHead = false;
				foreach($heads as $head) {
					if( stristr($name, $head) ) {
						$isHead = true;
					}
				}
				if( $isHead && !$price_low && !$price_high ) {
					if( !stristr($name, "ZÓNA") )
						$foods[$name] = array();
					$currentCategory = $name;
					continue;
				}

				if( $price_high || $price_low ) {
					$doTranslate = false;
					if( stristr($currentCategory, "ZÓNA") ) {
						$currentCategory = "NAPI AJÁNLAT";
						$doTranslate = true;
					}
					if( $doTranslate ) {
						$done = false;
						for($d=0;$d<count($foods[$currentCategory]);$d++) {
							if( $foods[$currentCategory][$d]["name"] == $name ) {
								$foods[$currentCategory][$d]["price"][] = $price_high;
								$currentCategory = "ZÓNA NAPI AJÁNLAT";
								$done = true;
								$d = 100;
							}
						}
						if( !$done ) {
							$c =  array(
								"name" => $name,
								"price" => array($price_high)
							);
							$foods[$currentCategory][] = $c;
						}
					}
					else {
						$c =  array(
							"name" => $name,
							"price" => array($price_high)
						);
						if( $price_low ) {
							$c["price"][] = $price_low;
						}
						$foods[$currentCategory][] = $c;
					}
				}
			}
		}
	}
	mylog("Got foods");

	$menu_id = 0;
	$q = mysql_query("SELECT * FROM menu WHERE valid='{$date}'");
	if( !mysql_num_rows($q) ) {
		mysql_query("INSERT INTO menu (valid, created_at) VALUES('{$date}', now())");
		$menu_id = mysql_insert_id();
	}
	else {
		$row = mysql_fetch_assoc($q);
		$menu_id = $row["id"];
	}

	mylog("Menu id is:".$menu_id);

	mysql_query("DELETE FROM items WHERE menu_id={$menu_id}");

	foreach( $foods as $category => $items ) {
		$q = mysql_query("SELECT id FROM categories WHERE name='{$category}'");
		$cat = mysql_fetch_assoc($q);
		$category_id = $cat["id"];

		foreach( $items as $item ) {
			$name = addslashes(trim($item["name"]));
			$foodQuery = mysql_query(sprintf("SELECT id FROM foods WHERE name LIKE '%s'",
				$name
			));
			$foodId = 0;
			if( mysql_num_rows($foodQuery) ) {
				$fr = mysql_fetch_assoc($foodQuery);
				$foodId = $fr["id"];
			}
			else {
				mysql_query(sprintf("INSERT INTO foods (name) VALUES('%s')",
					$name
				));

				$foodId = mysql_insert_id();
			}

			mysql_query(sprintf("INSERT INTO items (menu_id, category_id, food_id, price_high, price_low) VALUES(%d, %d, '%s', %d, %d)",
				$menu_id,
				$category_id,
				$foodId,
				$item["price"][0],
				count($item["price"]) > 1 ? $item["price"][1] : null
			));
		}
	}

	return array(
		"id" => $menu_id,
		"date" => $date
	);
}

function mylog($msg) {
	global $threadId;
	$msg = date("Y-m-d H:i:s")."[{$threadId}] " .$msg."\n";
	file_put_contents(ROOT_PATH."log.txt", $msg, FILE_APPEND | LOCK_EX);
}
?>
