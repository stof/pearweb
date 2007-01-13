<?php
/*
   +----------------------------------------------------------------------+
   | PEAR Web site version 1.0                                            |
   +----------------------------------------------------------------------+
   | Copyright (c) 2001-2005 The PHP Group                                |
   +----------------------------------------------------------------------+
   | This source file is subject to version 2.02 of the PHP license,      |
   | that is bundled with this package in the file LICENSE, and is        |
   | available at through the world-wide-web at                           |
   | http://www.php.net/license/2_02.txt.                                 |
   | If you did not receive a copy of the PHP license and are unable to   |
   | obtain it through the world-wide-web, please send a note to          |
   | license@php.net so we can mail you a copy immediately.               |
   +----------------------------------------------------------------------+
   | Authors:                                                             |
   +----------------------------------------------------------------------+
   $Id$
*/

/**
 * Details about PEAR accounts
 */
require_once 'Damblan/URL.php';
require_once 'HTTP.php';
$site = new Damblan_URL();

$params = array('handle' => '', 'action' => '');
$site->getElements($params);

$handle = htmlspecialchars(strtolower($params['handle']));

/*
 * Redirect to the accounts list if no handle was specified
 */
if (empty($handle)) {
    localRedirect('/accounts.php');
}

$dbh->setFetchmode(DB_FETCHMODE_ASSOC);

$row = user::info($handle);

if ($row === null) {
    error_handler($handle . ' is not a valid account name.', 'Invalid Account');
}

switch ($params['action']) {
 case "wishlist" :
     if (!empty($row['wishlist'])) {
         HTTP::redirect($row['wishlist']);
     } else {
         PEAR::raiseError(htmlspecialchars($row['name']) . " has not registered a wishlist");
     }
     break;

 case "bugs" :
     HTTP::redirect("/bugs/search.php?handle=" . $handle . "&cmd=display");
     break;

 case "rss" :
     HTTP::redirect("/feeds/user_" . $handle . ".rss");
     break;

}

response_header('User Information :: ' . htmlspecialchars($row['name']));

echo '<h1>User Information: ' . htmlspecialchars($row['name']) . "</h1>\n";

if (isset($auth_user) && is_object($auth_user) 
    && ($auth_user->handle == $handle ||
        auth_check('pear.admin'))) {

    $nav_items = array('Edit user' => array('url' => '/account-edit.php?handle=' . $handle,
                                            'title' => 'Edit user standing data.'),
                       'Change password' => array('url' => '/account-edit.php?handle=' . $handle . '#password',
                                                  'title' => 'Change your password.')
                       );

    if (auth_check('pear.admin')) {
        $nav_items['Edit Karma'] = array('url' => '/admin/karma.php?handle=' . $handle,
                                         'title' => 'Edit karma for this user');
    }

    print '<div id="nav">';

    foreach ($nav_items as $title => $item) {
        if (!empty($item['url']) && $item['url']{0} == '/') {
            $url = $item['url'];
        } else {
            $url = '/package/' . $name . '/' . $item['url'];
        }
        print '<a href="' . $url . '"'
            . ' title="' . $item['title'] . '"> '
            . $title
            . '</a>';
    }

    print '</div>';
}
?>

<table border="0" cellspacing="0" cellpadding="2" style="width: 100%">
 <tr>
  <th class="headrow" colspan="2">&raquo;
  <?php echo htmlspecialchars($row['name']); ?></th>
 </tr>

<?php

if ($row['userinfo']) {
    echo ' <tr>' . "\n";
    echo '  <td class="textcell" colspan="2">';
    echo nl2br(htmlspecialchars($row['userinfo'])) . "</td>\n";
    echo ' </tr>' . "\n";
}

?>

 <tr>
  <td colspan="2">
   <ul>
    <li>Username: <?php echo $row['handle']; ?></li>
<?php

if (isset($auth_user)) {
    echo "<li>Email: &nbsp;";
    echo make_mailto_link($row['email']);
    echo "</li>\n";
} else if ($row['showemail']) {
    $row['email'] = str_replace(array('@', '.'),
                                array(' at ', ' dot '),
                                $row['email']);
    echo '<li>Email: &nbsp;';
    print_link('/account-mail.php?handle=' . $handle,
               htmlspecialchars($row['email']));
    echo "</li>\n";
} else {
    echo '<li>Email: &nbsp;';
    print_link('/account-mail.php?handle=' . $handle, 'via web form');
    echo "</li>\n";
}

if ($row['homepage']) {
    echo '<li>Homepage: &nbsp;';
    print_link(htmlspecialchars($row['homepage']));
    echo "</li>\n";
}

if ($row['wishlist']) {
    echo '<li>Wishlist: &nbsp;';
    print_link('http://' . htmlspecialchars($_SERVER['HTTP_HOST']) . '/user/' . $handle . '/wishlist');
    echo "</li>\n";
}

if ($row['pgpkeyid']) {
    echo '<li>PGP Key: &nbsp;';
    print_link('http://pgp.mit.edu:11371/pks/lookup?search=0x'
               . htmlspecialchars($row['pgpkeyid']) . '&amp;op=get',
               htmlspecialchars($row['pgpkeyid']));
    echo "</li>\n";
}

echo '<li>RSS Feed: &nbsp;';
print_link('http://' . htmlspecialchars($_SERVER['HTTP_HOST']) . '/feeds/user_' . $handle . '.rss');
echo '</li>';

echo '<li>Bug Statistics: <br /><ul>';

require 'bugs/pear-bugs.php';
$bugs = new PEAR_Bugs;
$info = $bugs->developerBugStats($handle);
echo '<li>Rank: <strong><a href="/bugs/stats.php#devs">#' . $info['rank'] . ' of ' . count($info['rankings']) . '</a></strong> developers who have fixed bugs <strong>(' .
    $info['alltime'] . ' fixed bugs)</strong></li>';
echo '<li>Average age of open bugs: <strong>' . $info['openage'] . ' days</strong></li>';
echo '<li>Number of open bugs: <strong>' . $info['opencount'] . '</strong></li>';
echo '<li>Assigned bugs relative to all maintained packages\' bugs: <strong>' .
    round($info['assigned'] * 100) . '%</strong></li>';
echo '</ul></li>';

?>

   </ul>
  </td>
 </tr>

<?php
$packages = user::getPackages($handle);
$notes = note::getAll($handle);

if (count($packages) > 0 || count($notes) > 0) {
?>

 <tr>
  <th class="headrow" style="width: 50%">&raquo; Maintains These Packages</th>
  <th class="headrow" style="width: 50%">&raquo; Notes Regarding User</th>
 </tr>
 <tr>
  <td valign="top">

<?php
if (count($packages) == 0) {
    echo '<p>This user does not maintain any packages.</p>';
}
?>
   <ul>
<?php
foreach ($packages as $row) {
    echo '<li>';
    print_link('/package/' . htmlspecialchars($row['name']),
               htmlspecialchars($row['name']));
    echo ' &nbsp;(' . htmlspecialchars($row['role']) . ($row['active'] == 0 ? ", inactive " : "") . ')';
    echo ' &nbsp;<small><a href="/bugs/search.php?package_name%5B%5D=';
    echo htmlspecialchars($row['name']) . '&amp;cmd=display">Bugs</a></small>';
    echo "</li>\n";
}

?>

   </ul>
  </td>
  <td valign="top">
<?php
if (count($notes) == 0) {
    echo '<p>There are no notes for this user.</p>';
}
?>
   <ul>
<?php
foreach ($notes as $nid => $data) {
    echo ' <li>' . "\n";
    echo '' . $data['nby'] . ' ';
    echo substr($data['ntime'], 0, 10) . ":<br />\n";
    echo htmlspecialchars($data['note']);
    echo "\n </li>\n";
}

?>

   </ul>
  </td>
 </tr>
<?php
}
?>
</table>

<?php

response_footer();

?>
