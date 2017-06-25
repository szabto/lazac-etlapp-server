<?php
require_once "config.php";

mysql_connect($config["host"], $config["name"], $config["password"]);
mysql_select_db($config["database"]);
mysql_query("SET NAMES utf8");

header("Content-type: application/json");

$action = isset($_GET["action"]) ? $_GET["action"] : "list";
$days = explode(" ", "vasárnap hétfő kedd szerda csütörtök péntek szombat");
$daysShort = explode(" ", "V H K SZE CS P SZO");
$months = explode(" ", "január február március április május június július augusztus szeptember október november december");

$resp = array();

$limit = 10;

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

	case "setfavoritestate":
		if( isset($_GET["id"]) && isset($_GET["uid"]) && isset($_GET["state"]) ) {
			$id = $_GET["id"];
			$uid = $_GET["uid"];
			$state = $_GET["state"];

			$resp = setFavoriteState($uid, $id, $state);
		}
		else {
			$resp = array(
				"success" => false,
				"error" => "Hiányos adatok!"
			);
		}
	break;

	case "getfavoritedfoods":
		if( isset($_GET["uid"]) ) {
			$uid = $_GET["uid"];

			$resp = getFavoritedFoods($uid);
		}
		else {
			$resp = array(
				"success" => false,
				"error" => "Nem adtál meg USER ID-t!"
			);
		}
	break;

	case "gettoday":
		$date = date("Y-m-d");
		$resp = getDay($date, false);
	break;

	case "registeruuid":
		if( isset($_GET["uuid"]) && isset($_GET["firebase_id"]) ) {
			$uuid = $_GET["uuid"];
			$token = $_GET["firebase_id"];



			$resp = registerUUID( $uuid, $token );
		}
		else {
			$resp = array(
				"success" => false,
				"error" => "Hiányos adatok!"
			);
		}
	break;

	case "getbroadcast":
		$date = date("Y-m-d");
		$resp = getBroadcast($date);
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

function registerUUID( $id, $token ) {
	$id = addslashes($id);
	$token = addslashes($token);

	$cq = mysql_query(sprintf("SELECT * FROM firebase_uids WHERE guid='%s' AND firebase_id='%s'",
		$id,
		$token
	));
	if( mysql_num_rows($cq) ) {
		return array(
			"success" => false,
			"message" => "Already registered."
		);
	}
	else {
		mysql_query(sprintf("INSERT INTO firebase_uids (guid, firebase_id) VALUES('%s', '%s')",
			$id,
			$token
		));
		return array(
			"success" => true
		);
	}
}

function getFavoritedFoods( $uid ) {
	$uid = addslashes($uid);

	$fq = mysql_query(sprintf("SELECT fav.food_id, food.name food_name FROM favorites fav JOIN foods food ON food.id=fav.food_id WHERE fav.user_token='%s'",
		$uid
	));

	if( mysql_num_rows($fq) ) {
		$result = array();
		while( $row = mysql_fetch_assoc($fq) ) {
			$result[] = array(
				"id" => intval($row["food_id"]),
				"name" => $row["food_name"]
			);
		}

		return $result;
	}
	else {
		return array();
	}
}

function setFavoriteState( $uid, $foodId, $state ) {
	sleep(.5);
	$id = intval($foodId);
	$uid = addslashes($uid);
	$state = $state == "true";

	$cq = mysql_query(sprintf("SELECT * FROM favorites WHERE food_id=%d AND user_token='%s'",
		$id,
		$uid
	));

	$exists = mysql_num_rows($cq) > 0;

	if( $state == "1" && $exists ) {
		return array(
			"success" => false,
			"message" => "Ez az étel már a kedvenceid között van."
		);
	}
	if( $state == "0" && !$exists ) {
		return array(
			"success" => false,
			"message" => "Nem található ez az étel a kedvenceid között."
		);
	}

	if( $state == "1" ) { // insert
		mysql_query(sprintf("INSERT INTO favorites (food_id, user_token) VALUES(%d, '%s')",
			$id,
			$uid
		));

		return array(
			"success" => true
		);
	}
	else if( $state == "0" ) {
		mysql_query(sprintf("DELETE FROM favorites WHERE food_id=%d AND user_token='%s'",
			$id,
			$uid
		));

		return array(
			"success" => true
		);
	}

	return array(
		"success" => false,
		"message" => "Ismeretlen hiba."
	);
}

function getFood( $id ) {
	global $months;
	global $days;

	$id = intval($id);
	$food = mysql_query(sprintf("SELECT f.*, COUNT(i.id) served_count, MIN(i.price_low) price_low_min, MAX(i.price_low) price_low_max, AVG(i.price_low) price_low_avg, MIN(i.price_high) price_high_min, MAX(i.price_high) price_high_max, AVG(i.price_high) price_high_avg FROM foods f JOIN items i ON i.food_id=f.id WHERE f.id=%d GROUP BY f.id",
		$id
	));
	if( mysql_num_rows($food) ) {
		$row = mysql_fetch_assoc($food);

		$occurred = array();

		$statQuery = mysql_query(sprintf("SELECT * FROM items i JOIN menu m ON i.menu_id=m.id WHERE i.food_id=%d ORDER BY m.valid ASC",
			$row["id"]
		));
		
		$occurrence = 0;
		$occurrenceHelper = 0;
		$occurrenceCount = 0;
		if( mysql_num_rows($statQuery) ) {
			while($r = mysql_fetch_assoc($statQuery) ) {
				$ct = strtotime($r["valid"] . " 05:05:05");
				if( $occurrenceHelper > 0 ) {
					$dayDiff = round(($ct - $occurrenceHelper) / 60 / 60 / 24);
					$occurrence += $dayDiff;
					$occurrenceCount ++;
				}
				$occurrenceHelper = $ct;

				$day = date("w", $ct);
				$month = intval(date("m", $ct)) - 1;

				$dn = $days[$day];
				$mn = $months[$month];

				$occurred[] = date("Y", $ct) . ". " . $mn . " " . date("d", $ct).", " . $dn;
			}
		}
		$occurrence += round((time() - $occurrenceHelper) / 60 / 60 / 24);
		$occurrence /= ($occurrenceCount+1);

		$retArr = array(
			"success" => true,
			"details" => array(
				"name" => $row["name"],
				"image_url" => strlen($row["image_url"]) < 1 ? null : $row["image_url"],
				"description" => strlen($row["description"]) < 1 ? null : explode("\r\n", $row["description"]),
				"prices" => array()
			),
			"statistic" => array(
				"occurrenceDates" => $occurred,
				"served_count" => intval($row["served_count"]),
				"occurrence" => round($occurrence)
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

function getBroadcast( $date ) {
	$resp = array(
		"success" => true,
		"broadcastMessage" => null,
		"hasBroadcast" => false
	);
	$bq = mysql_query(sprintf("SELECT message FROM broadcasts WHERE '%s' BETWEEN valid_from and valid_to",
		$date
	));
	if( mysql_num_rows($bq) ) {
		$row = mysql_fetch_assoc($bq);
		$resp["broadcastMessage"] = $row["message"];
		$resp["hasBroadcast"] = true;
	}
	return $resp;
}

function getDay( $value, $isId = true ) {
	$menuQ = null;
	if( $isId ) {
		$value = intval($value);
		$menuQ = mysql_query(sprintf("SELECT id, valid FROM menu WHERE id=%d",
			$value
		));
	}
	else {
		$value = addslashes($value);
		$menuQ = mysql_query(sprintf("SELECT id, valid FROM menu WHERE valid='%s'",
			$value
		));
	}
	
	if( mysql_num_rows($menuQ) ) {
		$menu = mysql_fetch_assoc($menuQ);
		$id = $menu["id"];
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
				"can_favorited" => $row["can_favorited"] == "1",
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
					"price_low" => intval($row["price_low"]),
					"can_favorited" => $cc["can_favorited"]
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
