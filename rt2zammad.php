<?php
require "./config.php";

$GLOBALS['opt'] = array();
$article=array();
$zid=0;


////////////////////////////////////////////////////////////////////////////////
// want to rework this setup a bit...
// I want to be able to export import by tickets, so lets get a list of tickets to start
// I think I also want to work this into an object...
////////////////////////////////////////////////////////////////////////////////
class rt2zammad {
	////////////////////////////////////////////////////////////////////////////
	function __construct(){
		$this->dateStampLog();
		$this->epochBeg = time();
		$this->ticketIdClauses = array();
		$this->connection=mysqli_connect($GLOBALS['config']['rt_mysql_host'],$GLOBALS['config']['rt_mysql_user'],$GLOBALS['config']['rt_mysql_pass'],$GLOBALS['config']['rt_mysql_db']);

		if(! $this->connection){
			print("this->connection error\n");
			myErrorLog(mysqli_error());
		}
		mysqli_set_charset($this->connection,'utf8');

		// $sql = "DROP TABLE IF EXISTS rt_zammad;";
		// print("Dropping rt_zammad table: $sql\n");
		// $resultc=mysqli_query($this->connection,$sql);

        // this could be moved into the create-tickets sub
		$this->resolveWhichTickets();
	}
	////////////////////////////////////////////////////////////////////////////
	function __destruct(){
		$this->epochEnd = time();
		$ss = $this->epochEnd - $this->epochBeg;
		// $d = floor($secs / 86400);
		// $h = floor($secs % 86400) / 3600;
		// $m = floor($secs % 3600) / 60;
		// $s = $secs % 60;

		$s = $ss%60;
        $m = floor(($ss%3600)/60);
        $h = floor(($ss%86400)/3600);
        $d = floor(($ss%2592000)/86400);

		$this->dateStampLog();
        $dur  = sprintf("%02d:%02d:%02d:%02d", $d, $h, $m, $s);
        myErrorLog("#### run time: $dur");
	}
	////////////////////////////////////////////////////////////////////////////
	function sleep($int){
		sleep($int);
	}
	////////////////////////////////////////////////////////////////////////////
	function dateStampLog(){
		myErrorLog("#### " . date("Ymd-His"));
	}
	////////////////////////////////////////////////////////////////////////////
	function create_rt_zammad_table(){
		if ($GLOBALS['opt']['drop']){
			$sql = "DROP TABLE IF EXISTS rt_zammad;";
			myErrorLog("##### Dropping rt_zammad table: $sql #####");
			dprint("Dropping rt_zammad table: $sql");
			$resultc=mysqli_query($this->connection,$sql);
		}

		$sql = "CREATE TABLE IF NOT EXISTS rt_zammad (rt_tid INTEGER, zm_tid INTEGER, UNIQUE(rt_tid,zm_tid));";
		print("Creating rt_zammad table: $sql\n");
		$resultc=mysqli_query($this->connection,$sql);
	}
	////////////////////////////////////////////////////////////////////////////
	function resolveWhichTickets(){
		if (isset($GLOBALS['opt']['tickets']) && $GLOBALS['opt']['tickets'] != ''){
			$ticketChunks = explode(",",$GLOBALS['opt']['tickets']);
			foreach($ticketChunks as $ticketChunk){
				dprint("ticketChunk: $ticketChunk");
				if ( strpos($ticketChunk,':') ){
					list($tRangeBeg,$tRangeEnd) = explode(":",$ticketChunk,2);
					$tRangeBegInt = intval($tRangeBeg);
					$tRangeEndInt = intval($tRangeEnd);
					if ( $tRangeBegInt > 0 && $tRangeEndInt > 0){
						$this->ticketIdClauses[]="AND Tickets.Id BETWEEN $tRangeBegInt AND $tRangeEndInt";
					}
					else {
						$errState = "Bad Range Specification ($ticketChunk)";
						dprint("$errState");
					}
				}
				elseif( strpos($ticketChunk,'>') !== False){
					list($blank,$ticketStr) = explode(">",$ticketChunk,2);
					$ticketInt = intval($ticketStr);
					$this->ticketIdClauses[] = "AND Tickets.Id > $ticketInt";
				}
				elseif( strpos($ticketChunk,'<') !== False){
					list($blank,$ticketStr) = explode("<",$ticketChunk,2);
					$ticketInt = intval($ticketStr);
					$this->ticketIdClauses[] = "AND Tickets.Id < $ticketInt";
				}
				else {
					$ticketInt = intval($ticketChunk);
					$this->ticketIdClauses[] = "AND Tickets.Id = $ticketInt";
				}
			}
		}
		else $this->ticketIdClauses[] = "";
	}
	////////////////////////////////////////////////////////////////////////////
	// Want to allow individual tickets (or ticketRanges) to be handled from one specification
	// So comma separated entries, ranges specified with a :, greater than specified with >000
	////////////////////////////////////////////////////////////////////////////
	function createTicketLoop(){
		$this->create_rt_zammad_table();

		// Want to see about options for modifying this query to get a unique list of Effective Ticket Ids for when bringing in tickets.
		// This would allow us to match the query in the transactions section and in essense merge tickets appropriately as they get imported into zammad
		foreach($this->ticketIdClauses as $ticketIdClause){
			dprint("Running select using the following ticketClause: $ticketIdClause");
			$sql = "SELECT Tickets.Id AS rt_tid,Tickets.Queue AS rt_tqueue,Tickets.Status AS rt_tstatus from Tickets WHERE ( Tickets.Status IN ('new','open','resolved') {$ticketIdClause} ) ORDER by Tickets.Id;";
			dprint("Running: $sql");
			$result=mysqli_query($this->connection,$sql);
			while($ticket=mysqli_fetch_assoc($result)){
				dprint("TicketId: " . $ticket['rt_tid'] . ", TicketStatus: " . $ticket['rt_tstatus'] . ", TicketQueue: " . $ticket['rt_tqueue']);
				$this->createSingleTicket($ticket['rt_tid']);
			}
		}
	}
	////////////////////////////////////////////////////////////////////////////
	////////////////////////////////////////////////////////////////////////////
	function getDestination($id){
		// want to rework this a bit, while we want a string, I think it's going to
		// be best to treat it as a number and then convert to a string...
		// so shifting --prefix= from a single digit prefix added and then padded, it
		// will just become an offset, added to the ticket number.

		// we could also try to sort out the maximum length of existing tickets

		// return $GLOBALS['opt']['prefix'] . str_pad($id,5,'0',STR_PAD_LEFT);
		$strid = sprintf("%d",intval($GLOBALS['opt']['offset']) + intval($id));
		if ($GLOBALS['opt']['debug']) print("DEBUG: id: $id, offset: {$GLOBALS['opt']['offset']}, strid: $strid\n");
		return $strid;
	}
	////////////////////////////////////////////////////////////////////////////
	// merge tickets, looks like all the required data is contained in the url
	// Looks like create-tickts needs to be run before this is
	////////////////////////////////////////////////////////////////////////////
	function merge_tickets(){
		$this->create_rt_zammad_table();
		$sql="SELECT Transactions.Created,Tickets.id,Tickets.EffectiveId,rt_zammad.zm_tid as source,zmt.zm_tid as destination from Transactions
		  LEFT JOIN Tickets on Transactions.ObjectId=Tickets.id and Transactions.ObjectType='RT::Ticket'
		  LEFT JOIN rt_zammad on Transactions.ObjectId=rt_zammad.rt_tid
		  LEFT JOIN rt_zammad zmt on Tickets.EffectiveId=zmt.rt_tid
		  where ObjectType='RT::Ticket' and Tickets.Status in ('new','open','resolved') and Transactions.Type='AddLink' and Tickets.id<>Tickets.EffectiveId order by Transactions.id";
		//$sql="select Transactions.*,Users.EmailAddress,Tickets.id as TicketId,Tickets.Subject,Tickets.Queue,zm_tid from Transactions LEFT JOIN Users on Transactions.Creator=Users.id LEFT JOIN Tickets on Transactions.ObjectId=Tickets.id LEFT JOIN rt_zammad on Transactions.ObjectId=rt_zammad.rt_tid where ObjectType='RT::Ticket' and Tickets.Status in ('new','open','resolved') and Transactions.Type='AddLink' and ObjectId=23 order by Transactions.id";
		$result1=mysqli_query($this->connection,$sql);
		$cntr = 0;
		while($transaction=mysqli_fetch_assoc($result1)){
			$cntr++;
			$created=$transaction['Created'];
			$source=$transaction['source'];
			$eid=$transaction['EffectiveId'];
			// $destination="9" . str_pad($transaction['EffectiveId'],5,'0',STR_PAD_LEFT);
			$destination=$this->getDestination($transaction['EffectiveId']);

			$url="";
			$curl_action="GET";
			$url="ticket_merge/$source/$destination";

            dprint("created: $created, source: $source, destination: $destination, eid: $eid, url: $url");
			if ( $source == '' || $destination == ''){
				print("ERROR: source ($source) or destination ($destination) is empty, cannot merge tickets.\n");
			}
            if ( $GLOBALS['opt']['test'] ) continue;

            // so this is an attempt to set the time of the system running this to the ticket creation time
			// would likely work if you are running on the system hosting zammad
			//exec("/usr/bin/timedatectl set-time '$created'");

			// $base_url=$GLOBALS['config']['base_url'];
			// $base_url="http://help.nixe.co.uk/api/v1";
			$hmrc=curl_init();
			$headers=array();
			$options=array();
			$options[CURLOPT_URL]= $GLOBALS['config']['base_url'] . "/$url";
			myErrorLog($options[CURLOPT_URL]);
			//$options[CURLOPT_POST]=true;
			$options[CURLOPT_RETURNTRANSFER]=true;
			$options[CURLOPT_VERBOSE]=true;
			$options[CURLOPT_HEADER]=false;
			// $options[CURLOPT_USERPWD]="fgaspar@nixe.co.uk:*******";
			$options[CURLOPT_USERPWD]=$GLOBALS['config']['curlopt_userpwd'];
			$headers[]="Content-Type: application/json";
			//myErrorLog($jdata);
			//$options[CURLOPT_POSTFIELDS]=$jdata;
			$options[CURLOPT_HTTPHEADER]=$headers;
			$options[CURLOPT_CUSTOMREQUEST]=$curl_action;
			curl_setopt_array($hmrc,$options);

			$data=curl_exec($hmrc);
			if(! $data){
				echo "\nError: " . curl_error($hmrc) . "\n";
				myErrorLog(curl_error($hmrc));
			}else{
				$res=json_decode($data,true);
				myErrorLog("RESULT: $data");
				curl_close($hmrc);
			}
		}
		dprint("cntr: $cntr");
	}
	////////////////////////////////////////////////////////////////////////////
	function curl_GET($url){
		$hmrc=curl_init();
		$headers=array();
		$options=array();
		$options[CURLOPT_URL]= $GLOBALS['config']['base_url'] . "/$url";
		dprint("URI: {$options[CURLOPT_URL]}");
		myErrorLog($options[CURLOPT_URL]);
		//$options[CURLOPT_POST]=true;
		$options[CURLOPT_RETURNTRANSFER]=true;
		$options[CURLOPT_VERBOSE]=true;
		$options[CURLOPT_HEADER]=false;
		// $options[CURLOPT_USERPWD]="fgaspar@nixe.co.uk:*******";
		$options[CURLOPT_USERPWD]=$GLOBALS['config']['curlopt_userpwd'];
		$headers[]="Content-Type: application/json";
		//myErrorLog($jdata);
		//$options[CURLOPT_POSTFIELDS]=$jdata;
		$options[CURLOPT_HTTPHEADER]=$headers;
		$options[CURLOPT_CUSTOMREQUEST]='GET';
		curl_setopt_array($hmrc,$options);

		$data=curl_exec($hmrc);
		if(! $data){
			echo "\nError: " . curl_error($hmrc) . "\n";
			myErrorLog(curl_error($hmrc));
		}else{
			$obj=json_decode($data,true);
			myErrorLog("RESULT: $data");
			curl_close($hmrc);
		}
		return($obj);
	}
	////////////////////////////////////////////////////////////////////////////
	// This needs to be run after rt_zammad has been fully populated by create_tickets
	// Also looks like this is expected to be run on a system with access to both RT and zammad databases
	////////////////////////////////////////////////////////////////////////////
	function createSingleTicket($ticketId = 0){
		// sql to collect all transactions associated with a given ticket id
		// NOTE: Transaction.Id will be duplicated if there are multiple requestors on a ticket
		// feel like this should be restructured a bit...  Pretty sure the WHERE clause should be Tickets.EffectiveId = $ticketId
		// Using EffectiveId would essentially merge the tickets in zammad, but will cause issues with new_ticket since there will be multiple create tickets
		// transactions for a merged ticket...  If we can easily exclude those other creates, we should be fine
		$sql="SELECT Transactions.*,Users.EmailAddress,Requestor.EmailAddress AS Requestor,Tickets.id AS TicketId,Tickets.Subject,Tickets.Queue FROM Transactions
		  LEFT JOIN Users ON Transactions.Creator=Users.id
		  LEFT JOIN Tickets ON Transactions.ObjectId=Tickets.id
		  LEFT JOIN Groups ON Tickets.id=Groups.Instance AND Groups.Domain='RT::Ticket-Role' AND Groups.Name='Requestor'
		  LEFT JOIN GroupMembers ON Groups.id=GroupMembers.GroupId
		  LEFT JOIN Users Requestor ON GroupMembers.MemberId=Requestor.id
		  WHERE Tickets.Id = $ticketId
		    AND ObjectType='RT::Ticket'
		    AND Tickets.Status IN ('new','open','resolved')
			AND Transactions.Type IN ('Create','Status','Correspond','Comment','Set','AddLink')
		  ORDER BY Transactions.id;";
		// $sql="SELECT Transactions.*,Users.EmailAddress,Requestor.EmailAddress as Requestor,Tickets.id as TicketId,Tickets.Subject,Tickets.Queue from Transactions
		//   LEFT JOIN Users on Transactions.Creator=Users.id
		//   LEFT JOIN Tickets on Transactions.ObjectId=Tickets.id
		//   LEFT JOIN Groups on Tickets.id=Groups.Instance and Groups.Domain='RT::Ticket-Role' and Groups.Name='Requestor'
		//   LEFT JOIN GroupMembers on Groups.id=GroupMembers.GroupId
		//   LEFT JOIN Users Requestor on GroupMembers.MemberId=Requestor.id
		//   where ObjectType='RT::Ticket'
		//     and Tickets.Status in ('new','open','resolved')
		// 	and Transactions.Type in ('Create','Status','Correspond','Comment','Set','AddLink')
		// 	and Transactions.id in(177292)
		//   order by Transactions.id";
		//$sql="select Transactions.*,Users.EmailAddress,Requestor.EmailAddress as Requestor,Tickets.id as TicketId,Tickets.Subject,Tickets.Queue from Transactions LEFT JOIN Users on Transactions.Creator=Users.id LEFT JOIN Tickets on Transactions.ObjectId=Tickets.id LEFT JOIN Groups on Tickets.id=Groups.Instance and Groups.Domain='RT::Ticket-Role' and Groups.Name='Requestor' LEFT JOIN GroupMembers on Groups.id=GroupMembers.GroupId LEFT JOIN Users Requestor on GroupMembers.MemberId=Requestor.id where ObjectType='RT::Ticket' and Tickets.Status in ('new','open','resolved') and Transactions.Type in ('Create','Status','Correspond','Comment','Set','AddLink') and Transactions.id between $tid_beg and $tid_end order by Transactions.id";
		// $sql="select Transactions.*,Users.EmailAddress,Requestor.EmailAddress as Requestor,Tickets.id as TicketId,Tickets.Subject,Tickets.Queue from Transactions LEFT JOIN Users on Transactions.Creator=Users.id LEFT JOIN Tickets on Transactions.ObjectId=Tickets.id LEFT JOIN Groups on Tickets.id=Groups.Instance and Groups.Domain='RT::Ticket-Role' and Groups.Name='Requestor' LEFT JOIN GroupMembers on Groups.id=GroupMembers.GroupId LEFT JOIN Users Requestor on GroupMembers.MemberId=Requestor.id where ObjectType='RT::Ticket' and Tickets.Status in ('new','open','resolved') and Transactions.Type in ('Create','Status','Correspond','Comment','Set','AddLink') order by Transactions.id";
		//$sql="select Transactions.*,Users.EmailAddress,Requestor.EmailAddress as Requestor,Tickets.id as TicketId,Tickets.Subject,Tickets.Queue from Transactions LEFT JOIN Users on Transactions.Creator=Users.id LEFT JOIN Tickets on Transactions.ObjectId=Tickets.id LEFT JOIN Groups on Tickets.id=Groups.Instance and Groups.Domain='RT::Ticket-Role' and Groups.Name='Requestor' LEFT JOIN GroupMembers on Groups.id=GroupMembers.GroupId LEFT JOIN Users Requestor on GroupMembers.MemberId=Requestor.id where ObjectType='RT::Ticket' and Tickets.Status in ('new','open','resolved') and Transactions.Type in ('Create','Status','Correspond','Comment','Set','AddLink') and Transactions.ObjectId in(select Tickets.id from Tickets LEFT JOIN zammad.tickets on concat('43',lpad(Tickets.id,8,'0'))=tickets.number where isnull(tickets.id) and Status<>'deleted' and Status<>'rejected' order by id) and Transactions.id<=463246 order by Transactions.id";
		//$sql="select Transactions.*,Users.EmailAddress,Tickets.id as TicketId,Tickets.Subject,Tickets.Queue from Transactions LEFT JOIN Users on Transactions.Creator=Users.id LEFT JOIN Tickets on Transactions.ObjectId=Tickets.id where ObjectType='RT::Ticket' and Tickets.Status in ('new','open','resolved') and Transactions.Type in ('Create','Status','Correspond','Comment','Set','AddLink') and Transactions.id between 461841 and 463246 order by Transactions.id";
		myErrorLog("############## Fetching transactions related to Ticket: $ticketId ##############");
		// print("############## Fetching transactions related to Ticket: $ticketId ##############\n");
		myErrorLog("## Fetch SQL: $sql");
		$lastTransaction=0;
		$result1=mysqli_query($this->connection,$sql);
		while($transaction=mysqli_fetch_assoc($result1)){
			// print("  ############# Transaction ({$transaction['Type']}) related to Ticket $ticketId #############\n");
			$txStatus = ($transaction['id'] == $lastTransaction && $GLOBALS['opt']['dedup'] ) ? ' DEDUPED' : '' ;
			$blurb  =  $transaction['Type'];
			$blurb .= ($transaction['Type'] == 'Set') ? ' ' . $transaction['Field'] : '';
			myErrorLog("##-------- Transaction {$transaction['id']} ({$transaction['Type']}) related to Ticket $ticketId$txStatus --------##");

			# short circuit this if are trying to duplicate a transaction id, this causes complaints that the article id already exists
			if ($transaction['id'] == $lastTransaction && $GLOBALS['opt']['dedup'] ) continue;
			$lastTransaction = $transaction['id'];

			$created=$transaction['Created'];
			// $ticket_number="9" . str_pad($transaction['TicketId'],5,'0',STR_PAD_LEFT);
			$ticket_number=$this->getDestination($transaction['TicketId']);
			$subject=$transaction['Subject'];

			// This was hardwired by original coder and is VERY site specific
			if($transaction['Queue']==10){
				$queue="Software Development::Change Requests";
			} else {
				$queue="Support::Miscellaneous";
			}
			$from="";
			$to="";
			$cc="";
			$creator="guess:" . $transaction['Requestor'];
			$time=$transaction['TimeTaken'];
			$new_value = "";  // this was not being initialized, might have caused issues

			// If this is NOT a ticket creation, we want to fetch the matching zammad ticket id from the rt_zammad table
			// If multiple imports are done, then different matchups can exist in the db...  Original query grabs the first match only
			// want to revise that so that the last in the db is used...  Neither is really correct, but the original causes realy ugly
			// issues.
			if($transaction['Type']<>"Create"){
				$sql="SELECT zm_tid FROM rt_zammad WHERE rt_tid={$transaction['TicketId']} ORDER BY zm_tid DESC;";
				//myErrorLog($sql);
				$result3=mysqli_query($this->connection,$sql);
				$row=mysqli_fetch_assoc($result3);
				//myErrorLog($row['zm_tid']);

				// This is an ugly hack to avoid some php warnings during test mode
				if ( $GLOBALS['opt']['test'] ){
					dprint("Setting up fake row data because of test mode");
					$row=array('zm_tid' => 0);
				}
			}
			switch($transaction['Type']){
			    case 'Create':
			    	$action="new_ticket";
			    	break;
			    case 'Status':
			    	$action="change_status";
			    	$new_value=$transaction['NewValue'];
			    	if($new_value=="resolved"){
			    		$new_value="closed";
			    	}
			    	break;
			    case 'Correspond':
			    	$action="reply";
			    	break;
			    case 'Comment':
			    	$action="comment";
			    	break;
			    case 'Set':
				    // some values we see for "Field" are Owner,Subject
			    	$action=$transaction['Field']; //Queue TimeWorked Subject Owner
			    	$new_value=$transaction['NewValue'];
			    	if($new_value=="resolved"){
			    		$new_value="closed";
			    	}
			    	if($transaction['Field']=="Queue"){
			    		if($transaction['NewValue']==10){
			    			$new_value="GRIT";
			    		}else{
			    			$new_value="GRIT";
			    		}
			    	}
			    	break;
			    case 'AddLink':
			    	$action="merge";
			    	$link=explode('/',$transaction['NewValue']);
			    	// $new_value="9" . str_pad($link[count($link)-1],5,'0',STR_PAD_LEFT);
					$new_value=$this->getDestination($link[count($link)-1]);

			    	break;
			}

			// Gather any attachments associated with the transactions associated with this ticket
			$sql="select * from Attachments where TransactionId={$transaction['id']} order by id";
			//myErrorLog($sql);
			$result2=mysqli_query($this->connection,$sql);
			$content=array();
			$content_type=array();
			$file_name=array();
			$i=0;
			$html=false;
			while($attachment=mysqli_fetch_assoc($result2)){
				//myErrorLog("CT: " . $attachment['ContentType']);
				//myErrorLog(substr($attachment['ContentType'],0,9));
				switch(substr($attachment['ContentType'],0,9)){
				    case "multipart":
				    	if($attachment['Parent']==0){
				    		$subject=$attachment['Subject'];
				    		//myErrorLog($attachment['Headers']);
				    		$lh=explode("\n",$attachment['Headers']);
				    		foreach($lh as $line){
				    			//myErrorLog("HEADER: $line");
				    			if(substr($line,0,5)=="From:"){
				    				$from=substr($line,6);
				    				//myErrorLog($from);
				    			}
				    			if(substr($line,0,3)=="To:"){
				    				$to=substr($line,4);
				    				//myErrorLog($to);
				    			}
				    			if(substr($line,0,3)=="CC:"){
				    				$cc=substr($line,4);
				    				//myErrorLog($cc);
				    			}
				    		}
				    	}
				    	break;
					case "text/plain":
					case "text/plai":
				    case "text/html":
				    	if($attachment['ContentType']=="text/html"){
				    		$html=true;
				    		//myErrorLog('HTML');
				    	}
				    	$content[$i]=$attachment['Content'];
				    	$content_type[$i]=$attachment['ContentType'];
				    	break;
				    default:
				    	if(! is_null($attachment['Filename'])){
				    		$file_name[$i]=$attachment['Filename'];
				    		$content[$i]=$attachment['Content'];
				    		$content_type[$i]=$attachment['ContentType'];
				    	}
				}
                $i++;
            }

			// Prepare data for curl command to zammad api
			$data=array();
			$url="";
			$jdata="";
			$curl_action="POST";  // default action
			switch($action){
				case "new_ticket":
				    	$url="tickets";
				    	if(is_null($subject) or $subject==""){
				    		$subject="Support Request";
				    	}
				    	myErrorLog("SUBJECT:|$subject|");
				    	$data['title']=addslashes($subject);
				    	$data['group']="GRIT";
				    	$data['customer_id']=$creator;
				    	$data['number']=$ticket_number;
				    	$data['queue']=$queue;
						$data['created_at']="$created";
						$data['updated_at']="$created";  // testing to see if this gets picked up
						$data['last_contact_at']="$created";  // testing to see if this gets picked up
						$data['last_contact_agent_at']="$created";  // testing to see if this gets picked up
						// these seem extraneous and potentially robbed some tickets of a timestamp
				    	// $article=array();
						// $article['created_at']="$created";
				    	foreach($content_type as $key => $ct){
				    		//myErrorLog($ct);
				    		switch(substr($ct,0,9)){
				    		// case "multipart":
				    		// 	break;
							case "text/plai":
				    		case "text/html":
				    			if(($html and $ct=="text/html") or !$html){
				    				$data['article']['subject']=$subject;
				    				$data['article']['from']=$from;
				    				$data['article']['to']=$to;
				    				$data['article']['cc']=$cc;
				    				$data['article']['content_type']=$ct;
				    				$data['article']['type']="email";
				    				$data['article']['internal']=false;
									$data['article']['created_at'] = $created;  // created may not be supported in ticket portion, but needed here
									$data['article']['updated_at'] = $created;  // updated may not be supported in ticket portion, but needed here
				    				$data['article']['body']=$content[$key];
				    			}
				    			break;
				    		default:
							    myErrorLog("CONTENT_TYPE for new ticket transaction: $ticket_number, content_type: $content_type");
				    			if(! is_null($file_name[$key])){
				    				if(! isset($data['article']['attachments'])){
				    					$data['article']['attachments']=array();
				    				}
				    				$att=array();
				    				$att['filename']=$file_name[$key];
				    				$att['mime-type']=$ct;
				    				$att['data']=base64_encode($content[$key]);
				    				array_push($data['article']['attachments'],$att);
				    			}
				    			break;
				    		}
				    	}
				    	if(! isset($data['article'])){
				    		$data['article']['subject']=$subject;
				    		$data['article']['type']="note";
				    		$data['article']['internal']=false;
				    		$data['article']['body']=$subject;
				    	}
				    	$jdata=json_encode($data);
				    	//var_dump($data);
				    	break;
				case "change_status":
				    	$curl_action="PUT";
				    	$url="tickets/{$row['zm_tid']}";
				    	$data['id']=$row['zm_tid'];
				    	$data['state']="$new_value";
						$data['created_at']="$created";
						$data['updated_at']="$created";  // testing to see if this gets picked up
						$data['last_contact_at']="$created";  // testing to see if this gets picked up
						$data['last_contact_agent_at']="$created";  // testing to see if this gets picked up
				    	$jdata=json_encode($data);
				    	break;
				case "reply":
				    	$url="ticket_articles";
				    	$article=array();
						$article['created_at']="$created";
						$article['updated_at']="$created";  // testing to see if this gets picked up
				    	foreach($content_type as $key => $ct){
				    		switch(substr($ct,0,9)){
				    		case "multipart":
				    			break;
							case "text/plai":
				    		case "text/html":
				    			if(($html and $ct=="text/html") or !$html){
				    				$article['ticket_id']=$row['zm_tid'];
				    				$article['subject']=$subject;
				    				$article['from']=$from;
				    				$article['to']=$to;
				    				$article['cc']=$cc;
				    				$article['body']=$content[$key];
				    				$article['content_type']=$ct;
				    				$article['type']="email";
				    				$article['internal']=false;
				    				$article['time_unit']=$transaction['TimeTaken'];
				    			}
				    			break;
				    		default:
				    			if(! is_null($file_name[$key])){
				    				if(! isset($article['attachments'])){
				    					$article['attachments']=array();
				    				}
				    				$att=array();
				    				$att['filename']=$file_name[$key];
				    				$att['mime-type']=$ct;
				    				$att['data']=base64_encode($content[$key]);
				    				array_push($article['attachments'],$att);
				    			}
				    			break;
				    		}
				    	}
				    	$jdata=json_encode($article);
				    	break;
				case "comment":
				    	$url="ticket_articles";
				    	$article=array();
						$article['created_at']="$created";
						$article['updated_at']="$created";  // testing to see if this gets picked up
				    	foreach($content_type as $key => $ct){
				    		switch(substr($ct,0,9)){
								case "multipart":
								    break;
								case "text/plai":
								case "text/html":
								    if(($html and $ct=="text/html") or !$html){
									    $article['ticket_id']=$row['zm_tid'];
									    $article['subject']=$subject;
									    $article['body']=$content[$key];
									    $article['content_type']=$ct;
									    $article['type']="note";
									    $article['internal']=true;
									    $article['time_unit']=$transaction['TimeTaken'];
								    }
								    break;
								default:
								    if(! is_null($file_name[$key])){
								    	if(! isset($article['attachments'])){
								    		$article['attachments']=array();
								    	}
								    	if(! isset($article['ticket_id'])){
								    		$article['ticket_id']=$row['zm_tid'];
								    		$article['subject']=$file_name[$key];
								    		$article['body']="";
								    		$article['content_type']=$ct;
								    		$article['type']="note";
								    		$article['internal']=true;
								    		$article['time_unit']=$transaction['TimeTaken'];
								    	}
								    	$att=array();
								    	$att['filename']=$file_name[$key];
								    	$att['mime-type']=$ct;
								    	$att['data']=base64_encode($content[$key]);
								    	array_push($article['attachments'],$att);
								    }
								    break;
				    		}
				    	}
				    	$jdata=json_encode($article);
				    	break;
				case "TimeWorked":
				    	$url="ticket_articles";
				    	$article=array();
				    	$article['ticket_id']=$row['zm_tid'];
				    	$article['subject']="Time Worked";
				    	$article['body']="";
				    	$article['type']="note";
				    	$article['internal']=true;
				    	$article['time_unit']=$transaction['TimeTaken'];
						$article['created_at']="$created";
						$article['updated_at']="$created";  // testing to see if this gets picked up
				    	$jdata=json_encode($article);
				    	break;
				case "Subject":
				    	$curl_action="PUT";
				    	$url="tickets/{$row['zm_tid']}";
				    	$data=array();
				    	$data['id']=$row['zm_tid'];
				    	$data['title']=$new_value;
						$data['created_at']="$created";
						$data['updated_at']="$created";  // testing to see if this gets picked up
				    	$jdata=json_encode($data);
				    	break;
				case "Owner":
				    	$url="tickets/{$row['zm_tid']}";
				    	$curl_action="PUT";
				    	$data=array();
				    	$data['id']=$row['zm_tid'];
				    	$data['owner_id']=$GLOBALS['config']['owner_id'][$new_value];
						$data['created_at']="$created";
						$data['updated_at']="$created";  // testing to see if this gets picked up
				    	$jdata=json_encode($data);
				    	break;
				case "Queue":
				    	$url="tickets/{$row['zm_tid']}";
				    	$curl_action="PUT";
				    	$data=array();
				    	$data['id']=$row['zm_tid'];
				    	$data['queue']=$new_value;
						$data['created_at']="$created";
						$data['updated_at']="$created";  // testing to see if this gets picked up
				    	$jdata=json_encode($data);
				    	break;
				case "merge":
				        // this attempts to merge tickets during creation
						// ostensibly, this should fail if merging into a ticket that the script has not seen yet
				    	$curl_action="GET";
				    	$url="ticket_merge/{$row['zm_tid']}/$new_value";
				    	//$data=array();
				    	//$data['id']=$row['zm_tid'];
				    	//$data['number']=$new_value;
				    	//$jdata=json_encode($data);
				    	$jdata="";
				    	break;
			}

			// make a copy of the data and null out content
			$logcopy = (count($data) > 3) ? $data : $article;
			if (isset($logcopy['body']))            $logcopy['body'] = "BODY-REPLACED";
			if (isset($logcopy['article']['body'])) $logcopy['article']['body'] = "BODY_REPLACED";
			$jlog = json_encode($logcopy);
			//myErrorLog($jdata);
				//execute transaction
				//exec("/usr/bin/timedatectl set-time '$created'");
				//$base_url="http://localhost:9200/api/v1";
			// $base_url=$GLOBALS['config']['base_url'];
			$hmrc=curl_init();
			$headers=array();
			$options=array();
			$options[CURLOPT_URL]=$GLOBALS['config']['base_url'] . "/$url";
			dprint("CURLOPT_URL: " . $options[CURLOPT_URL]);
			$options[CURLOPT_POST]=true;
			$options[CURLOPT_RETURNTRANSFER]=true;
			$options[CURLOPT_VERBOSE]=true;
			$options[CURLOPT_HEADER]=false;
			$options[CURLOPT_USERPWD]=$GLOBALS['config']['curlopt_userpwd'];
			$headers[]="Content-Type: application/json";
			//myErrorLog($options[CURLOPT_URL]);
			myErrorLog("JSON DATA (-body) [$action]: ". $jlog);
			$options[CURLOPT_POSTFIELDS]=$jdata;
			$options[CURLOPT_HTTPHEADER]=$headers;
			$options[CURLOPT_CUSTOMREQUEST]=$curl_action;

			if ($GLOBALS['opt']['test']){
				print("# TEST MODE: would otherwise run curl commands to zammad api:" . $options[CURLOPT_URL] . "\n");
			}
			else {
				// prep and execute the curl command
				curl_setopt_array($hmrc,$options);
				$curldata=curl_exec($hmrc);

				if(! $curldata){
					echo "\nError: " . curl_error($hmrc) . "\n";
					myErrorLog(curl_error($hmrc));
				}
				elseif( isset($curldata['id']) && is_null($curldata['id'])){
					echo "\nError (id is null)\n";
				}
				else {
					//save IDs
					//echo "\n\n$curldata\n\n";
					$res=json_decode($curldata,true);

                    // build an json string WITHOUT the body field for log output
					$rescp = $res;
					if (isset($rescp['body'])) $rescp['body'] = "BODY-REPLACED";
					if (isset($rescp['article']['body'])) $rescp['article']['body'] = "BODY-REPLACED";
					$jcurldata = json_encode($rescp);
					myErrorLog("CURL RESULT (-body): $jcurldata");

					if(! isset($res['error'])){
						if($action=="new_ticket"){
							// the zammad id here is NOT the ticket number but the id of the ticket in the db (assuming)
							echo "Old ID: {$transaction['TicketId']}\nNew ID: {$res['id']} ({$res['number']})\n\n";
							$zm_tid=$res['id'];
							$sql="INSERT INTO rt_zammad VALUES({$transaction['TicketId']},$zm_tid)";
							dprint("rt_zammad insert sql: $sql");
							$save=mysqli_query($this->connection,$sql);
							if(! $save){
								myErrorLog("Error saving to rt_zammad");
								myErrorLog("$sql");
							}
						}

						/* commented code for trying to get date set
						// This section was commented out in original code
						if($url=="ticket_articles"){
							$sql="update ticket_articles set created_at='$created',updated_at='$created' where id={$res['id']}";
							$zm=mysqli_query($zammad,$sql);
						}
						if($url=="tickets"){
							$sql="update tickets set created_at='$created',updated_at='$created' where id={$res['id']}";
							$zm=mysqli_query($zammad,$sql);
							if($new_value=="closed"){
								$sql="update tickets set close_at='$resolved',updated_at='$created' where id={$res['id']}";
								$zm=mysqli_query($zammad,$sql);
							}
						}
						if(substr($url,0,8)=="tickets/"){
							$sql="update tickets set updated_at='$created' where id={$res['id']}";
							$zm=mysqli_query($zammad,$sql);
							if($new_value=="closed"){
								$sql="update tickets set close_at='$resolved',updated_at='$created' where id={$res['id']}";
								$zm=mysqli_query($zammad,$sql);
							}
						}
						*/
					}
					else{
						myErrorLog("!!!! CURL ERROR \n\n{$res['error']}\n\n$jlog\n\n");
					}
				}
				curl_close($hmrc);
			}
		}
	}
	function assignCustomerLoop(){
		foreach($this->ticketIdClauses as $ticketIdClause){
			dprint("Running select using the following ticketClause: $ticketIdClause");
			$sql = "SELECT Tickets.Id AS rt_tid,Tickets.Queue AS rt_tqueue,Tickets.Status AS rt_tstatus from Tickets WHERE ( Tickets.Status IN ('new','open','resolved') {$ticketIdClause} ) ORDER by Tickets.Id;";
			dprint("Running: $sql");
			$result=mysqli_query($this->connection,$sql);
			while($ticket=mysqli_fetch_assoc($result)){
				dprint("TicketId: " . $ticket['rt_tid'] . ", TicketStatus: " . $ticket['rt_tstatus'] . ", TicketQueue: " . $ticket['rt_tqueue']);
				$this->assignCustomerToTicket($ticket['rt_tid']);
			}
		}
	}
	////////////////////////////////////////////////////////////////////////////
	// This will go through the assignment of the RT Requestor to the corresponding
	// User in the zammad db
	// This requires having a zammad db with a fully stocked "users" table.
	// I think if rt users have been imported into zammad prior to ticket creation that
	// this is not needed.
	////////////////////////////////////////////////////////////////////////////
	function assignCustomerToTicket($ticketId){
		// mysqli_set_charset($connection,'utf-8');
		$sql="SELECT Tickets.id,if(isnull(Users.EmailAddress),Users.Name,Users.EmailAddress) as Requestor,zm_tid,users.id as user_id from Tickets
		    LEFT JOIN Groups on Tickets.id=Groups.Instance and Groups.Domain='RT::Ticket-Role' and Groups.Name='Requestor'
		    LEFT JOIN GroupMembers on Groups.id=GroupMembers.GroupId
		    LEFT JOIN Users on GroupMembers.MemberId=Users.id
		    LEFT JOIN rt_zammad on Tickets.id=rt_zammad.rt_tid
		    LEFT JOIN zammad.users on Users.EmailAddress=users.email
		  where Status in('resolved','new','open') and Tickets.id = $ticketId group by Tickets.id order by Tickets.id";
		$result1=mysqli_query($this->connection,$sql);
		while($transaction=mysqli_fetch_assoc($result1)){
			if(is_null($transaction['Requestor'])) continue;  // short circuit whole process if Requestor is null

			$data=array();
			$url="";
			$jdata="";
			$zm_tid=$transaction['zm_tid'];
			$user_id=$transaction['user_id'];
			$requestor="guess:" . $transaction['Requestor'];
			$curl_action="PUT";
			$url="tickets/$zm_tid";
			$data['id']=$zm_tid;
			$data['customer_id']=$user_id;
			$jdata=json_encode($data);

			dprint("zm_tid: $zm_tid, user_id: $user_id, ");

			if ($GLOBALS['opt']['test']) continue;
			//exec("/usr/bin/timedatectl set-time '$created'");
			// $base_url="http://zammad.geog.ucsb.edu/api/v1";
			$hmrc=curl_init();
			$headers=array();
			$options=array();
			// $options[CURLOPT_URL]="$base_url/$url";
			$options[CURLOPT_URL]= $GLOBALS['config']['base_url'] . "/$url";
			$options[CURLOPT_POST]=true;
			$options[CURLOPT_RETURNTRANSFER]=true;
			$options[CURLOPT_VERBOSE]=true;
			$options[CURLOPT_HEADER]=false;
			// $options[CURLOPT_USERPWD]="fgaspar@nixe.co.uk:*******";
			$options[CURLOPT_USERPWD]=$GLOBALS['config']['curlopt_userpwd'];
			$headers[]="Content-Type: application/json";
			myErrorLog($jdata);
			$options[CURLOPT_POSTFIELDS]=$jdata;
			$options[CURLOPT_HTTPHEADER]=$headers;
			$options[CURLOPT_CUSTOMREQUEST]=$curl_action;
			curl_setopt_array($hmrc,$options);

			$data=curl_exec($hmrc);
			if(! $data){
				echo "\nError: " . curl_error($hmrc) . "\n";
				myErrorLog(curl_error($hmrc));
			}else{
				$res=json_decode($data,true);
				myErrorLog("RESULT: $data");
			}
			curl_close($hmrc);
			// }
		}
	}
	////////////////////////////////////////////////////////////////////////////
	// function rt_users(){
	// 	$types = array('ALL','NULL','OK','FAIL','FILT','PARTIAL','CTYPE');
	// 	$cntr = array();
	// 	$sql="SELECT id,Name,RealName,NickName,Organization,EmailAddress from Users WHERE Name not like '%@qq.com' AND Name not like '%@lists.jobson.com' AND Name not like '%@sendgrid.net';";
	// 	dprint("RT-Users SQL: $sql");
	// 	$result=mysqli_query($this->connection,$sql);
	// 	foreach($types as $type)  $cntr[$type]=0;
	// 	while($user=mysqli_fetch_assoc($result)){
	// 		$cntr['ALL']++;
	// 		$clean='';
	// 		if (is_null($user['RealName'])){
	// 		    $type="NULL";
	// 		}
	// 		else {
	// 			// three (maybe more?) situations Here: all bad chars, some bad chars, no bad chars
	// 			// the clean setup just replaces bad chars with good, so
	// 			$clean = cleanNonAsciiCharactersInString($user['RealName']);  // this does a substitution
	// 			if     (strlen($clean) == 0)                         $type = 'FAIL';
	// 			elseif (!ctype_print($clean))                        $type = 'CTYPE';
	// 			elseif (strlen($clean) == strlen($user['RealName'])) $type = 'OK';
	// 			else                                                 $type = 'PARTIAL';
	// 			// $type = (ctype_print($user['RealName'])) ? "OK" : "FAIL";
	// 		}
    //         // do some filtering?
	// 		if ( $type == 'OK'){
	// 			if( preg_match('/\?\?/',$user['RealName'])) $type = 'FILT';
	// 			if( preg_match('/FUCK EXPRESS/',$user['RealName'])) $type = 'FILT';
	// 			if( preg_match('/Constipation/',$user['RealName'])) $type = 'FILT';
	// 			if( preg_match('/webmaster@*ucsb.edu/',$user['RealName'])) $type = 'FILT';
	// 			if( preg_match('/Reverse Mortgage/',$user['RealName'])) $type = 'FILT';
	// 			if( preg_match('/Medigap.com/',$user['RealName'])) $type = 'FILT';
	// 			if( preg_match('/@ucsbcollabsupport.zendesk.com/',$user['Name'])) $type = 'FILT';
	// 			if( preg_match('/XXX/',$user['RealName'])) $type = 'FILT';
	//
	// 		}
	//
	//
    //         $cntr[$type]++;
	//
	// 		$str = sprintf("%-40s %-40s %-40s %-4s",$user['Name'],$user['RealName'],$clean,$type);
	// 		dprint("$str");
	// 	}
	// 	foreach($types as $type){
	// 		dprint("Type $type found: " . $cntr[$type]);
	// 	}
	//
	// }
	////////////////////////////////////////////////////////////////////////////
	function rt_users_new(){
		$cntrFields = array('ALL','UNIQ');
		$uniqRequestors = array();
		$sql="SELECT Tickets.id,Tickets.Status,if(isnull(Users.EmailAddress),Users.Name,Users.EmailAddress) as Requestor,Users.RealName as fullname,Users.Name as username,Users.EmailAddress as email,zm_tid,users.id as user_id from Tickets
			LEFT JOIN Groups on Tickets.id=Groups.Instance AND Groups.Domain='RT::Ticket-Role' AND Groups.Name='Requestor'
			LEFT JOIN GroupMembers on Groups.id=GroupMembers.GroupId
			LEFT JOIN Users on GroupMembers.MemberId=Users.id
			LEFT JOIN rt_zammad on Tickets.id=rt_zammad.rt_tid
			-- LEFT JOIN zammad.users on Users.EmailAddress=users.email
		    where Status IN('resolved','new','open') GROUP BY Tickets.id ORDER BY Tickets.id";
		$result1=mysqli_query($this->connection,$sql);
		$cntr=array();
		foreach($cntrFields as $field) $cntr[$field] = 0;
		while($ticket=mysqli_fetch_assoc($result1)){
			$cntr['ALL']++;
			// dprint("Ticket ID: {$ticket['id']} ({$ticket['Status']}), Requestor: {$ticket['Requestor']} ({$ticket['user_id']}), Fullname: {$ticket['fullname']}");

			$n = new userMigrate($ticket);
			// $uniqRequestors[$ticket['Requestor']] = $ticket['user_id'];
			$uniqRequestors[$ticket['Requestor']] = $n;
		}

		dprint("Unique Entries Follow");
		// foreach($uniqRequestors as $email => $uid){
		userMigrate::csvHeader();
		foreach($uniqRequestors as $obj){
			// if ($obj->status == '')  $obj->csv();
			$obj->csv();
			// print("$uid,$email\n");
		}

		$cntr['UNIQ'] = count($uniqRequestors);
		foreach($cntrFields as $field) dprint("$field count: {$cntr[$field]}");
		dprint("Overall (all ticket entries) Status Stats follow:");
		foreach(userMigrate::$statusStats as $k => $count) dprint("$k: $count");
		$str = sprintf("%0x",ord('('));
		dprint("str: $str");
		// For export/import, looks like we want the following fields
		// the field keys are the field names we need, the values are default values or null

	}
	////////////////////////////////////////////////////////////////////////////
	function z_users(){
		$objs = array();
		$page=1;
		$per_page=100;
		do {
			$newobjs = $this->curl_GET("users?per_page=$per_page&page=$page");
			$page++;
			$objs = array_merge($objs,$newobjs);
		} while (count($newobjs) > 0);

		foreach($objs as $e){
			print("id: {$e['id']}, login: {$e['login']}, email: {$e['email']}\n");
		}
		//$objs = $this->curl_GET('users');
	}
	////////////////////////////////////////////////////////////////////////////
    function getOffsetForTickets(){
		$sql = "SELECT Id FROM Tickets ORDER BY Id DESC LIMIT 1;";
		$res = mysqli_query($this->connection,$sql);
		$ids = mysqli_fetch_assoc($res);
		$maxId = $ids['Id'];
		$offset = $GLOBALS['opt']['prefix'] * pow(10,strlen((string) $maxId));
		print("MaxId: $maxId, Offset: $offset\n");
		return $offset;
	}
}
////////////////////////////////////////////////////////////////////////////////
// One of these will be instantiated for each row pulled
////////////////////////////////////////////////////////////////////////////////
class userMigrate {
	// should potentially have the fields (and order) as some sort of static class property???
	// dont like duplicating these, but
    public static $fields = array(
		// 'id'            => null,
		'login'         => null,
		'firstname'     => null,
		'lastname'      => null,
		'email'         => null,
		'vip'           => 'FALSE',
		'verified'      => 'FALSE',
		'active'        => 'TRUE',
		'out_of_office' => 'FALSE',
		'roles'         => 'Customer'
	);
	public static $statusStats = array();
	public static function csvHeader(){
		$vals = array();
		foreach(self::$fields as $k => $defval) $vals[] = $k;
		print(implode(",",$vals) . "\n");
	}
	// pass in rtdata from mysql query
	function __construct($ticket){
		$this->status = '';
		// set default data right off the bat
        $this->initData();
		dprint("Ticket ID: {$ticket['id']} ({$ticket['Status']}), Requestor: {$ticket['Requestor']} ({$ticket['user_id']}), Fullname: {$ticket['fullname']}");
		$this->data['id'] = $ticket['user_id'];
		$this->data['email'] = $ticket['email'];
		$this->data['login'] = $ticket['Requestor'];

        $this->deriveNameData($ticket['fullname']);
		$this->fullname = $ticket['fullname'];
	}
	function deriveNameData($fullname){
		$match = array();
		// if we have a single space, then just split into firstname lastname
		if (is_null($fullname)){
			// Dont actually do anything here, but there is no further processing to be done...
			$this->status = "NULL";
		}
		else {
			// chop off any trailing description following a " - "
			if (preg_match('/^([^-]*) - .*$/',$fullname,$m)){
				$fullname = $m[1];
			}
			if (preg_match('/^(.*) [^ ]*423.*$/',$fullname,$m)){
				$fullname = $m[1];
			}
			if (preg_match('/^([a-zA-Z-]*) ([a-zA-Z\'-]*)$/',$fullname,$m)){
				$this->data['firstname'] = $m[1];
				$this->data['lastname'] = $m[2];
				$this->status = "SIMPLE";
			}
			if (preg_match('/^([a-zA-Z]*) [A-Z]\. ([a-zA-Z\'-]*)$/',$fullname,$m)){
				$this->data['firstname'] = $m[1];
				$this->data['lastname'] = $m[2];
				$this->status = "INITIAL";
			}
			elseif(preg_match('/^([a-zA-Z\'-]*), ([a-zA-Z]*)$/',$fullname,$m)){
				$this->data['firstname'] = $m[2];
				$this->data['lastname'] = $m[1];
				$this->status = "REVERSE2";
			}
			elseif(preg_match('/^([a-zA-Z\'-]*), ([a-zA-Z]*) ([a-zA-Z]*)$/',$fullname,$m)){
				$this->data['firstname'] = $m[2];
				$this->data['lastname'] = $m[1];
				$this->status = "REVERSE3";
			}
			elseif(preg_match('/^([a-zA-Z]*) ([a-zA-Z]*) ([a-zA-Z]*)$/',$fullname,$m)){
				$this->data['firstname'] = $m[1];
				$this->data['lastname'] = $m[2];
				$this->status = "MIDDLENAME";
			}
			if (preg_match('/^([a-zA-Z]*) ([Dd]e [Ll][ae][a-z]* [a-zA-Z\'-]*)$/',$fullname,$m)){
				$this->data['firstname'] = $m[1];
				$this->data['lastname'] = $m[2];
				$this->status = "DELA";
			}
			if (preg_match('/^([a-zA-Z]*) ([Vv]an [Dd]en [a-zA-Z\'-]*)$/',$fullname,$m)){
				$this->data['firstname'] = $m[1];
				$this->data['lastname'] = $m[2];
				$this->status = "VANDE";
			}
			if (preg_match('/^([a-zA-Z]*) ([Dd]e [a-zA-Z\'-]*)$/',$fullname,$m)){
				$this->data['firstname'] = $m[1];
				$this->data['lastname'] = $m[2];
				$this->status = "DE";
			}
		}
		if(isset(self::$statusStats[$this->status])) self::$statusStats[$this->status]++;
		else self::$statusStats[$this->status]=0;

	}
	function initData(){
		$this->data = array();
		foreach(self::$fields as $k => $default){
			$this->data[$k] = $default;
		}
	}
	function print(){
		dprint("{$this->data['id']},{$this->data['email']},$this->fullname,{$this->data['firstname']},{$this->data['lastname']},{$this->status}");
	}
	function csv(){
		$vals = array();
		foreach(self::$fields as $k => $default){
			if (isset($this->data[$k]) && ! is_null($this->data[$k])) $vals[] = (preg_match('/,/',$this->data[$k])) ? "\"{$this->data[$k]}\"" : "{$this->data[$k]}";
			else $vals[] = "";
		}
		$str = implode(",",$vals);
		print("$str\n");
	}
}
////////////////////////////////////////////////////////////////////////////////
function myErrorLog($string){
	error_log($string . "\n",$GLOBALS['opt']['logtype'],$GLOBALS['opt']['logfile']);
}
////////////////////////////////////////////////////////////////////////////////
function dprint($str){
	if ($GLOBALS['opt']['debug']) print("# DEBUG: $str\n");
}
////////////////////////////////////////////////////////////////////////////////
function cleanNonAsciiCharactersInString($orig_text) {
	// interesting thread on this topic
    // https://stackoverflow.com/questions/8781911/remove-non-ascii-characters-from-string
    $text = $orig_text;

    // Single letters
    $text = preg_replace("/[∂άαáàâãªä]/u",      "a", $text);
    $text = preg_replace("/[∆лДΛдАÁÀÂÃÄ]/u",     "A", $text);
    $text = preg_replace("/[ЂЪЬБъь]/u",           "b", $text);
    $text = preg_replace("/[βвВ]/u",            "B", $text);
    $text = preg_replace("/[çς©с]/u",            "c", $text);
    $text = preg_replace("/[ÇС]/u",              "C", $text);
    $text = preg_replace("/[δ]/u",             "d", $text);
    $text = preg_replace("/[éèêëέëèεе℮ёєэЭ]/u", "e", $text);
    $text = preg_replace("/[ÉÈÊË€ξЄ€Е∑]/u",     "E", $text);
    $text = preg_replace("/[₣]/u",               "F", $text);
    $text = preg_replace("/[НнЊњ]/u",           "H", $text);
    $text = preg_replace("/[ђћЋ]/u",            "h", $text);
    $text = preg_replace("/[ÍÌÎÏ]/u",           "I", $text);
    $text = preg_replace("/[íìîïιίϊі]/u",       "i", $text);
    $text = preg_replace("/[Јј]/u",             "j", $text);
    $text = preg_replace("/[ΚЌК]/u",            'K', $text);
    $text = preg_replace("/[ќк]/u",             'k', $text);
    $text = preg_replace("/[ℓ∟]/u",             'l', $text);
    $text = preg_replace("/[Мм]/u",             "M", $text);
    $text = preg_replace("/[ñηήηπⁿ]/u",            "n", $text);
    $text = preg_replace("/[Ñ∏пПИЙийΝЛ]/u",       "N", $text);
    $text = preg_replace("/[óòôõºöοФσόо]/u", "o", $text);
    $text = preg_replace("/[ÓÒÔÕÖθΩθОΩ]/u",     "O", $text);
    $text = preg_replace("/[ρφрРф]/u",          "p", $text);
    $text = preg_replace("/[®яЯ]/u",              "R", $text);
    $text = preg_replace("/[ГЃгѓ]/u",              "r", $text);
    $text = preg_replace("/[Ѕ]/u",              "S", $text);
    $text = preg_replace("/[ѕ]/u",              "s", $text);
    $text = preg_replace("/[Тт]/u",              "T", $text);
    $text = preg_replace("/[τ†‡]/u",              "t", $text);
    $text = preg_replace("/[úùûüџμΰµυϋύ]/u",     "u", $text);
    $text = preg_replace("/[√]/u",               "v", $text);
    $text = preg_replace("/[ÚÙÛÜЏЦц]/u",         "U", $text);
    $text = preg_replace("/[Ψψωώẅẃẁщш]/u",      "w", $text);
    $text = preg_replace("/[ẀẄẂШЩ]/u",          "W", $text);
    $text = preg_replace("/[ΧχЖХж]/u",          "x", $text);
    $text = preg_replace("/[ỲΫ¥]/u",           "Y", $text);
    $text = preg_replace("/[ỳγўЎУуч]/u",       "y", $text);
    $text = preg_replace("/[ζ]/u",              "Z", $text);

    // Punctuation
    $text = preg_replace("/[‚‚]/u", ",", $text);
    $text = preg_replace("/[`‛′’‘]/u", "'", $text);
    $text = preg_replace("/[″“”«»„]/u", '"', $text);
    $text = preg_replace("/[—–―−–‾⌐─↔→←]/u", '-', $text);
    $text = preg_replace("/[  ]/u", ' ', $text);

    $text = str_replace("…", "...", $text);
    $text = str_replace("≠", "!=", $text);
    $text = str_replace("≤", "<=", $text);
    $text = str_replace("≥", ">=", $text);
    $text = preg_replace("/[‗≈≡]/u", "=", $text);


    // Exciting combinations
    $text = str_replace("ыЫ", "bl", $text);
    $text = str_replace("℅", "c/o", $text);
    $text = str_replace("₧", "Pts", $text);
    $text = str_replace("™", "tm", $text);
    $text = str_replace("№", "No", $text);
    $text = str_replace("Ч", "4", $text);
    $text = str_replace("‰", "%", $text);
    $text = preg_replace("/[∙•]/u", "*", $text);
    $text = str_replace("‹", "<", $text);
    $text = str_replace("›", ">", $text);
    $text = str_replace("‼", "!!", $text);
    $text = str_replace("⁄", "/", $text);
    $text = str_replace("∕", "/", $text);
    $text = str_replace("⅞", "7/8", $text);
    $text = str_replace("⅝", "5/8", $text);
    $text = str_replace("⅜", "3/8", $text);
    $text = str_replace("⅛", "1/8", $text);
    $text = preg_replace("/[‰]/u", "%", $text);
    $text = preg_replace("/[Љљ]/u", "Ab", $text);
    $text = preg_replace("/[Юю]/u", "IO", $text);
    $text = preg_replace("/[ﬁﬂ]/u", "fi", $text);
    $text = preg_replace("/[зЗ]/u", "3", $text);
    $text = str_replace("£", "(pounds)", $text);
    $text = str_replace("₤", "(lira)", $text);
    $text = preg_replace("/[‰]/u", "%", $text);
    $text = preg_replace("/[↨↕↓↑│]/u", "|", $text);
    $text = preg_replace("/[∞∩∫⌂⌠⌡]/u", "", $text);


    //2) Translation CP1252.
    $trans = get_html_translation_table(HTML_ENTITIES);
    $trans['f'] = '&fnof;';    // Latin Small Letter F With Hook
    $trans['-'] = array(
        '&hellip;',     // Horizontal Ellipsis
        '&tilde;',      // Small Tilde
        '&ndash;'       // Dash
        );
    $trans["+"] = '&dagger;';    // Dagger
    $trans['#'] = '&Dagger;';    // Double Dagger
    $trans['M'] = '&permil;';    // Per Mille Sign
    $trans['S'] = '&Scaron;';    // Latin Capital Letter S With Caron
    $trans['OE'] = '&OElig;';    // Latin Capital Ligature OE
    $trans["'"] = array(
        '&lsquo;',  // Left Single Quotation Mark
        '&rsquo;',  // Right Single Quotation Mark
        '&rsaquo;', // Single Right-Pointing Angle Quotation Mark
        '&sbquo;',  // Single Low-9 Quotation Mark
        '&circ;',   // Modifier Letter Circumflex Accent
        '&lsaquo;'  // Single Left-Pointing Angle Quotation Mark
        );

    $trans['"'] = array(
        '&ldquo;',  // Left Double Quotation Mark
        '&rdquo;',  // Right Double Quotation Mark
        '&bdquo;',  // Double Low-9 Quotation Mark
        );

    $trans['*'] = '&bull;';    // Bullet
    $trans['n'] = '&ndash;';    // En Dash
    $trans['m'] = '&mdash;';    // Em Dash
    $trans['tm'] = '&trade;';    // Trade Mark Sign
    $trans['s'] = '&scaron;';    // Latin Small Letter S With Caron
    $trans['oe'] = '&oelig;';    // Latin Small Ligature OE
    $trans['Y'] = '&Yuml;';    // Latin Capital Letter Y With Diaeresis
    $trans['euro'] = '&euro;';    // euro currency symbol
    ksort($trans);

    foreach ($trans as $k => $v) {
        $text = str_replace($v, $k, $text);
    }

    // 3) remove <p>, <br/> ...
    $text = strip_tags($text);

    // 4) &amp; => & &quot; => '
    $text = html_entity_decode($text);


    // transliterate
    // if (function_exists('iconv')) {
    // $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    // }

    // remove non ascii characters
    // $text =  preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $text);

    return $text;
}

////////////////////////////////////////////////////////////////////////////////
function myHelp(){
	print("EXAMPLE:\n
	php ./rt2zammad.php --log=./log-20220301-00.log --prefix=9 --drop --dedup --tickets='<20000' create-tickets
	php ./rt2zammad.php --prefix=2 --log=./log-$(date +'%Y%m%d-%H%M')-02.log --debug --dedup --tickets=18921,18920,11491,11492,11579 --drop create-tickets

	legend:
	--drop    -- drops and recreates the rt_zammad table - important to do between runs of create-tickets where tickets overlap
	--dedup   -- due to a join in the transaction query transactions are processed for each requestor resulting in duplicated articles in zammad
	--prefix  -- specifies the leading number for ticket numbers, rt ticket number is padded to 5 characters with this prefixed
	--log     -- specifies the logfile name
    \n");
}
////////////////////////////////////////////////////////////////////////////////
// Main
////////////////////////////////////////////////////////////////////////////////
$GLOBALS['opt']['verbose'] = False;
$GLOBALS['opt']['debug'] = False;
$GLOBALS['opt']['test'] = False;
$GLOBALS['opt']['drop'] = False;
$GLOBALS['opt']['ticket'] = '';
$GLOBALS['opt']['offset'] = 0;
$GLOBALS['opt']['prefix'] = 0;
$GLOBALS['opt']['logtype'] = 0;
$GLOBALS['opt']['logfile'] = '';
$GLOBALS['opt']['dedup']   = False;
// Command line processing
$exe = array_shift($argv);
// while( $argfull = array_shift($argv)){
while( count($argv) > 0 && substr($argv[0],0,1) == "-" ){
    if ( strpos($argv[0],'=') ) list($arg,$val) = explode("=",$argv[0],2);
	else                        $arg=$argv[0];

    switch($arg){
        case "--verbose" :
	        $GLOBALS['opt']['verbose'] = True;
            break;
		case "--dedupe" :
		    $GLOBALS['opt']['dedup'] = True;
			break;
		case "--dedup" :
		    $GLOBALS['opt']['dedup'] = True;
			break;
        case "--debug" :
	        $GLOBALS['opt']['debug'] = True;
            break;
		case "--drop" :
		    $GLOBALS['opt']['drop'] = True;
	        break;
		case "--tickets" :
			if( isset($val)) $GLOBALS['opt']['tickets'] = $val;
			break;
		case "--test" :
			$GLOBALS['opt']['test'] = True;
			break;
		case "--prefix" :
			if( isset($val)) $GLOBALS['opt']['prefix'] = intval($val);
		    break;
		case "--log" :
		    if( isset($val)) $GLOBALS['opt']['logtype'] = 3;
		    if( isset($val)) $GLOBALS['opt']['logfile'] = $val;
			break;
        default:
            break;
    }
	array_shift($argv);
}

$subcommand = array_shift($argv);
dprint("exe: $exe, subcommand: $subcommand");
$rt2za = new rt2zammad();
if ($GLOBALS['opt']['prefix'] != 0) $GLOBALS['opt']['offset'] = $rt2za->getOffsetForTickets($GLOBALS['opt']['prefix']);
switch($subcommand){
	case "create-tickets" :
	    $rt2za->createTicketLoop();
	    break;
	case "merge-tickets" :
		$rt2za->merge_tickets();
		break;
	case "assign-customer" :
		$rt2za->assignCustomerLoop();
		break;
	case "rt-users-old" :
		$rt2za->rt_users();
		break;
	case "rt-users" :
		$rt2za->rt_users_new();
		break;
	case "z-users":
	    $rt2za->z_users();
	    break;
	case "sleep":
	    $rt2za->sleep(184);
    case "help" :
	    myHelp();
	    break;
	default:
	    dprint("Default Case - not sure what I want to do yet :-)");
	    break;
}

/**
Per thread at: https://community.zammad.org/t/importing-tickets-and-articles-using-the-api/5775
  looks like we may be able to set the time using "created_at" field for transactions/articles and ticket creation during import
  Specify 'created_at' in json and set import_mode to true in rails console...
  zammad run rails console       # interactively run the console
  In rails console we need to execute:  Settings.set('import_mode', true)

  So something like: zammad run rails r 'Setting.set('import_mode',true)'
  Assuming you would put back to false once import was done



Per thread at: https://community.zammad.org/t/reset-database-to-start-from-zero/326
  Looks like we can clear the db with the following commands executed in rails console:
  zammad run rails r 'Ticket.destroy_all'
  zammad run rails r 'OnlineNotification.destroy_all'
  zammad run rails r 'ActivityStream.destroy_all'
  zammad run rails r 'RecentView.destroy_all'
  zammad run rails r 'History.destroy_all'
**/

/**
Current Issues we are trying to deal with/sort out
  * The zammad merge api does not seem to work with the implementation expressed in the original code here (unchanged as of this writing)
      ** (this implementation does not actually make sense to me as it indicates that zammad wants two different
	  ** specifications for tickets, first is the db refid, the second is a ticketid)
  * We could potentially merge the entries during import by switching the transaction query to use EffectiveId;
      ** But that would end up grabbing multiple creates, so we would have to figure out how to skip those extras
  * We have no way to currently get email addresses assigned to the ticket into zammad via api.
      ** could potentially build db queries
  * Timestamps for the ticket creation seem to ignore the updated_at value as well as the last_contact_at and last_contact_agent_at values
      ** could possibly build sql commands to correct those
  * email address data in the zammad db is saved in TOAST extended storage
**/
?>
