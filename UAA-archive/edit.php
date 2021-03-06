<?php
require __DIR__ . "/../config/config.php";
if (!in_array(PHP_SAPI, $C["allowsapi"])) {
	echo "No permission";
	exit(0);
}

set_time_limit(600);
date_default_timezone_set('UTC');
$starttime = microtime(true);
@include __DIR__ . "/config.php";
require __DIR__ . "/../function/curl.php";
require __DIR__ . "/../function/login.php";
require __DIR__ . "/../function/edittoken.php";

function converttime($chitime) {
	if (preg_match("/(\d{4})年(\d{1,2})月(\d{1,2})日 \(.{3}\) (\d{2})\:(\d{2}) \(UTC\)/", $chitime, $m)) {
		return strtotime($m[1] . "/" . $m[2] . "/" . $m[3] . " " . $m[4] . ":" . $m[5]);
	} else {
		echo "converttime fail\n";
		exit(0);
	}
}
function TimediffFormat($time) {
	if ($time < 60) {
		return $time . "秒";
	}

	if ($time < 60 * 50) {
		return round($time / 60) . "分";
	}

	if ($time < 60 * 60 * 23.5) {
		return round($time / (60 * 60)) . "小時";
	}

	return round($time / (60 * 60 * 24)) . "天";
}

echo "The time now is " . date("Y-m-d H:i:s") . " (UTC)\n";

$config_page = file_get_contents($C["config_page"]);
if ($config_page === false) {
	echo "get config failed\n";
	exit(0);
}
$cfg = json_decode($config_page, true);

if (!$cfg["enable"]) {
	echo "disabled\n";
	exit(0);
}

login("bot");
$edittoken = edittoken();

$year = date("Y");
$half = (date("n") <= 6);

for ($i = $C["fail_retry"]; $i > 0; $i--) {
	$starttimestamp = time();
	$res = cURL($C["wikiapi"] . "?" . http_build_query(array(
		"action" => "query",
		"prop" => "revisions",
		"format" => "json",
		"rvprop" => "content|timestamp",
		"titles" => $cfg["main_page_name"],
	)));
	if ($res === false) {
		echo "fetch page fail\n";
		exit(0);
	}
	$res = json_decode($res, true);
	$pages = current($res["query"]["pages"]);
	$text = $pages["revisions"][0]["*"];
	$basetimestamp = $pages["revisions"][0]["timestamp"];

	$hash = md5(uniqid(rand(), true));
	$text = preg_replace("/^(\*\s*{{\s*user-uaa\s*\|)/mi", $hash . "$1", $text);
	$text = explode($hash, $text);
	$oldpagetext = trim($text[0]);
	$newpagetext = "";
	unset($text[0]);

	$archive_count = [
		"sum" => 0,
		"blocked" => 0,
		"tagged" => 0,
		"timeout" => 0,
		"remain" => 0,
	];
	foreach ($text as $temp) {
		$temp = trim($temp);
		$blocked = false;
		$tagged = false;
		$starttime = time();
		$lasttime = 0;
		if (preg_match("/{{user-uaa\|(?:1=)?(.+?)}}/", $temp, $m)) {
			$user = $m[1];
			$res = cURL($C["wikiapi"] . "?" . http_build_query(array(
				"action" => "query",
				"format" => "json",
				"list" => "users",
				"usprop" => "blockinfo",
				"ususers" => $user,
			)));
			if ($res === false) {
				echo "fetch page fail\n";
				exit(0);
			}
			$res = json_decode($res, true);
			if (isset($res["query"]["users"][0]["blockexpiry"]) && in_array($res["query"]["users"][0]["blockexpiry"], $C['blocked_expiry'])) {
				$blocked = true;
				$lasttime = strtotime($res["query"]["users"][0]["blockedtimestamp"]);
			}
			if (preg_match($cfg["tagged_regex"], $temp)) {
				$tagged = true;
			}
		} else if (preg_match("/^\* *{{deltalk|/i", $temp)) {
			$blocked = true;
		} else {
			// Unknown user
		}

		if (preg_match_all("/\d{4}年\d{1,2}月\d{1,2}日 \(.{3}\) \d{2}\:\d{2} \(UTC\)/", $temp, $m)) {
			foreach ($m[0] as $timestr) {
				$time = converttime($timestr);
				if ($time < $starttime) {
					$starttime = $time;
				}

				if ($time > $lasttime) {
					$lasttime = $time;
				}

			}
		} else {
			$lasttime = time();
			$temp .= "{{subst:Unsigned-before|~~~~~}}";
		}

		$archive_type = null;
		if (
			$blocked
			&& time() - $lasttime > $cfg["time_to_live_for_blocked"]
		) {
			$archive_type = "blocked";
		} else if (
			$tagged
			&& time() - $lasttime > $cfg["time_to_live_for_tagged"]
		) {
			$archive_type = "tagged";
		} else if (
			!$blocked
			&& time() - $lasttime > $cfg["time_to_live_for_not_blocked"]
			&& time() - $starttime > $cfg["minimum_time_to_live_for_not_blocked"]
		) {
			$archive_type = "timeout";
		}

		if (is_string($archive_type)) {
			$newpagetext .= "\n" . $temp;
			$archive_count[$archive_type]++;
			$archive_count["sum"]++;
		} else {
			$oldpagetext .= "\n\n" . $temp;
			$archive_count["remain"]++;
		}
	}

	if ($archive_count["sum"] === 0) {
		echo "no change\n";
		exit(0);
	}

	$summary_append = [];
	foreach ($cfg["summary_append"] as $type => $_) {
		if ($archive_count[$type] > 0) {
			$summary_append[] = sprintf($cfg["summary_append"][$type], $archive_count[$type]);
		}
	}
	$summary = sprintf($cfg["main_page_summary"], $archive_count["sum"], implode("、", $summary_append), $archive_count["remain"]);
	$post = array(
		"action" => "edit",
		"format" => "json",
		"title" => $cfg["main_page_name"],
		"summary" => $summary,
		"text" => $oldpagetext,
		"token" => $edittoken,
		"minor" => "",
		"starttimestamp" => $starttimestamp,
		"basetimestamp" => $basetimestamp,
	);
	echo "edit " . $cfg["main_page_name"] . " summary=" . $summary . "\n";
	if (!$C["test"]) {
		$res = cURL($C["wikiapi"], $post);
	} else {
		$res = false;
		file_put_contents("out1.txt", $oldpagetext);
	}
	$res = json_decode($res, true);
	if (isset($res["error"])) {
		echo "edit fail\n";
		if ($i === 1) {
			echo "quit\n";
			exit(0);
		} else {
			echo "retry\n";
			continue;
		}
	} else {
		// saved
	}

	$page = sprintf(
		$cfg["archive_page_name"],
		$year,
		($half ? $cfg["archive_page_name_first_half_year"] : $cfg["archive_page_name_second_half_year"])
	);
	$starttimestamp2 = time();
	$res = cURL($C["wikiapi"] . "?" . http_build_query(array(
		"action" => "query",
		"prop" => "revisions",
		"format" => "json",
		"rvprop" => "content|timestamp",
		"titles" => $page,
	)));
	$res = json_decode($res, true);
	$pages = current($res["query"]["pages"]);

	$oldtext = sprintf(
		$cfg["archive_page_preload"],
		$year,
		($half ? $cfg["first_half_year"] : $cfg["second_half_year"])
	);

	$basetimestamp2 = null;
	if (!isset($pages["missing"])) {
		$oldtext = trim($pages["revisions"][0]["*"]);
		$basetimestamp2 = $pages["revisions"][0]["timestamp"];
		echo $page . " exist\n";
	} else {
		echo $page . " not exist\n";
	}

	$oldtext .= "\n" . trim($newpagetext);

	$text = preg_replace("/\n{3,}/", "\n\n", $oldtext);

	$summary = sprintf($cfg["archive_page_summary"], $archive_count["sum"]);
	$post = array(
		"action" => "edit",
		"format" => "json",
		"title" => $page,
		"summary" => $summary,
		"text" => $text,
		"token" => $edittoken,
		"minor" => "",
		"starttimestamp" => $starttimestamp2,
	);
	if ($basetimestamp2 !== null) {
		$post["basetimestamp"] = $basetimestamp2;
	}
	echo "edit " . $page . " summary=" . $summary . "\n";
	if (!$C["test"]) {
		$res = cURL($C["wikiapi"], $post);
	} else {
		$res = false;
		file_put_contents("out2.txt", $text);
	}
	$res = json_decode($res, true);
	if (isset($res["error"])) {
		echo "edit fail\n";
		if ($i === 1) {
			echo "quit\n";
			exit(0);
		} else {
			echo "retry\n";
			continue;
		}
	} else {
		// saved
		break;
	}
}

$spendtime = (microtime(true) - $starttime);
echo "spend " . $spendtime . " s.\n";
