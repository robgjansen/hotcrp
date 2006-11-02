<?php 
require_once('Code/header.inc');
require_once('Code/papertable.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();


// header
function confHeader() {
    global $paperId, $prow, $Conf;
    $title = ($paperId > 0 ? "Paper #$paperId Contact Authors" : "Paper Contact Authors");
    $Conf->header($title, "contactauthors", actionBar($prow, false, "Contact Authors", "contactauthors.php?paperId=$paperId"), false);
}

function errorMsgExit($msg) {
    global $Conf;
    confHeader();
    $Conf->errorMsgExit($msg);
}


// collect paper ID
maybeSearchPaperId("contactauthors.php", $Me);
$paperId = cvtint($_REQUEST["paperId"]);

// grab paper row
$prow = null;
function getProw($contactId) {
    global $prow, $paperId, $Conf, $Me;
    if (!($prow = $Conf->paperRow($paperId, $contactId, $whyNot))
	|| !$Me->canViewPaper($prow, $Conf, $whyNot))
	errorMsgExit(whyNotText($whyNot, "view"));
}
getProw($Me->contactId);


// check permissions
$notAuthor = !$Me->amPaperAuthor($paperId, $Conf);
if ($notAuthor && !$Me->amAssistant())
    errorMsgExit("You are not an author of paper #$paperId.  If you believe this is incorrect, get a registered author to list you as a coauthor, or contact the site administrator.");

function pt_data_html($what, $row) {
    global $can_update;
    if (isset($_REQUEST[$what]) && $can_update)
	return htmlspecialchars($_REQUEST[$what]);
    else
	return htmlspecialchars($row->$what);
}

function addContactAuthor($paperId, $contactId) {
    // don't want two entries for the same contact, if we can avoid it
    global $Conf;
    
    $result = $Conf->qe("lock tables PaperConflict write", "while adding contact author");
    if (DB::isError($result))
	return $result;

    $result = $Conf->qe("select author from PaperConflict where paperId=$paperId and contactId=$contactId", "while adding contact author");
    if (!DB::isError($result) && $result->numRows() > 0)
	$q = "update PaperConflict set author=1 where paperId=$paperId and contactId=$contactId";
    else
	$q = "insert into PaperConflict (paperId, contactId, author) values ($paperId, $contactId, 1)";

    $result = $Conf->qe($q, "while adding contact author");

    $Conf->qe("unlock tables");
    return $result;
}

function removeContactAuthor($paperId, $contactId) {
    global $Conf;
    return $Conf->qe("delete from PaperConflict where paperId=$paperId and contactId=$contactId", "while removing contact author");
}


confHeader();


if (!$Me->canManagePaper($prow))
    $Conf->errorMsg("You can't manage paper #$paperId since you are not a contact author.  If you believe this is incorrect, get a registered author to list you as a coauthor, or contact the site administrator.");
else if (isset($_REQUEST["update"])) {
    if (!isset($_REQUEST["email"]) || trim($_REQUEST["email"]) == "")
	$Conf->errorMsg("You must enter the new contact author's email address."); 
    else if (($id = $Conf->getContactId($_REQUEST["email"])) > 0) {
	$result = addContactAuthor($paperId, $id);
	if (!DB::isError($result))
	    $Conf->confirmMsg("Contact author added.");
    }
} else if (isset($_REQUEST["remove"])) {
    if (!$Me->amAssistant())
	$Conf->errorMsg("Only the PC chair can remove contact authors from a paper.");
    else if (($id = cvtint($_REQUEST['remove'])) <= 0)
	$Conf->errorMsg("Invalid contact author ID in request.");
    else {
	$result = removeContactAuthor($paperId, $id);
	if (!DB::isError($result))
	    $Conf->confirmMsg("Contact author removed.");
    }
} else
    $Conf->infoMsg("Use this screen to add more contact authors for your paper.  Any contact author can edit paper information, upload new versions, submit the paper, and view reviews." . ($Me->amAssistant() ? "" : "  Only the PC chair can <i>remove</i> contact authors from the paper, so act carefully."));
    



if ($OK) {    
    $paperTable = new PaperTable(false, false, true, false);
    
    echo "<form method='post' action=\"contactauthors.php?paperId=$paperId&amp;post=1\" enctype='multipart/form-data'>
<table class='paper'>\n";

    // title
    echo "<tr class='id'>\n  <td class='caption'><h2>#$paperId</h2></td>\n";
    echo "  <td class='entry' colspan='2'><h2>";
    $paperTable->echoTitle($prow);
    echo "</h2></td>\n</tr>\n\n";

    // Paper contents
    $paperTable->echoStatusRow($prow, PaperTable::STATUS_DOWNLOAD);
    $paperTable->echoAbstractRow($prow);
    $paperTable->echoAuthorInformation($prow);
    $paperTable->echoCollaborators($prow);
    $paperTable->echoTopics($prow);

    // Contact authors
    echo "<tr>\n  <td class='caption'>Contact&nbsp;authors</td>\n";
    echo "  <td class='entry plholder'><table class='pltable'>
    <tr class='pl_headrow'><th>Name</th> <th>Email</th> <th></th></tr>\n";
    $q = "select firstName, lastName, email, contactId
	from ContactInfo
	join PaperConflict using (contactId)
	where paperId=$paperId and author=1
	order by lastName, firstName, email";
    $result = $Conf->qe($q, "while finding contact authors");
    if (!DB::isError($result)) {
	while ($row = $result->fetchRow()) {
	    echo "<tr><td>", contactHtml($row[0], $row[1]), "</td> <td>", htmlspecialchars($row[2]), "</td>";
	    if ($Me->amAssistant())
		echo " <td><button class='button_small' type='submit' name='remove' value='$row[3]'>Remove contact author</button></td>";
	    echo "</tr>\n    ";
	}
    }

    echo "    <tr><td><input class='textlite' type='text' name='name' size='20' onchange='highlightUpdate()' /></td>
	<td><input class='textlite' type='text' name='email' size='20' onchange='highlightUpdate()' /></td>
	<td><input class='button_default' type='submit' name='update' value='Add contact author' /></td>
    </tr>
  </table></td>
</tr>

</table>
</form>\n";
    
} else {
    $Conf->errorMsg("The paper disappeared!");
    printPaperLinks();
}

$Conf->footer();
?>