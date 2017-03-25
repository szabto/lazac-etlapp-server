#!/usr/bin/php
<?php
define("ROOT_PATH", "/www/szabto.com/public/szori/");

require_once ROOT_PATH."config.php";

mylog("Caught incoming mail");
$fd = fopen("php://stdin", "r");
$email = "";

while (!feof($fd)) {
	$line = fread($fd, 1024);
	$email .= $line;
}
fclose($fd);

$email = substr($email, strpos($email, "Content-Description: ujetlap"));
$email = substr($email, strpos($email, "base64") + 6);
$email = substr($email, 0, strpos($email, "--"));

$f = fopen(ROOT_PATH."tst.xls", "w");
fwrite($f, base64_Decode($email));
fclose($f);

mylog("Got xls, connecting to db");

mysql_connect($config["host"], $config["name"], $config["password"]);
mysql_select_db($config["database"]);
mysql_query("SET NAMES utf8");

require(ROOT_PATH."php-excel-reader/excel_reader2.php");
mylog("Loaded excel parser lib, opening xls");

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
		mysql_query(sprintf("INSERT INTO items (menu_id, category_id, name, price_high, price_low) VALUES(%d, %d, '%s', %d, %d)",
			$menu_id,
			$category_id,
			$item["name"],
			$item["price"][0],
			count($item["price"]) > 1 ? $item["price"][1] : null
		));
	}
}

mylog("Sending notification");

$data = array(
	"to" => "/topics/newmenu",
	"data" => array(
		"menu_id" => $menu_id
	),
	"notification"=> array(
		"title" => "Új étlap",
		"text" => "Új étlap érkezett - ".$date,
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

mylog("Done");
mysql_close();

function mylog($msg) {
	$msg = date("Y-m-d H:i:s")." " .$msg."\n";
	file_put_contents(ROOT_PATH."log.txt", $msg, FILE_APPEND | LOCK_EX);
}
?>