<?php
error_reporting(E_ERROR);

$path = dirname((__FILE__)) . DIRECTORY_SEPARATOR;
require_once($path . "conf.php");
require_once($path . "lib/$DRIVER.class.php");
require_once($path . "lib/Misc.php");
$db = new sql();

while (@ob_end_clean());

ignore_user_abort(true);
set_time_limit(1200);

$startTime=time();


function findDotPosition($string) {
    $dotPosition = strpos($string, ".");
    
    if ($dotPosition !== false && strpos($string, ".", $dotPosition + 1) === false) {
        return $dotPosition ;
    }
    
    return false;
}

function split_sentences_stream($paragraph) {
    $sentences = preg_split('/(?<=[.!?])\s+/', $paragraph, -1, PREG_SPLIT_NO_EMPTY);

	$splitSentences = [];
	$currentSentence = '';

	foreach ($sentences as $sentence) {
		$currentSentence .= ' ' . $sentence;
		if (strlen($currentSentence) > 120) {
			$splitSentences[] = trim($currentSentence);
			$currentSentence = '';
		} elseif (strlen($currentSentence) >= 60 && strlen($currentSentence) <= 120) {
			$splitSentences[] = trim($currentSentence);
			$currentSentence = '';
		}
	}

	if (!empty($currentSentence)) {
		$splitSentences[] = trim($currentSentence);
	}
	
	return $splitSentences;
}

function returnLines($lines) {
	foreach ($lines as $n=>$sentence) {

		preg_match_all('/\((.*?)\)/', $sentence, $matches);
		$responseTextUnmooded = trim(preg_replace('/\((.*?)\)/', '', $sentence));
		
		if ($forceMood) {
			$mood = $forceMood;
		} else
			$mood = $matches[1][0];

		$responseText=$responseTextUnmooded;

		
		if ($GLOBALS["TTSFUNCTION"] == "azure") {
			if ($GLOBALS["AZURE_API_KEY"]) {
				require_once("tts/tts-azure.php");
				tts($responseTextUnmooded, $mood, $responseText);
			}
		}

		if ($GLOBALS["TTSFUNCTION"] == "mimic3") {
			if ($GLOBALS["MIMIC3"]) {
				require_once("tts/tts-mimic3.php");
				ttsMimic($responseTextUnmooded, $mood, $responseText);
			}
		}
		
		
		$outBuffer=array(
						'localts' => time(),
						'sent' => 1,
						'text' => trim(preg_replace('/\s\s+/', ' ', $responseTextUnmooded)),
						'actor' => "Herika",
						'action' => "AASPGQuestDialogue2Topic1B1Topic",
						'tag'=>$tag
					);
		
		echo "{$outBuffer["actor"]}|{$outBuffer["action"]}|$responseTextUnmooded\r\n";
		ob_flush();
		flush();
	}
	
}

$starTime=microtime(true);

// PARSE GET RESPONSE
$finalData = base64_decode(stripslashes($_GET["DATA"]));
$finalParsedData = explode("|", $finalData);
foreach ($finalParsedData as $i => $ele)
		$finalParsedData[$i] = trim(preg_replace('/\s\s+/', ' ', preg_replace('/\'/m', "''", $ele)));

// Log my chat
$db->insert(
			'eventlog',
			array(
				'ts' => $finalParsedData[1],
				'gamets' => $finalParsedData[2],
				'type' => $finalParsedData[0],
				'data' => $finalParsedData[3],
				'sess' => 'pending',
				'localts' => time()
			)
		);


// PREPARE CONTEXT DATA
require_once(__DIR__ . DIRECTORY_SEPARATOR . "prompts.php");

$PROMPT_HEAD=($GLOBALS["PROMPT_HEAD"])?$GLOBALS["PROMPT_HEAD"]:"Let\'s roleplay in the Universe of Skyrim. I\'m {$GLOBALS["PLAYER_NAME"]} ";
$request=$PROMPTS["inputtext"][0];
$preprompt=preg_replace("/^[^:]*:/", "", $finalParsedData[3]);
$lastNDataForContext=10;
$contextData = $db->lastDataFor("",$lastNDataForContext*-1);
$head = array();
$foot = array();

$head[] = array('role' => 'user', 'content' => '('.$PROMPT_HEAD.$GLOBALS["HERIKA_PERS"]);
$prompt[] = array('role' => 'assistant', 'content' => $request);
$foot[] = array('role' => 'user', 'content' => $GLOBALS["PLAYER_NAME"].':' . $preprompt);

if (!$preprompt)
	$parms = array_merge($head, ($contextData), $prompt);
else
	$parms = array_merge($head, ($contextData), $foot, $prompt);

	
//// DIRECT OPENAI REST API
	
$url = 'https://api.openai.com/v1/chat/completions';
$data = array(
    'model' => 'gpt-3.5-turbo',
    'messages' => 
        $parms
    ,
    'stream' => true,
    //'max_tokens'=>((isset($GLOBALS["OPENAI_MAX_TOKENS"])?$GLOBALS["OPENAI_MAX_TOKENS"]:48)+0)
	'max_tokens'=>1024
);


$headers = array(
    'Content-Type: application/json',
    "Authorization: Bearer {$GLOBALS["OPENAI_API_KEY"]}"
);

$options = array(
    'http' => array(
        'method' => 'POST',
        'header' => implode("\r\n", $headers),
        'content' => json_encode($data)
    )
);
error_reporting(E_ALL);
$context = stream_context_create($options);
$handle = fopen($url, 'r', false, $context);

///////DEBUG CODE
$fileLog = fopen("log.txt", 'a');
/////

if ($handle === false) {
	die("$url");
} else {
    // Read and process the response line by line
    $buffer="";
    $totalBuffer="";
    while (!feof($handle)) {
        $line = fgets($handle);
	    
		
		
        $data=json_decode(substr($line,6),true);
        if (isset($data["choices"][0]["delta"]["content"]))
            if (strlen(trim($data["choices"][0]["delta"]["content"]))>0)
                $buffer.=$data["choices"][0]["delta"]["content"];
        $totalBuffer.=$data["choices"][0]["delta"]["content"];
       
		if (strlen($buffer)<50)	// Avoid too short buffers
			continue;
		
		$position = findDotPosition($buffer);
		
        if ($position !== false) {
            $extractedData = substr($buffer, 0, $position + 1);
            $remainingData = substr($buffer, $position + 1);
            $sentences=split_sentences_stream(cleanReponse($extractedData));
			
            returnLines($sentences);
            //echo "$extractedData  # ".(microtime(true)-$starTime)."\t".strlen($finalData)."\t".PHP_EOL;  // Output
            $extractedData="";
            $buffer=$remainingData;
            
        }
    }
    if ($buffer) {
		 $sentences=split_sentences_stream($buffer);
         returnLines($sentences);
	}
    fclose($handle);
	fwrite($fileLog, $totalBuffer . PHP_EOL); // Write the line to the file with a line break // DEBUG CODE
}


echo 'X-CUSTOM-CLOSE';
//echo "\r\n<$totalBuffer>";
?>
