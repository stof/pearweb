<?php
$logfile = '/path/to/logfile';

function scrape_log_line($line, &$last_download)
{
    if (!preg_match('/^(\S+) (\S+) (\S+) \[([^:]+):(\d+:\d+:\d+) ([^\]]+)\]' .
        ' "(\S+) (.*?) (\S+)" (\S+) (\S+) (".*?") (".*?")$/', $line, $matches)) {
        return false;
    }
    if (!preg_match('/([a-zA-Z0-9_]+)-([a-zA-Z0-9.]+)\.(?:tgz|tar)$/', $matches[8], $fileinfo)) {
        return false;
    }
    $dl_time = strtotime(str_replace('/', ' ', $matches[4]) . ' ' . $matches[5]);
    if ($last_download > $dl_time) {
        // this entry is too old
        return false;
    }
    $last_download = $dl_time;
    return array('package' => $fileinfo[1], 'version' => $fileinfo[2]);
}

if (!isset($_GET['last_dl'])) {
    die('<?xml version="1.0" ?><error>last_dl is not set</error>');
}
if ($_GET['last_dl'] == '0') {
    $last_dl = strtotime(0);
} else {
    $last_dl = strtotime($_GET['last_dl']);
}

$downloaded = array();

foreach (new SplFileObject($logfile) as $line) {
    if ($info = scrape_log_line($line, $last_dl)) {
        if (!isset($downloaded[$info['package']])) {
            $downloaded[$info['package']] = array();
        }
        if (!isset($downloaded[$info['package']][$info['version']])) {
            $downloaded[$info['package']][$info['version']] = 1;
        } else {
            $downloaded[$info['package']][$info['version']]++;
        }
    }
}
header('Content-Type: text/xml');
echo '<?xml version="1.0"?>', "\n";
foreach ($downloaded as $package => $versions) {
    echo '<p>' . htmlspecialchars($package) . '</p><r>';
    foreach ($versions as $version => $count) {
        echo '<v><n>', htmlspecialchars($version), '</n><c>', $count, '</c></v>';
    }
    echo '</r>';
}
echo '<l>', $last_dl, '</l>';
