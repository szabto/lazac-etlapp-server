<?php
require_once "config.php";

mysql_connect($config["host"], $config["name"], $config["password"]);
mysql_select_db($config["database"]);
mysql_query("SET NAMES utf8");

header("Content-type: application/json");	

$action = isset($_GET["action"]) ? $_GET["action"] : "list";
//$days = explode(" ", "vasárnap hétfő kedd szerda csütörtök péntek szombat");
$daysShort = explode(" ", "V H K SZE CS P SZO");

$resp = array();

$limit = 11;

switch( $action ) {
	case "getday":
		if( isset($_GET["id"]) ) {
			$resp = getDay($_GET["id"]);
		}
		else {
			$resp = array(
				"success" => false,
				"error" => "Nem adtál meg ID-t!"
			);
		}
	break;

	case "getfood":
		if( isset($_GET["id"]) ) {
			$resp = getFood($_GET["id"]);
		}
		else {
			$resp = array(
				"success" => false,
				"error" => "Nem adtál meg ID-t!"
			);
		}
	break;

	default:
		$resp = getList(isset($_GET["start"]) ? intval($_GET["start"]) : null);
	break;
}

echo json_encode($resp);

mysql_close();

#####################################
# Helpers
#####################################

function getFood( $id ) {
	$id = intval($id);
	$food = mysql_query(sprintf("SELECT *, COUNT(i.id) served_count, MIN(i.price_low) price_low_min, MAX(i.price_low) price_low_max, AVG(i.price_low) price_low_avg, MIN(i.price_high) price_high_min, MAX(i.price_high) price_high_max, AVG(i.price_high) price_high_avg FROM foods f JOIN items i ON i.food_id=f.id WHERE f.id=%d GROUP BY f.id",
		$id
	));
	if( mysql_num_rows($food) ) {
		$row = mysql_fetch_assoc($food);
		$retArr = array(
			"success" => true,
			"details" => array(
				"name" => $row["name"],
				"served_count" => intval($row["served_count"]),
				"image_url" => strlen($row["image_url"]) < 1 ? null : $row["image_url"],
				"description" => strlen($row["description"]) < 1 ? null : explode("\r\n", $row["description"]),
				"prices" => array()
			)
		);


		if( 0 < $val = intval($row["price_low_min"]) )
			$retArr["details"]["prices"]["low_min"] = $val;
		if( 0 < $val = intval($row["price_low_max"]) )
			$retArr["details"]["prices"]["low_max"] = $val;
		if( 0 < $val = intval($row["price_low_avg"]) )
			$retArr["details"]["prices"]["low_avg"] = $val;
		if( 0 < $val = intval($row["price_high_min"]) )
			$retArr["details"]["prices"]["high_min"] = $val;
		if( 0 < $val = intval($row["price_high_max"]) )
			$retArr["details"]["prices"]["high_max"] = $val;
		if( 0 < $val = intval($row["price_high_avg"]) )
			$retArr["details"]["prices"]["high_avg"] = $val;

		return $retArr;
	}
	else {
		return array(
			"success" => false,
			"error" => "Erre a napra nem található menü."
		);
	}
	return $resp;
}

function getDay( $id ) {
	$menuQ = mysql_query(sprintf("SELECT id, valid FROM menu WHERE id=%d",
		$id
	));
	if( mysql_num_rows($menuQ) ) {
		$menu = mysql_fetch_assoc($menuQ);
		$resp = array(
			"success" => true,
			"date" => $menu["valid"],
			"data" => array()
		);
		$cats = mysql_query("SELECT * FROM categories ORDER BY sort ASC");
		while($row=mysql_fetch_assoc($cats)) {
			$resp["data"][] = array(
				"id" => $row["id"],
				"name" => str_replace("  ", " ", $row["name"]),
				"items" => array()
			);
		}

		$items = mysql_query(sprintf("SELECT i.*, f.name, f.image_url, f.description FROM items i JOIN foods f ON f.id=i.food_id WHERE menu_id=%d",
			$id
		));
		while($row = mysql_fetch_assoc($items)) {
			$cc = null;
			foreach( $resp["data"] as &$cat ) {
				if( $cat["id"] == $row["category_id"] ) {
					$cc = &$cat;
				}
			}
			
			if( $cc != null ) {
				$cc["items"][] = array(
					"id" => intval($row["food_id"]),
					"name" => $row["name"],
					"price_high" => intval($row["price_high"]),
					"price_low" => intval($row["price_low"])
				);
			}
			unset($cc);
		}

		foreach( $resp["data"] as &$cat ) {
			unset($cat["id"]);
		}
		return $resp;
	}
	else {
		return array(
			"success" => false,
			"error" => "Erre a napra nem található menü."
		);
	}
}

function getList( $start ) {
	global $limit;
	global $daysShort;
	if( !$start ) $start = 0;
	$r = array(
		"success" => true,
		"list" => array(),
		"there_more" => false
	);
	$l = $limit+1;
	$list = mysql_query("SELECT m.*, count(i.id) as item_count FROM menu m JOIN items i ON i.menu_id=m.id WHERE i.category_id in (1,6,7) GROUP BY menu_id ORDER BY valid DESC LIMIT {$start},{$l}");

	while($row = mysql_fetch_assoc($list)) {
		$t = strtotime($row["valid"]);
		$wd = date("w", $t);
		$day = $daysShort[$wd];
		$weekNum = date("W", $t);

		$r["list"][] = array(
		    "day_name" => $day,
			"id" => intval($row["id"]),
			"week_num" => intval($weekNum),
			"date" => $row["valid"],
			"posted" => $row["created_at"],
			"item_count" => intval($row["item_count"])
		);
	}
	if( count($r["list"]) > $limit ) { //marks to the app, there are more items, and we remove the last
		$r["there_more"] = true;
		unset($r["list"][count($r["list"])-1]);
	}
	
	return $r;
}
?>
