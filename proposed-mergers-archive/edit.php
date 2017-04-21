<?php
require(__DIR__."/../config/config.php");
if (!in_array(PHP_SAPI, $C["allowsapi"])) {
	exit("No permission");
}

set_time_limit(600);
date_default_timezone_set('UTC');
$starttime = microtime(true);
@include(__DIR__."/config.php");
require(__DIR__."/../function/curl.php");
require(__DIR__."/../function/login.php");
require(__DIR__."/../function/edittoken.php");

echo "The time now is ".date("Y-m-d H:i:s")." (UTC)\n";

login();
$edittoken = edittoken();

for ($i=$C["fail_retry"]; $i > 0; $i--) {
	$starttimestamp = time();
	$res = cURL($C["wikiapi"]."?".http_build_query(array(
		"action" => "query",
		"prop" => "revisions",
		"format" => "json",
		"rvprop" => "content|timestamp",
		"titles" => $C["from_page"]
	)));
	if ($res === false) {
		exit("fetch page fail\n");
	}
	$res = json_decode($res, true);
	$pages = current($res["query"]["pages"]);
	$text = $pages["revisions"][0]["*"];
	$basetimestamp = $pages["revisions"][0]["timestamp"];
	echo "get main page\n";

	$start = strpos($text, $C["text1"]);
	$oldpagetext = substr($text, 0, $start+strlen($C["text1"]));
	$text = substr($text, $start+strlen($C["text1"]));

	$hash = md5(uniqid(rand(), true));
	$text = preg_replace("/^( *==.+?== *)$/m", $hash."$1", $text);
	$text = explode($hash, $text);
	echo "find ".(count($text)-1)." sections\n";

	$oldpagetext .= $text[0];
	$newpagetext = array();
	$archive_count = array("all" => 0);
	unset($text[0]);
	echo "start split\n";
	foreach ($text as $temp) {
		if (preg_match("/=== *(\d+)年(\d+)月 *=== */", $temp, $m)) {
			$year = $m[1];
			$month = $m[2];
			echo $year."/".$month."\t";
		} else {
			echo "title get fail\t";
			$oldpagetext .= $temp;
			continue;
		}

		$hash = md5(uniqid(rand(), true));
		$temp = preg_replace("/^(\*[^*:])/m", $hash."$1", $temp);
		$temp = explode($hash, $temp);
		$origincount = (count($temp)-1);
		echo "find ".$origincount." sections\n";

		$oldpagetexttemp = $temp[0];
		unset($temp[0]);

		$count = 0;
		foreach ($temp as $temp2) {
			if (preg_match("/{{(完成|Done|Finish|未完成|Undone|Not Done|Notdone)}}/i", $temp2)) {
				echo "archive ".$temp2."\n";
				if (!isset($newpagetext[$year])) {
					$newpagetext[$year] = array();
					$archive_count[$year] = 0;
				}
				if (!isset($newpagetext[$year][$month])) {
					$newpagetext[$year][$month] = array();
				}
				$newpagetext[$year][$month] []= $temp2;
				$archive_count[$year]++;
				$archive_count["all"]++;
				$count++;
			} else {
				$oldpagetexttemp .= $temp2;
			}
		}

		if ($origincount != $count) {
			$oldpagetext .= $oldpagetexttemp;
		} else {
			echo "empty section\n";
		}
	}

	if ($archive_count["all"] === 0) {
		exit("no change\n");
	}

	echo "start edit\n";

	echo "edit main page\n";
	$summary = $C["summary_prefix"]."：存檔".$archive_count["all"]."請求至".count($newpagetext)."頁面";
	$post = array(
		"action" => "edit",
		"format" => "json",
		"title" => $C["from_page"],
		"summary" => $summary,
		"text" => $oldpagetext,
		"token" => $edittoken,
		"starttimestamp" => $starttimestamp,
		"basetimestamp" => $basetimestamp
	);
	echo "edit ".$C["from_page"]." summary=".$summary."\n";
	if (!$C["test"]) $res = cURL($C["wikiapi"], $post);
	else $res = false;
	$res = json_decode($res, true);
	if (isset($res["error"])) {
		echo "edit fail\n";
		if ($i === 1) {
			exit("quit\n");
		} else {
			echo "retry\n";
		}
	} else {
		break;
	}
}

echo "edit archive page\n";
foreach ($newpagetext as $year => $newtext) {
	$page = $C["to_page_prefix"].$year."年";
	for ($i=$C["fail_retry"]; $i > 0; $i--) { 
		$starttimestamp2 = time();
		$res = cURL($C["wikiapi"]."?".http_build_query(array(
			"action" => "query",
			"prop" => "revisions",
			"format" => "json",
			"rvprop" => "content|timestamp",
			"titles" => $page
		)));
		$res = json_decode($res, true);
		$pages = current($res["query"]["pages"]);

		$oldtext = "{{存档页|Wikipedia:合并请求}}\n";

		$basetimestamp2 = null;
		if (!isset($pages["missing"])) {
			$oldtext = $pages["revisions"][0]["*"];
			$basetimestamp2 = $pages["revisions"][0]["timestamp"];
			echo $page." exist\n";
		} else {
			echo $page." not exist\n";
		}

		$oldtext = preg_replace("/\n{3,}/", "\n\n", $oldtext);

		$hash = md5(uniqid(rand(), true));
		$oldtext = preg_replace("/^(=== *\d+年\d+月 *=== *)$/m", $hash."$1", $oldtext);
		$oldtext = explode($hash, $oldtext);
		echo "find ".(count($oldtext)-1)." sections\n";

		$oldtextarr = array();
		$oldtextarr[0] = $oldtext[0];
		unset($oldtext[0]);

		foreach ($oldtext as $temp) {
			$temp = preg_replace("/\n*$/", "\n", $temp);
			if (preg_match("/=== *\d+年(\d+)月 *=== */", $temp, $m)) {
				$oldtextarr[$m[1]] = $temp;
			} else {
				$oldtextarr[13] = $temp;
			}
		}

		foreach ($newtext as $month => $newtext2) {
			foreach ($newtext2 as $newtext3) {
				if (!isset($oldtextarr[$month])) {
					$oldtextarr[$month] = "===".$year."年".$month."月===\n";
					echo "new section ".$year."年".$month."月\n";
				}
				$oldtextarr[$month] .= $newtext3;
			}
		}

		$text = implode("\n", $oldtextarr);
		$text = preg_replace("/\n{3,}/", "\n\n", $text);

		$summary = $C["summary_prefix"]."：存檔自[[".$C["from_page"]."]]共".$archive_count[$year]."個請求";
		$post = array(
			"action" => "edit",
			"format" => "json",
			"title" => $page,
			"summary" => $summary,
			"text" => $text,
			"token" => $edittoken,
			"starttimestamp" => $starttimestamp2
		);
		if ($basetimestamp2 !== null) {
			$post["basetimestamp"] = $basetimestamp2;
		}
		echo "edit ".$page." summary=".$summary."\n";
		if (!$C["test"]) $res = cURL($C["wikiapi"], $post);
		else $res = false;
		$res = json_decode($res, true);
		if (isset($res["error"])) {
			echo "edit fail\n";
			if ($i === 1) {
				exit("quit\n");
			} else {
				echo "retry\n";
			}
		} else {
			break;
		}
	}
	echo "saved\n";
}

$spendtime = (microtime(true)-$starttime);
echo "spend ".$spendtime." s.\n";
