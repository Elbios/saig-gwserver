<?php
error_reporting(E_ERROR);
require_once("lib/sql.class.php");
$db = new sql();


while (@ob_end_clean())
	;
ignore_user_abort(true);
set_time_limit(1200);

ob_start();
/* Send reponse */
// Dequeue message and send +		action	"AASPGDialogueHerika1WhatTopic"	std::string

$responseDataMl = $db->dequeue();
foreach ($responseDataMl as $responseData)
	echo "{$responseData["actor"]}|{$responseData["action"]}|{$responseData["text"]}\r\n";

// Fake Close conection asap

header('Content-Encoding: none');
header('Content-Length: ' . ob_get_length());
header('Connection: close');

ob_end_flush();
ob_flush();
flush();

// Log here (we can be slower)




try {
	$finalData = base64_decode(stripslashes($_GET["DATA"]));
	$finalParsedData = explode("|", $finalData);
	foreach ($finalParsedData as $i => $ele)
		$finalParsedData[$i] = trim(preg_replace('/\s\s+/', ' ', preg_replace('/\'/m', "''", $ele)));


	if ($finalParsedData[0] == "init") // Reset reponses if init sent (Think about this)
		$db->delete("eventlog", "gamets>{$finalParsedData[2]}  ");
	else if ($finalParsedData[0] == "request") { // Just requested reponse
		// Do nothing
	} else // It's an event. Store it
		$db->insert('eventlog', array(
			'ts' => $finalParsedData[1],
			'gamets' => $finalParsedData[2],
			'type' => $finalParsedData[0],
			'data' => $finalParsedData[3],
			'sess' => 'pending',
			'localts' => time()
		)
		);

} catch (Exception $e) {
	syslog(LOG_WARNING, $e->getMessage());
}


// Queue for more responses. Be carefull here. This will efective send data to AI Chat.
if ($finalParsedData[0] == "combatend") {
	require_once("chat/generic.php");
	$GLOBALS["DEBUG_MODE"] = false;
	requestGeneric("(Chat as Herika, comment about last combat)");

} else if ($finalParsedData[0] == "location") { // Locations might be cached
	require_once("chat/generic.php");
	$GLOBALS["DEBUG_MODE"] = false;
	$alreadyGenerated = $db->fetchAll(("select * from responselog where  sent=1 and tag='{$finalParsedData[3]}'"));
	if (sizeof($alreadyGenerated) > 0) {
		$db->update("responselog", "sent=0", "sent=1 and tag='{$finalParsedData[3]}'");
		die();
	}

	//requestGeneric("(Chat as Herika)","What do you think about last events?","AASPGDialogueHerika1WhatTopic");
	//requestGeneric("(Chat as Herika)","What should we do?","AASPGDialogueHerika2Branch1Topic");
	requestGeneric("(Chat as Herika)", "What do you know about this place?", "AASPGDialogueHerika3Branch1Topic", 5, $finalParsedData[3]);

} else if ($finalParsedData[0] == "book") { // Books should be cached
	require_once("chat/generic.php");
	$GLOBALS["DEBUG_MODE"] = false;
	requestGeneric("(Chat as Herika)", "Herika, summarize the book '{$finalParsedData[3]}' in one sentence", 'AASPGQuestDialogue2Topic1B1Topic', 0);

} else if ($finalParsedData[0] == "quest") { // Books should be cached
	require_once("chat/generic.php");
	$GLOBALS["DEBUG_MODE"] = false;
	requestGeneric("(Chat as Herika)", "Herika, what do you think about last events?", 'AASPGDialogueHerika1WhatTopic', 10);

} else if ($finalParsedData[0] == "bleedout") { 
	require_once("chat/generic.php");
	$GLOBALS["DEBUG_MODE"] = false;
	requestGeneric("(Chat as Herika, complain about almost being defeated)", "", 'AASPGQuestDialogue2Topic1B1Topic', 10);
}

?>