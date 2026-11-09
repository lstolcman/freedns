<?php

/*
  This file is part of XName.org project
  See  http://www.xname.org/ for details

  License: GPLv2
  See LICENSE file, or http://www.gnu.org/copyleft/gpl.html

  Dynamic DNS HTTP endpoint, an alternative to xmlrpc.php.

  Regular request:

    /ddns.php?domains=host.example.com&token=login:password
             [&ip=ADDRESS] [&ipv6=ADDRESS] [&txt=VALUE] [&ttl=TTL]
             [&updatereverse=1] [&verbose=true]
             [&clear=a,aaaa,txt]

  Alternative form for clients that cannot send query parameters:

    /ddns.php/host.example.com/login:password[/ip]

    The token is one path segment. Percent-encode special characters.
    A slash in the password must be sent as %2F. Some front ends reject
    encoded slashes.

  Examples:

    /ddns.php?domains=host.example.com&token=login:password&ip=192.0.2.10
    /ddns.php?domains=@.example.com&token=login:password&ip=192.0.2.10
    /ddns.php?domains=@&zone=example.com&token=login:password&ip=192.0.2.10
    /ddns.php?domains=host.example.com&token=login:password&ipv6=2001:db8::1
    /ddns.php?domains=host.example.com&token=login:password&txt=example
    /ddns.php?domains=host.example.com&token=login:password&clear=true
    /ddns.php/host.example.com/login:password/192.0.2.10

  domains
    Comma-separated record names. The first label is the record name.
    The other labels are the zone name (host.example.com means the
    record host in the zone example.com). If the "zone" parameter is
    set, it is the zone name and the whole domains value is the record
    name. The apex record can be written as @.example.com, or with the
    "zone" parameter as domains=@. With the "zone" parameter, a domains
    value equal to the zone name is also the apex. A single trailing dot
    is removed. Record names are compared without case. A stored owner
    can be relative or absolute. A stored owner that is the absolute
    record name in the zone (host.example.com. for host) is the same
    record.

  token
    The account credentials as login:password. The service has no per
    user API token. Only the first colon is a separator. The endpoint
    does not validate the login format; it must be an existing account
    login. A login cannot contain a colon. A password can contain colons
    and spaces. Percent-encode them in the query or path form.

  ip, ipv6
    Addresses to set. A blank value is the same as an absent value. If
    only ipv6 is set, the endpoint does not change the A record. If no
    address is given and txt is absent, the endpoint uses the IPv4
    address of the caller. The detected address is the web server's
    REMOTE_ADDR. Behind a proxy, pass the client address or send ip
    explicitly. The endpoint answers KO when the request needs an
    address and no usable address is available. All valid IPv6
    forms are accepted. A form that the record helper rejects is
    expanded to eight hexadecimal groups before it is stored. The
    endpoint rejects A or AAAA updates in a reverse zone, because the
    reverse zone generator prints PTR records only.

  txt
    TXT value for the name. The endpoint adds the value if it is not
    present and keeps the other TXT values. It does not change the A or
    AAAA records. An empty txt value changes nothing. A value of only
    spaces is stored. Use clear=txt to delete all TXT records of the
    name. Use more than one request to keep more than one value. The
    value is limited to 8000 bytes and keeps its leading and trailing
    spaces. The endpoint rejects values that contain a double quote, a
    backslash or a control character, because the zone file generator
    cannot represent them safely. When clear deletes TXT, the endpoint
    ignores the txt value and does not validate it.

  ttl
    TTL for the new records. The default value is -1, which is the zone
    default. Use "default" (any case) or -1 for the zone default, or an
    integer from 1 to 2147483647. A different TTL rewrites an A or AAAA
    record even if the address is the same. A TXT value that already
    exists is not added again.

  updatereverse
    Set to 1 to update PTR records too. The default value is 0. The
    endpoint uses the existing helper. It only updates a PTR when the
    matching reverse zone exists and is owned by the user. It adds a
    PTR when none exists for the address, but it does not replace an
    existing PTR. A missing or unusable reverse zone does not fail the
    request.

  verbose
    Any verbose parameter enables detail; its value is ignored. For an
    address request, append two address lines and UPDATED or NOCHANGE
    to an OK answer. For a TXT-only request, append the txt parameter
    value and UPDATED or NOCHANGE instead. The TXT line is blank when
    txt is absent or empty. A TXT clear echoes the supplied txt value,
    as DuckDNS does.

  clear
    Delete records of the given name in the given zone. Use a
    comma-separated list with any of the exact lowercase values "a",
    "aaaa" and "txt", for example "a,txt". The exact value "true"
    follows the request type: an IP request (no txt parameter) deletes
    A and AAAA, and a TXT request (txt parameter present) deletes TXT.
    Use an explicit "a,aaaa,txt" list to delete all three. A list
    without "txt" does not delete TXT records. A txt value in the same
    request is added. In a clear request, ip and ipv6 are ignored.
    "false"/"0"/an empty value are treated as absent, so the normal
    request rules apply. Any other value, including uppercase "TRUE"
    or "1", answers KO. A normal request with no address and no txt
    uses the IPv4 address of the caller.

  Answers are OK and KO. The body has no trailing newline. A verbose
  answer adds newline-separated lines. The endpoint accepts only HTTPS,
  because the token contains the account password. The token and TXT
  value are part of the request URL, so the web server access log
  records them. Protect or redact that log. The endpoint is stateless.
  It removes the session row that the authentication helper creates.

  A request is not transactional. The endpoint prevalidates names,
  zones, TTL values and TXT values before it writes records. It checks
  each database operation, so a failed read answers KO instead of
  NOCHANGE. A failure during a later database or serial update can
  still leave earlier records changed. The endpoint does not write a
  zone file or start a zone checker. The zone builder validates the
  zone later. A KO does not mean that nothing changed. Concurrent
  updates to the same zone are not locked. The endpoint has no rate
  limit. A failed database write can also send the stock database error
  mail. That mail can contain the failed query, including a TXT value.
  Keep the administrator mailbox trusted.
*/

ini_set('display_errors', '0');

require __DIR__ . '/libs/xname.php';

/* Messages used by the zone and user classes. The endpoint prints no page. */
include __DIR__ . '/includes/strings/en/strings.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/*
  Print OK or KO. A null $txt selects the address form. A string selects
  the TXT form. The body has no trailing newline, like the DuckDNS
  answer.
*/
function ddns_reply($ok, $verbose, $ip4, $ip6, $updated, $txt = null) {
  $lines = array($ok ? "OK" : "KO");
  if ($ok && $verbose) {
    if ($txt === null) {
      $lines[] = ($ip4 === null ? "" : $ip4);
      $lines[] = ($ip6 === null ? "" : $ip6);
    } else {
      $lines[] = $txt;
    }
    $lines[] = ($updated ? "UPDATED" : "NOCHANGE");
  }
  print implode("\n", $lines);
}

/* Answer KO and stop. The endpoint does not write an error log. */
function ddns_ko() {
  ddns_reply(false, false, null, null, false);
  exit;
}

/* Remove one trailing dot from a fully qualified name. */
function ddns_strip_dot($value) {
  if ($value !== "" && substr($value, -1) === ".") {
    return substr($value, 0, -1);
  }
  return $value;
}

/*
  Check a TXT value. Keep leading and trailing spaces. Reject
  characters that the zone file generator cannot quote safely. Return
  "" for an empty value and false for a bad value.
*/
function ddns_clean_txt($value) {
  if (!is_string($value)) {
    return false;
  }
  if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
    return false;
  }
  if (strlen($value) > 8000) {
    return false;
  }
  if (strpos($value, '"') !== false || strpos($value, "\\") !== false) {
    return false;
  }
  return $value;
}

/* Check the DNS label characters and full name lengths. */
function ddns_name_ok($name, $zone) {
  if (strlen($zone) > 253) {
    return false;
  }
  if ($name === "@") {
    return true;
  }
  if ($name !== "*" &&
      strspn($name, "abcdefghijklmnopqrstuvwxyz0123456789-._") !=
      strlen($name)) {
    return false;
  }
  foreach (explode(".", $name) as $label) {
    if ($label === "" || strlen($label) > 63) {
      return false;
    }
  }
  return strlen($name) + strlen($zone) + 1 <= 253;
}

/*
  Return true when the last query on the main connection failed. Check
  the main link, because Db::error() uses the default link and that can
  be the separate authentication connection.
*/
function ddns_db_failed($db) {
  if (!empty($db->sh)) {
    return mysql_errno($db->sh) != 0;
  }
  return $db->error() != 0;
}

/*
  Return the owner name relative to its zone. A stored owner can be an
  absolute name with a trailing dot. It is the same record.
*/
function ddns_canonical($name, $zone) {
  $name = strtolower((string)$name);
  $zone = strtolower((string)$zone);
  if ($name === $zone . ".") {
    return "@";
  }
  $suffix = "." . $zone . ".";
  if (substr($name, -strlen($suffix)) === $suffix) {
    return substr($name, 0, -strlen($suffix));
  }
  return $name;
}

/*
  Load the stored owners, values and TTLs for one canonical name.
  Return false on a database error, so a failed read cannot look like
  an empty record set.
*/
function ddns_find($current, $type, $name) {
  global $db;
  $names = $values = $ttls = $ids = array();
  $v3 = $v4 = $v5 = array();
  $current->retrieveMultiRecords($type, $names, $values, $v3, $v4, $v5,
                                 $ids, $ttls);
  if (!empty($current->error)) {
    return false;
  }
  if (ddns_db_failed($db)) {
    $current->error = "database error";
    return false;
  }
  $want = ddns_canonical($name, $current->zonename);
  $owners = $have = $have_ttl = array();
  foreach ($names as $i => $owner) {
    if (ddns_canonical($owner, $current->zonename) === $want) {
      $owners[] = $owner;
      $have[] = $values[$i];
      $have_ttl[] = $ttls[$i];
    }
  }
  return array($owners, $have, $have_ttl);
}

/* Return true when a record of one type exists for one name. */
function ddns_has($current, $type, $name) {
  $match = ddns_find($current, $type, $name);
  if ($match === false) {
    ddns_ko();
  }
  return (bool)$match[0];
}

/*
  Delete every stored owner name for one canonical name and confirm
  that no record remains.
*/
function ddns_delete($current, $type, $name, $owners) {
  foreach (array_unique($owners) as $owner) {
    $current->deleteMultipleARecords($owner, $type);
    if (!empty($current->error)) {
      return false;
    }
  }
  $match = ddns_find($current, $type, $name);
  if ($match === false) {
    return false;
  }
  if ($match[0]) {
    $current->error = $type . " record still present after delete";
    return false;
  }
  return true;
}

/*
  Replace the A or AAAA records of one name, or add one TXT value.
  A null $value deletes the records. Return true when something changed.
*/
function ddns_apply($current, $name, $type, $value, $ttl, $updatereverse) {
  $match = ddns_find($current, $type, $name);
  if ($match === false) {
    return false;
  }
  list($owners, $have, $have_ttl) = $match;

  if ($value === null) {
    if (!$owners) {
      return false;
    }
    return ddns_delete($current, $type, $name, $owners);
  }

  if ($type === "TXT") {
    if (in_array($value, $have, true)) {
      return false;
    }
  } else {
    $ttl_value = $current->fixDNSTTL($ttl);
    if (count($have) == 1 && strcasecmp((string)$have[0], $value) === 0 &&
        (string)$have_ttl[0] === (string)$ttl_value) {
      return false;
    }
  }

  if (ddns_has($current, "CNAME", $name)) {
    $current->error = "CNAME exists for this name";
    return false;
  }

  if ($type !== "TXT" && $owners &&
      !ddns_delete($current, $type, $name, $owners)) {
    return false;
  }

  if ($type === "A") {
    $current->addARecord($current->zoneid, array($value), array($name),
                         array($ttl), $updatereverse);
  } elseif ($type === "AAAA") {
    $current->addAAAARecord($current->zoneid, array($value), array($name),
                            array($ttl), $updatereverse);
  } else {
    $current->addTXTRecord(array($name), array($value), array($ttl));
  }
  if (!empty($current->error)) {
    return false;
  }

  $match = ddns_find($current, $type, $name);
  if ($match === false) {
    return false;
  }
  foreach ($match[1] as $stored) {
    if ($type === "TXT" ? $stored === $value :
        strcasecmp((string)$stored, $value) === 0) {
      return true;
    }
  }
  $current->error = $type . " record not found after update";
  return false;
}

/* Apply one change and answer KO on a helper error. */
function ddns_do($current, $name, $type, $value, $ttl, $updatereverse) {
  $changed = ddns_apply($current, $name, $type, $value, $ttl,
                        $updatereverse);
  if (!empty($current->error)) {
    ddns_ko();
  }
  return $changed;
}

/* Load one primary zone and check its status, owner and records. */
function ddns_zone($zonename, $user, $owner) {
  global $db;
  $zone = new Zone($zonename, "P");
  $zone->isErroneous();
  if (!empty($zone->error) || empty($zone->zoneid) || empty($owner)) {
    ddns_ko();
  }
  $zone_user = $zone->RetrieveUser();
  if (empty($zone_user) || $zone_user != $owner || !empty($zone->error)) {
    ddns_ko();
  }
  $current = new Primary($zone->zonename, $zone->zonetype, $user);
  if (empty($current->zoneid) || !empty($current->error) ||
      ddns_db_failed($db)) {
    ddns_ko();
  }
  return $current;
}

/*
  Expand an IPv6 address to eight hexadecimal groups. The stock record
  helper rejects some valid compressed forms, so use the expanded form
  for those addresses.
*/
function ddns_ip6($value) {
  if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
    return false;
  }
  if (checkIPv6($value)) {
    return $value;
  }
  $packed = @inet_pton($value);
  if ($packed === false) {
    return false;
  }
  $groups = array();
  foreach (unpack("n8", $packed) as $group) {
    $groups[] = dechex($group);
  }
  $expanded = implode(":", $groups);
  return checkIPv6($expanded) ? $expanded : false;
}

/* Return a valid TTL or false. The default value is -1. */
function ddns_ttl($value) {
  if ($value === "" || $value === "-1" ||
      strcasecmp($value, "default") === 0) {
    return "-1";
  }
  if (!ctype_digit($value)) {
    return false;
  }
  $value = ltrim($value, "0");
  if ($value === "" || strlen($value) > 10 ||
      (strlen($value) === 10 && strcmp($value, "2147483647") > 0)) {
    return false;
  }
  return $value;
}

/* Special no-parameter form: /ddns.php/domain/token[/ip] */
if (empty($_GET["domains"]) && !empty($_SERVER["PATH_INFO"])) {
  $path = explode("/", trim($_SERVER["PATH_INFO"], "/"));
  $_GET["domains"] = rawurldecode($path[0]);
  $_GET["token"] = rawurldecode(isset($path[1]) ? $path[1] : "");
  if (isset($path[2]) && $path[2] !== "") {
    $_GET["ip"] = rawurldecode($path[2]);
  }
}

/* Malformed ?ip[]=... style parameters must not become a 500. */
foreach (array("domains", "token", "ip", "ipv6", "txt", "verbose", "clear",
               "zone", "ttl", "updatereverse") as $key) {
  if (isset($_GET[$key]) && !is_string($_GET[$key])) {
    ddns_ko();
  }
}

$method = isset($_SERVER["REQUEST_METHOD"]) ? $_SERVER["REQUEST_METHOD"] : "";
if ($method !== "" && $method !== "GET") {
  ddns_ko();
}
$query = isset($_SERVER["QUERY_STRING"]) ? (string)$_SERVER["QUERY_STRING"] : "";
$pathinfo = isset($_SERVER["PATH_INFO"]) ? (string)$_SERVER["PATH_INFO"] : "";
if (strlen($query) > 16384 || strlen($pathinfo) > 4096) {
  ddns_ko();
}

$https = isset($_SERVER["HTTPS"]) ? strtolower((string)$_SERVER["HTTPS"]) : "";
if ($https !== "on" && $https !== "1") {
  ddns_ko();
}

$domains = isset($_GET["domains"]) ? trim($_GET["domains"]) : "";
$token = isset($_GET["token"]) ? $_GET["token"] : "";
if ($domains === "" || strpos($token, ":") === false) {
  ddns_ko();
}
if (strlen($domains) > 4096 || strlen($token) > 4096) {
  ddns_ko();
}

$names = array_values(array_filter(array_map("trim", explode(",", $domains)),
                                   "strlen"));
/* Only the first colon is a separator. A password can contain colons and
   whitespace, so the password part is taken verbatim. */
list($login, $password) = explode(":", $token, 2);
$login = trim($login);
if (!$names || $login === "") {
  ddns_ko();
}
if (count($names) > 32) {
  ddns_ko();
}

$verbose = isset($_GET["verbose"]);
$txt_given = array_key_exists("txt", $_GET);

/* clear deletes records of the name. true follows the request type. */
$clear = array();
if (isset($_GET["clear"])) {
  $clear_value = $_GET["clear"];
  if (!is_string($clear_value)) {
    ddns_ko();
  }
  if ($clear_value === "true") {
    $clear = $txt_given ? array("txt") : array("a", "aaaa");
  } elseif ($clear_value !== "" && $clear_value !== "false" &&
            $clear_value !== "0") {
    foreach (explode(",", $clear_value) as $item) {
      if (!in_array($item, array("a", "aaaa", "txt"), true)) {
        ddns_ko();
      }
      $clear[] = $item;
    }
    $clear = array_values(array_unique($clear));
  }
}

/* A clear request ignores the txt value. Validate it only when the
   endpoint is going to store it. */
$txt_value = null;
if ($txt_given && !in_array("txt", $clear, true)) {
  $txt_value = ddns_clean_txt($_GET["txt"]);
  if ($txt_value === false) {
    ddns_ko();
  }
}
if ($txt_value === "") {
  $txt_value = null;
}

$ttl = ddns_ttl(isset($_GET["ttl"]) ? trim($_GET["ttl"]) : "");
if ($ttl === false) {
  ddns_ko();
}

$updatereverse = !empty($_GET["updatereverse"]) &&
                 in_array(strtolower(trim($_GET["updatereverse"])),
                          array("1", "true"), true);

$ip4 = null;
$ip6 = null;
$ip_given = array_key_exists("ip", $_GET);
$ipv6_given = array_key_exists("ipv6", $_GET);
if (!$clear) {
  $ip_value = $ip_given ? trim($_GET["ip"]) : "";
  $ipv6_value = $ipv6_given ? trim($_GET["ipv6"]) : "";

  if ($ip_value !== "") {
    if (filter_var($ip_value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
      $ip4 = $ip_value;
    } else {
      $ip6 = ddns_ip6($ip_value);
      if ($ip6 === false) {
        ddns_ko();
      }
    }
  }
  if ($ipv6_value !== "") {
    $ip6 = ddns_ip6($ipv6_value);
    if ($ip6 === false) {
      ddns_ko();
    }
  }

  /* DuckDNS detects only an IPv4 caller address. It does not detect
     the address when the request is for TXT only. */
  if ($ip4 === null && $ip6 === null && !$txt_given) {
    $remote = isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : "";
    if (filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
      $ip4 = $remote;
    }
  }
}

$want_a = in_array("a", $clear, true) || (!$clear && $ip4 !== null);
$want_aaaa = in_array("aaaa", $clear, true) || (!$clear && $ip6 !== null);
$want_txt = $txt_given || in_array("txt", $clear, true);
$set_a = (!$clear && $ip4 !== null);
$set_aaaa = (!$clear && $ip6 !== null);
$clear_a = in_array("a", $clear, true);
$clear_aaaa = in_array("aaaa", $clear, true);
$clear_txt = in_array("txt", $clear, true);
$set_txt = ($txt_given && !$clear_txt && $txt_value !== null);
$add_txt = $set_txt;
$address_request = ($want_a || $want_aaaa);

if (!$address_request && !$want_txt) {
  ddns_ko();
}

/*
  The verbose TXT form shows the txt parameter and no label. A clear
  echoes the supplied value, as DuckDNS does. An absent or empty value
  prints a blank line.
*/
$txt_echo = null;
if ($want_txt && !$address_request) {
  $txt_echo = ($txt_value === null ?
               ($txt_given && is_string($_GET["txt"]) ? $_GET["txt"] : "") :
               $txt_value);
}

/* Normalize and split all names before the first write. */
$zone_param = isset($_GET["zone"]) ?
              ddns_strip_dot(strtolower(trim($_GET["zone"]))) : "";
if (strlen($zone_param) > 253) {
  ddns_ko();
}
$targets = array();
$seen = array();
foreach ($names as $fqdn) {
  $fqdn = ddns_strip_dot(strtolower(trim($fqdn)));
  if ($zone_param !== "") {
    /* the zone parameter overrides the split. domains is the record name */
    $name = $fqdn;
    $zonename = $zone_param;
    if (strcasecmp($name, $zonename) === 0) {
      $name = "@";
    }
  } else {
    $dot = strpos($fqdn, ".");
    if ($dot === false) {
      ddns_ko();
    }
    $name = substr($fqdn, 0, $dot);
    $zonename = ddns_strip_dot(substr($fqdn, $dot + 1));
  }
  if ($name === "" || $zonename === "") {
    ddns_ko();
  }
  $key = $zonename . "\0" . $name;
  if (isset($seen[$key])) {
    continue;
  }
  $seen[$key] = 1;
  $targets[] = array($name, $zonename);
}

$config = new Config();
$db = new Db();
if ($config->userdbname) {
  $dbauth = new DbAuth();
} else {
  $dbauth = $db;
}
$user = new User($login, $password, NULL);
if ($user->authenticated != 1) {
  ddns_ko();
}

/* The API is stateless. Remove the session row created by User. */
if (!empty($user->idsession)) {
  $session = mysql_real_escape_string($user->idsession);
  $db->query("DELETE FROM dns_session WHERE sessionID='" . $session . "'");
  if (ddns_db_failed($db)) {
    ddns_ko();
  }
}

if ($config->usergroups) {
  include_once __DIR__ . '/libs/group.php';
  $group = new Group($user->userid);
  if (!empty($group->error)) {
    ddns_ko();
  }
  $owner = $group->groupid;
} else {
  $owner = $user->userid;
}
if (empty($owner)) {
  ddns_ko();
}

/* Match the web interface: read-only group members cannot update. */
if ($config->usergroups && $user->grouprights == 'R') {
  ddns_ko();
}

/* The operations are the same for every target. */
$ops = array();
if ($clear_a) {
  $ops[] = array("A", null);
} elseif ($set_a) {
  $ops[] = array("A", $ip4);
}
if ($clear_aaaa) {
  $ops[] = array("AAAA", null);
} elseif ($set_aaaa) {
  $ops[] = array("AAAA", $ip6);
}
if ($clear_txt) {
  $ops[] = array("TXT", null);
} elseif ($set_txt) {
  $ops[] = array("TXT", $txt_value);
}

/* Validate every zone, owner and name before the update loop. */
foreach ($targets as $i => $target) {
  $name = $target[0];
  $zonename = $target[1];
  $current = ddns_zone($zonename, $user, $owner);

  if (($set_a || $set_aaaa) && $current->reversezone) {
    ddns_ko();
  }

  $name_ok = true;
  if ($want_a || $want_aaaa) {
    $name_ok = $current->checkAName($name);
  }
  if ($want_txt && ($set_txt || $clear_txt)) {
    $name_ok = $name_ok && $current->checkTXTName($name);
  }
  if (!$name_ok || !ddns_name_ok($name, $zonename)) {
    ddns_ko();
  }

  /* A CNAME conflict must fail before any target is changed. */
  if (($set_a || $set_aaaa || $add_txt) &&
      ddns_has($current, "CNAME", $name)) {
    ddns_ko();
  }

  $targets[$i][2] = $current;
}

$updated = false;
foreach ($targets as $target) {
  $name = $target[0];
  $current = $target[2];

  $changed = false;
  foreach ($ops as $op) {
    if (ddns_do($current, $name, $op[0], $op[1], $ttl, $updatereverse)) {
      $changed = true;
    }
  }

  if (!$changed) {
    continue;
  }

  /* Mark the zone and update its serial number before the next target. */
  $flag = $current->flagModified($current->zoneid);
  if (trim((string)$flag) !== "" || ddns_db_failed($db)) {
    ddns_ko();
  }
  if (!$current->updateSerial($current->zoneid) ||
      !empty($current->error) || ddns_db_failed($db)) {
    ddns_ko();
  }
  $updated = true;
}

ddns_reply(true, $verbose, $ip4, $ip6, $updated, $txt_echo);
